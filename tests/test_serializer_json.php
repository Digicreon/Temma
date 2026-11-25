#!/usr/bin/php
<?php

/**
 * Validation script for the JSON deserialization of the Serializer helper.
 * Checks the decoding of JSON streams with comments and trailing commas, the reading of JSON files,
 * and the error management.
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;
use \Temma\Utils\Serializer as TµSerializer;
use \Temma\Exceptions\IO as TµIOException;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');
\Temma\Base\Log::disable();
// PHP warnings and notices are converted to exceptions, so they make the tests fail
set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline) {
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});
// temporary directory for the JSON files
$tmpPath = sys_get_temp_dir() . '/temma-serializer-json-test-' . getmypid();
mkdir($tmpPath, 0755, true);
register_shutdown_function(function() use ($tmpPath) {
	exec('rm -rf ' . escapeshellarg($tmpPath));
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
 * Executes a function and returns the code of the thrown IO exception.
 * @param	callable	$function	The function to execute.
 * @return	?int	The code of the IO exception, or null if no IO exception was thrown.
 */
function ioExceptionCode(callable $function) : ?int {
	try {
		$function();
	} catch (TµIOException $e) {
		return ($e->getCode());
	}
	return (null);
}

/* ********** TESTS ********** */
print(TµAnsi::bold("Standard JSON\n"));
check("objects, lists and scalar values are decoded", function() {
	$json = '{"name": "Alice", "age": 28, "ratio": 1.5, "admin": true, "note": null, "tags": ["a", "b"], "empty": {}}';
	return (TµSerializer::decodeJson($json) === ['name' => 'Alice', 'age' => 28, 'ratio' => 1.5, 'admin' => true, 'note' => null, 'tags' => ['a', 'b'], 'empty' => []]);
});
check("scalar root values are decoded, including null", function() {
	return (TµSerializer::decodeJson('42') === 42 && TµSerializer::decodeJson('"text"') === 'text' &&
	        TµSerializer::decodeJson('null') === null && TµSerializer::decodeJson('false') === false);
});
check("a round trip with encodeJson() preserves the data", function() {
	$data = ['name' => 'Alice', 'list' => [1, 2.5, true, null], 'nested' => ['a' => ['b' => 'c']]];
	return (TµSerializer::decodeJson(TµSerializer::encodeJson($data)) === $data);
});

print("\n" . TµAnsi::bold("Comments\n"));
check("single-line and multi-line comments are ignored", function() {
	$json = "/* header\n * comment */\n{\n\t// first\n\t\"a\": 1, /* inline */ \"b\": 2 // end\n}\n";
	return (TµSerializer::decodeJson($json) === ['a' => 1, 'b' => 2]);
});
check("comment markers inside strings are kept", function() {
	$json = '{"url": "https://example.com/path", "text": "a /* b */ c", "esc": "x\\"// y"}';
	return (TµSerializer::decodeJson($json) === ['url' => 'https://example.com/path', 'text' => 'a /* b */ c', 'esc' => 'x"// y']);
});
check("comment markers inside strings with escape sequences are kept", function() {
	$json = '{"a": "line\\nnext // not a comment", "b": "tab\\t/* not a comment */"}';
	return (TµSerializer::decodeJson($json) === ['a' => "line\nnext // not a comment", 'b' => "tab\t/* not a comment */"]);
});

print("\n" . TµAnsi::bold("Trailing commas\n"));
check("a trailing comma at the end of a list is accepted", fn() => (TµSerializer::decodeJson('[1, 2, 3,]') === [1, 2, 3]));
check("a trailing comma at the end of an object is accepted", fn() => (TµSerializer::decodeJson('{"a": 1, "b": 2,}') === ['a' => 1, 'b' => 2]));
check("trailing commas followed by spaces, line breaks and comments are accepted", function() {
	$json = "{\n\t\"list\": [\n\t\t1,\n\t\t2, // last\n\t],\n\t\"b\": 2, /* end */\n}\n";
	return (TµSerializer::decodeJson($json) === ['list' => [1, 2], 'b' => 2]);
});
check("trailing commas in nested structures are accepted", fn() => (TµSerializer::decodeJson('{"a": [{"b": [1,],},],}') === ['a' => [['b' => [1]]]]));
check("commas followed by brackets inside strings are kept", function() {
	return (TµSerializer::decodeJson('{"a": "x,]", "b": "y, }",}') === ['a' => 'x,]', 'b' => 'y, }']);
});

print("\n" . TµAnsi::bold("Files\n"));
check("readJson() reads a JSON file with comments and trailing commas", function() use ($tmpPath) {
	file_put_contents("$tmpPath/data.json", "{\n\t// comment\n\t\"a\": [1, 2,],\n}\n");
	return (TµSerializer::readJson("$tmpPath/data.json") === ['a' => [1, 2]]);
});
check("reading a missing JSON file raises an IO exception (NOT_FOUND), without PHP warning",
      fn() => (ioExceptionCode(fn() => TµSerializer::readJson('/nonexistent/file.json')) === TµIOException::NOT_FOUND));

print("\n" . TµAnsi::bold("Errors\n"));
check("invalid JSON raises an IO exception (BAD_FORMAT)", function() {
	foreach (['{a: 1}', "{'a': 1}", '[1, 2', '', 'text', '/* unterminated comment'] as $json) {
		if (ioExceptionCode(fn() => TµSerializer::decodeJson($json)) !== TµIOException::BAD_FORMAT)
			return (false);
	}
	return (true);
});
check("a comma alone in a list or an object raises an IO exception (BAD_FORMAT)", function() {
	return (ioExceptionCode(fn() => TµSerializer::decodeJson('[,]')) === TµIOException::BAD_FORMAT &&
	        ioExceptionCode(fn() => TµSerializer::decodeJson('{ , }')) === TµIOException::BAD_FORMAT);
});
check("several trailing commas raise an IO exception (BAD_FORMAT)", function() {
	return (ioExceptionCode(fn() => TµSerializer::decodeJson('[1,,]')) === TµIOException::BAD_FORMAT &&
	        ioExceptionCode(fn() => TµSerializer::decodeJson('[1,,2]')) === TµIOException::BAD_FORMAT);
});

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
