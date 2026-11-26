#!/usr/bin/php
<?php

/**
 * Validation script for the Sqs data source. No request is sent to AWS: the SQS client uses a mock handler.
 * - DSN parsing (long polling wait duration, limited to 20 seconds; secret key masked in exception messages);
 * - HTTP timeouts of the SQS client (greater than the long polling wait duration);
 * - count() reads the "Attributes" of the queue, and keeps the original exception;
 * - write(), set() and "$sqs[] = ..." send the message body;
 * - mWrite() accepts an empty list, sends the messages by batches of 10, ignores null values and rejects
 *   values which are not strings or numbers;
 * - mSet() JSON-encodes the values and ignores null values;
 * - a disabled data source sends and receives nothing;
 * - aws.phar is not loaded if the SQS client class is already available;
 * - get() decodes "falsy" payloads ("0", "false", "\"\"");
 * - read() accepts a wait duration of 0 seconds, limits it to 20 seconds, returns null on an empty queue
 *   and doesn't hide errors;
 * - read() returns the number of receptions of the message;
 * - changeVisibility() and stats();
 * - Asynk changes the visibility timeout of the message of a failed task if retries are enabled, and can only count
 *   the tasks.
 *
 * The AWS SDK (aws.phar) must be in the include path (e.g. in the "lib" directory), otherwise the tests are skipped.
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');
\Temma\Base\Log::disable();
if (!stream_resolve_include_path('aws.phar')) {
	print(TµAnsi::color('yellow', 'The AWS SDK (aws.phar) is not in the include path, tests skipped.') . "\n");
	exit(0);
}
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
/**
 * Create an Sqs object whose client uses a mock handler.
 * @param	array	$requests	Reference to the list of sent requests (each one is an array with the command name and its parameters).
 * @param	array	$results	List of results returned by the mock handler, in the order of the requests. Each result is an
 *					associative array, or a closure which receives the command and returns an exception.
 * @param	string	$dsn		(optional) DSN.
 * @return	\Temma\Datasources\Sqs	The created object.
 */
function mockedSqs(array &$requests, array $results, string $dsn='sqs://KEY:SECRET@sqs.eu-west-3.amazonaws.com/123456789012/queue') : \Temma\Datasources\Sqs {
	$sqs = \Temma\Datasources\Sqs::factory($dsn);
	$mock = new \Aws\MockHandler();
	foreach ($results as $result) {
		$mock->append(function(\Aws\CommandInterface $command) use (&$requests, $result) {
			$requests[] = [$command->getName(), $command->toArray()];
			if ($result instanceof \Closure)
				return ($result($command));
			return (new \Aws\Result($result));
		});
	}
	// the client created by the data source is used, with the mock handler instead of the HTTP handler
	$sqs->connect();
	property($sqs, '_sqsClient')->getHandlerList()->setHandler($mock);
	return ($sqs);
}

