#!/usr/bin/php
<?php

/**
 * Validation script for the ZeroMQ data source, using PUSH/PULL pairs of sockets created by the script
 * (on the local interface, with random ports):
 * - invalid DSNs throw a Database exception;
 * - set() and get() send and receive "falsy" values; set() ignores null values;
 * - get() throws a Database exception when the message is not valid JSON;
 * - mWrite() and mSet() accept an empty list;
 * - mSet() sends the data as the parts of a multipart message, and ignores null values;
 * - mWrite() converts numbers to strings, ignores null values, and rejects other values without sending anything;
 * - a disabled data source sends and receives nothing;
 * - a connected socket can be used after a reconnection;
 * - a bound socket can be reconnected (and disconnected, then connected again) on the same address,
 *   and can be used afterwards.
 *
 * The zmq PHP extension is needed, otherwise the tests are skipped.
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');
\Temma\Base\Log::disable();
if (!extension_loaded('zmq')) {
	print(TµAnsi::color('yellow', "The 'zmq' PHP extension is not loaded, tests skipped.") . "\n");
	exit(0);
}
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

/* ********** TESTS ********** */
print(TµAnsi::bold("ZeroMQ data source\n"));
check('Invalid DSNs throw a Database exception', function() {
	foreach (['zmq://127.0.0.1:5000', 'zmq-foo://PUSH@127.0.0.1:5000', 'zmq://FOO@127.0.0.1:5000', 'zmq://PUSH@'] as $dsn) {
		try {
			\Temma\Datasources\ZeroMQ::factory($dsn);
			return (false);
		} catch (\Temma\Exceptions\Database $e) {
		}
	}
	return (true);
});
// PULL socket bound on a random port (the reception timeout avoids blocking the script), PUSH socket connected to it
$pull = \Temma\Datasources\ZeroMQ::factory('zmq-bind://PULL@127.0.0.1:*');
$pull->connect();
$pullSocket = (new \ReflectionProperty($pull, '_socket'))->getValue($pull);
$pullSocket->setSockOpt(\ZMQ::SOCKOPT_RCVTIMEO, 2000);
$endpoint = $pullSocket->getSockOpt(\ZMQ::SOCKOPT_LAST_ENDPOINT);
$push = \Temma\Datasources\ZeroMQ::factory('zmq://PUSH@' . mb_substr($endpoint, mb_strlen('tcp://')));
check('set() and get() send and receive falsy values', function() use ($push, $pull) {
	foreach ([0, '0', false, '', [], ['a' => 1]] as $value) {
		$push->set('', $value);
		if ($pull->get('') !== $value)
			return (false);
	}
	return (true);
});
check('set() ignores null values', function() use ($push, $pull) {
	$result = $push->set('', null);
	$push->set('', 'next');
	return ($result === false && $pull->get('') === 'next');
});
check('get() throws a Database exception when the message is not valid JSON', function() use ($push, $pull) {
	$push->write('', 'not JSON');
	try {
		$pull->get('');
	} catch (\Temma\Exceptions\Database $e) {
		return (true);
	}
	return (false);
});
check('mWrite() and mSet() accept an empty list', fn() => (($push->mWrite([]) === 0) && ($push->mSet([]) === 0)));
check('mSet() sends the data as the parts of a multipart message', function() use ($push, $pull) {
	$sent = $push->mSet([1, 'two', ['three']]);
	return ($sent === 3 && ($pull->get('') === 1) && ($pull->get('') === 'two') && ($pull->get('') === ['three']));
});
check('mSet() ignores null values', function() use ($push, $pull) {
	$sent = $push->mSet([null, 'one', null]);
	$push->set('', 'next');
	return ($sent === 1 && $pull->get('') === 'one' && $pull->get('') === 'next');
});
check('mWrite() converts numbers to strings and ignores null values', function() use ($push, $pull) {
	$sent = $push->mWrite([1, null, 2.5, 'three']);
	return ($sent === 3 && $pull->read('') === '1' && $pull->read('') === '2.5' && $pull->read('') === 'three');
});
check('mWrite() rejects values which are not strings or numbers, without sending anything', function() use ($push, $pull) {
	try {
		$push->mWrite(['first', ['array']]);
		return (false);
	} catch (\Temma\Exceptions\Database $e) {
	}
	$push->set('', 'next');
	return ($pull->get('') === 'next');
});
check('A disabled data source sends and receives nothing', function() use ($endpoint) {
	$zmq = \Temma\Datasources\ZeroMQ::factory('zmq://PUSH@' . mb_substr($endpoint, mb_strlen('tcp://')))->disable();
	$path = sys_get_temp_dir() . '/temma-zeromq-test-' . getmypid();
	return ($zmq->read('') === null && $zmq->get('') === null && $zmq->write('', 'x') === false &&
	        $zmq->set('', 'x') === false && $zmq->mWrite(['x']) === 0 && $zmq->mSet(['x']) === 0 &&
	        $zmq->copyFrom('', $path) === false && !file_exists($path));
});
check('The data source can be used after a reconnection', function() use ($push, $pull) {
	$push->reconnect();
	$push->set('', 'after');
	return ($pull->get('') === 'after');
});
check('A bound socket can be reconnected on the same address, and used afterwards', function() {
	// find a free port
	$server = stream_socket_server('tcp://127.0.0.1:0');
	$port = (int)explode(':', stream_socket_get_name($server, false))[1];
	fclose($server);
	// PULL socket bound on this port, reconnected, then disconnected and connected again
	$pull = \Temma\Datasources\ZeroMQ::factory("zmq-bind://PULL@127.0.0.1:$port");
	$pull->connect();
	$pull->reconnect();
	$pull->disconnect();
	$pull->connect();
	$pull->reconnect();
	(new \ReflectionProperty($pull, '_socket'))->getValue($pull)->setSockOpt(\ZMQ::SOCKOPT_RCVTIMEO, 2000);
	// the bound socket receives the messages
	$push = \Temma\Datasources\ZeroMQ::factory("zmq://PUSH@127.0.0.1:$port");
	$push->set('', 'rebound');
	$result = $pull->get('');
	$push->disconnect();
	$pull->disconnect();
	return ($result === 'rebound');
});

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
