#!/usr/bin/php
<?php

/**
 * Validation script for the Beanstalk data source:
 * - DSN parsing (optional port number);
 * - Pheanstalk 5 API (tube names, job identifiers, tube statistics);
 * - "falsy" payloads (0, "0", false, "") are decoded by get();
 * - set() with a null value removes the job;
 * - mWrite() ignores null values (no job is removed) and rejects values which are not strings or numbers;
 * - read() returns null when no job is available (with a limited wait duration), and only reserves jobs of the
 *   configured tube;
 * - without wait duration, read() and get() wait until a job is available, beyond the duration of a single
 *   reservation request;
 * - bury() puts aside a reserved job, kick() puts buried jobs back in the queue;
 * - Asynk buries the job of a failed task.
 *
 * The tests using the Pheanstalk library are executed only if it is available (Composer "vendor" directory of
 * the project, or autoloader given in the TEMMA_TEST_AUTOLOAD environment variable). They use a fake Beanstalkd
 * server created by the script (with the pcntl and posix extensions; it stops when the script ends), or a real server
 * if its DSN is given in the TEMMA_TEST_BEANSTALK environment variable (e.g. "beanstalk://localhost:11300/temma-test";
 * the tube must be empty).
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');
\Temma\Base\Log::disable();
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
		$error = get_class($t) . ': ' . $t->getMessage();
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
 * Read the value of a private property.
 * @param	object	$object	The object.
 * @param	string	$name	Name of the property.
 * @return	mixed	The property's value.
 */
function property(object $object, string $name) : mixed {
	return ((new \ReflectionProperty($object, $name))->getValue($object));
}
/**
 * Reserve a ready job of the tubes watched by a client of the fake Beanstalkd server.
 * @param	array	$jobs	List of jobs (modified if a job is reserved).
 * @param	array	$client	Client connection.
 * @return	?string	The "RESERVED" response, or null if no job is available.
 */
function reserveJob(array &$jobs, array $client) : ?string {
	foreach ($jobs as $id => &$job) {
		if ($job['ready'] && isset($client['watched'][$job['tube']])) {
			$job['ready'] = false;
			return ("RESERVED $id " . mb_strlen($job['data'], 'ascii') . "\r\n" . $job['data'] . "\r\n");
		}
	}
	return (null);
}
/**
 * Fake Beanstalkd server, handling the commands used by the data source.
 * Runs until the process is killed, or until its parent process ends.
 * The "reserve-with-timeout" command waits for a job, with an accelerated time (each second of timeout lasts
 * 50 milliseconds). The "reserve" command (without timeout) is not supported.
 * @param	resource	$server		Server socket.
 * @param	int		$parentPid	PID of the parent process.
 */
