#!/usr/bin/php
<?php

/**
 * Validation script for Asynk:
 * - parsing of the "retries" configuration;
 * - MySQL storage (tested with an SQLite database in memory): task processing, new attempts after a failure (not for PHP
 *   errors), failed tasks, management of the tasks (stats, list, show, retry, remove, purge, recover), renamed fields,
 *   table without the retry fields;
 * - Redis queue (tested with a temporary Redis server, if the redis-server program and the phpredis extension are
 *   available): same tests, delayed and failed lists, removal of tasks with empty objects in their parameters;
 * - AsynkManager command and Asynk worker, executed with bin/comma (Redis queue).
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
	check('recoverTasks() puts back in the queue the tasks processed for a long time', function() use ($asynk, $db) {
		$db->exec('DELETE FROM Task');
		$asynk->createTask('AsynkTestTask', 'ok', [1]);
		$asynk->createTask('AsynkTestTask', 'ok', [2]);
		$first = $asynk->getNextTask();
		$second = $asynk->getNextTask();
		// SQLite doesn't update the dateUpdate field automatically
		$db->exec("UPDATE Task SET dateUpdate = '" . date('Y-m-d H:i:s', time() - 7200) . "' WHERE id = " . $first['id']);
		$db->exec("UPDATE Task SET dateUpdate = '" . date('Y-m-d H:i:s', time() - 600) . "' WHERE id = " . $second['id']);
		if ($asynk->listTasks('processing')[0]['dateStart'] === null)
			return (false);
		return ($asynk->recoverTasks(60) === 1 && $asynk->getTask($first['id'])['state'] === 'waiting' &&
		        $asynk->getTask($second['id'])['state'] === 'processing' && $asynk->recoverTasks(5) === 1);
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
		return ($stored['state'] === 'failed' && $stored['attempts'] === null && $stored['error'] === null &&
		        $asynk->getStats() === ['waiting' => 0, 'delayed' => 0, 'processing' => 0, 'failed' => 1] &&
		        $asynk->listTasks('delayed') === [] && $asynk->retryFailedTasks() === 1 && $asynk->getNextTask()['id'] === $task['id']);
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

/* ********** REDIS QUEUE ********** */
$redisServer = trim((string)shell_exec('command -v redis-server 2>/dev/null'));
if (!extension_loaded('redis') || !$redisServer) {
	print(TµAnsi::color('yellow', 'The redis-server program or the phpredis extension is not available, Redis tests skipped.') . "\n");
} else {
	print("\n" . TµAnsi::bold("Redis queue\n"));
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
	for ($i = 0; $i < 100 && !file_exists($socket); $i++)
		usleep(20000);
	usleep(50000);
	$redis = \Temma\Datasources\Redis::factory("redis-sock://$socket");
	$asynk = asynkDao(['storage' => 'ndb', 'retries' => ['0s', '1h']], ['ndb' => $redis]);
	/**
	 * Return the content of a Redis list, the oldest elements first.
	 * @param	string	$list	List name.
	 * @return	array	The elements.
	 */
	function redisList(string $list) : array {
		global $redis;
		return (array_reverse($redis->lRange($list)));
	}
	/**
	 * Reset the date of the next check of the delayed tasks.
	 * @param	\Temma\Asynk\AsynkDao	$asynk	Asynk DAO.
	 */
	function resetDelayedCheck(\Temma\Asynk\AsynkDao $asynk) : void {
		(new \ReflectionProperty($asynk, '_nextDelayedCheck'))->setValue($asynk, 0);
	}
	check('Created tasks have an identifier; a fetched task gets a processing start date, and is removed after its execution', function() use ($asynk, $redis) {
		AsynkTestTask::$calls = [];
		$asynk->createTask('AsynkTestTask', 'ok', [1, new \stdClass(), ['a' => []]]);
		$waiting = redisList('asynk');
		if (count($waiting) !== 1 || !preg_match('/^\d+\.\d{6}-\w+$/', $waiting[0]['id']))
			return (false);
		$task = $asynk->getNextTask();
		$processing = redisList('asynk_processing');
		if (count($processing) !== 1 || $processing[0] !== $task || !isset($task['dateStart']) || $task['id'] !== $waiting[0]['id'])
			return (false);
		// the task contains an empty object, but it can be removed from the list
		if (!$asynk->executeTask($task))
			return (false);
		$asynk->removeFetchedTask($task);
		return (AsynkTestTask::$calls === [[1, [], ['a' => []]]] && $redis->lLen('asynk_processing') === 0 &&
		        $asynk->getStats() === ['waiting' => 0, 'delayed' => 0, 'processing' => 0, 'failed' => 0]);
	});
	check('A failed task is moved to the delayed list, put back in the queue when its date is reached, then moved to the failed list', function() use ($asynk, $redis) {
		$asynk->createTask('AsynkTestTask', 'fail', ['x']);
		// first failure: new attempt immediately (delay 0)
		$task = $asynk->getNextTask();
		if ($asynk->executeTask($task) || $redis->lLen('asynk_processing') !== 0)
			return (false);
		$delayed = redisList('asynk_delayed');
		if (count($delayed) !== 1 || $delayed[0]['attempts'] !== 1 || $delayed[0]['error'] !== 'Exception: Temporary failure' ||
		    !isset($delayed[0]['dateNextAttempt']) || isset($delayed[0]['dateStart']))
			return (false);
		// the delayed task is put back in the queue
		resetDelayedCheck($asynk);
		$task = $asynk->getNextTask();
		if (($task['id'] ?? null) !== $delayed[0]['id'] || $redis->lLen('asynk_delayed') !== 0)
			return (false);
		// second failure: new attempt in 1 hour
		if ($asynk->invalidateFetchedTask($task, 'Second failure') !== 3600)
			return (false);
		resetDelayedCheck($asynk);
		if ($asynk->getNextTask() !== null || $asynk->getStats() !== ['waiting' => 0, 'delayed' => 1, 'processing' => 0, 'failed' => 0])
			return (false);
		// third failure: failed task
		$redis->lPush('asynk_delayed', array_merge(redisList('asynk_delayed')[0], ['dateNextAttempt' => date('c', time() - 10)]));
		$redis->rPop('asynk_delayed');
		resetDelayedCheck($asynk);
		$task = $asynk->getNextTask();
		if (!$task || $asynk->invalidateFetchedTask($task, 'Third failure') !== null)
			return (false);
		$failed = redisList('asynk_failed');
		return (count($failed) === 1 && $failed[0]['attempts'] === 3 && $failed[0]['error'] === 'Third failure' &&
		        $failed[0]['status'] === 'error' && isset($failed[0]['dateFailure']) &&
		        $asynk->getStats() === ['waiting' => 0, 'delayed' => 0, 'processing' => 0, 'failed' => 1]);
	});
	check('Without retries, a failed task is moved to the failed list', function() use ($redis) {
		$asynk = asynkDao(['storage' => 'ndb'], ['ndb' => $redis]);
		$asynk->createTask('AsynkTestTask', 'fail', ['no retry']);
		$asynk->executeTask($asynk->getNextTask());
		$failed = redisList('asynk_failed');
		return (count($failed) === 2 && $failed[1]['data'] === ['no retry'] && $failed[1]['attempts'] === 1);
	});
	check('listTasks() and getTask()', function() use ($asynk) {
		$asynk->createTask('AsynkTestTask', 'ok', ['w1']);
		$asynk->createTask('AsynkTestTask', 'ok', ['w2']);
		$all = $asynk->listTasks();
		$waiting = $asynk->listTasks('waiting', 1);
		$task = $asynk->getTask($all[2]['id']);
		return (count($all) === 4 && $all[0]['data'] === ['w1'] && $all[0]['state'] === 'waiting' && $all[2]['state'] === 'failed' &&
		        count($waiting) === 1 && $waiting[0]['data'] === ['w1'] &&
		        $task['state'] === 'failed' && $task['attempts'] === 3 && $task['error'] === 'Third failure' &&
		        $asynk->getTask('unknown') === null);
	});
	check('retryTask(), retryFailedTasks(), removeTask() and purgeFailedTasks()', function() use ($asynk, $redis) {
		$failed = $asynk->listTasks('failed');
		if (!$asynk->retryTask($failed[0]['id']) || $asynk->retryTask($failed[0]['id']))
			return (false);
		$requeued = redisList('asynk')[2];
		if ($requeued['id'] !== $failed[0]['id'] || $requeued['attempts'] !== 0 || isset($requeued['error']) || $requeued['status'] !== 'waiting')
			return (false);
		if ($asynk->retryFailedTasks() !== 1 || $redis->lLen('asynk_failed') !== 0 || $redis->lLen('asynk') !== 4)
			return (false);
		if (!$asynk->removeTask($requeued['id']) || $asynk->removeTask($requeued['id']) || $redis->lLen('asynk') !== 3)
			return (false);
		// purge
		while (($task = $asynk->getNextTask()))
			$asynk->invalidateFetchedTask($task, 'Failure', false);
		return ($asynk->purgeFailedTasks() === 3 && $asynk->getStats() === ['waiting' => 0, 'delayed' => 0, 'processing' => 0, 'failed' => 0]);
	});
	check('recoverTasks() puts back in the queue the tasks processed for a long time, and those without start date', function() use ($asynk, $redis) {
		$redis->lPush('asynk_processing', ['id' => 'old', 'target' => 'AsynkTestTask', 'action' => 'ok', 'data' => []]);
		$redis->lPush('asynk_processing', ['id' => 'long', 'target' => 'AsynkTestTask', 'action' => 'ok', 'data' => [], 'dateStart' => date('c', time() - 7200)]);
		$redis->lPush('asynk_processing', ['id' => 'recent', 'target' => 'AsynkTestTask', 'action' => 'ok', 'data' => [], 'dateStart' => date('c', time() - 600)]);
		if ($asynk->recoverTasks(60) !== 2)
			return (false);
		$waiting = redisList('asynk');
		$processing = redisList('asynk_processing');
		$redis->remove('asynk');
		$redis->remove('asynk_processing');
		return (array_column($waiting, 'id') === ['old', 'long'] && !isset($waiting[1]['dateStart']) &&
		        array_column($processing, 'id') === ['recent']);
	});

	/* ********** COMMANDS ********** */
	print("\n" . TµAnsi::bold("AsynkManager command and Asynk worker (bin/comma)\n"));
	file_put_contents("$tmpDir/temma.php", '<?php return ' . var_export([
		'application' => [
			'dataSources' => [
				'ndb' => "redis-sock://$socket",
			],
		],
		'loglevels' => 'ERROR',
		'x-asynk'   => [
			'storage' => 'ndb',
			'retries' => ['1h'],
		],
	], true) . ';');
	mkdir("$tmpDir/inc");
	file_put_contents("$tmpDir/inc/AsynkCommandTask.php", '<?php
		class AsynkCommandTask {
			public function write(string $text) { file_put_contents(' . var_export("$tmpDir/output.txt", true) . ', $text, FILE_APPEND); }
			public function fail() { throw new \Exception("Command failure"); }
		}
	');
	/**
	 * Execute a command with bin/comma.
	 * @param	string	...$args	Arguments.
	 * @return	string	The output of the command, without ANSI escape sequences.
	 */
	function comma(string ...$args) : string {
		global $tmpDir;
		$command = escapeshellarg(__DIR__ . '/../bin/comma') . ' nostderr ' . escapeshellarg("conf=$tmpDir/temma.php") . ' ' .
		           escapeshellarg("inc=$tmpDir/inc") . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
		return (preg_replace('/\e\[[0-9;]*m/', '', (string)shell_exec($command)));
	}
	$asynk->createTask('AsynkCommandTask', 'write', ['first']);
	$asynk->createTask('AsynkCommandTask', 'fail', []);
	$asynk->createTask('AsynkCommandTask', 'write', ['second']);
	check('AsynkManager/stats and AsynkManager/list', function() {
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
	check('AsynkManager/show, retry, remove and purge', function() use ($asynk) {
		$id = $asynk->listTasks('delayed')[0]['id'];
		$show = comma('AsynkManager/show', $id);
		if (!str_contains($show, "Identifier        $id") || !str_contains($show, 'Last error        Exception: Command failure') ||
		    !str_contains($show, 'Next attempt'))
			return (false);
		if (comma('AsynkManager/retry', $id) !== "Task put back in the queue.\n" || comma('AsynkManager/retry', $id) !== "Task '$id' not found, or not failed nor delayed.\n")
			return (false);
		if (comma('AsynkManager/remove', $id) !== "Task removed.\n" || comma('AsynkManager/show', $id) !== "Unknown task '$id'.\n")
			return (false);
		$asynk->createTask('AsynkCommandTask', 'fail', []);
		$asynk->invalidateFetchedTask($asynk->getNextTask(), 'Failure', false);
		return (comma('AsynkManager/retry', 'all') === "1 task(s) put back in the queue.\n" &&
		        comma('AsynkManager/purge') === "0 failed task(s) removed.\n" &&
		        comma('AsynkManager/recover') === "0 task(s) put back in the queue.\n" &&
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
