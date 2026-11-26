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
 * - release() puts back a reserved job in the queue (with or without delay);
 * - stats(), peek() and peekBuried();
 * - Asynk creates tasks in the queue, buries the job of a failed task, or releases it with a delay if retries are enabled;
 * - Asynk task management (stats, list, show, retry, remove, purge).
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
			$job['reserves']++;
			$job['reservedBy'] = (int)$client['stream'];
			return ("RESERVED $id " . mb_strlen($job['data'], 'ascii') . "\r\n" . $job['data'] . "\r\n");
		}
	}
	return (null);
}
/**
 * Tell if a job of the fake Beanstalkd server is reserved by a given client.
 * @param	array	$jobs	List of jobs.
 * @param	string	$id	Job identifier.
 * @param	array	$client	Client connection.
 * @return	bool	True if the job exists and is reserved by the client.
 */
function reservedBy(array $jobs, string $id, array $client) : bool {
	return (isset($jobs[$id]) && (jobState($jobs[$id]) == 'reserved') && (($jobs[$id]['reservedBy'] ?? null) === (int)$client['stream']));
}
/**
 * Return the state of a job of the fake Beanstalkd server.
 * @param	array	$job	The job.
 * @return	string	"ready", "delayed", "buried" or "reserved".
 */
function jobState(array $job) : string {
	return ($job['ready'] ? 'ready' : ($job['delayedUntil'] ? 'delayed' : ($job['buried'] ? 'buried' : 'reserved')));
}
/**
 * Fake Beanstalkd server, handling the commands used by the data source.
 * Runs until the process is killed, or until its parent process ends.
 * The "reserve-with-timeout" command waits for a job, and the "release" command delays a job, with an accelerated time
 * (each second lasts 50 milliseconds). The "reserve" command (without timeout) is not supported.
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
		// delayed jobs whose delay is reached are ready
		foreach ($jobs as &$job) {
			if ($job['delayedUntil'] && microtime(true) >= $job['delayedUntil']) {
				$job['delayedUntil'] = null;
				$job['ready'] = true;
			}
		}
		unset($job);
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
				// the jobs reserved by the client are put back in the queue
				foreach ($jobs as &$job) {
					if ((jobState($job) == 'reserved') && (($job['reservedBy'] ?? null) === (int)$stream))
						$job['ready'] = true;
				}
				unset($job);
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
					$jobs[$nextId] = ['tube' => $client['used'], 'data' => $data, 'ready' => true, 'buried' => false, 'delayedUntil' => null,
					                  'reserves' => 0, 'releases' => 0, 'buries' => 0, 'kicks' => 0, 'priority' => (int)$parts[1],
					                  'delay' => (int)$parts[2], 'ttr' => (int)$parts[3]];
					if ((int)$parts[2] > 0) {
						$jobs[$nextId]['ready'] = false;
						$jobs[$nextId]['delayedUntil'] = microtime(true) + (int)$parts[2] * 0.05;
					}
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
					// a job reserved by another client can't be deleted
					if (!isset($jobs[$parts[1]]) || ((jobState($jobs[$parts[1]]) == 'reserved') && !reservedBy($jobs, $parts[1], $client))) {
						$response = "NOT_FOUND\r\n";
						break;
					}
					unset($jobs[$parts[1]]);
					$response = "DELETED\r\n";
					break;
				case 'touch':
					$response = reservedBy($jobs, $parts[1], $client) ? "TOUCHED\r\n" : "NOT_FOUND\r\n";
					break;
				case 'bury':
					// only a job reserved by the client can be buried
					if (!reservedBy($jobs, $parts[1], $client)) {
						$response = "NOT_FOUND\r\n";
						break;
					}
					$jobs[$parts[1]]['buried'] = true;
					$jobs[$parts[1]]['buries']++;
					$response = "BURIED\r\n";
					break;
				case 'kick':
					// buried jobs are kicked; if there is no buried job, delayed jobs are kicked
					$kicked = 0;
					$hasBuried = (bool)array_filter($jobs, fn($job) => (($job['tube'] == $client['used']) && (jobState($job) == 'buried')));
					foreach ($jobs as &$job) {
						if (($kicked < (int)$parts[1]) && ($job['tube'] == $client['used']) &&
						    (jobState($job) == ($hasBuried ? 'buried' : 'delayed'))) {
							$job['buried'] = false;
							$job['delayedUntil'] = null;
							$job['ready'] = true;
							$job['kicks']++;
							$kicked++;
						}
					}
					unset($job);
					$response = "KICKED $kicked\r\n";
					break;
				case 'kick-job':
					// buried and delayed jobs can be kicked
					if (!isset($jobs[$parts[1]]) || !in_array(jobState($jobs[$parts[1]]), ['buried', 'delayed'])) {
						$response = "NOT_FOUND\r\n";
						break;
					}
					$jobs[$parts[1]]['buried'] = false;
					$jobs[$parts[1]]['delayedUntil'] = null;
					$jobs[$parts[1]]['ready'] = true;
					$jobs[$parts[1]]['kicks']++;
					$response = "KICKED\r\n";
					break;
				case 'release':
					// only a job reserved by the client can be released
					if (!reservedBy($jobs, $parts[1], $client)) {
						$response = "NOT_FOUND\r\n";
						break;
					}
					$jobs[$parts[1]]['releases']++;
					$jobs[$parts[1]]['delay'] = (int)$parts[3];
					if ((int)$parts[3] > 0)
						$jobs[$parts[1]]['delayedUntil'] = microtime(true) + (int)$parts[3] * 0.05;
					else
						$jobs[$parts[1]]['ready'] = true;
					$response = "RELEASED\r\n";
					break;
				case 'stats-job':
					if (!isset($jobs[$parts[1]])) {
						$response = "NOT_FOUND\r\n";
						break;
					}
					$job = $jobs[$parts[1]];
					$timeLeft = $job['delayedUntil'] ? (int)ceil(max(0, $job['delayedUntil'] - microtime(true)) / 0.05) : 0;
					$stats = "---\nid: {$parts[1]}\ntube: {$job['tube']}\nstate: " . jobState($job) . "\npri: {$job['priority']}\nage: 0\n" .
					         'delay: ' . ($job['delay'] ?? 0) . "\nttr: {$job['ttr']}\ntime-left: $timeLeft\nfile: 0\nreserves: {$job['reserves']}\n" .
					         "timeouts: 0\nreleases: {$job['releases']}\nburies: {$job['buries']}\nkicks: {$job['kicks']}\n";
					$response = 'OK ' . mb_strlen($stats, 'ascii') . "\r\n$stats\r\n";
					break;
				case 'peek':
					$response = isset($jobs[$parts[1]]) ?
					            ("FOUND {$parts[1]} " . mb_strlen($jobs[$parts[1]]['data'], 'ascii') . "\r\n{$jobs[$parts[1]]['data']}\r\n") :
					            "NOT_FOUND\r\n";
					break;
				case 'peek-buried':
					$response = "NOT_FOUND\r\n";
					foreach ($jobs as $id => $job) {
						if ($job['tube'] == $client['used'] && jobState($job) == 'buried') {
							$response = "FOUND $id " . mb_strlen($job['data'], 'ascii') . "\r\n{$job['data']}\r\n";
							break;
						}
					}
					break;
				case 'stats-tube':
					if (!isset($tubes[$parts[1]])) {
						$response = "NOT_FOUND\r\n";
						break;
					}
					$states = array_count_values(array_map('jobState', array_filter($jobs, fn($job) => ($job['tube'] == $parts[1]))));
					$ready = $states['ready'] ?? 0;
					$reserved = $states['reserved'] ?? 0;
					$delayed = $states['delayed'] ?? 0;
					$buried = $states['buried'] ?? 0;
					$stats = "---\nname: {$parts[1]}\ncurrent-jobs-urgent: 0\ncurrent-jobs-ready: $ready\ncurrent-jobs-reserved: $reserved\n" .
					         "current-jobs-delayed: $delayed\ncurrent-jobs-buried: $buried\ntotal-jobs: 0\ncurrent-using: 0\ncurrent-waiting: 0\n" .
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
	        $bean->release('1', 10) === $bean && $bean->stats() === null && $bean->stats('1') === null &&
	        $bean->peek('1') === null && $bean->peekBuried() === null &&
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
		check('release() puts back a reserved job in the queue, immediately or after a delay', function() use ($bean) {
			$id = $bean->write('', 'released job');
			$message = $bean->read('');
			$released = $bean->release($message['id']);
			$again = $bean->read('', null, 0);
			$bean->release($again['id'], 2);
			$stats = $bean->stats($id);
			$notYet = $bean->read('', null, 0);
			$later = $bean->read('', null, 10);
			$finalStats = $bean->stats($id);
			$bean->remove($later['id']);
			return ($released === $bean && $again['id'] === $id && $stats['state'] === 'delayed' && $stats['releases'] === 2 &&
			        $notYet === null && $later['id'] === $id && $finalStats['state'] === 'reserved' && $finalStats['reserves'] === 3 &&
			        $bean->stats($id) === null);
		});
		check('release() throws an exception if the job is not reserved', function() use ($bean) {
			$id = $bean->write('', 'ready job');
			try {
				$bean->release($id);
			} catch (\Pheanstalk\Exception\JobNotFoundException $e) {
				return (true);
			} finally {
				$bean->remove($id);
			}
			return (false);
		});
		check('stats() returns the number of jobs in each state', function() use ($bean) {
			$before = $bean->stats();
			$bean->mWrite(['first', 'second', 'third']);
			$first = $bean->read('');
			$second = $bean->read('');
			$bean->bury($second['id']);
			$stats = $bean->stats();
			$third = $bean->read('');
			$bean->remove($first['id']);
			$bean->remove($second['id']);
			$bean->remove($third['id']);
			return ($before === ['ready' => 0, 'reserved' => 0, 'delayed' => 0, 'buried' => 0] &&
			        $stats === ['ready' => 1, 'reserved' => 1, 'delayed' => 0, 'buried' => 1]);
		});
		check('peek() reads a job without reserving it; peekBuried() reads the first buried job', function() use ($bean) {
			$id = $bean->write('', 'peeked job');
			$peeked = $bean->peek($id);
			$noBuried = $bean->peekBuried();
			$message = $bean->read('');
			$bean->bury($message['id']);
			$buried = $bean->peekBuried();
			$bean->remove($id);
			return ($peeked === ['id' => $id, 'data' => 'peeked job'] && $noBuried === null && $buried === $peeked &&
			        $bean->peek($id) === null);
		});
		/**
		 * Create an Asynk DAO using the Beanstalk data source.
		 * @param	\Temma\Datasources\Beanstalk	$bean		Data source.
		 * @param	?array				$retries	(optional) Delays between attempts.
		 * @return	\Temma\Asynk\AsynkDao	The DAO.
		 */
		function beanstalkAsynkDao(\Temma\Datasources\Beanstalk $bean, ?array $retries=null) : \Temma\Asynk\AsynkDao {
			$asynkDao = (new \ReflectionClass('\Temma\Asynk\AsynkDao'))->newInstanceWithoutConstructor();
			(new \ReflectionProperty($asynkDao, '_transport'))->setValue($asynkDao, $bean);
			(new \ReflectionProperty($asynkDao, '_transportClass'))->setValue($asynkDao, 'Temma\Datasources\Beanstalk');
			(new \ReflectionProperty($asynkDao, '_retries'))->setValue($asynkDao, $retries);
			return ($asynkDao);
		}
		check('Asynk creates a task in the queue (without storage)', function() use ($bean) {
			$asynkDao = beanstalkAsynkDao($bean);
			$asynkDao->createTask('object', 'method', [1, 'a']);
			$task = $asynkDao->getNextTask();
			$asynkDao->removeFetchedTask($task);
			return ($task['target'] === 'object' && $task['action'] === 'method' && $task['data'] === [1, 'a'] &&
			        $bean->read('', null, 0) === null);
		});
		check('Asynk releases the job of a failed task with a delay, then buries it after the last attempt', function() use ($bean) {
			$asynkDao = beanstalkAsynkDao($bean, [0, 2]);
			$bean->set('', ['target' => 'object', 'action' => 'method', 'data' => []]);
			$task = $asynkDao->getNextTask();
			$first = $asynkDao->invalidateFetchedTask($task, 'First failure');
			$task = $asynkDao->getNextTask();
			$second = $asynkDao->invalidateFetchedTask($task, 'Second failure');
			$delayed = $asynkDao->getStats();
			$task = $asynkDao->getNextTask();
			$third = $asynkDao->invalidateFetchedTask($task, 'Third failure');
			// no new attempt for PHP errors
			$bean->set('', ['target' => 'object', 'action' => 'bug', 'data' => []]);
			$noRetry = $asynkDao->invalidateFetchedTask($asynkDao->getNextTask(), 'Error', false);
			$stats = $asynkDao->getStats();
			$purged = $asynkDao->purgeFailedTasks();
			return ($first === 0 && $second === 2 && $delayed === ['waiting' => 0, 'delayed' => 1, 'processing' => 0, 'failed' => 0] &&
			        $third === null && $noRetry === null && $stats === ['waiting' => 0, 'delayed' => 0, 'processing' => 0, 'failed' => 2] &&
			        $purged === 2 && $asynkDao->getStats()['failed'] === 0);
		});
		check('Asynk task management: listTasks(), getTask(), retryTask() (job created again), removeTask()', function() use ($bean, $dsn) {
			// the management uses its own connection (like the AsynkManager command)
			$asynkDao = beanstalkAsynkDao(\Temma\Datasources\Beanstalk::factory($dsn));
			$bean->set('', ['creation' => '2026-01-01T00:00:00+00:00', 'target' => 'object', 'action' => 'method', 'data' => [1]]);
			$id = $bean->get('')['id'];
			$bean->bury($id);
			$list = $asynkDao->listTasks('failed');
			$defaultList = $asynkDao->listTasks();
			$task = $asynkDao->getTask($id);
			$waitingList = $asynkDao->listTasks('waiting');
			$retried = $asynkDao->retryTask($id);
			$notFailed = $asynkDao->retryTask($id);
			$oldTask = $asynkDao->getTask($id);
			// the job was created again: its counters are reset
			$message = $bean->get('', null, 0);
			$newTask = $asynkDao->getTask($message['id']);
			$notRemovable = $asynkDao->removeTask($message['id']);
			$bean->bury($message['id']);
			$removed = $asynkDao->removeTask($message['id']);
			return (count($list) === 1 && $list[0] === $task && $defaultList === $list && $task['id'] === $id && $task['state'] === 'failed' &&
			        $task['target'] === 'object' && $task['data'] === [1] &&
			        $task['dateCreation'] === date('Y-m-d H:i:s', strtotime('2026-01-01T00:00:00+00:00')) &&
			        $task['attempts'] === 1 && $waitingList === null && $retried && !$notFailed && $oldTask === null &&
			        $message['id'] !== $id && $message['data']['data'] === [1] && $newTask['state'] === 'processing' && $newTask['attempts'] === 1 &&
			        !$notRemovable && $removed && !$asynkDao->removeTask($message['id']) && $asynkDao->getTask($message['id']) === null);
		});
		check('write() and set() accept the priority, delay and time to run of the message', function() use ($bean) {
			$id = $bean->write('', 'job', ['priority' => 10, 'ttr' => 120]);
			$delayedId = $bean->set('', ['x' => 1], ['delay' => 100]);
			$stats = $bean->stats($id);
			$delayedStats = $bean->stats($delayedId);
			$bean->remove($id);
			$bean->remove($delayedId);
			return ($stats['state'] === 'ready' && $delayedStats['state'] === 'delayed' && $delayedStats['delay'] === 100 &&
			        $bean->stats('123456789012345678901') === null && $bean->peek('123456789012345678901') === null);
		});
		check('Asynk sends its tasks with a time to run of one hour; invalid messages are buried without processing', function() use ($bean) {
			$asynkDao = beanstalkAsynkDao($bean);
			$asynkDao->createTask('object', 'method', []);
			$id = $bean->write('', 'not JSON');
			$bean->set('', ['no' => 'target']);
			$asynkDao->createTask('object', 'valid', []);
			$first = $asynkDao->getNextTask();
			$ttr = (new \ReflectionMethod('\Pheanstalk\Pheanstalk', 'statsJob'))->invoke(property($bean, '_pheanstalk'), new \Pheanstalk\Values\JobId($first['id']))->timeToRelease;
			$asynkDao->removeFetchedTask($first);
			$valid = $asynkDao->getNextTask();
			$asynkDao->removeFetchedTask($valid);
			$buried = $asynkDao->getStats()['failed'];
			$purged = $asynkDao->purgeFailedTasks();
			return ($ttr === 3600 && $first['action'] === 'method' && $valid['action'] === 'valid' && $buried === 2 && $purged === 2 &&
			        $bean->read('', null, 0) === null);
		});
		check('Asynk retryFailedTasks() recreates the buried jobs', function() use ($bean, $dsn) {
			$asynkDao = beanstalkAsynkDao(\Temma\Datasources\Beanstalk::factory($dsn));
			$bean->mWrite(['{"target":"a","action":"b","data":[1]}', '{"target":"a","action":"b","data":[2]}']);
			$first = $bean->read('');
			$second = $bean->read('');
			$bean->bury($first['id'])->bury($second['id']);
			$count = $asynkDao->retryFailedTasks();
			$messages = [$bean->get('', null, 0), $bean->get('', null, 0)];
			foreach ($messages as $message)
				$bean->remove($message['id']);
			return ($count === 2 && $asynkDao->getStats()['failed'] === 0 && array_column(array_column($messages, 'data'), 'data') === [[1], [2]] &&
			        $messages[0]['id'] > $second['id']);
		});
		check('Asynk ignores the jobs of other tubes and the non-numeric identifiers', function() use ($bean, $dsn) {
			$asynkDao = beanstalkAsynkDao($bean);
			$other = \Temma\Datasources\Beanstalk::factory(preg_replace('/\/[^\/]+$/', '/temma-other', $dsn));
			$id = $other->write('', '{"target":"object","action":"method","data":[]}');
			$results = [$asynkDao->getTask($id), $asynkDao->retryTask($id), $asynkDao->removeTask($id),
			            $asynkDao->getTask('abc'), $asynkDao->retryTask('abc'), $asynkDao->removeTask('abc'),
			            $bean->stats('abc'), $bean->peek('abc'), $bean->kick('abc')];
			$stillThere = $other->peek($id);
			$otherTube = $bean->stats($id)['tube'];
			$other->remove($id);
			return ($results === [null, false, false, null, false, false, null, null, 0] && $stillThere['id'] === $id &&
			        $otherTube === 'temma-other' && $bean->getTube() === 'temma-test');
		});
		check('Asynk buries without processing the jobs reserved too many times (interrupted processings)', function() use ($bean, $dsn) {
			$asynkDao = beanstalkAsynkDao($bean, [0]);
			$bean->set('', ['target' => 'object', 'action' => 'crash', 'data' => []]);
			// two processings interrupted by a crash (the connection is closed without releasing the job)
			for ($i = 0; $i < 2; $i++) {
				$crashed = \Temma\Datasources\Beanstalk::factory($dsn);
				$crashed->get('');
				$crashed->disconnect();
				unset($crashed);
			}
			// the third reservation exceeds the number of attempts (1 + 1 retry): the job is buried
			$bean->set('', ['target' => 'object', 'action' => 'next', 'data' => []]);
			$task = $asynkDao->getNextTask();
			$asynkDao->removeFetchedTask($task);
			$stats = $bean->stats();
			$buried = $bean->peekBuried();
			$bean->remove($buried['id']);
			return ($task['action'] === 'next' && $task['attempts'] === 1 && $stats['buried'] === 1 &&
			        json_decode($buried['data'], true)['action'] === 'crash');
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