/* ********** TESTS ********** */
print(TµAnsi::bold("Sqs data source\n"));
check('The long polling wait duration is read from the DSN', function() {
	$sqs = \Temma\Datasources\Sqs::factory('sqs://KEY:SECRET@sqs.eu-west-3.amazonaws.com/123456789012/queue#20');
	$sqs2 = \Temma\Datasources\Sqs::factory('sqs://KEY:SECRET@sqs.eu-west-3.amazonaws.com/123456789012/queue');
	return ((property($sqs, '_waitPolling') === 20) &&
	        (property($sqs, '_url') === 'https://sqs.eu-west-3.amazonaws.com/123456789012/queue') &&
	        (property($sqs2, '_waitPolling') === null) &&
	        (property($sqs2, '_url') === 'https://sqs.eu-west-3.amazonaws.com/123456789012/queue'));
});
check('The long polling wait duration of the DSN is limited to 20 seconds', function() {
	$sqs = \Temma\Datasources\Sqs::factory('sqs://KEY:SECRET@sqs.eu-west-3.amazonaws.com/123456789012/queue#30');
	return (property($sqs, '_waitPolling') === 20);
});
check('The secret key is not written in the exception message of an invalid DSN', function() {
	try {
		\Temma\Datasources\Sqs::factory('sqs://KEY:VERYSECRET@sqs.eu-west-3.example.com/123456789012/queue');
	} catch (\Temma\Exceptions\Database $e) {
		return (!str_contains($e->getMessage(), 'VERYSECRET'));
	}
	return (false);
});
check('The HTTP requests have a connection timeout and a timeout greater than the long polling wait duration', function() {
	$requests = [];
	$sqs = mockedSqs($requests, [['Messages' => []]], 'sqs://KEY:SECRET@sqs.eu-west-3.amazonaws.com/123456789012/queue#20');
	$sqs->read('');
	$http = $requests[0][1]['@http'] ?? [];
	return ((($http['connect_timeout'] ?? 0) > 0) && (($http['timeout'] ?? 0) > 20));
});
check('count() returns the approximate number of messages', function() {
	$requests = [];
	$sqs = mockedSqs($requests, [['Attributes' => ['ApproximateNumberOfMessages' => '42']]]);
	return ($sqs->count() === 42);
});
check('count() throws a Database exception which keeps the original exception', function() {
	$requests = [];
	$sqs = mockedSqs($requests, [fn($command) => new \Aws\Sqs\Exception\SqsException('Access denied', $command)]);
	try {
		$sqs->count();
	} catch (\Temma\Exceptions\Database $e) {
		return ($e->getPrevious() instanceof \Aws\Sqs\Exception\SqsException);
	}
	return (false);
});
check('write() sends the raw message body', function() {
	$requests = [];
	$sqs = mockedSqs($requests, [['MessageId' => 'msg-1']]);
	$id = $sqs->write('', 'raw message');
	return ($id === 'msg-1' && $requests[0][0] === 'SendMessage' && $requests[0][1]['MessageBody'] === 'raw message');
});
check('set() and "$sqs[] = ..." send the JSON-encoded message body', function() {
	$requests = [];
	$sqs = mockedSqs($requests, [['MessageId' => 'msg-1'], ['MessageId' => 'msg-2']]);
	$id = $sqs->set('', ['a' => 1]);
	$sqs[] = 0;
	return ($id === 'msg-1' && (count($requests) === 2) &&
	        $requests[0][0] === 'SendMessage' && $requests[0][1]['MessageBody'] === '{"a":1}' &&
	        $requests[1][0] === 'SendMessage' && $requests[1][1]['MessageBody'] === '0');
});
check('mWrite() with an empty list sends nothing', function() {
	$requests = [];
	$sqs = mockedSqs($requests, []);
	return (($sqs->mWrite([]) === 0) && ($sqs->mWrite([null]) === 0) && $requests === []);
});
check('mWrite() sends the messages by batches of 10', function() {
	$requests = [];
	$successful = fn(int $nbr) => ['Successful' => array_fill(0, $nbr, ['Id' => '0', 'MessageId' => 'x', 'MD5OfMessageBody' => 'x'])];
	$sqs = mockedSqs($requests, [$successful(10), $successful(10), $successful(5)]);
	$messages = [];
	for ($i = 0; $i < 25; $i++)
		$messages["key $i"] = "message $i";
	$sent = $sqs->mWrite($messages);
	$sizes = array_map(fn($request) => count($request[1]['Entries']), $requests);
	$bodies = array_merge(...array_map(fn($request) => array_column($request[1]['Entries'], 'MessageBody'), $requests));
	return ($sent === 25 && ($sizes === [10, 10, 5]) && ($bodies === array_values($messages)));
});
check('mWrite() ignores null values (no message is removed) and converts numbers to strings', function() {
	$requests = [];
	$sqs = mockedSqs($requests, [['Successful' => [['Id' => '0'], ['Id' => '1']]]]);
	$sent = $sqs->mWrite(['first', null, 2]);
	return ($sent === 2 && (count($requests) === 1) && $requests[0][0] === 'SendMessageBatch' &&
	        (array_column($requests[0][1]['Entries'], 'MessageBody') === ['first', '2']) &&
	        (array_column($requests[0][1]['Entries'], 'Id') === ['0', '1']));
});
check('mWrite() rejects values which are not strings or numbers, without sending anything', function() {
	$requests = [];
	$sqs = mockedSqs($requests, [['Successful' => [['Id' => '0']]]]);
	try {
		$sqs->mWrite(['first', ['array']]);
	} catch (\Temma\Exceptions\Database $e) {
		return ($requests === []);
	}
	return (false);
});
check('mSet() JSON-encodes the values and ignores null values (no message is removed)', function() {
	$requests = [];
	$sqs = mockedSqs($requests, [['Successful' => [['Id' => '0'], ['Id' => '1']]]]);
	$sent = $sqs->mSet(['first', null, ['x' => 1]]);
	return ($sent === 2 && (count($requests) === 1) && $requests[0][0] === 'SendMessageBatch' &&
	        (array_column($requests[0][1]['Entries'], 'MessageBody') === ['"first"', '{"x":1}']));
});
check('A disabled data source sends and receives nothing', function() {
	$requests = [];
	$sqs = mockedSqs($requests, []);
	$sqs->disable();
	$sqs->remove('x');
	$sqs->flush();
	$sqs->changeVisibility('x', 10);
	return ($sqs->read('') === null && $sqs->get('') === null && $sqs->write('', 'x') === null &&
	        $sqs->set('', 'x') === null && $sqs->mWrite(['x']) === 0 && $sqs->mSet(['x']) === 0 &&
	        $sqs->count() === 0 && $sqs->stats() === null && $requests === []);
});
check('The AWS SDK is not loaded if the SQS client class already exists', function() {
	// a separate process, without aws.phar in the include path, where the SQS client class is already defined
	$lib = var_export(realpath(__DIR__ . '/../lib'), true);
	$code = 'namespace Aws\\Sqs { class SqsClient {} } namespace { require(' . $lib . ' . "/Temma/Base/Autoload.php"); ' .
	        '\\Temma\\Base\\Autoload::autoload(' . $lib . '); ' .
	        'print(class_exists("\\Temma\\Datasources\\Sqs") ? "ok" : "ko"); }';
	$output = shell_exec(escapeshellarg(PHP_BINARY) . ' -d include_path=/nonexistent -r ' . escapeshellarg($code) . ' 2>&1');
	return ($output === 'ok');
});
check('get() decodes falsy payloads', function() {
	$requests = [];
	$results = [];
	foreach (['0', 'false', '""', '[]'] as $body)
		$results[] = ['Messages' => [['MessageId' => 'id', 'ReceiptHandle' => 'handle', 'Body' => $body]]];
	$sqs = mockedSqs($requests, $results);
	return (($sqs->get('')['data'] === 0) && ($sqs->get('')['data'] === false) &&
	        ($sqs->get('')['data'] === '') && ($sqs->get('')['data'] === []));
});
check('read() uses the wait duration of the DSN without option, or with a 0 option', function() {
	$requests = [];
	$results = [['Messages' => []], ['Messages' => []]];
	$sqs = mockedSqs($requests, $results, 'sqs://KEY:SECRET@sqs.eu-west-3.amazonaws.com/123456789012/queue#15');
	return (($sqs->read('', null, 0) === null) && ($sqs->read('') === null) &&
	        $requests[0][1]['WaitTimeSeconds'] === 15 && $requests[1][1]['WaitTimeSeconds'] === 15);
});
check('read() limits the wait duration to 20 seconds, and returns null on an empty queue', function() {
	$requests = [];
	$sqs = mockedSqs($requests, [[], ['Messages' => []]]);
	return (($sqs->read('', null, 60) === null) && ($sqs->get('', null, -5) === null) &&
	        $requests[0][1]['WaitTimeSeconds'] === 20 && $requests[1][1]['WaitTimeSeconds'] === 0);
});
check("read() and get() don't hide errors (e.g. bad credentials)", function() {
	$requests = [];
	$error = fn($command) => new \Aws\Sqs\Exception\SqsException('The security token included in the request is invalid', $command);
	$sqs = mockedSqs($requests, [$error, $error]);
	$thrown = 0;
	foreach (['read', 'get'] as $method) {
		try {
			$sqs->$method('');
		} catch (\Aws\Sqs\Exception\SqsException $e) {
			$thrown++;
		}
	}
	return ($thrown === 2);
});
check('remove() uses the receipt handle, and throws a Database exception with an invalid identifier', function() {
	$requests = [];
	$sqs = mockedSqs($requests, [[]]);
	$sqs->remove(json_encode(['id', 'handle']));
	try {
		$sqs->remove('invalid');
	} catch (\Temma\Exceptions\Database $e) {
		return ($requests[0][0] === 'DeleteMessage' && $requests[0][1]['ReceiptHandle'] === 'handle');
	}
	return (false);
});
check('read() asks for the number of receptions, and returns it', function() {
	$requests = [];
	$sqs = mockedSqs($requests, [
		['Messages' => [['MessageId' => 'id', 'ReceiptHandle' => 'handle', 'Body' => '{}', 'Attributes' => ['ApproximateReceiveCount' => '3']]]],
		['Messages' => [['MessageId' => 'id', 'ReceiptHandle' => 'handle', 'Body' => 'raw']]],
	]);
	$first = $sqs->get('');
	$second = $sqs->read('');
	return ($requests[0][1]['AttributeNames'] === ['ApproximateReceiveCount'] && $first['receiveCount'] === 3 &&
	        $first['data'] === [] && $second === ['id' => json_encode(['id', 'handle']), 'data' => 'raw', 'receiveCount' => 1]);
});
check('changeVisibility() uses the receipt handle and limits the timeout to 12 hours', function() {
	$requests = [];
	$sqs = mockedSqs($requests, [[], [], []]);
	$sqs->changeVisibility(json_encode(['id', 'handle']), 120);
	$sqs->changeVisibility(json_encode(['id', 'handle']), 86400);
	$sqs->changeVisibility(json_encode(['id', 'handle']), -5);
	try {
		$sqs->changeVisibility('invalid', 10);
	} catch (\Temma\Exceptions\Database $e) {
		return ($requests[0][0] === 'ChangeMessageVisibility' && $requests[0][1]['ReceiptHandle'] === 'handle' &&
		        array_map(fn($request) => $request[1]['VisibilityTimeout'], $requests) === [120, 43200, 0]);
	}
	return (false);
});
check('stats() returns the approximate number of waiting, in-flight and delayed messages', function() {
	$requests = [];
	$sqs = mockedSqs($requests, [['Attributes' => [
		'ApproximateNumberOfMessages'           => '5',
		'ApproximateNumberOfMessagesNotVisible' => '2',
		'ApproximateNumberOfMessagesDelayed'    => '1',
	]]]);
	return ($sqs->stats() === ['waiting' => 5, 'inFlight' => 2, 'delayed' => 1] &&
	        count($requests[0][1]['AttributeNames']) === 3);
});
/**
 * Create an Asynk DAO using an SQS data source.
 * @param	\Temma\Datasources\Sqs	$sqs		Data source.
 * @param	?array			$retries	(optional) Delays between attempts.
 * @return	\Temma\Asynk\AsynkDao	The DAO.
 */
