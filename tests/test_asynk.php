#!/usr/bin/php
<?php

/**
 * Validation script for Asynk:
 * - parsing of the "retries" configuration, invalid configurations;
 * - MySQL storage (tested with an SQLite database in memory): task processing, new attempts after a failure (not for PHP
 *   errors), failed tasks, management of the tasks (stats, list, show, retry, remove, purge, recover), renamed fields,
 *   table without the retry fields, reservation by xinetd (getTaskFromId(), isTaskReady(), reserveTask());
 * - Redis storage (tested with a temporary Redis server, if the redis-server program and the phpredis extension are
 *   available): list, sorted set and hash methods of the data source, same tests, tasks processed only once even if
 *   their identifier is several times in the queue;
 * - Redis storage with an xinetd transport (fake xinetd server): notification, processing by xinetd or by the crontab,
 *   delayed tasks ignored by xinetd, unreachable xinetd;
 * - error messages with invalid UTF-8 characters (cleaned and truncated), non-encodable parameters, crash recovery
 *   counted as an attempt, dates of next attempt stored in UTC (MySQL), failure of the invalidation;
 * - xinetd script, Asynk worker and AsynkManager command (without terminal control sequences in its output), executed
 *   with bin/comma (Redis storage).
 * Beanstalkd and SQS transports are tested by test_datasource_beanstalk.php and test_datasource_sqs.php.
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');
\Temma\Base\Log::disable();
// dates are compared with the dates of SQLite (UTC)
date_default_timezone_set('UTC');
// PHP warnings and notices are converted to exceptions, so they make the tests fail
set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline) {
	// errors silenced with the @ operator are ignored
	if (!(error_reporting() & $errno))
		return (false);
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

/* ********** HELPERS ********** */
$count = 0;
$failed = 0;
/**
 * Executes a test and displays its result.
 * @param	string		$label	Description of the test.
 * @param	callable	$test	Function which returns true if the test is successful.
 *					If it throws an exception, the test fails and the exception is displayed.
 */
function check(string $label, callable $test) : void {
	global $count, $failed;
	$count++;
	$error = null;
	try {
		$ok = ($test() === true);
	} catch (\Throwable $t) {
		$ok = false;
		$error = get_class($t) . ': ' . $t->getMessage() . ' (' . basename($t->getFile()) . ':' . $t->getLine() . ')';
	}
	if (!$ok)
		$failed++;
	print(TµAnsi::faint(sprintf('%02d', $count)) . ' ' .
	      TµAnsi::color(($ok ? 'green' : 'red'), ($ok ? 'OK' : 'KO')) . ' ' .
	      "$label\n");
	if ($error)
		print('   ' . TµAnsi::faint($error) . "\n");
}
/**
 * Create an AsynkDao object.
 * @param	array	$xtra		Content of the "x-asynk" configuration.
 * @param	array	$dataSources	Data sources.
 * @return	\Temma\Asynk\AsynkDao	The object.
 */
function asynkDao(array $xtra, array $dataSources) : \Temma\Asynk\AsynkDao {
	$config = new class($xtra) {
		public function __construct(private array $_xtra) {
		}
		public function xtra(string $name, ?string $key=null, mixed $default=null) : mixed {
			return ($this->_xtra[$key] ?? $default);
		}
	};
	$loader = new \Temma\Base\Loader([
		'config'      => $config,
		'dataSources' => $dataSources,
	]);
	return (new \Temma\Asynk\AsynkDao($loader));
}
/**
 * Call a private method.
 * @param	object|string	$object	Object or class name.
 * @param	string		$method	Method name.
 * @param	array		$params	Parameters.
 * @return	mixed	The returned value.
 */
function callPrivate(object|string $object, string $method, array $params=[]) : mixed {
	$reflection = new \ReflectionMethod($object, $method);
	return ($reflection->invokeArgs(is_object($object) ? $object : null, $params));
}

/** Object whose methods are called by the tasks. */
class AsynkTestTask {
	/** List of calls. */
	static public array $calls = [];

	/**
	 * Successful task.
	 * @param	mixed	...$params	Parameters.
	 */
	public function ok(mixed ...$params) : void {
		self::$calls[] = $params;
	}
	/**
	 * Task throwing an exception.
	 * @throws	\Exception	Always.
	 */
	public function fail() : void {
		throw new \Exception('Temporary failure');
	}
	/** Task with a PHP error. */
	public function bug() : void {
		$value = null;
		$value->method();
	}
}

/**
 * DAO used for the tests with SQLite: the Asynk DAO uses sort and limit parameters in update queries, which are
 * accepted by the Temma DAO only with MySQL (SQLite supports them if it was compiled with the right option).
 */
class AsynkTestDao extends \Temma\Dao\Dao {
	/**
	 * Constructor.
	 * @param	mixed	...$params	Parameters of the Dao constructor.
	 */
	public function __construct(mixed ...$params) {
		parent::__construct(...$params);
		$this->_dbType = 'mysql';
	}
}

/* ********** RETRIES CONFIGURATION ********** */
print(TµAnsi::bold("Retries configuration\n"));
check('Delays are given in seconds or with a unit; true gives the default delays', function() {
	return (callPrivate('\Temma\Asynk\AsynkDao', '_parseRetries', [null]) === null &&
	        callPrivate('\Temma\Asynk\AsynkDao', '_parseRetries', [false]) === null &&
	        callPrivate('\Temma\Asynk\AsynkDao', '_parseRetries', [[]]) === [] &&
	        callPrivate('\Temma\Asynk\AsynkDao', '_parseRetries', [[30, '45', '10s', '2m', '4H', '1d']]) === [30, 45, 10, 120, 14400, 86400] &&
	        callPrivate('\Temma\Asynk\AsynkDao', '_parseRetries', [true]) === [60, 120, 900, 3600, 14400, 43200, 86400, 172800]);
});
check('Invalid delays throw a Framework exception', function() {
	foreach ([3, 'yes', ['1w'], [-5], [1.5], ['m']] as $retries) {
		try {
			callPrivate('\Temma\Asynk\AsynkDao', '_parseRetries', [$retries]);
			return (false);
		} catch (\Temma\Exceptions\Framework $e) {
		}
	}
	return (true);
});

