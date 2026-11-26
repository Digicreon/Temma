#!/usr/bin/php
<?php

/**
 * Validation script for the SMTP data source, with a fake local SMTP server (started and stopped by the script):
 * - the transaction is reset (RSET) after an error, so the connection can be used to send other messages;
 * - the connection is reopened if the server closed it, transparently (without losing the message) when the
 *   connection was closed while idle (silently, or with a 421 response);
 * - recipients given as a string are split on the commas which are not between double quotes;
 * - addresses containing CR, LF (or other control characters) or angle brackets are rejected (SMTP command injection);
 * - the TLS certificate is verified by default (STARTTLS with a self-signed certificate);
 * - a failed connection (authentication error) is closed;
 * - a disabled data source sends nothing and throws no exception (mWrite() and mSet() included);
 * - the password of the DSN is not written in the logs and in the exception messages.
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;
use \Temma\Datasources\Smtp as TµSmtp;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');
// the logs are captured, to check that they don't contain secrets
$logs = [];
\Temma\Base\Log::setThreshold('DEBUG');
\Temma\Base\Log::addCallback(function($requestId, $message) use (&$logs) {
	$logs[] = $message;
});
// PHP warnings and notices (not silenced by '@') are converted to exceptions, so they make the tests fail
set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline) {
	if (!(error_reporting() & $errno))
		return (false);
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

/* ********** FAKE SMTP SERVER ********** */
$serverPath = sys_get_temp_dir() . '/temma-smtp-test-' . getmypid();
mkdir($serverPath, 0755, true);
// self-signed certificate, used for STARTTLS
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'localhost'], $key), null, $key, 1);
openssl_x509_export($cert, $certPem);
openssl_pkey_export($key, $keyPem);
file_put_contents("$serverPath/cert.pem", $certPem . $keyPem);
// the server processes the connections one at a time, and writes the received lines in a log file
// (each line is prefixed by the connection number; "#N END" is written when the connection is closed)
file_put_contents("$serverPath/server.php", <<<'EOT'
	<?php
	[, $port, $logPath, $certPath] = $argv;
	$context = stream_context_create(['ssl' => ['local_cert' => $certPath, 'verify_peer' => false]]);
	$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
	$connection = 0;
	while (($client = @stream_socket_accept($server, -1))) {
		$connection++;
		stream_set_timeout($client, 5);
		$log = function(string $line) use ($logPath, $connection) {
			file_put_contents($logPath, "#$connection $line\n", FILE_APPEND);
		};
		fwrite($client, "220 fake ESMTP\r\n");
		$inMail = false;
		// closing of the connection after the message (simulation of an idle timeout): 'silent' or '421'
		$idleClose = null;
		while (($line = fgets($client)) !== false) {
			$line = rtrim($line, "\r\n");
			$log($line);
			$command = mb_strtoupper(mb_substr($line, 0, 4));
			if ($command == 'EHLO') {
				fwrite($client, "250-fake\r\n250-AUTH PLAIN LOGIN\r\n250 STARTTLS\r\n");
			} else if ($command == 'STAR') {
				fwrite($client, "220 go ahead\r\n");
				if (!@stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
					$log('TLS FAILED');
					break;
				}
				$log('TLS OK');
			} else if ($command == 'AUTH') {
				$credentials = explode("\0", base64_decode(mb_substr($line, 11)));
				fwrite($client, ((($credentials[1] ?? '') == 'bad') ? "535 authentication failed\r\n" : "235 ok\r\n"));
			} else if ($command == 'MAIL') {
				fwrite($client, ($inMail ? "503 nested MAIL command\r\n" : "250 ok\r\n"));
				$inMail = true;
			} else if ($command == 'RCPT') {
				if (str_contains($line, 'close@')) {
					fwrite($client, "421 closing connection\r\n");
					break;
				}
				if (str_contains($line, 'idle@'))
					$idleClose = 'silent';
				else if (str_contains($line, 'idle421@'))
					$idleClose = '421';
				fwrite($client, (str_contains($line, 'bad@') ? "550 no such user\r\n" : "250 ok\r\n"));
			} else if ($command == 'DATA') {
				fwrite($client, "354 go ahead\r\n");
				while ((($line = fgets($client)) !== false) && $line !== ".\r\n")
					$log('DATA ' . rtrim($line, "\r\n"));
				$inMail = false;
				fwrite($client, "250 queued\r\n");
				if ($idleClose == '421')
					fwrite($client, "421 idle timeout\r\n");
				if ($idleClose)
					break;
			} else if ($command == 'RSET') {
				$inMail = false;
				fwrite($client, "250 ok\r\n");
			} else if ($command == 'QUIT') {
				fwrite($client, "221 bye\r\n");
				break;
			} else {
				fwrite($client, "500 unknown command\r\n");
			}
		}
		@fclose($client);
		$log('END');
	}
	EOT);
