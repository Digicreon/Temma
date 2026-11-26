#!/usr/bin/php
<?php

/**
 * Validation script for the Telegram data source.
 * Starts a local HTTP server (PHP built-in server) that simulates the Telegram API, then checks:
 * - the management of the responses (success, API error, non-JSON response) without PHP warning;
 * - the disabled data source;
 * - the bot token masked in the logs and exception messages.
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;

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

/* ********** FAKE TELEGRAM SERVER ********** */
$serverPath = sys_get_temp_dir() . '/temma-telegram-test-' . getmypid();
mkdir($serverPath, 0755, true);
// the response depends on the first part of the called path; the URL (with the token) is given in the HTML page
file_put_contents("$serverPath/router.php", <<<'EOT'
	<?php
	$prefix = explode('/', $_SERVER['REQUEST_URI'])[1] ?? '';
	[$code, $type, $body] = match ($prefix) {
		'ok'    => [200, 'application/json', json_encode(['ok' => true, 'result' => ['message_id' => 1, 'text' => $_POST['text'] ?? null]])],
		'error' => [400, 'application/json', json_encode(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found'])],
		'html'  => [502, 'text/html', '<html>Bad gateway for ' . $_SERVER['REQUEST_URI'] . '</html>'],
		'empty' => [200, 'application/json', ''],
		default => [404, 'text/plain', 'Not found'],
	};
	http_response_code($code);
	header("Content-Type: $type");
	print($body);
	EOT);
$port = rand(20000, 60000);
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", "$serverPath/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
register_shutdown_function(function() use ($server, $serverPath) {
	proc_terminate($server);
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
define('BASE_URL', "http://127.0.0.1:$port");

/* ********** TELEGRAM DATA SOURCES USING THE FAKE SERVER ********** */
/** Successful sending. */
class TelegramOk extends \Temma\Datasources\Telegram {
	/** URL of the API. */
	const string API_URL = BASE_URL . '/ok/bot%s/sendMessage';
}
/** Error returned by the API. */
class TelegramError extends \Temma\Datasources\Telegram {
	/** URL of the API. */
	const string API_URL = BASE_URL . '/error/bot%s/sendMessage';
}
/** HTML response (proxy error). */
class TelegramHtml extends \Temma\Datasources\Telegram {
	/** URL of the API. */
	const string API_URL = BASE_URL . '/html/bot%s/sendMessage';
}
/** Empty response. */
class TelegramEmpty extends \Temma\Datasources\Telegram {
	/** URL of the API. */
	const string API_URL = BASE_URL . '/empty/bot%s/sendMessage';
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

/* ********** TESTS ********** */
print(TµAnsi::bold("Telegram data source\n"));
check('message sent: set() and write() return true', function() {
	$telegram = new TelegramOk('123:TOKEN');
	return (($telegram->set('42', 'Hello') === true) && ($telegram->write('42', 'Hello') === true));
});
check("falsy message ('0') sent", function() {
	return ((new TelegramOk('123:TOKEN'))->set('42', '0') === true);
});
check('API error: exception with the error description', function() {
	$e = exception(fn() => (new TelegramError('123:TOKEN'))->write('42', 'Hello'));
	return ($e instanceof \Temma\Exceptions\Database && str_contains($e->getMessage(), 'chat not found'));
});
check('non-JSON response: exception (no PHP warning), token not written in the message', function() {
	$e = exception(fn() => (new TelegramHtml('123:SECRET-TOKEN'))->set('42', 'Hello'));
	return ($e instanceof \Temma\Exceptions\Database && str_contains($e->getMessage(), '502') && !str_contains($e->getMessage(), 'SECRET'));
});
check('empty response: exception (no PHP warning)', function() {
	return (exception(fn() => (new TelegramEmpty('123:TOKEN'))->set('42', 'Hello')) instanceof \Temma\Exceptions\Database);
});
check('non-scalar message: exception', function() {
	return (exception(fn() => (new TelegramOk('123:TOKEN'))->set('42', ['x'])) instanceof \Temma\Exceptions\Database);
});
check('disabled data source: set() and write() return false, mSet() and mWrite() return 0', function() {
	$telegram = (new TelegramError('123:TOKEN'))->disable();
	return (($telegram->set('42', 'Hello') === false) && ($telegram->write('42', 'Hello') === false) &&
	        $telegram->mSet(['42' => 'Hello']) === 0 && $telegram->mWrite(['42' => 'Hello']) === 0);
});
check('token not written in the logs and the exception messages', function() {
	global $logs;
	$logs = [];
	\Temma\Datasources\Telegram::factory('telegram://123:SECRET-TOKEN');
	$e = exception(fn() => \Temma\Datasources\Telegram::factory('telegrm://123:SECRET-TOKEN'));
	return ($logs && $e instanceof \Temma\Exceptions\Database && !str_contains($e->getMessage(), 'SECRET') &&
	        !array_filter($logs, fn($log) => str_contains($log, 'SECRET')));
});

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