/* ********** MYSQL STORAGE (SQLITE) ********** */
if (!extension_loaded('pdo_sqlite')) {
	print(TµAnsi::color('yellow', 'The pdo_sqlite extension is not loaded, SQL storage tests skipped.') . "\n");
} else {
	print("\n" . TµAnsi::bold("SQL storage\n"));
	/**
	 * Create an SQLite database with the Task table.
	 * @param	bool	$retryFields	True to add the fields used by the retries.
	 * @return	\Temma\Datasources\Sql	The database.
	 */
	function sqlDatabase(bool $retryFields=true) : \Temma\Datasources\Sql {
		$db = \Temma\Datasources\Sql::factory('sqlite::memory:');
		$db->exec('CREATE TABLE Task (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				dateCreation TEXT,
				dateUpdate TEXT,
				status TEXT NOT NULL DEFAULT \'waiting\',
				token TEXT,
				target TEXT NOT NULL,
				action TEXT NOT NULL,
				data TEXT' .
				($retryFields ? ', attempts INTEGER NOT NULL DEFAULT 0, dateNextAttempt TEXT, error TEXT' : '') . '
			)');
		return ($db);
	}
	$db = sqlDatabase();
	$asynk = asynkDao(['storage' => 'db', 'dao' => 'AsynkTestDao', 'retries' => ['1m', '2m']], ['db' => $db]);
	check('A created task is waiting, then processing when it is fetched', function() use ($asynk) {
		$asynk->createTask('AsynkTestTask', 'ok', [1, 'a']);
		if ($asynk->getStats() !== ['waiting' => 1, 'delayed' => 0, 'processing' => 0, 'failed' => 0])
			return (false);
		$task = $asynk->getNextTask();
		return ($task['target'] === 'AsynkTestTask' && $task['action'] === 'ok' && $task['data'] === [1, 'a'] &&
		        $asynk->getStats() === ['waiting' => 0, 'delayed' => 0, 'processing' => 1, 'failed' => 0] &&
		        $asynk->getNextTask() === null);
	});
	check('A successful task is executed, then removed', function() use ($asynk, $db) {
		$db->exec('DELETE FROM Task');
		AsynkTestTask::$calls = [];
		$asynk->createTask('AsynkTestTask', 'ok', ['x', ['y' => 2]]);
		$task = $asynk->getNextTask();
		if (!$asynk->executeTask($task))
			return (false);
		$asynk->removeFetchedTask($task);
		return (AsynkTestTask::$calls === [['x', ['y' => 2]]] && $asynk->getStats()['processing'] === 0 &&
		        $asynk->getTask($task['id']) === null);
	});
	check('A failed task is delayed (error and number of attempts stored), then failed after the last attempt', function() use ($asynk, $db) {
		$db->exec('DELETE FROM Task');
		$asynk->createTask('AsynkTestTask', 'fail', []);
		// first attempt: new attempt in 1 minute
		$task = $asynk->getNextTask();
		$before = time();
		if ($asynk->executeTask($task))
			return (false);
		$stored = $asynk->getTask($task['id']);
		$next = strtotime($stored['dateNextAttempt']);
		if ($stored['state'] !== 'delayed' || $stored['attempts'] !== 1 || $stored['error'] !== 'Exception: Temporary failure' ||
		    $next < $before + 60 || $next > time() + 60 || $asynk->getNextTask() !== null ||
		    $asynk->getStats() !== ['waiting' => 0, 'delayed' => 1, 'processing' => 0, 'failed' => 0])
			return (false);
		// second attempt (the date of the next attempt is reached): new attempt in 2 minutes
		$db->exec("UPDATE Task SET dateNextAttempt = '2000-01-01 00:00:00'");
		$task = $asynk->getNextTask();
		if (!$task || $asynk->invalidateFetchedTask($task, 'Second failure') !== 120)
			return (false);
		// third attempt: failed
		$db->exec("UPDATE Task SET dateNextAttempt = '2000-01-01 00:00:00'");
		$task = $asynk->getNextTask();
		if (!$task || $asynk->invalidateFetchedTask($task, 'Third failure') !== null)
			return (false);
		$stored = $asynk->getTask($task['id']);
		return ($stored['state'] === 'failed' && $stored['attempts'] === 3 && $stored['error'] === 'Third failure' &&
		        $asynk->getStats() === ['waiting' => 0, 'delayed' => 0, 'processing' => 0, 'failed' => 1]);
	});
	check('A PHP error or an unknown method puts the task aside without new attempt', function() use ($asynk, $db) {
		$db->exec('DELETE FROM Task');
		$asynk->createTask('AsynkTestTask', 'bug', []);
		$asynk->createTask('AsynkTestTask', 'unknownMethod', []);
		$asynk->executeTask($asynk->getNextTask());
		$asynk->executeTask($asynk->getNextTask());
		$tasks = $asynk->listTasks('failed');
		return (count($tasks) === 2 && $tasks[0]['action'] === 'bug' && str_starts_with($tasks[0]['error'], 'Error: ') &&
		        $tasks[1]['error'] === "Exception: No method 'unknownMethod' in object 'AsynkTestTask'.");
	});
	check('Failed and delayed tasks are put back in the queue by retryTask(), with attempts reset', function() use ($asynk, $db) {
		$tasks = $asynk->listTasks('failed');
		if (!$asynk->retryTask($tasks[0]['id']) || $asynk->retryTask($tasks[0]['id']) || $asynk->retryTask(999))
			return (false);
		$task = $asynk->getTask($tasks[0]['id']);
		if ($task['state'] !== 'waiting' || $task['attempts'] !== 0 || $task['error'] !== null)
			return (false);
		// delayed task
		$asynk->createTask('AsynkTestTask', 'fail', []);
		$db->exec("UPDATE Task SET status = 'error' WHERE action = 'bug'");
		while (($task = $asynk->getNextTask()) && $task['action'] != 'fail')
			;
		$asynk->executeTask($task);
		return ($asynk->getTask($task['id'])['state'] === 'delayed' && $asynk->retryTask($task['id']) &&
		        $asynk->getTask($task['id'])['state'] === 'waiting');
	});
	check('retryFailedTasks(), removeTask() and purgeFailedTasks()', function() use ($asynk, $db) {
		$db->exec('DELETE FROM Task');
		for ($i = 0; $i < 4; $i++)
			$asynk->createTask('AsynkTestTask', 'bug', [$i]);
		for ($i = 0; $i < 4; $i++)
			$asynk->executeTask($asynk->getNextTask());
		if ($asynk->getStats()['failed'] !== 4 || $asynk->retryFailedTasks() !== 4 || $asynk->getStats()['waiting'] !== 4)
			return (false);
		for ($i = 0; $i < 4; $i++)
			$asynk->executeTask($asynk->getNextTask());
		$tasks = $asynk->listTasks('failed', 2);
		if (count($tasks) !== 2 || $tasks[0]['data'] !== [0] || $tasks[1]['data'] !== [1])
			return (false);
		if (!$asynk->removeTask($tasks[0]['id']) || $asynk->removeTask($tasks[0]['id']))
			return (false);
		return ($asynk->purgeFailedTasks() === 3 && $asynk->getStats() === ['waiting' => 0, 'delayed' => 0, 'processing' => 0, 'failed' => 0]);
	});
	check('recoverTasks(): an interrupted processing counts as an attempt (new attempt planned, or task put aside)', function() use ($asynk, $db) {
		$db->exec('DELETE FROM Task');
		$asynk->createTask('AsynkTestTask', 'ok', [1]);
		$asynk->createTask('AsynkTestTask', 'ok', [2]);
		$asynk->createTask('AsynkTestTask', 'ok', [3]);
		$first = $asynk->getNextTask();
		$second = $asynk->getNextTask();
		$third = $asynk->getNextTask();
		// SQLite doesn't update the dateUpdate field automatically
		$db->exec("UPDATE Task SET dateUpdate = '" . date('Y-m-d H:i:s', time() - 7200) . "' WHERE id IN ({$first['id']}, {$third['id']})");
		$db->exec("UPDATE Task SET dateUpdate = '" . date('Y-m-d H:i:s', time() - 600) . "' WHERE id = " . $second['id']);
		// the third task has already been started 3 times (more than the number of retries)
		$db->exec("UPDATE Task SET attempts = 3 WHERE id = " . $third['id']);
		if ($first['attempts'] !== 1 || $asynk->listTasks('processing')[0]['dateStart'] === null)
			return (false);
		if ($asynk->recoverTasks(60) !== 2)
			return (false);
		$recovered = $asynk->getTask($first['id']);
		$failed = $asynk->getTask($third['id']);
		return ($recovered['state'] === 'delayed' && $recovered['error'] === \Temma\Asynk\AsynkDao::INTERRUPTED_ERROR &&
		        $failed['state'] === 'failed' && $asynk->getTask($second['id'])['state'] === 'processing' &&
		        $asynk->recoverTasks(5) === 1 && $asynk->getTask($second['id'])['state'] === 'delayed');
	});
	check('Error messages with invalid UTF-8 characters are cleaned; non-encodable parameters are refused', function() use ($asynk, $db) {
		$db->exec('DELETE FROM Task');
		$asynk->createTask('AsynkTestTask', 'fail', []);
		$task = $asynk->getNextTask();
		$asynk->invalidateFetchedTask($task, "Bad \xff\xfe response");
		$error = $asynk->getTask($task['id'])['error'];
		try {
			$asynk->createTask('AsynkTestTask', 'ok', ["\xff"]);
			return (false);
		} catch (\Temma\Exceptions\Application $e) {
		}
		return (mb_check_encoding($error, 'UTF-8') && str_starts_with($error, 'Bad ') && str_ends_with($error, ' response') &&
		        $asynk->getStats()['waiting'] === 0);
	});
	check('Dates of next attempt are stored in UTC, and returned in the local time zone', function() use ($asynk, $db) {
		$db->exec('DELETE FROM Task');
		date_default_timezone_set('Europe/Paris');
		try {
			$asynk->createTask('AsynkTestTask', 'fail', []);
			$task = $asynk->getNextTask();
			$asynk->invalidateFetchedTask($task, 'Failure');
			$stored = $db->queryOne('SELECT dateNextAttempt FROM Task', 'dateNextAttempt');
			$returned = $asynk->getTask($task['id'])['dateNextAttempt'];
		} finally {
			date_default_timezone_set('UTC');
		}
		return (abs(strtotime("$stored UTC") - (time() + 60)) <= 2 && $returned !== $stored &&
		        abs((new \DateTime($returned, new \DateTimeZone('Europe/Paris')))->getTimestamp() - (time() + 60)) <= 2);
	});
	check('Unknown states throw an Application exception', function() use ($asynk) {
		try {
			$asynk->listTasks('unknown');
		} catch (\Temma\Exceptions\Application $e) {
			return (true);
		}
		return (false);
	});
	check('Without retries, the table doesn\'t need the retry fields and failed tasks get the "error" status', function() {
		$db = sqlDatabase(false);
		$asynk = asynkDao(['storage' => 'db', 'dao' => 'AsynkTestDao'], ['db' => $db]);
		$asynk->createTask('AsynkTestTask', 'fail', []);
		$task = $asynk->getNextTask();
		if ($asynk->executeTask($task))
			return (false);
		$stored = $asynk->getTask($task['id']);
		if ($stored['state'] !== 'failed' || $stored['attempts'] !== null || $stored['error'] !== null ||
		    $asynk->getStats() !== ['waiting' => 0, 'delayed' => 0, 'processing' => 0, 'failed' => 1] ||
		    $asynk->listTasks('delayed') !== [] || $asynk->retryFailedTasks() !== 1 || $asynk->getNextTask()['id'] !== $task['id'])
			return (false);
		// recovered tasks are put back in the queue
		$db->exec("UPDATE Task SET dateUpdate = '" . date('Y-m-d H:i:s', time() - 7200) . "'");
		return ($asynk->recoverTasks(60) === 1 && $asynk->getTask($task['id'])['state'] === 'waiting');
	});
	check('xinetd: getTaskFromId() returns the task whatever its state; reserveTask() refuses a delayed or reserved task', function() use ($asynk, $db) {
		$db->exec('DELETE FROM Task');
		$asynk->createTask('AsynkTestTask', 'fail', []);
		$task = $asynk->getTaskFromId(1) ?? $asynk->getTaskFromId($db->queryOne('SELECT id FROM Task', 'id'));
		if (!$asynk->isTaskReady($task))
			return (false);
		$reserved = $asynk->reserveTask($task);
		if (($reserved['attempts'] ?? null) !== 1 || $reserved['status'] !== 'processing' || $asynk->reserveTask($task) !== null ||
		    $asynk->getNextTask() !== null)
			return (false);
		// the task is delayed: it is returned by getTaskFromId(), but it can't be reserved
		$asynk->invalidateFetchedTask($reserved, 'Failure');
		$delayed = $asynk->getTaskFromId($task['id']);
		return ($delayed['status'] === 'waiting' && !$asynk->isTaskReady($delayed) && $asynk->reserveTask($delayed) === null &&
		        $asynk->getTaskFromId(999999) === null && $asynk->getTaskFromId('abc') === null && $asynk->getTask('1 OR 1') === null &&
		        $asynk->retryTask('abc') === false && $asynk->removeTask('abc') === false);
	});
	check('recoverTasks(): a reserved but not started task is put back in the queue without counting an attempt', function() use ($asynk, $db) {
		$db->exec('DELETE FROM Task');
		$asynk->createTask('AsynkTestTask', 'ok', []);
		$id = $db->queryOne('SELECT id FROM Task', 'id');
		$db->exec("UPDATE Task SET status = 'reserved', token = 'abc', attempts = 2, dateUpdate = '" . date('Y-m-d H:i:s', time() - 7200) . "'");
		$task = $asynk->recoverTasks(60) === 1 ? $asynk->getTask($id) : null;
		return (($task['state'] ?? null) === 'waiting' && $task['dateNextAttempt'] === null && $asynk->getNextTask()['id'] === $id);
	});
	check('If the invalidation fails, the error is logged and executeTask() returns false (no exception)', function() {
		// table without the "error" field, with retries enabled
		$db = sqlDatabase(false);
		$db->exec('ALTER TABLE Task ADD attempts INTEGER NOT NULL DEFAULT 0');
		$db->exec('ALTER TABLE Task ADD dateNextAttempt TEXT');
		$asynk = asynkDao(['storage' => 'db', 'dao' => 'AsynkTestDao', 'retries' => [60]], ['db' => $db]);
		$asynk->createTask('AsynkTestTask', 'fail', []);
		return ($asynk->executeTask($asynk->getNextTask()) === false);
	});
	check('Invalid configurations throw a Framework exception', function() use ($db) {
		// a message queue can't be used with a storage
		$bean = \Temma\Datasources\Beanstalk::factory('beanstalk://127.0.0.1:1/tube');
		foreach ([[[], []], [['storage' => 'db', 'transport' => 'bean'], ['db' => $db, 'bean' => $bean]], [['storage' => 'unknown'], []],
		          [['storage' => 'db', 'retries' => 'yes'], ['db' => $db]]] as [$xtra, $dataSources]) {
			try {
				asynkDao($xtra, $dataSources);
				return (false);
			} catch (\Temma\Exceptions\Framework $e) {
			}
		}
		return (true);
	});
	check('Renamed fields (some or all of them)', function() {
		$db = \Temma\Datasources\Sql::factory('sqlite::memory:');
		$db->exec('CREATE TABLE asynk_tasks (task_id INTEGER PRIMARY KEY AUTOINCREMENT, dateCreation TEXT, dateUpdate TEXT,
				task_status TEXT NOT NULL DEFAULT \'waiting\', token TEXT, target TEXT NOT NULL, action TEXT NOT NULL,
				data TEXT, nb_attempts INTEGER NOT NULL DEFAULT 0, dateNextAttempt TEXT, error TEXT)');
		$asynk = asynkDao([
			'storage'  => 'db',
			'dao'      => 'AsynkTestDao',
			'table'    => 'asynk_tasks',
			'id'       => 'task_id',
			'status'   => 'task_status',
			'attempts' => 'nb_attempts',
			'retries'  => [60],
		], ['db' => $db]);
		$asynk->createTask('AsynkTestTask', 'fail', ['p']);
		$task = $asynk->getNextTask();
		$asynk->executeTask($task);
		$stored = $asynk->getTask($task['id']);
		$row = $db->queryOne('SELECT * FROM asynk_tasks');
		return ($task['id'] === 1 && $task['data'] === ['p'] && $stored['state'] === 'delayed' && $stored['attempts'] === 1 &&
		        $row['task_status'] === 'waiting' && $row['nb_attempts'] === 1);
	});
}