$port = rand(20000, 60000);
$logPath = "$serverPath/server.log";
touch($logPath);
$server = proc_open([PHP_BINARY, "$serverPath/server.php", $port, $logPath, "$serverPath/cert.pem"],
                    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
register_shutdown_function(function() use ($server, $serverPath) {
	proc_terminate($server);
	proc_close($server);
	exec('rm -rf ' . escapeshellarg($serverPath));
});
// wait for the server
foreach (range(1, 50) as $i) {
	usleep(100000);
	if (($socket = @fsockopen('127.0.0.1', $port))) {
		fclose($socket);
		break;
	}
}

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
 * Executes a function which opens connections to the fake server, and returns the lines received by the server.
 * @param	int		$connections	Number of connections opened by the function.
 * @param	callable	$function	The function.
 * @return	array	The lines received by the server (without the connection numbers).
 */
function session(int $connections, callable $function) : array {
	global $logPath;
	$offset = filesize($logPath);
	$function();
	// wait for the end of the connections
	foreach (range(1, 50) as $i) {
		clearstatcache();
		$log = file_get_contents($logPath, false, null, $offset);
		if (preg_match_all('/^#\d+ END$/m', $log) >= $connections)
			break;
		usleep(100000);
	}
	return (array_map(fn($line) => preg_replace('/^#\d+ /', '', $line), explode("\n", trim($log))));
}
/**
 * Returns the exception thrown by a function, or null.
 * @param	callable	$function	The function.
 * @return	?\Throwable	The exception.
 */
function exception(callable $function) : ?\Throwable {
	try {
		$function();
	} catch (\Throwable $e) {
		return ($e);
	}
	return (null);
}
/**
 * Returns the value of a private property of an SMTP object.
 * @param	TµSmtp	$smtp	The object.
 * @param	string	$name	Name of the property.
 * @return	mixed	The value.
 */
function smtpProp(TµSmtp $smtp, string $name) : mixed {
	return ((new \ReflectionProperty(TµSmtp::class, $name))->getValue($smtp));
}
$dsn = "smtp://127.0.0.1:$port?timeout=3&helo=test.local";
$message = "Subject: test\r\n\r\nHello\r\n.line starting with a dot";

/* ********** TESTS ********** */
print(TµAnsi::bold("Sending\n"));
check('message sent, with dot-stuffing', function() use ($dsn, $message) {
	$lines = session(1, function() use ($dsn, $message) {
		$smtp = TµSmtp::factory($dsn);
		$smtp->set('id', ['from' => 'Sender <s@x.com>', 'recipients' => ['a@x.com', 'B <b@x.com>', '', 'a@x.com'], 'message' => $message]);
		$smtp->disconnect();
	});
	return (in_array('MAIL FROM:<s@x.com>', $lines) && in_array('RCPT TO:<a@x.com>', $lines) && in_array('RCPT TO:<b@x.com>', $lines) &&
	        (count(preg_grep('/^RCPT/', $lines)) == 2) && in_array('DATA ..line starting with a dot', $lines));
});
check("a rejected recipient doesn't prevent sending the next messages on the same connection (RSET)", function() use ($dsn, $message) {
	$error = null;
	$lines = session(1, function() use ($dsn, $message, &$error) {
		$smtp = TµSmtp::factory($dsn);
		$error = exception(fn() => $smtp->set('id1', ['from' => 's@x.com', 'recipients' => ['bad@x.com'], 'message' => $message]));
		$smtp->set('id2', ['from' => 's@x.com', 'recipients' => ['good@x.com'], 'message' => $message]);
		$smtp->disconnect();
	});
	return ($error instanceof \Temma\Exceptions\Database && str_contains($error->getMessage(), '550') &&
	        in_array('RSET', $lines) && in_array('RCPT TO:<good@x.com>', $lines) && in_array('DATA Hello', $lines));
});
check('the connection is reopened when the server closed it during a transaction', function() use ($dsn, $message) {
	$error = null;
	$lines = session(2, function() use ($dsn, $message, &$error) {
		$smtp = TµSmtp::factory($dsn);
		$error = exception(fn() => $smtp->set('id1', ['from' => 's@x.com', 'recipients' => ['close@x.com'], 'message' => $message]));
		$smtp->set('id2', ['from' => 's@x.com', 'recipients' => ['good@x.com'], 'message' => $message]);
		$smtp->disconnect();
	});
	return ($error instanceof \Temma\Exceptions\Database && in_array('RCPT TO:<good@x.com>', $lines) && in_array('DATA Hello', $lines));
});
// the server closes the connection after the first message (idle timeout); the second message must not be lost
$idleCases = [
	'silently'            => ['recipient' => 'idle@x.com', 'log' => 'closed by the server'],
	'with a 421 response' => ['recipient' => 'idle421@x.com', 'log' => 'lost, reconnection'],
];
foreach ($idleCases as $label => $case) {
	check("connection closed by the server while idle ($label): transparent reconnection, message sent", function() use ($dsn, $message, $case) {
		global $logs;
		$logs = [];
		$error = null;
		$lines = session(2, function() use ($dsn, $message, $case, &$error) {
			$smtp = TµSmtp::factory($dsn);
			$smtp->set('id1', ['from' => 's@x.com', 'recipients' => [$case['recipient']], 'message' => $message]);
			// idle time, during which the server closes the connection
			usleep(300000);
			$error = exception(fn() => $smtp->set('id2', ['from' => 's@x.com', 'recipients' => ['good@x.com'], 'message' => $message]));
			$smtp->disconnect();
		});
		return ($error === null && in_array('RCPT TO:<good@x.com>', $lines) &&
		        (count(array_keys($lines, 'DATA Hello')) == 2) && (count(array_keys($lines, 'END')) == 2) &&
		        (bool)array_filter($logs, fn($log) => str_contains($log, $case['log'])));
	});
}
check('connection refused by the server: no reconnection loop, exception', function() use ($message) {
	// port 1 is never open
	$smtp = TµSmtp::factory('smtp://127.0.0.1:1?timeout=2');
	$error = exception(fn() => $smtp->set('id', ['from' => 's@x.com', 'recipients' => ['a@x.com'], 'message' => $message]));
	return ($error instanceof \Temma\Exceptions\Database && str_contains($error->getMessage(), 'Unable to connect'));
});

print("\n" . TµAnsi::bold("Recipients given as a string\n"));
check('write(): comma-separated recipients, with commas between double quotes', function() use ($dsn, $message) {
	$lines = session(1, function() use ($dsn, $message) {
		$smtp = TµSmtp::factory($dsn);
		$smtp->write('id', $message, ['from' => 's@x.com', 'recipients' => 'Alice <a@x.com>, "Smith, Bob" <b@y.com>,c@z.com']);
		$smtp->disconnect();
	});
	return (in_array('RCPT TO:<a@x.com>', $lines) && in_array('RCPT TO:<b@y.com>', $lines) && in_array('RCPT TO:<c@z.com>', $lines) &&
	        (count(preg_grep('/^RCPT/', $lines)) == 3));
});
check('set(): comma-separated recipients, empty items ignored', function() use ($dsn, $message) {
	$lines = session(1, function() use ($dsn, $message) {
		$smtp = TµSmtp::factory($dsn);
		$smtp->set('id', ['from' => 's@x.com', 'recipients' => 'a@x.com, , b@y.com,', 'message' => $message]);
		$smtp->disconnect();
	});
	return (in_array('RCPT TO:<a@x.com>', $lines) && in_array('RCPT TO:<b@y.com>', $lines) &&
	        (count(preg_grep('/^RCPT/', $lines)) == 2));
});
check('invalid address in a comma-separated list: exception, nothing sent', function() use ($dsn, $message) {
	$error = null;
	$lines = session(0, function() use ($dsn, $message, &$error) {
		$smtp = TµSmtp::factory($dsn);
		$error = exception(fn() => $smtp->write('id', $message, ['from' => 's@x.com', 'recipients' => 'a@x.com, b@y.com> SIZE=1']));
		$smtp->disconnect();
	});
	return ($error instanceof \Temma\Exceptions\Database && str_contains($error->getMessage(), 'Invalid email address') &&
	        !array_filter($lines));
});

print("\n" . TµAnsi::bold("SMTP command injection\n"));
$injections = [
	'CR/LF in the sender'           => ['from' => "s@x.com\r\nRSET", 'recipients' => ['a@x.com']],
	'LF in the sender'              => ['from' => "s@x.com\nRSET", 'recipients' => ['a@x.com']],
	'CR/LF in a recipient'          => ['from' => 's@x.com', 'recipients' => ["a@x.com\r\nRCPT TO:<evil@y.com>"]],
	'null byte in a recipient'      => ['from' => 's@x.com', 'recipients' => ["a@x\0.com"]],
	'CR/LF between brackets'        => ['from' => "Name <s@x.com\r\nRSET>", 'recipients' => ['a@x.com']],
	'CR/LF in the display name'     => ['from' => "Evil\r\nRSET <s@x.com>", 'recipients' => ['a@x.com']],
	'ESMTP parameter in an address' => ['from' => 's@x.com> SIZE=1', 'recipients' => ['a@x.com']],
];
foreach ($injections as $label => $envelope) {
	check("$label: exception, nothing sent", function() use ($dsn, $message, $envelope) {
		$error = null;
		$lines = session(0, function() use ($dsn, $message, $envelope, &$error) {
			$smtp = TµSmtp::factory($dsn);
			$error = exception(fn() => $smtp->write('id', $message, $envelope));
			$smtp->disconnect();
		});
		return ($error instanceof \Temma\Exceptions\Database && str_contains($error->getMessage(), 'Invalid email address') &&
		        !array_filter($lines));
	});
}
check('leading and trailing spaces and line breaks are removed from the addresses', function() use ($dsn, $message) {
	$lines = session(1, function() use ($dsn, $message) {
		$smtp = TµSmtp::factory($dsn);
		$smtp->write('id', $message, ['from' => " s@x.com\r\n", 'recipients' => "Name <a@x.com>\n"]);
		$smtp->disconnect();
	});
	return (in_array('MAIL FROM:<s@x.com>', $lines) && in_array('RCPT TO:<a@x.com>', $lines));
});
check('CR/LF in the EHLO host name: exception', function() use ($port) {
	return (exception(fn() => TµSmtp::fromParams(['host' => '127.0.0.1', 'port' => $port, 'helo' => "h\r\nRSET"])) instanceof \Temma\Exceptions\Database);
});

print("\n" . TµAnsi::bold("Connection security\n"));
// the server is reached with the host name of the certificate ('localhost'), so the rejection can only come from
// the verification of the certificate chain (self-signed certificate), not from the verification of the peer name
check('STARTTLS with a self-signed certificate (matching host name): rejected by default, certificate verification failure', function() use ($port, $message) {
	$error = null;
	$lines = session(1, function() use ($port, $message, &$error) {
		$smtp = TµSmtp::factory("smtp+tls://localhost:$port?timeout=3");
		$error = exception(fn() => $smtp->set('id', ['from' => 's@x.com', 'recipients' => ['a@x.com'], 'message' => $message]));
		$smtp->disconnect();
	});
	return ($error instanceof \Temma\Exceptions\Database && str_contains($error->getMessage(), 'STARTTLS') &&
	        str_contains($error->getMessage(), 'certificate verify failed') && !str_contains($error->getMessage(), 'did not match') &&
	        in_array('TLS FAILED', $lines) && !in_array('DATA Hello', $lines));
});
check("STARTTLS with a self-signed certificate: accepted with the 'verify=0' option", function() use ($port, $message) {
	$lines = session(1, function() use ($port, $message) {
		$smtp = TµSmtp::factory("smtp+tls://127.0.0.1:$port?timeout=3&verify=0");
		$smtp->set('id', ['from' => 's@x.com', 'recipients' => ['a@x.com'], 'message' => $message]);
		$smtp->disconnect();
	});
	return (in_array('TLS OK', $lines) && in_array('DATA Hello', $lines));
});
check('authentication failure: exception, connection closed', function() use ($port, $message) {
	$error = null;
	$smtp = null;
	$lines = session(1, function() use ($port, $message, &$error, &$smtp) {
		$smtp = TµSmtp::factory("smtp://bad:secret@127.0.0.1:$port?timeout=3");
		$error = exception(fn() => $smtp->set('id', ['from' => 's@x.com', 'recipients' => ['a@x.com'], 'message' => $message]));
	});
	return ($error instanceof \Temma\Exceptions\Database && str_contains($error->getMessage(), '535') &&
	        (smtpProp($smtp, '_stream') === null) && in_array('QUIT', $lines));
});

print("\n" . TµAnsi::bold("Disabled data source\n"));
check('disabled data source: nothing sent, no exception (write(), set(), mWrite(), mSet())', function() use ($message) {
	$smtp = TµSmtp::factory('smtp://127.0.0.1:1')->disable();
	return ($smtp->write('id', $message, ['from' => 'a@x.com', 'recipients' => ['b@y.com']]) === false &&
	        $smtp->set('id', ['from' => 'a@x.com', 'recipients' => ['b@y.com'], 'message' => $message]) === false &&
	        $smtp->mWrite([$message]) === 0 && $smtp->mSet([['message' => $message]]) === 0);
});
check('enabled data source: mWrite() and mSet() are not available', function() {
	$smtp = TµSmtp::factory('smtp://127.0.0.1:1');
	return (exception(fn() => $smtp->mWrite(['x'])) instanceof \Temma\Exceptions\Database &&
	        exception(fn() => $smtp->mSet([['message' => 'x']])) instanceof \Temma\Exceptions\Database);
});

print("\n" . TµAnsi::bold("Secrets\n"));
check('password not written in the logs', function() use ($port) {
	global $logs;
	$logs = [];
	TµSmtp::factory("smtp+tls://user:Pa55w0rd@127.0.0.1:$port");
	return ($logs && !array_filter($logs, fn($log) => str_contains($log, 'Pa55w0rd')));
});
check('password not written in the exception message of an invalid DSN', function() {
	global $logs;
	$logs = [];
	$error = exception(fn() => TµSmtp::factory('smtpz://user:Pa55w0rd@host'));
	return ($error instanceof \Temma\Exceptions\Database && !str_contains($error->getMessage(), 'Pa55w0rd') &&
	        !array_filter($logs, fn($log) => str_contains($log, 'Pa55w0rd')));
});

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
