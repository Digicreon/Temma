#!/usr/bin/php
<?php

/**
 * Validation script for the notification data sources using an HTTP API (Google Chat, Slack, Discord, Pushover).
 * Starts a local HTTP server (PHP built-in server) that records the requests, then checks:
 * - Google Chat: payload of simple text messages and of rich messages (cards with header, pictogram and sections);
 *   messages without sections (text only, or header only) are accepted; invalid messages are rejected;
 * - Slack and Discord: payload of the messages;
 * - Pushover: the emergency priority sends the "retry" and "expire" parameters required by the API;
 * - disabled data sources send nothing and throw no exception (mSet() and mWrite() included).
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');
\Temma\Base\Log::disable();
// PHP warnings and notices (not silenced by '@') are converted to exceptions, so they make the tests fail
set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline) {
	if (!(error_reporting() & $errno))
		return (false);
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

/* ********** FAKE HTTP SERVER ********** */
$serverPath = sys_get_temp_dir() . '/temma-webhooks-test-' . getmypid();
mkdir($serverPath, 0755, true);
define('REQUEST_LOG', "$serverPath/requests.log");
// each request is appended to the log file (path, JSON body or POST parameters); the response depends on the path
file_put_contents("$serverPath/router.php", <<<'EOT'
	<?php
	$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
	$body = file_get_contents('php://input');
	$request = ['path' => $path, 'json' => json_decode($body, true), 'post' => $_POST];
	file_put_contents(__DIR__ . '/requests.log', json_encode($request) . "\n", FILE_APPEND | LOCK_EX);
	[$code, $type, $response] = match ($path) {
		'/googlechat'       => [200, 'application/json', json_encode(['name' => 'spaces/x/messages/y'])],
		'/googlechat-error' => [400, 'application/json', json_encode(['error' => ['code' => 400, 'message' => 'Invalid card']])],
		'/slack'            => [200, 'text/plain', 'ok'],
		'/discord'          => [204, 'text/plain', ''],
		'/pushover'         => [200, 'application/json', json_encode(['status' => 1, 'request' => 'x'])],
		default             => [404, 'text/plain', 'Not found'],
	};
	http_response_code($code);
	header("Content-Type: $type");
	print($response);
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

/** Pushover data source using the fake server. */
class PushoverTest extends \Temma\Datasources\Pushover {
	/** URL of the API. */
	const API_URL = BASE_URL . '/pushover';
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
/**
 * Returns the requests received by the fake server since the last call, and clears the list.
 * @return	array	List of requests (associative arrays with the keys 'path', 'json' and 'post').
 */
function requests() : array {
	if (!file_exists(REQUEST_LOG))
		return ([]);
	$lines = file(REQUEST_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	unlink(REQUEST_LOG);
	return (array_map(fn($line) => json_decode($line, true), $lines));
}

/* ********** GOOGLE CHAT ********** */
print(TµAnsi::bold("Google Chat\n"));
$gchat = new \Temma\Datasources\GoogleChat(BASE_URL . '/googlechat');
check('simple text message', function() use ($gchat) {
	requests();
	$result = $gchat->set('', 'Text with *simple* _formatting_.');
	$requests = requests();
	return ($result === true && count($requests) === 1 && $requests[0]['json'] === ['text' => 'Text with *simple* _formatting_.']);
});
check('rich message: header with title, subtitle and pictogram, sections', function() use ($gchat) {
	requests();
	$gchat[''] = [
		'title'    => 'A title',
		'subtitle' => 'A subtitle',
		'picto'    => 'https://example.com/picto.png',
		'sections' => [
			[
				'title' => 'First section',
				'html'  => 'Some <b>text</b>',
			],
			[
				'icon'        => 'STAR',
				'topLabel'    => 'Status',
				'html'        => 'OK',
				'bottomLabel' => 'Generated yesterday',
			],
			[
				'image' => 'https://example.com/image.png',
				'alt'   => 'Alternate text',
			],
			[
				'buttons' => ['Button' => 'https://example.com/'],
			],
		],
	];
	$card = requests()[0]['json']['cardsV2'][0]['card'] ?? [];
	return ($card['header'] === ['title' => 'A title', 'subtitle' => 'A subtitle', 'imageUrl' => 'https://example.com/picto.png'] &&
	        count($card['sections']) === 4 && $card['sections'][0]['header'] === 'First section' &&
	        $card['sections'][0]['widgets'][0]['textParagraph']['text'] === 'Some <b>text</b>' &&
	        $card['sections'][1]['widgets'][0]['decoratedText'] === ['startIcon' => ['knownIcon' => 'STAR'], 'topLabel' => 'Status',
	                                                                 'bottomLabel' => 'Generated yesterday', 'text' => 'OK'] &&
	        $card['sections'][2]['widgets'][0]['image'] === ['imageUrl' => 'https://example.com/image.png', 'altText' => 'Alternate text'] &&
	        $card['sections'][3]['widgets'][0]['buttonList']['buttons'][0]['onClick']['openLink']['url'] === 'https://example.com/');
});
check("rich message without sections: text only, header only, text with a card", function() use ($gchat) {
	requests();
	$gchat->set('', ['text' => 'Only a text']);
	$gchat->set('', ['title' => 'Only a header']);
	$gchat->set('', ['text' => 'Text', 'sections' => [['html' => 'Section']]]);
	$requests = requests();
	return (count($requests) === 3 && $requests[0]['json'] === ['text' => 'Only a text'] &&
	        $requests[1]['json'] === ['cardsV2' => [['card' => ['header' => ['title' => 'Only a header']]]]] &&
	        $requests[2]['json']['text'] === 'Text' &&
	        $requests[2]['json']['cardsV2'][0]['card']['sections'][0]['widgets'][0]['textParagraph']['text'] === 'Section');
});
check('invalid messages (empty array, number, null): exception, nothing sent', function() use ($gchat) {
	requests();
	foreach ([[], ['foo' => 'bar'], 12, null] as $message) {
		if (!(exception(fn() => $gchat->set('', $message)) instanceof \Temma\Exceptions\Database))
			return (false);
	}
	return (requests() === []);
});
check('error returned by Google Chat: exception', function() {
	$error = exception(fn() => (new \Temma\Datasources\GoogleChat(BASE_URL . '/googlechat-error'))->set('', 'Hello'));
	return ($error instanceof \Temma\Exceptions\Database && str_contains($error->getMessage(), 'Invalid card'));
});

/* ********** SLACK AND DISCORD ********** */
print("\n" . TµAnsi::bold("Slack and Discord\n"));
check('Slack: text and attachments', function() {
	requests();
	$slack = new \Temma\Datasources\Slack(BASE_URL . '/slack');
	$result = $slack->set('', [
		'text'        => 'Text',
		'attachments' => [
			['pretext' => 'Pretext', 'text' => 'Attachment text', 'image' => 'https://example.com/i.png', 'footer' => 'Footer'],
			'Text-only attachment',
		],
	]);
	$json = requests()[0]['json'] ?? [];
	return ($result === true && $json['text'] === 'Text' &&
	        $json['attachments'] === [['pretext' => 'Pretext', 'text' => 'Attachment text', 'image_url' => 'https://example.com/i.png',
	                                   'footer' => 'Footer'], ['text' => 'Text-only attachment']]);
});
check('Discord: text message', function() {
	requests();
	$result = (new \Temma\Datasources\Discord(BASE_URL . '/discord'))->write('', 'Hello');
	return ($result === true && (requests()[0]['json'] ?? null) === ['content' => 'Hello']);
});

/* ********** PUSHOVER ********** */
print("\n" . TµAnsi::bold("Pushover\n"));
check('emergency priority: default "retry" and "expire" parameters', function() {
	requests();
	$result = (new PushoverTest('TOKEN'))->write('USER', 'Alert', ['priority' => 2]);
	$post = requests()[0]['post'] ?? [];
	return ($result === true && $post['priority'] === '2' && $post['retry'] === '60' && $post['expire'] === '3600');
});
check('emergency priority: "retry" and "expire" options, limited to the API ranges', function() {
	requests();
	$push = new PushoverTest('TOKEN');
	$push->write('USER', 'Alert', ['priority' => 2, 'retry' => 120, 'expire' => 600]);
	$push->write('USER', 'Alert', ['priority' => 2, 'retry' => 5, 'expire' => 99999]);
	$requests = requests();
	return ($requests[0]['post']['retry'] === '120' && $requests[0]['post']['expire'] === '600' &&
	        $requests[1]['post']['retry'] === '30' && $requests[1]['post']['expire'] === '10800');
});
check('other priorities: no "retry" and "expire" parameters', function() {
	requests();
	(new PushoverTest('TOKEN'))->write('USER', 'Message', ['priority' => 1, 'retry' => 60, 'expire' => 600]);
	$post = requests()[0]['post'] ?? [];
	return ($post['priority'] === '1' && !isset($post['retry']) && !isset($post['expire']) && $post['message'] === 'Message');
});

/* ********** DISABLED DATA SOURCES ********** */
print("\n" . TµAnsi::bold("Disabled data sources\n"));
check('nothing sent, no exception (write(), set(), mWrite(), mSet())', function() {
	requests();
	$sources = [
		(new \Temma\Datasources\GoogleChat(BASE_URL . '/googlechat'))->disable(),
		(new \Temma\Datasources\Slack(BASE_URL . '/slack'))->disable(),
		(new \Temma\Datasources\Discord(BASE_URL . '/discord'))->disable(),
		(new PushoverTest('TOKEN'))->disable(),
	];
	foreach ($sources as $source) {
		if ($source->write('', 'Hello') !== false || $source->set('', 'Hello') !== false ||
		    $source->mWrite(['x' => 'Hello']) !== 0 || $source->mSet(['x' => 'Hello']) !== 0)
			return (false);
	}
	return (requests() === []);
});

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