/* ********** REDIS STORAGE ********** */
$redisServer = trim((string)shell_exec('command -v redis-server 2>/dev/null'));
if (!extension_loaded('redis') || !$redisServer) {
	print(TµAnsi::color('yellow', 'The redis-server program or the phpredis extension is not available, Redis tests skipped.') . "\n");
} else {
	print("\n" . TµAnsi::bold("Redis storage\n"));
	// remove the directories left by previous executions of the script (killed processes)
	foreach (glob(sys_get_temp_dir() . '/temma-asynk-test-*') as $oldDir) {
		$pid = (int)substr($oldDir, strrpos($oldDir, '-') + 1);
		if ($pid && !file_exists("/proc/$pid"))
			shell_exec('rm -rf ' . escapeshellarg($oldDir));
	}
	// start a temporary Redis server, listening only on a Unix socket
	$tmpDir = sys_get_temp_dir() . '/temma-asynk-test-' . getmypid();
	mkdir($tmpDir);
	$socket = "$tmpDir/redis.sock";
	$process = proc_open([$redisServer, '--port', '0', '--unixsocket', $socket, '--save', '', '--appendonly', 'no', '--dir', $tmpDir],
	                     [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
	register_shutdown_function(function() use ($process, $tmpDir) {
		proc_terminate($process);
		proc_close($process);
		shell_exec('rm -rf ' . escapeshellarg($tmpDir));
	});
	// wait until the server answers
	for ($i = 0; $i < 250; $i++) {
		try {
			$ping = new \Redis();
			if (file_exists($socket) && $ping->connect($socket) && $ping->ping())
				break;
		} catch (\Exception $e) {
		}
		usleep(20000);
	}
	$redis = \Temma\Datasources\Redis::factory("redis-sock://$socket");
	$asynk = asynkDao(['storage' => 'ndb', 'retries' => ['0s', '1h']], ['ndb' => $redis]);
	/**
	 * Return the identifiers of a Redis list, the oldest first.
	 * @param	string	$list	List name.
	 * @return	array	The identifiers.
	 */
	function redisList(string $list) : array {
		global $redis;
		return (array_reverse($redis->lRange($list)));
	}
	/**
	 * Return the identifiers of the delayed tasks (sorted set).
	 * @return	array	The identifiers, sorted by date of next attempt.
	 */
	function redisDelayed() : array {
		global $redis;
		return ($redis->zRangeByScore('asynk_delayed', '-inf', '+inf'));
	}
	/**
	 * Return a task stored in Redis.
	 * @param	string	$taskId	Task identifier.
	 * @return	mixed	The task data.
	 */
	function redisTask(string $taskId) : mixed {
		global $redis;
		return ($redis->hGet('asynk_tasks', $taskId));
	}
	/**
	 * Remove all the Asynk data from Redis.
	 */
	function redisClear() : void {
		global $redis;
		foreach (['asynk', 'asynk_processing', 'asynk_delayed', 'asynk_failed', 'asynk_tasks', 'asynk_claims'] as $key)
			$redis->remove($key);
	}
	/**
	 * Reset the date of the next check of the delayed tasks.
	 * @param	\Temma\Asynk\AsynkDao	$asynk	Asynk DAO.
	 */
	function resetDelayedCheck(\Temma\Asynk\AsynkDao $asynk) : void {
		(new \ReflectionProperty($asynk, '_nextDelayedCheck'))->setValue($asynk, 0);
	}
	/**
	 * Move a delayed task to the past (its next attempt date is reached).
	 * @param	string	$taskId	Task identifier.
	 */
	function redisMakeDue(string $taskId) : void {
		global $redis;
		$task = redisTask($taskId);
		$task['dateNextAttempt'] = date('c', time() - 10);
		$redis->hSet('asynk_tasks', $taskId, $task);
		$redis->zAdd('asynk_delayed', time() - 10, $taskId);
	}
	check('Redis data source: list, sorted set and hash methods', function() use ($redis) {
		$added = [$redis->zAdd('temma_test_zset', 30, ['c']), $redis->zAdd('temma_test_zset', 10, ['a']), $redis->zAdd('temma_test_zset', 20, 'b'),
		          $redis->zAdd('temma_test_zset', 5, ['a'])];
		$all = $redis->zRangeByScore('temma_test_zset', '-inf', '+inf');
		$range = $redis->zRangeByScore('temma_test_zset', 6, 30, 1, 1);
		$card = $redis->zCard('temma_test_zset');
		$removed = [$redis->zRem('temma_test_zset', 'b'), $redis->zRem('temma_test_zset', 'b')];
		$redis->remove('temma_test_zset');
		if ($added !== [1, 1, 1, 0] || $all !== [['a'], 'b', ['c']] || $range !== [['c']] || $card !== 3 || $removed !== [1, 0])
			return (false);
		$hash = [$redis->hSet('temma_test_hash', 'a', [1]), $redis->hSet('temma_test_hash', 'a', [2]), $redis->hSetNx('temma_test_hash', 'a', 3),
		         $redis->hSetNx('temma_test_hash', 'b', 'x'), $redis->hGet('temma_test_hash', 'a'), $redis->hGet('temma_test_hash', 'z'),
		         $redis->hGetAll('temma_test_hash'), $redis->hDel('temma_test_hash', 'a'), $redis->hDel('temma_test_hash', 'a')];
		$redis->remove('temma_test_hash');
		$redis->lPush('temma_test_list', 'b');
		$redis->rPush('temma_test_list', 'c');
		$redis->lPush('temma_test_list', 'a');
		$list = $redis->lRange('temma_test_list');
		$redis->remove('temma_test_list');
		return ($hash === [1, 0, false, true, [2], null, ['a' => [2], 'b' => 'x'], 1, 0] && $list === ['a', 'b', 'c']);
	});
	check('A created task is stored in the hash with an identifier; a fetched task gets a start date and an attempt, and is removed after its execution', function() use ($asynk, $redis) {
		redisClear();
		AsynkTestTask::$calls = [];
		$asynk->createTask('AsynkTestTask', 'ok', [1, new \stdClass(), ['a' => []]]);
		$waiting = redisList('asynk');
		if (count($waiting) !== 1 || !preg_match('/^\d+\.\d{6}-\w+$/', $waiting[0]) || redisTask($waiting[0])['status'] !== 'waiting')
			return (false);
		$task = $asynk->getNextTask();
		if (($task['id'] ?? null) !== $waiting[0] || $task['attempts'] !== 1 || $task['status'] !== 'processing' || !isset($task['dateStart']) ||
		    redisList('asynk_processing') !== [$task['id']] || redisTask($task['id']) !== $task || !$redis->hGet('asynk_claims', $task['id']))
			return (false);
		if (!$asynk->executeTask($task))
			return (false);
		$asynk->removeFetchedTask($task);
		return (AsynkTestTask::$calls === [[1, [], ['a' => []]]] && redisTask($task['id']) === null && $redis->lLen('asynk_processing') === 0 &&
		        $redis->hGetAll('asynk_claims') === [] && $asynk->getStats() === ['waiting' => 0, 'delayed' => 0, 'processing' => 0, 'failed' => 0]);
	});
	check('A failed task is delayed, put back in the queue when its date is reached, then failed after the last attempt', function() use ($asynk, $redis) {
		redisClear();
		$asynk->createTask('AsynkTestTask', 'fail', ['x']);
		// first failure: new attempt immediately (delay 0)
		$task = $asynk->getNextTask();
		if ($asynk->executeTask($task) || $redis->lLen('asynk_processing') !== 0 || $redis->hGetAll('asynk_claims') !== [])
			return (false);
		$stored = redisTask($task['id']);
		if (redisDelayed() !== [$task['id']] || $stored['attempts'] !== 1 || $stored['error'] !== 'Exception: Temporary failure' ||
		    $stored['status'] !== 'waiting' || !isset($stored['dateNextAttempt']) || isset($stored['dateStart']))
			return (false);
		// the delayed task is put back in the queue
		resetDelayedCheck($asynk);
		$task = $asynk->getNextTask();
		if (($task['id'] ?? null) !== $stored['id'] || $task['attempts'] !== 2 || isset($task['dateNextAttempt']) || $redis->zCard('asynk_delayed') !== 0)
			return (false);
		// second failure: new attempt in 1 hour
		if ($asynk->invalidateFetchedTask($task, 'Second failure') !== 3600)
			return (false);
		resetDelayedCheck($asynk);
		if ($asynk->getNextTask() !== null || $asynk->getStats() !== ['waiting' => 0, 'delayed' => 1, 'processing' => 0, 'failed' => 0] ||
		    $asynk->getTask($task['id'])['state'] !== 'delayed')
			return (false);
		// third failure: failed task
		redisMakeDue($task['id']);
		resetDelayedCheck($asynk);
		$task = $asynk->getNextTask();
		if (!$task || $asynk->invalidateFetchedTask($task, 'Third failure') !== null)
			return (false);
		$stored = redisTask($task['id']);
		return (redisList('asynk_failed') === [$task['id']] && $stored['attempts'] === 3 && $stored['error'] === 'Third failure' &&
		        $stored['status'] === 'error' && isset($stored['dateFailure']) &&
		        $asynk->getStats() === ['waiting' => 0, 'delayed' => 0, 'processing' => 0, 'failed' => 1]);
	});
	check('Without retries, a failed task is put in the failed list', function() use ($redis) {
		redisClear();
		$asynk = asynkDao(['storage' => 'ndb'], ['ndb' => $redis]);
		$asynk->createTask('AsynkTestTask', 'fail', ['no retry']);
		$asynk->executeTask($asynk->getNextTask());
		$failed = redisList('asynk_failed');
		return (count($failed) === 1 && redisTask($failed[0])['data'] === ['no retry'] && redisTask($failed[0])['attempts'] === 1);
	});
	check('A task is processed only once, even if its identifier is several times in the queue', function() use ($asynk, $redis) {
		redisClear();
		AsynkTestTask::$calls = [];
		$asynk->createTask('AsynkTestTask', 'ok', ['once']);
		$taskId = redisList('asynk')[0];
		$redis->lPush('asynk', $taskId);
		$task = $asynk->getNextTask();
		// the second identifier is ignored (the task is reserved)
		$second = $asynk->getNextTask();
		$asynk->executeTask($task);
		$asynk->removeFetchedTask($task);
		// the identifier of a removed task is ignored
		$redis->lPush('asynk', $taskId);
		$third = $asynk->getNextTask();
		return ($second === null && $third === null && AsynkTestTask::$calls === [['once']] && $redis->lLen('asynk') === 0 &&
		        $redis->lLen('asynk_processing') === 0 && $redis->hGetAll('asynk_claims') === []);
	});
	check('listTasks() and getTask() (dates in the local format)', function() use ($asynk) {
		redisClear();
		$asynk->createTask('AsynkTestTask', 'fail', []);
		$asynk->createTask('AsynkTestTask', 'ok', ['w1']);
		$failing = $asynk->getNextTask()['id'];
		$asynk->invalidateFetchedTask($asynk->getTaskFromId($failing), 'Failure', false);
		$asynk->createTask('AsynkTestTask', 'ok', ['w2']);
		$all = $asynk->listTasks();
		$waiting = $asynk->listTasks('waiting', 1);
		$task = $asynk->getTask($failing);
		return (count($all) === 3 && $all[0]['data'] === ['w1'] && $all[0]['state'] === 'waiting' && $all[1]['data'] === ['w2'] &&
		        $all[2]['state'] === 'failed' && $waiting[0]['data'] === ['w1'] &&
		        $task['state'] === 'failed' && $task['attempts'] === 1 && $task['error'] === 'Failure' &&
		        preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $task['dateCreation']) &&
		        $asynk->getTask('1764150000.123456-unknown') === null && $asynk->getTask('bad id') === null);
	});
	check('retryTask() (failed and delayed tasks, processed first), retryFailedTasks(), removeTask() and purgeFailedTasks()', function() use ($asynk, $redis) {
		redisClear();
		for ($i = 0; $i < 3; $i++)
			$asynk->createTask('AsynkTestTask', 'fail', [$i]);
		for ($i = 0; $i < 3; $i++)
			$asynk->invalidateFetchedTask($asynk->getNextTask(), 'Failure', false);
		$asynk->createTask('AsynkTestTask', 'ok', ['other']);
		$failed = redisList('asynk_failed');
		// the retried task is put at the end of the queue (processed first)
		if (!$asynk->retryTask($failed[0]) || $asynk->retryTask($failed[0]) || $asynk->getNextTask()['id'] !== $failed[0])
			return (false);
		$requeued = redisTask($failed[0]);
		if ($requeued['attempts'] !== 1 || isset($requeued['error']) || $redis->lLen('asynk_failed') !== 2)
			return (false);
		$asynk->invalidateFetchedTask($requeued, 'Failure', false);
		if ($asynk->retryFailedTasks() !== 3 || $redis->lLen('asynk_failed') !== 0 || $redis->lLen('asynk') !== 4)
			return (false);
		if (!$asynk->removeTask($failed[1]) || $asynk->removeTask($failed[1]) || $redis->lLen('asynk') !== 3 || redisTask($failed[1]) !== null)
			return (false);
		// delayed task
		$task = $asynk->getNextTask();
		$asynk->invalidateFetchedTask(['attempts' => 2] + $task, 'Failure');
		if ($asynk->getTask($task['id'])['state'] !== 'delayed' || !$asynk->retryTask($task['id']) || $redis->zCard('asynk_delayed') !== 0 ||
		    $asynk->getTask($task['id'])['state'] !== 'waiting')
			return (false);
		// purge
		while (($task = $asynk->getNextTask()))
			$asynk->invalidateFetchedTask($task, 'Failure', false);
		return ($asynk->purgeFailedTasks() === 3 && $redis->hGetAll('asynk_tasks') === [] &&
		        $asynk->getStats() === ['waiting' => 0, 'delayed' => 0, 'processing' => 0, 'failed' => 0]);
	});
	check('Error messages with invalid UTF-8 characters are cleaned, and truncated; non-encodable parameters are refused', function() use ($asynk, $redis) {
		redisClear();
		$asynk->createTask('AsynkTestTask', 'fail', []);
		$asynk->createTask('AsynkTestTask', 'fail', []);
		$first = $asynk->getNextTask();
		$asynk->invalidateFetchedTask($first, "Bad \xff\xfe response", false);
		$second = $asynk->getNextTask();
		$asynk->invalidateFetchedTask($second, str_repeat('é', 6000), false);
		$error = redisTask($first['id'])['error'];
		$long = redisTask($second['id'])['error'];
		try {
			$asynk->createTask('AsynkTestTask', 'ok', ["\xff"]);
			return (false);
		} catch (\Temma\Exceptions\Application $e) {
		}
		return (str_starts_with($error, 'Bad ') && mb_check_encoding($error, 'UTF-8') &&
		        mb_check_encoding($long, 'UTF-8') && strlen($long) <= 10000 && strlen($long) >= 9998 && $redis->lLen('asynk') === 0);
	});
	check('recoverTasks(): an interrupted processing counts as an attempt (new attempt planned, or task put aside)', function() use ($asynk, $redis) {
		redisClear();
		foreach (['first' => [1, 7200], 'second' => [2, 7200], 'last' => [3, 7200], 'recent' => [1, 600]] as $name => [$attempts, $age]) {
			$taskId = sprintf('%.06f-%s', microtime(true), $name);
			$redis->hSet('asynk_tasks', $taskId, ['id' => $taskId, 'target' => 'AsynkTestTask', 'action' => 'ok', 'data' => [$name],
			                                      'status' => 'processing', 'attempts' => $attempts, 'dateStart' => date('c', time() - $age)]);
			$redis->lPush('asynk_processing', $taskId);
			$redis->hSet('asynk_claims', $taskId, time() - $age);
			$ids[$name] = $taskId;
		}
		if ($asynk->recoverTasks(60) !== 3 || $asynk->recoverTasks(60) !== 0)
			return (false);
		// "first" (delay 0) is put back in the queue, "second" is delayed (1 hour), "last" is failed
		return (redisList('asynk') === [$ids['first']] && redisDelayed() === [$ids['second']] && redisList('asynk_failed') === [$ids['last']] &&
		        redisList('asynk_processing') === [$ids['recent']] && redisTask($ids['second'])['error'] === \Temma\Asynk\AsynkDao::INTERRUPTED_ERROR &&
		        array_keys($redis->hGetAll('asynk_claims')) === [$ids['recent']] && $asynk->getNextTask()['id'] === $ids['first']);
	});
	check('Without retries, recoverTasks() puts back in the queue the tasks processed for a long time', function() use ($redis) {
		redisClear();
		$asynk = asynkDao(['storage' => 'ndb'], ['ndb' => $redis]);
		$asynk->createTask('AsynkTestTask', 'ok', []);
		$task = $asynk->getNextTask();
		$redis->hSet('asynk_tasks', $task['id'], ['dateStart' => date('c', time() - 7200), 'attempts' => 5] + $task);
		return ($asynk->recoverTasks(60) === 1 && redisList('asynk') === [$task['id']] && redisTask($task['id'])['status'] === 'waiting');
	});
	check('If the invalidation fails, the error is logged and executeTask() returns false (no exception)', function() use ($redis) {
		redisClear();
		$asynk = asynkDao(['storage' => 'ndb'], ['ndb' => $redis]);
		$asynk->createTask('AsynkTestTask', 'fail', []);
		$task = $asynk->getNextTask();
		$broken = \Temma\Datasources\Redis::factory("redis-sock:///nonexistent/redis.sock");
		(new \ReflectionProperty($asynk, '_redis'))->setValue($asynk, $broken);
		return ($asynk->executeTask($task) === false);
	});

	/* ********** REDIS STORAGE WITH XINETD ********** */
	print("\n" . TµAnsi::bold("Redis storage with xinetd transport\n"));
	// fake xinetd: Unix socket server which receives the task identifiers
	$xinetdPath = "$tmpDir/xinetd.sock";
	$xinetd = stream_socket_server("unix://$xinetdPath");
	/**
	 * Return the task identifiers received by the fake xinetd server.
	 * @return	array	List of identifiers.
	 */
	function xinetdIds() : array {
		global $xinetd;
		$ids = [];
		while (true) {
			$read = [$xinetd];
			$write = $except = null;
			if (!stream_select($read, $write, $except, 0, 200000))
				return ($ids);
			$conn = stream_socket_accept($xinetd);
			$ids[] = trim(stream_get_contents($conn));
			fclose($conn);
		}
	}
	$sock = \Temma\Datasources\Socket::factory("unix://$xinetdPath");
	$asynkXinetd = asynkDao(['storage' => 'ndb', 'transport' => 'sock', 'retries' => ['1h']], ['ndb' => $redis, 'sock' => $sock]);
	check('The identifier is sent to xinetd, which gets the task, reserves it and processes it', function() use ($asynkXinetd, $redis) {
		redisClear();
		$asynkXinetd->createTask('AsynkTestTask', 'ok', ['xinetd']);
		$ids = xinetdIds();
		$task = $asynkXinetd->getTaskFromId($ids[0] ?? '');
		if (count($ids) !== 1 || ($task['data'] ?? null) !== ['xinetd'] || !$asynkXinetd->isTaskReady($task))
			return (false);
		$reserved = $asynkXinetd->reserveTask($task);
		$again = $asynkXinetd->reserveTask($task);
		// the identifier stays in the waiting list during the processing; a worker ignores it
		$fromWorker = $asynkXinetd->getNextTask();
		if ($reserved['attempts'] !== 1 || $again !== null || $fromWorker !== null || !$asynkXinetd->executeTask($reserved))
			return (false);
		$asynkXinetd->removeFetchedTask($reserved);
		return ($redis->lLen('asynk') === 0 && $redis->lLen('asynk_processing') === 0 && $redis->hGetAll('asynk_tasks') === [] &&
		        $redis->hGetAll('asynk_claims') === [] && $asynkXinetd->getTaskFromId('bad id') === null);
	});
	check('The crontab processes the tasks not processed by xinetd, and the delayed tasks; xinetd ignores a delayed task', function() use ($asynkXinetd, $redis) {
		redisClear();
		$asynkXinetd->createTask('AsynkTestTask', 'fail', []);
		$id = xinetdIds()[0];
		// xinetd didn't process the task: the crontab fetches it, then xinetd can't reserve it
		$task = $asynkXinetd->getNextTask();
		if (($task['id'] ?? null) !== $id || $asynkXinetd->reserveTask($asynkXinetd->getTaskFromId($id)) !== null)
			return (false);
		// failure: new attempt in 1 hour; xinetd ignores the delayed task
		$asynkXinetd->executeTask($task);
		if ($asynkXinetd->isTaskReady($asynkXinetd->getTaskFromId($id)) || $asynkXinetd->reserveTask($asynkXinetd->getTaskFromId($id)) !== null)
			return (false);
		// the crontab processes the delayed task when its date is reached
		redisMakeDue($id);
		resetDelayedCheck($asynkXinetd);
		$task = $asynkXinetd->getNextTask();
		$asynkXinetd->invalidateFetchedTask($task, 'Failure');
		$stats = $asynkXinetd->getStats();
		// retryTask() notifies xinetd
		$retried = $asynkXinetd->retryTask($id);
		$ids = xinetdIds();
		return (($task['id'] ?? null) === $id && $task['attempts'] === 2 && $stats === ['waiting' => 0, 'delayed' => 0, 'processing' => 0, 'failed' => 1] &&
		        $retried && $ids === [$id]);
	});
	check('retryTask() succeeds even if xinetd is not reachable (the task will be processed by the crontab)', function() use ($redis) {
		redisClear();
		$sock = \Temma\Datasources\Socket::factory('unix:///nonexistent/xinetd.sock');
		$asynk = asynkDao(['storage' => 'ndb', 'transport' => 'sock'], ['ndb' => $redis, 'sock' => $sock]);
		try {
			$asynk->createTask('AsynkTestTask', 'fail', []);
			return (false);
		} catch (\Exception $e) {
			// the notification of a new task fails
		}
		$asynk->executeTask($asynk->getNextTask());
		return ($asynk->retryTask(redisList('asynk_failed')[0]) === true && $redis->lLen('asynk') === 1);
	});

	/* ********** COMMANDS ********** */
	print("\n" . TµAnsi::bold("Asynk xinetd script, worker and AsynkManager command (bin/comma)\n"));
	$commandConfig = [
		'application' => [
			'dataSources' => [
				'ndb'  => "redis-sock://$socket",
				'sock' => "unix://$xinetdPath",
			],
		],
		'loglevels' => 'ERROR',
		'x-asynk'   => [
			'storage' => 'ndb',
			'retries' => ['1h'],
		],
	];
	file_put_contents("$tmpDir/temma.php", '<?php return ' . var_export($commandConfig, true) . ';');
	$commandConfig['x-asynk']['transport'] = 'sock';
	file_put_contents("$tmpDir/temma-xinetd.php", '<?php return ' . var_export($commandConfig, true) . ';');
	mkdir("$tmpDir/inc");
	file_put_contents("$tmpDir/inc/AsynkCommandTask.php", '<?php
		class AsynkCommandTask {
			public function write(string $text) { file_put_contents(' . var_export("$tmpDir/output.txt", true) . ', $text, FILE_APPEND); }
			public function fail() { throw new \Exception("Command failure \e]0;title\x07 \e[1A"); }
		}
	');
	/**
	 * Execute a command with bin/comma.
	 * @param	string	...$args	Arguments.
	 * @return	string	The output of the command, without ANSI color sequences.
	 */
	function comma(string ...$args) : string {
		global $tmpDir;
		$command = escapeshellarg(__DIR__ . '/../bin/comma') . ' nostderr ' . escapeshellarg("conf=$tmpDir/temma.php") . ' ' .
		           escapeshellarg("inc=$tmpDir/inc") . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
		return (preg_replace('/\e\[[0-9;]*m/', '', (string)shell_exec($command)));
	}
	/**
	 * Execute the xinetd script with bin/comma.
	 * @param	string	$input	Data sent on the standard input.
	 * @return	int	The exit code.
	 */
	function inetd(string $input) : int {
		global $tmpDir;
		$command = escapeshellarg(__DIR__ . '/../bin/comma') . ' nostderr ' . escapeshellarg("conf=$tmpDir/temma-xinetd.php") . ' ' .
		           escapeshellarg("inc=$tmpDir/inc") . " 'Asynk\Inetd' > /dev/null 2>&1";
		$process = proc_open($command, [['pipe', 'r']], $pipes);
		fwrite($pipes[0], $input);
		fclose($pipes[0]);
		return (proc_close($process));
	}
	check('The xinetd script processes a waiting task, and ignores unknown, invalid or delayed tasks', function() use ($asynkXinetd, $tmpDir) {
		redisClear();
		@unlink("$tmpDir/output.txt");
		$asynkXinetd->createTask('AsynkCommandTask', 'write', ['xinetd']);
		$id = xinetdIds()[0];
		$codes = [inetd("$id\r\n"), inetd("$id\n"), inetd("1764150000.123456-unknown\n"), inetd("bad id\n"), inetd('')];
		// delayed task
		$asynkXinetd->createTask('AsynkCommandTask', 'fail', []);
		$failId = xinetdIds()[0];
		$codes[] = inetd($failId);
		$codes[] = inetd($failId);
		return ($codes === [0, 0, 0, 0, 0, 1, 0] && file_get_contents("$tmpDir/output.txt") === 'xinetd' &&
		        $asynkXinetd->getTask($failId)['state'] === 'delayed' && $asynkXinetd->getTask($failId)['attempts'] === 1 &&
		        $asynkXinetd->getStats() === ['waiting' => 0, 'delayed' => 1, 'processing' => 0, 'failed' => 0]);
	});
	check('AsynkManager/stats and AsynkManager/list', function() use ($asynk, $tmpDir) {
		redisClear();
		@unlink("$tmpDir/output.txt");
		$asynk->createTask('AsynkCommandTask', 'write', ['first']);
		$asynk->createTask('AsynkCommandTask', 'fail', []);
		$asynk->createTask('AsynkCommandTask', 'write', ['second']);
		$stats = comma('AsynkManager/stats');
		$list = comma('AsynkManager/list');
		return (preg_match('/waiting\s+3\n/', $stats) && preg_match('/failed\s+0\n/', $stats) &&
		        substr_count($list, 'AsynkCommandTask::') === 3 && str_contains($list, ' waiting '));
	});
	check('The worker (crontab mode) processes the tasks; the failed task is delayed', function() use ($tmpDir) {
		comma('Asynk\Worker/crontab');
		$stats = comma('AsynkManager/stats');
		$list = comma('AsynkManager/list', 'delayed');
		return (file_get_contents("$tmpDir/output.txt") === 'firstsecond' && preg_match('/delayed\s+1\n/', $stats) &&
		        str_contains($list, 'AsynkCommandTask::fail() attempts: 1 next attempt: '));
	});
	check('AsynkManager/show (without terminal control sequences), retry, remove, purge and recover', function() use ($asynk) {
		$id = $asynk->listTasks('delayed')[0]['id'];
		$show = comma('AsynkManager/show', $id);
		if (!str_contains($show, "Identifier        $id") || !str_contains($show, 'Last error        Exception: Command failure  ') ||
		    str_contains($show, "\e") || str_contains($show, "\x07") || !str_contains($show, 'Next attempt') || !str_contains($show, 'Attempts          1'))
			return (false);
		if (comma('AsynkManager/retry', $id) !== "Task put back in the queue.\n" || comma('AsynkManager/retry', $id) !== "Task '$id' not found, or not failed nor delayed.\n")
			return (false);
		if (comma('AsynkManager/remove', $id) !== "Task removed.\n" || comma('AsynkManager/show', $id) !== "Unknown task '$id'.\n" ||
		    comma('AsynkManager/show', "bad\e[2J\x07id") !== "Unknown task 'bad?id'.\n")
			return (false);
		$asynk->createTask('AsynkCommandTask', 'fail', []);
		$asynk->invalidateFetchedTask($asynk->getNextTask(), 'Failure', false);
		return (comma('AsynkManager/retry', 'all') === "1 task(s) put back in the queue.\n" &&
		        comma('AsynkManager/purge') === "0 failed task(s) removed.\n" &&
		        comma('AsynkManager/recover') === "0 task(s) recovered.\n" &&
		        comma('AsynkManager/list', 'unknown') === "Unknown task state 'unknown'.\n");
	});
}

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