function fakeServer($server, int $parentPid) : void {
	$jobs = [];
	$tubes = ['default' => true];
	$nextId = 1;
	$connections = [];
	while (true) {
		// quit if the parent process has ended
		if (posix_getppid() !== $parentPid)
			return;
		// answer the pending reservations (job available or timeout reached)
		foreach ($connections as &$client) {
			if (!isset($client['deadline']))
				continue;
			$response = reserveJob($jobs, $client);
			if ($response === null && microtime(true) < $client['deadline'])
				continue;
			unset($client['deadline']);
			fwrite($client['stream'], ($response ?? "TIMED_OUT\r\n"));
		}
		unset($client);
		// wait for a new connection or a command
		$read = array_column($connections, 'stream');
		$read[] = $server;
		$write = $except = null;
		if (!stream_select($read, $write, $except, 0, 20000))
			continue;
		foreach ($read as $stream) {
			if ($stream === $server) {
				$conn = stream_socket_accept($server);
				$connections[(int)$conn] = ['stream' => $conn, 'used' => 'default', 'watched' => ['default' => true]];
				continue;
			}
			$client = &$connections[(int)$stream];
			if (($line = fgets($stream)) === false) {
				fclose($stream);
				unset($connections[(int)$stream]);
				continue;
			}
			$parts = explode(' ', rtrim($line, "\r\n"));
			$response = "UNKNOWN_COMMAND\r\n";
			switch ($parts[0]) {
				case 'use':
					$client['used'] = $parts[1];
					$tubes[$parts[1]] = true;
					$response = "USING {$parts[1]}\r\n";
					break;
				case 'watch':
					$client['watched'][$parts[1]] = true;
					$tubes[$parts[1]] = true;
					$response = 'WATCHING ' . count($client['watched']) . "\r\n";
					break;
				case 'ignore':
					if ((count($client['watched']) == 1) && isset($client['watched'][$parts[1]])) {
						$response = "NOT_IGNORED\r\n";
						break;
					}
					unset($client['watched'][$parts[1]]);
					$response = 'WATCHING ' . count($client['watched']) . "\r\n";
					break;
				case 'put':
					$data = mb_substr(stream_get_contents($stream, (int)$parts[4] + 2), 0, -2, 'ascii');
					$jobs[$nextId] = ['tube' => $client['used'], 'data' => $data, 'ready' => true, 'buried' => false];
					$response = "INSERTED $nextId\r\n";
					$nextId++;
					break;
				case 'reserve-with-timeout':
					if (!ctype_digit($parts[1] ?? '') || ((int)$parts[1] > 20)) {
						$response = "BAD_FORMAT\r\n";
						break;
					}
					$response = reserveJob($jobs, $client);
					if ($response === null && (int)$parts[1] > 0) {
						// no job available: the response is sent when a job is available, or when the timeout is reached
						$client['deadline'] = microtime(true) + (int)$parts[1] * 0.05;
						unset($client);
						continue 2;
					}
					$response ??= "TIMED_OUT\r\n";
					break;
				case 'delete':
					$response = isset($jobs[$parts[1]]) ? "DELETED\r\n" : "NOT_FOUND\r\n";
					unset($jobs[$parts[1]]);
					break;
				case 'touch':
					$response = isset($jobs[$parts[1]]) ? "TOUCHED\r\n" : "NOT_FOUND\r\n";
					break;
				case 'bury':
					// only a reserved job can be buried
					if (!isset($jobs[$parts[1]]) || $jobs[$parts[1]]['ready'] || $jobs[$parts[1]]['buried']) {
						$response = "NOT_FOUND\r\n";
						break;
					}
					$jobs[$parts[1]]['buried'] = true;
					$response = "BURIED\r\n";
					break;
				case 'kick':
					$kicked = 0;
					foreach ($jobs as &$job) {
						if ($kicked < (int)$parts[1] && $job['buried'] && $job['tube'] == $client['used']) {
							$job['buried'] = false;
							$job['ready'] = true;
							$kicked++;
						}
					}
					unset($job);
					$response = "KICKED $kicked\r\n";
					break;
				case 'kick-job':
					if (!($jobs[$parts[1]]['buried'] ?? false)) {
						$response = "NOT_FOUND\r\n";
						break;
					}
					$jobs[$parts[1]]['buried'] = false;
					$jobs[$parts[1]]['ready'] = true;
					$response = "KICKED\r\n";
					break;
				case 'stats-tube':
					if (!isset($tubes[$parts[1]])) {
						$response = "NOT_FOUND\r\n";
						break;
					}
					$ready = count(array_filter($jobs, fn($job) => ($job['tube'] == $parts[1] && $job['ready'])));
					$buried = count(array_filter($jobs, fn($job) => ($job['tube'] == $parts[1] && $job['buried'])));
					$stats = "---\nname: {$parts[1]}\ncurrent-jobs-urgent: 0\ncurrent-jobs-ready: $ready\ncurrent-jobs-reserved: 0\n" .
					         "current-jobs-delayed: 0\ncurrent-jobs-buried: $buried\ntotal-jobs: 0\ncurrent-using: 0\ncurrent-waiting: 0\n" .
					         "current-watching: 0\npause: 0\ncmd-delete: 0\ncmd-pause-tube: 0\npause-time-left: 0\n";
					$response = 'OK ' . mb_strlen($stats, 'ascii') . "\r\n$stats\r\n";
					break;
			}
			unset($client);
			fwrite($stream, $response);
		}
	}
}

