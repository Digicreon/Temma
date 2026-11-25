#!/usr/bin/php
<?php

/**
 * Validation script for the Socket data source, using a local TCP server created by the script:
 * - DSN parsing (port, connection timeout, invalid DSN);
 * - a connection failure throws a Database exception;
 * - read() returns the data received before the peer closed the connection, doesn't stop on a "0" chunk,
 *   and returns null when it times out;
 * - get() returns the lines, including a last line "0" (without line feed);
 * - set() and mSet() send "falsy" values (0, "0", false, ""), but not null values;
 * - mWrite() ignores null values (the connection is not closed) and rejects values which are not strings or numbers;
 * - write() sends all the data, even after a non-blocking read (this test needs the pcntl extension, it is skipped otherwise).
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');
\Temma\Base\Log::disable();
// PHP warnings and notices (not silenced by the "@" operator) are converted to exceptions, so they make the tests fail
set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline) {
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

/* ********** TESTS ********** */
// local server
$server = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)explode(':', stream_socket_get_name($server, false))[1];
/**
 * Create a client connected to the local server, and returns the client and the server-side connection.
 * @return	array	The Socket object and the server-side stream.
 */
function connection() : array {
	global $server, $port;
	$sock = \Temma\Datasources\Socket::factory("tcp://127.0.0.1:$port#2");
	$sock->connect();
	$peer = stream_socket_accept($server, 2);
	return ([$sock, $peer]);
}

print(TµAnsi::bold("Socket data source\n"));
check('The connection timeout is null if it is not given in the DSN', function() {
	$sock = \Temma\Datasources\Socket::factory('tcp://localhost:80');
	return ((property($sock, '_connectionTimeout') === null) && (property($sock, '_port') === 80));
});
check('The connection timeout is read from the DSN', function() {
	$sock = \Temma\Datasources\Socket::factory('tls://localhost:443#5');
	return ((property($sock, '_connectionTimeout') === 5) && (property($sock, '_port') === 443) && (property($sock, '_host') === 'localhost'));
});
check('Invalid DSNs throw a Database exception', function() {
	foreach (['foo', 'tcp://', 'tcp://host:12:34', 'http://localhost:80'] as $dsn) {
		try {
			\Temma\Datasources\Socket::factory($dsn);
			return (false);
		} catch (\Temma\Exceptions\Database $e) {
		}
	}
	return (true);
});
check('A connection failure throws a Database exception', function() {
	$sock = \Temma\Datasources\Socket::factory('tcp://127.0.0.1:1#1');
	try {
		$sock->connect();
	} catch (\Temma\Exceptions\Database $e) {
		return ($e->getCode() === \Temma\Exceptions\Database::CONNECTION);
	}
	return (false);
});
check('read() returns the data sent before the peer closed the connection, then null', function() {
	[$sock, $peer] = connection();
	fwrite($peer, 'hello');
	fclose($peer);
	usleep(100000);
	return (($sock->read('') === 'hello') && ($sock->read('') === null));
});
check('read() doesn\'t stop on a "0" chunk', function() {
	[$sock, $peer] = connection();
	$data = str_repeat('a', 4096) . '0';
	fwrite($peer, $data);
	usleep(100000);
	$result = $sock->read('');
	fclose($peer);
	return ($result === $data);
});
check('read() returns a "0" sent before the peer closed the connection', function() {
	[$sock, $peer] = connection();
	fwrite($peer, '0');
	fclose($peer);
	usleep(100000);
	return (($sock->read('') === '0') && ($sock->read('') === null));
});
check('read() returns null when it times out', function() {
	[$sock, $peer] = connection();
	$result = $sock->read('', null, 1);
	fclose($peer);
	return ($result === null);
});
check('get() returns the lines, including a last "0" line without line feed, then null', function() {
	[$sock, $peer] = connection();
	fwrite($peer, "first\r\n\r\n0");
	fclose($peer);
	usleep(100000);
	return (($sock->get('') === 'first') && ($sock->get('') === '') && ($sock->get('') === '0') && ($sock->get('') === null));
});
check('set() and mSet() send falsy values, not null', function() {
	[$sock, $peer] = connection();
	$results = [$sock->set('', 0), $sock->set('', '0'), $sock->set('', false), $sock->set('', ''), $sock->set('', null)];
	$written = $sock->mSet([0, null, ['a' => 1]]);
	$sock->disconnect();
	$received = stream_get_contents($peer);
	fclose($peer);
	return (($results === [true, true, true, true, false]) && $written === 2 &&
	        ($received === "0\r\n0\r\nfalse\r\n\r\n0\r\n{\"a\":1}\r\n"));
});
check('mWrite() ignores null values, without closing the connection', function() {
	[$sock, $peer] = connection();
	$written = $sock->mWrite(['first', null, 2, 'last']);
	$connected = (property($sock, '_sock') !== null);
	$sock->disconnect();
	$received = stream_get_contents($peer);
	fclose($peer);
	return ($written === 3 && $connected && $received === 'first2last');
});
check('mWrite() rejects values which are not strings or numbers, without sending anything', function() {
	[$sock, $peer] = connection();
	try {
		$sock->mWrite(['first', ['array']]);
		return (false);
	} catch (\Temma\Exceptions\Database $e) {
	}
	$sock->disconnect();
	$received = stream_get_contents($peer);
	fclose($peer);
	return ($received === '');
});
if (!function_exists('pcntl_fork')) {
	print(TµAnsi::color('yellow', 'The pcntl extension is not loaded, the test of write() after a non-blocking read is skipped.') . "\n");
} else {
	check('write() sends all the data after a non-blocking read', function() {
		[$sock, $peer] = connection();
		$data = random_bytes(20 * 1024 * 1024);
		// non-blocking read, which leaves the socket in non-blocking mode
		if ($sock->read('', null, false) !== '')
			return (false);
		// the server side is read by a child process, which exits with the status 0 if it received all the data
		$pid = pcntl_fork();
		if (!$pid) {
			$sock->disconnect();
			$received = stream_get_contents($peer);
			exit(($received === $data) ? 0 : 1);
		}
		fclose($peer);
		$result = $sock->write('', $data);
		$sock->disconnect();
		pcntl_waitpid($pid, $status);
		return ($result === true && pcntl_wifexited($status) && (pcntl_wexitstatus($status) === 0));
	});
}
fclose($server);

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
