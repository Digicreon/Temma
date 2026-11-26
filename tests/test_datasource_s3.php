#!/usr/bin/php
<?php

/**
 * Validation script for the S3 data source. No request is sent to AWS: the S3 client uses a mock handler.
 * - mRemove() sends DeleteObjects requests (by batches of 1000 keys), sends nothing for an empty list, and raises
 *   an IO exception if some files couldn't be removed;
 * - flush() lists the files and removes them by batches;
 * - clear() with an empty prefix doesn't remove anything (no request), clear() with a prefix removes the matching files;
 * - getUrl() returns a pre-signed URL, and throws the exception if the URL can't be created (instead of a TypeError);
 * - read() returns the raw content as a string; set() always uses the 'application/json' MIME type;
 *   write() and copyTo() use the given MIME type and access;
 * - a disabled data source behaves like an empty one, and sends no request.
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
// temporary directory for the local files
$tmpPath = sys_get_temp_dir() . '/temma-s3-test-' . getmypid();
mkdir($tmpPath, 0755, true);
register_shutdown_function(function() use ($tmpPath) {
	exec('rm -rf ' . escapeshellarg($tmpPath));
});
file_put_contents("$tmpPath/source.txt", 'Local content');

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
 * Read or write the value of a private property.
 * @param	object	$object	The object.
 * @param	string	$name	Name of the property.
 * @param	mixed	$value	(optional) New value of the property.
 * @return	mixed	The property's value.
 */
function property(object $object, string $name, mixed $value=null) : mixed {
	$property = new \ReflectionProperty($object, $name);
	if (func_num_args() > 2)
		$property->setValue($object, $value);
	return ($property->getValue($object));
}
/**
 * Create an S3 object whose client uses a mock handler.
 * @param	array	$requests	Reference to the list of sent requests (each one is an array with the command name and its parameters).
 * @param	array	$results	List of results returned by the mock handler, in the order of the requests. Each result is an
 *					associative array, or a closure which receives the command and returns a result or an exception.
 *					If more requests are sent, the mock handler raises an exception.
 * @return	\Temma\Datasources\S3	The created object.
 */
function mockedS3(array &$requests, array $results) : \Temma\Datasources\S3 {
	$s3 = \Temma\Datasources\S3::factory('s3://KEY:SECRET@eu-west-3/bucket');
	$mock = new \Aws\MockHandler();
	foreach ($results as $result) {
		$mock->append(function(\Aws\CommandInterface $command) use (&$requests, $result) {
			// the streams are read immediately, because they are closed after the request
			$params = $command->toArray();
			if (($params['Body'] ?? null) instanceof \Psr\Http\Message\StreamInterface)
				$params['Body'] = (string)$params['Body'];
			$requests[] = [$command->getName(), $params];
			if ($result instanceof \Closure)
				return ($result($command));
			return (new \Aws\Result($result));
		});
	}
	// the client created by the data source is used, with the mock handler instead of the HTTP handler
	$s3->connect();
	property($s3, '_s3Client')->getHandlerList()->setHandler($mock);
	return ($s3);
}
/**
 * Returns the keys of a DeleteObjects request.
 * @param	array	$request	The request (command name and parameters).
 * @return	array	The list of keys.
 */
function deletedKeys(array $request) : array {
	return (array_column($request[1]['Delete']['Objects'], 'Key'));
}