/* ********** TESTS WITHOUT SERVER ********** */
print(TµAnsi::bold("Beanstalk data source, DSN parsing\n"));
check('The port number is optional', function() {
	$bean = \Temma\Datasources\Beanstalk::factory('beanstalk://localhost/tube');
	$bean2 = \Temma\Datasources\Beanstalk::factory('beanstalk://localhost:1234/tube');
	return ((property($bean, '_port') === 11300) && (property($bean, '_tube') === 'tube') &&
	        (property($bean2, '_port') === 1234) && (property($bean2, '_host') === 'localhost'));
});
check('Invalid DSNs throw a Database exception', function() {
	foreach (['beanstalk://localhost', 'beanstalk://localhost:abc/tube', 'beanstalk://localhost/'] as $dsn) {
		try {
			\Temma\Datasources\Beanstalk::factory($dsn);
			return (false);
		} catch (\Temma\Exceptions\Database $e) {
		}
	}
	return (true);
});

check('A disabled data source sends and receives nothing, without connection', function() {
	$bean = \Temma\Datasources\Beanstalk::factory('beanstalk://127.0.0.1:1/tube')->disable();
	return ($bean->read('') === null && $bean->get('') === null && $bean->write('', 'x') === null &&
	        $bean->set('', 'x') === null && $bean->mWrite(['x']) === 0 && $bean->mSet(['x']) === 0 &&
	        $bean->count() === 0 && $bean->bury('1') === $bean && $bean->kick() === 0 && $bean->kick('1') === 0 &&
	        $bean->copyFrom('', sys_get_temp_dir() . '/temma-beanstalk-test-' . getmypid()) === false &&
	        !file_exists(sys_get_temp_dir() . '/temma-beanstalk-test-' . getmypid()));
});

/* ********** TESTS WITH SERVER ********** */
$autoload = getenv('TEMMA_TEST_AUTOLOAD') ?: (__DIR__ . '/../vendor/autoload.php');
if (file_exists($autoload))
	require_once($autoload);