function sqsAsynkDao(\Temma\Datasources\Sqs $sqs, ?array $retries=null) : \Temma\Asynk\AsynkDao {
	$asynkDao = (new \ReflectionClass('\Temma\Asynk\AsynkDao'))->newInstanceWithoutConstructor();
	(new \ReflectionProperty($asynkDao, '_transport'))->setValue($asynkDao, $sqs);
	(new \ReflectionProperty($asynkDao, '_transportClass'))->setValue($asynkDao, 'Temma\Datasources\Sqs');
	(new \ReflectionProperty($asynkDao, '_retries'))->setValue($asynkDao, $retries);
	return ($asynkDao);
}
check('Asynk changes the visibility timeout of a failed task (12 hours at most), until the last attempt', function() {
	$requests = [];
	$message = fn($count) => ['Messages' => [['MessageId' => 'id', 'ReceiptHandle' => "handle$count", 'Body' => '{"target":"object","action":"method","data":[]}',
	                                         'Attributes' => ['ApproximateReceiveCount' => (string)$count]]]];
	$sqs = mockedSqs($requests, [$message(1), [], $message(2), [], $message(3), $message(4)]);
	$asynkDao = sqsAsynkDao($sqs, [60, 86400, 120]);
	$task = $asynkDao->getNextTask();
	$first = $asynkDao->invalidateFetchedTask($task, 'Failure');
	$second = $asynkDao->invalidateFetchedTask($asynkDao->getNextTask(), 'Failure');
	$task = $asynkDao->getNextTask();
	$noRetry = $asynkDao->invalidateFetchedTask($task, 'Error', false);
	$last = $asynkDao->invalidateFetchedTask($asynkDao->getNextTask(), 'Failure');
	$visibility = array_values(array_filter($requests, fn($request) => $request[0] == 'ChangeMessageVisibility'));
	return ($task['attempts'] === 2 && $task['target'] === 'object' && $first === 60 && $second === 43200 && $noRetry === null && $last === null &&
	        count($visibility) === 2 && $visibility[0][1]['ReceiptHandle'] === 'handle1' && $visibility[1][1]['VisibilityTimeout'] === 43200);
});
check('Asynk can only count the SQS tasks', function() {
	$requests = [];
	$sqs = mockedSqs($requests, [['Attributes' => ['ApproximateNumberOfMessages' => '4']]]);
	$asynkDao = sqsAsynkDao($sqs);
	if ($asynkDao->getStats() !== ['waiting' => 4, 'delayed' => 0, 'processing' => 0, 'failed' => null] ||
	    $asynkDao->listTasks() !== null || $asynkDao->listTasks('failed') !== null)
		return (false);
	foreach (['getTask', 'retryTask', 'removeTask'] as $method) {
		try {
			$asynkDao->$method('id');
			return (false);
		} catch (\Temma\Exceptions\Application $e) {
		}
	}
	return (true);
});

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