/* ********** TESTS ********** */
print(TµAnsi::bold("S3 data source\n"));
// mRemove()
check('mRemove() sends a DeleteObjects request', function() {
	$requests = [];
	$s3 = mockedS3($requests, [[]]);
	$s3->mRemove(['a.txt', 'dir/b.txt']);
	return (count($requests) == 1 && $requests[0][0] == 'DeleteObjects' && $requests[0][1]['Bucket'] == 'bucket' &&
	        deletedKeys($requests[0]) === ['a.txt', 'dir/b.txt']);
});
check('mRemove() sends the keys by batches of 1000', function() {
	$requests = [];
	$s3 = mockedS3($requests, [[], [], []]);
	$keys = [];
	for ($i = 0; $i < 2500; $i++)
		$keys[] = "file-$i.txt";
	$s3->mRemove($keys);
	return (count($requests) == 3 && count(deletedKeys($requests[0])) == 1000 && count(deletedKeys($requests[1])) == 1000 &&
	        deletedKeys($requests[2]) === array_slice($keys, 2000));
});
check('mRemove() with an empty list sends no request', function() {
	$requests = [];
	$s3 = mockedS3($requests, []);
	$s3->mRemove([]);
	return ($requests === []);
});
check('mRemove() raises an IO exception if some files could not be removed, after all the batches', function() {
	$requests = [];
	$s3 = mockedS3($requests, [['Errors' => [['Key' => 'a.txt', 'Code' => 'AccessDenied']]], []]);
	$keys = [];
	for ($i = 0; $i < 1001; $i++)
		$keys[] = "file-$i.txt";
	try {
		$s3->mRemove($keys);
	} catch (\Temma\Exceptions\IO $e) {
		return (count($requests) == 2 && str_contains($e->getMessage(), 'a.txt (AccessDenied)'));
	}
	return (false);
});
// clear() and flush()
check('clear() with an empty prefix sends no request', function() {
	$requests = [];
	$s3 = mockedS3($requests, []);
	$s3->clear('');
	return ($requests === []);
});
check('clear() with a prefix removes the matching files', function() {
	$requests = [];
	$s3 = mockedS3($requests, [['Contents' => [['Key' => 'dir/a.txt'], ['Key' => 'dir/b.txt']]], []]);
	$s3->clear('dir/');
	return (count($requests) == 2 && $requests[0][0] == 'ListObjects' && $requests[0][1]['Prefix'] == 'dir/' &&
	        $requests[1][0] == 'DeleteObjects' && deletedKeys($requests[1]) === ['dir/a.txt', 'dir/b.txt']);
});
check('flush() lists all the files and removes them with DeleteObjects requests', function() {
	$requests = [];
	$s3 = mockedS3($requests, [['Contents' => [['Key' => 'a.txt'], ['Key' => 'dir/b.txt']]], []]);
	$s3->flush();
	return (count($requests) == 2 && $requests[0][0] == 'ListObjects' && !isset($requests[0][1]['Prefix']) &&
	        $requests[1][0] == 'DeleteObjects' && deletedKeys($requests[1]) === ['a.txt', 'dir/b.txt']);
});
// getUrl()
check('getUrl() returns a pre-signed URL, without sending any request', function() {
	$requests = [];
	$s3 = mockedS3($requests, []);
	$url = $s3->getUrl('dir/file.pdf');
	return ($requests === [] && str_contains($url, 'bucket') && str_contains($url, 'dir/file.pdf') && str_contains($url, 'X-Amz-Signature='));
});
check('getUrl() throws the exception if the URL can\'t be created (no TypeError)', function() {
	$requests = [];
	$s3 = mockedS3($requests, []);
	property($s3, '_s3Client', new class {
		/**
		 * Always throws an exception.
		 * @param	string	$name	Command name.
		 * @param	array	$args	Parameters.
		 * @throws	\RuntimeException	Always.
		 */
		public function getCommand(string $name, array $args=[]) : void {
			throw new \RuntimeException('Presign failure');
		}
	});
	try {
		$s3->getUrl('file.pdf');
	} catch (\RuntimeException $e) {
		return ($e->getMessage() == 'Presign failure');
	}
	return (false);
});
// read and write
check('read() returns the raw content as a string, get() decodes it', function() {
	$requests = [];
	$s3 = mockedS3($requests, [['Body' => \GuzzleHttp\Psr7\Utils::streamFor('{"a":1}')],
	                           ['Body' => \GuzzleHttp\Psr7\Utils::streamFor('{"a":1}')]]);
	return ($s3->read('data.json') === '{"a":1}' && $s3->get('data.json') === ['a' => 1]);
});
check('set() always uses the application/json MIME type (a MIME type string is ignored), and the access option', function() {
	$requests = [];
	$s3 = mockedS3($requests, [[], [], []]);
	$s3->set('a.json', ['x' => 1]);
	$s3->set('b.json', 'text', 'application/pdf');
	$s3->set('c.json', 'text', ['public' => true, 'mimetype' => 'application/pdf']);
	return (count($requests) == 3 && $requests[0][1]['ContentType'] == 'application/json' && $requests[0][1]['Body'] == '{"x":1}' &&
	        $requests[0][1]['ACL'] == 'private' && $requests[1][1]['ContentType'] == 'application/json' &&
	        $requests[2][1]['ContentType'] == 'application/json' && $requests[2][1]['ACL'] == 'public-read');
});
check('write() and copyTo() use the given MIME type and access, application/octet-stream by default', function() use ($tmpPath) {
	$requests = [];
	$s3 = mockedS3($requests, [[], [], [], []]);
	$s3->write('a.bin', 'raw');
	$s3->write('a.pdf', 'raw', 'application/pdf');
	$s3->copyTo('b.pdf', "$tmpPath/source.txt", true);
	$s3->copyTo('c.pdf', "$tmpPath/source.txt", ['public' => true, 'mimetype' => 'application/pdf']);
	return (count($requests) == 4 && $requests[0][1]['ContentType'] == 'application/octet-stream' &&
	        $requests[1][1]['ContentType'] == 'application/pdf' && $requests[1][1]['ACL'] == 'private' &&
	        $requests[2][1]['ACL'] == 'public-read' && $requests[2][1]['Body'] === 'Local content' &&
	        $requests[3][1]['ContentType'] == 'application/pdf' && $requests[3][1]['ACL'] == 'public-read');
});
// disabled data source
check('disabled: behaves like an empty data source, writings are ignored, no request is sent', function() use ($tmpPath) {
	$requests = [];
	$s3 = mockedS3($requests, []);
	$s3->disable();
	$ok = ($s3->isSet('a') === false && count($s3) === 0 && $s3->find('') === [] && $s3->search('', true) === [] &&
	       $s3->read('a') === null && $s3->read('a', 'default') === 'default' && $s3->read('a', fn() => 'cb') === 'cb' &&
	       $s3->get('a', fn() => ['cb']) === ['cb'] && $s3['a'] === null &&
	       $s3->mRead(['a', 'b']) === ['a' => null, 'b' => null] && $s3->mGet(['a']) === ['a' => null] &&
	       $s3->copyFrom('a', "$tmpPath/copy.txt") === false && !file_exists("$tmpPath/copy.txt") &&
	       $s3->copyFrom('a', "$tmpPath/copy.txt", '0') === true && file_get_contents("$tmpPath/copy.txt") === '0' &&
	       $s3->mCopyFrom(['a' => "$tmpPath/copy2.txt"]) === 0 &&
	       $s3->write('a', 'v') === false && $s3->set('a', 'v') === false && $s3->set('a', null) === false &&
	       $s3->copyTo('a', "$tmpPath/source.txt") === false && $s3->mWrite(['a' => 'v']) === 0 &&
	       $s3->mSet(['a' => 'v']) === 0 && $s3->mCopyTo(['a' => "$tmpPath/source.txt"]) === 0 && $s3->getUrl('a') === '');
	$s3->remove('a');
	$s3->mRemove(['a']);
	$s3->clear('a');
	$s3->flush();
	unset($s3['a']);
	return ($ok && $requests === []);
});

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