$dsn = getenv('TEMMA_TEST_BEANSTALK');
$fakePid = null;
if (!class_exists('\Pheanstalk\Pheanstalk')) {
	print("\n" . TµAnsi::color('yellow', 'Pheanstalk library not available, tests with a server skipped.') . "\n");
} else if (!$dsn && (!function_exists('pcntl_fork') || !function_exists('posix_getppid'))) {
	print("\n" . TµAnsi::color('yellow', 'The pcntl or posix extension is not loaded and TEMMA_TEST_BEANSTALK is not defined, tests with a server skipped.') . "\n");
} else {
	$fake = !$dsn;
	if ($fake) {
		// fake server, in a child process
		$server = stream_socket_server('tcp://127.0.0.1:0');
		$port = (int)explode(':', stream_socket_get_name($server, false))[1];
		$parentPid = getmypid();
		$fakePid = pcntl_fork();
		if (!$fakePid) {
			fakeServer($server, $parentPid);
			exit(0);
		}
		fclose($server);
		$dsn = "beanstalk://127.0.0.1:$port/temma-test";
	}
	print("\n" . TµAnsi::bold("Beanstalk data source ($dsn" . ($fake ? ', fake server' : '') . ")\n"));
	try {
		$bean = \Temma\Datasources\Beanstalk::factory($dsn);
		check('write() and read() send and receive a raw message', function() use ($bean) {
			$id = $bean->write('', 'raw message');
			$message = $bean->read('');
			$bean->remove($message['id']);
			return (is_string($id) && ($message === ['id' => $id, 'data' => 'raw message']));
		});
		check('set() and get() send and receive falsy values', function() use ($bean) {
			foreach ([0, '0', false, '', []] as $value) {
				$bean->set('', $value);
				$message = $bean->get('');
				$bean->remove($message['id']);
				if ($message['data'] !== $value)
					return (false);
			}
			return (true);
		});
		check('read() and get() return null when no job is available', function() use ($bean) {
			return (($bean->read('', null, 0) === null) && ($bean->get('', null, 0) === null));
		});
		check('get() with a wait duration returns null when no job is available in time', function() use ($bean) {
			return ($bean->get('', null, 5) === null);
		});
		check('Without wait duration, get() waits until a job is available', function() use ($bean, $dsn) {
			// a child process sends a job after 2.5 seconds (more than a reservation request on the fake server)
			$pid = pcntl_fork();
			if (!$pid) {
				usleep(2500000);
				\Temma\Datasources\Beanstalk::factory($dsn)->set('', 'delayed job');
				exit(0);
			}
			// the test is interrupted if get() doesn't return
			pcntl_async_signals(true);
			pcntl_signal(SIGALRM, function() {
				throw new \Exception('get() never returned.');
			});
			pcntl_alarm(60);
			try {
				$start = microtime(true);
				$message = $bean->get('');
				$duration = microtime(true) - $start;
				$bean->remove($message['id']);
			} finally {
				pcntl_alarm(0);
				pcntl_waitpid($pid, $status);
			}
			return ($message['data'] === 'delayed job' && $duration >= 2);
		});
		check('read() limits the wait duration to 20 seconds', function() use ($bean) {
			$bean->set('', 'job');
			$message = $bean->get('', null, 60);
			$bean->remove($message['id']);
			return ($message['data'] === 'job');
		});
		check('count() returns the number of ready jobs', function() use ($bean) {
			$before = $bean->count();
			$bean->set('', 'job');
			$after = $bean->count();
			$message = $bean->get('');
			$bean->touch($message['id']);
			$bean->remove($message['id']);
			return ($after === ($before + 1));
		});
		check('set() with a null value removes the job, without sending a message', function() use ($bean) {
			$bean->set('', 'job');
			$message = $bean->get('');
			$before = $bean->count();
			$bean->set($message['id'], null);
			try {
				$bean->remove($message['id']);
			} catch (\Pheanstalk\Exception\JobNotFoundException $e) {
				return ($bean->count() === $before);
			}
			return (false);
		});
		check('mWrite() ignores null values, without removing the job with the same identifier', function() use ($bean) {
			$id = $bean->write('', 'first');
			$sent = $bean->mWrite([$id => null, 'x' => 'second', 'y' => 3]);
			$messages = [$bean->read(''), $bean->read(''), $bean->read('')];
			foreach ($messages as $message)
				$bean->remove($message['id']);
			return ($sent === 2 && (array_column($messages, 'data') === ['first', 'second', '3']) &&
			        $messages[0]['id'] === $id);
		});
		check('mSet() JSON-encodes the values and ignores null values', function() use ($bean) {
			$sent = $bean->mSet(['first', null, ['x' => 1]]);
			$messages = [$bean->get(''), $bean->get('')];
			foreach ($messages as $message)
				$bean->remove($message['id']);
			return ($sent === 2 && array_column($messages, 'data') === ['first', ['x' => 1]] &&
			        $bean->read('', null, 0) === null);
		});
		check('mWrite() rejects values which are not strings or numbers, without sending anything', function() use ($bean) {
			$before = $bean->count();
			try {
				$bean->mWrite(['first', ['array']]);
			} catch (\Temma\Exceptions\Database $e) {
				return ($bean->count() === $before);
			}
			return (false);
		});
		check('bury() puts aside a reserved job, kick() puts it back in the queue', function() use ($bean) {
			$id = $bean->write('', 'failed job');
			$message = $bean->read('');
			$buried = $bean->bury($message['id']);
			$afterBury = [$bean->count(), $bean->read('', null, 0)];
			$kicked = $bean->kick();
			$again = $bean->read('', null, 0);
			$bean->remove($again['id']);
			return ($buried === $bean && $afterBury === [0, null] && $kicked === 1 &&
			        $again === ['id' => $id, 'data' => 'failed job'] && $bean->kick() === 0);
		});
		check('kick() with an identifier kicks only this job (0 if it is not buried)', function() use ($bean) {
			$bean->mWrite(['first', 'second']);
			$first = $bean->read('');
			$second = $bean->read('');
			$bean->bury($first['id'])->bury($second['id']);
			$kicked = $bean->kick($second['id']);
			$message = $bean->read('', null, 0);
			$notBuried = $bean->kick($message['id']);
			$bean->remove($message['id']);
			$others = $bean->kick();
			$last = $bean->read('', null, 0);
			$bean->remove($last['id']);
			return ($kicked === 1 && $message['data'] === 'second' && $notBuried === 0 && $others === 1 &&
			        $last['data'] === 'first');
		});
		check('bury() throws an exception if the job is not reserved', function() use ($bean) {
			$id = $bean->write('', 'ready job');
			try {
				$bean->bury($id);
			} catch (\Pheanstalk\Exception\JobNotFoundException $e) {
				return (true);
			} finally {
				$bean->remove($id);
			}
			return (false);
		});
		check('Asynk buries the job of a failed task (and ignores a burial failure)', function() use ($bean) {
			$asynkDao = (new \ReflectionClass('\Temma\Asynk\AsynkDao'))->newInstanceWithoutConstructor();
			(new \ReflectionProperty($asynkDao, '_transport'))->setValue($asynkDao, $bean);
			(new \ReflectionProperty($asynkDao, '_transportClass'))->setValue($asynkDao, 'Temma\Datasources\Beanstalk');
			$bean->set('', ['target' => 'object', 'action' => 'method', 'data' => []]);
			$task = $asynkDao->getNextTask();
			$asynkDao->invalidateFetchedTask($task);
			// the job is not reserved anymore: burying it again fails silently
			$asynkDao->invalidateFetchedTask($task);
			$afterBury = $bean->read('', null, 0);
			$kicked = $bean->kick();
			$message = $bean->get('', null, 0);
			$bean->remove($message['id']);
			return ($afterBury === null && $kicked === 1 && $message['id'] === $task['id'] && $message['data']['target'] === 'object');
		});
		check('The datasource can be used after a disconnection', function() use ($bean) {
			$bean->disconnect();
			$bean->set('', 'after');
			$message = $bean->get('');
			$bean->remove($message['id']);
			return ($message['data'] === 'after');
		});
		if ($fake) {
			check("count() returns 0 for a tube which doesn't exist", function() use ($port) {
				return (\Temma\Datasources\Beanstalk::factory("beanstalk://127.0.0.1:$port/temma-unknown")->count() === 0);
			});
			check('read() only reserves jobs of the configured tube', function() use ($bean, $port) {
				$default = \Temma\Datasources\Beanstalk::factory("beanstalk://127.0.0.1:$port/default");
				$default->set('', 'default job');
				$bean->set('', 'test job');
				$message = $bean->get('');
				$bean->remove($message['id']);
				$defaultMessage = $default->get('');
				$default->remove($defaultMessage['id']);
				return ($message['data'] === 'test job' && $defaultMessage['data'] === 'default job');
			});
		}
	} finally {
		if ($fakePid) {
			posix_kill($fakePid, SIGTERM);
			pcntl_waitpid($fakePid, $status);
		}
	}
}

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
