#!/usr/bin/php
<?php

/**
 * Validation script for the format detection of the Serializer helper (decode() method with several types).
 * The YAML tests need the yaml extension; the NEON tests need the nette/neon library (they are skipped otherwise).
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
$hasYaml = extension_loaded('yaml');
$hasNeon = class_exists('\Nette\Neon\Neon');

/* ********** HELPERS ********** */
$count = 0;
$failed = 0;
$skipped = 0;
/**
 * Executes a test and displays its result.
 * @param	string		$label		Description of the test.
 * @param	callable	$test		Function which returns true if the test is successful.
 *						If it throws an exception, the test fails and the exception is displayed.
 * @param	bool		$enabled	(optional) False to skip the test. Defaults to true.
 */
function check(string $label, callable $test, bool $enabled=true) : void {
	global $count, $failed, $skipped;
	$count++;
	if (!$enabled) {
		$skipped++;
		print(TµAnsi::faint(sprintf('%02d', $count)) . ' ' . TµAnsi::color('yellow', '--') . " $label (skipped)\n");
		return;
	}
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
print(TµAnsi::bold("Detection with the default list of types\n"));
check("a PHP stream (starting with '<?php') is detected", function() {
	return (TµSerializer::decode("<?php\nreturn ['a' => 1];") === ['a' => 1]);
});
check("an XML stream is detected, with or without XML declaration", function() {
	return (TµSerializer::decode('<?xml version="1.0"?><data><name>Alice</name></data>') === ['name' => 'Alice'] &&
	        TµSerializer::decode("\n  <data><name>Alice</name></data>") === ['name' => 'Alice']);
});
check("a JSON stream is detected (object, list, with comments and trailing commas)", function() {
	return (TµSerializer::decode('{"name": "Alice", "age": 28}') === ['name' => 'Alice', 'age' => 28] &&
	        TµSerializer::decode('[1, 2, 3,]') === [1, 2, 3] &&
	        TµSerializer::decode("// comment\n{\"a\": 1}") === ['a' => 1]);
});
check("a JSON scalar value is detected", fn() => (TµSerializer::decode('42') === 42 && TµSerializer::decode('"text"') === 'text'));
check("an INI stream is detected (with or without sections)", function() {
	return (TµSerializer::decode("name = Alice\nage = 28\n") === ['name' => 'Alice', 'age' => 28] &&
	        TµSerializer::decode("; comment\n[db]\nhost = localhost\n") === ['db' => ['host' => 'localhost']]);
});
check("a YAML stream is detected (mapping)", function() {
	return (TµSerializer::decode("name: Alice\nage: 28\n") === ['name' => 'Alice', 'age' => 28]);
}, $hasYaml);
check("a YAML stream is detected (list, flow sequence, flow mapping)", function() {
	return (TµSerializer::decode("- a\n- b\n") === ['a', 'b'] &&
	        TµSerializer::decode('[a, b]') === ['a', 'b'] &&
	        TµSerializer::decode('{a: 1}') === ['a' => 1]);
}, $hasYaml);
check("a YAML mapping containing '=' characters is not taken for INI", function() {
	return (TµSerializer::decode("name: Alice\nurl: http://example.com/?a=b\n") === ['name' => 'Alice', 'url' => 'http://example.com/?a=b']);
}, $hasYaml);
check("a UTF-8 BOM and leading spaces are ignored", function() {
	return (TµSerializer::decode("\xEF\xBB\xBF  \n{\"a\": 1}") === ['a' => 1] &&
	        TµSerializer::decode("\xEF\xBB\xBF<data><a>1</a></data>") === ['a' => 1]);
});

print("\n" . TµAnsi::bold("Priority of the given types\n"));
check("an INI stream is not taken for YAML (YAML only gives a scalar string)", function() {
	return (TµSerializer::decode("a=1\nb=2\n", [TµSerializer::YAML, TµSerializer::INI]) === ['a' => 1, 'b' => 2]);
}, $hasYaml);
check("an INI stream may be read as NEON if NEON has a higher priority", function() {
	return (TµSerializer::decode("a=1\nb=2\n", [TµSerializer::NEON, TµSerializer::INI]) === ['a' => 1, 'b' => 2]);
}, $hasNeon);
check("a NEON stream is detected", function() {
	return (TµSerializer::decode("name: Alice\nage: 28\n", [TµSerializer::JSON, TµSerializer::INI, TµSerializer::NEON]) === ['name' => 'Alice', 'age' => 28]);
}, $hasNeon);
check("a stream which is not in the accepted types raises an IO exception (BAD_FORMAT)", function() {
	return (ioExceptionCode(fn() => TµSerializer::decode("name = Alice\n", [TµSerializer::JSON, TµSerializer::XML])) === TµIOException::BAD_FORMAT &&
	        ioExceptionCode(fn() => TµSerializer::decode('<data/>', [TµSerializer::JSON, TµSerializer::INI])) === TµIOException::BAD_FORMAT);
});

print("\n" . TµAnsi::bold("PHP streams\n"));
check("a PHP stream is never executed if the PHP type is not accepted", function() {
	$GLOBALS['executed'] = false;
	$code = ioExceptionCode(fn() => TµSerializer::decode("<?php\n\$GLOBALS['executed'] = true; return 1;", [TµSerializer::JSON, TµSerializer::XML]));
	return ($code === TµIOException::BAD_FORMAT && $GLOBALS['executed'] === false);
});
check("a stream without '<?php' tag is never executed during detection", function() {
	$GLOBALS['executed'] = false;
	$code = ioExceptionCode(fn() => TµSerializer::decode("\$GLOBALS['executed'] = true;\nreturn 1;"));
	return ($code === TµIOException::BAD_FORMAT && $GLOBALS['executed'] === false);
});
check("with the PHP type only, a stream without '<?php' tag is executed", fn() => (TµSerializer::decode('return [12, 23];', TµSerializer::PHP) === [12, 23]));
check("a PHP syntax error raises an IO exception (BAD_FORMAT)", function() {
	return (ioExceptionCode(fn() => TµSerializer::decodePhp('{"a": 1}')) === TµIOException::BAD_FORMAT &&
	        ioExceptionCode(fn() => TµSerializer::decode("<?php\nreturn [;")) === TµIOException::BAD_FORMAT);
});

print("\n" . TµAnsi::bold("Single type (no detection)\n"));
check("with a single type, the stream is decoded with this format", function() {
	return (TµSerializer::decode('{"a": 1}', TµSerializer::JSON) === ['a' => 1] &&
	        TµSerializer::decode("a = 1\n", [TµSerializer::INI]) === ['a' => 1]);
});
check("with a single type, YAML may return a scalar value", fn() => (TµSerializer::decode('hello', TµSerializer::YAML) === 'hello'), $hasYaml);
check("an unknown type raises an IO exception (BAD_FORMAT)",
      fn() => (ioExceptionCode(fn() => TµSerializer::decode('{}', 'toml')) === TµIOException::BAD_FORMAT));

print("\n" . TµAnsi::bold("Errors\n"));
check("an empty stream raises an IO exception (BAD_FORMAT)", function() {
	return (ioExceptionCode(fn() => TµSerializer::decode('')) === TµIOException::BAD_FORMAT &&
	        ioExceptionCode(fn() => TµSerializer::decode("  \n ")) === TµIOException::BAD_FORMAT);
});
check("a plain text raises an IO exception (BAD_FORMAT), without PHP warning",
      fn() => (ioExceptionCode(fn() => TµSerializer::decode('hello world')) === TµIOException::BAD_FORMAT));
check("an invalid XML stream raises an IO exception (BAD_FORMAT), without PHP warning",
      fn() => (ioExceptionCode(fn() => TµSerializer::decode('<data><a></data>')) === TµIOException::BAD_FORMAT));
check("an invalid YAML stream raises an IO exception (BAD_FORMAT), without PHP warning",
      fn() => (ioExceptionCode(fn() => TµSerializer::decodeYaml("a: [1, 2\nb: }")) === TµIOException::BAD_FORMAT), $hasYaml);

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed (" . ($count - $skipped) . ")" . ($skipped ? ", $skipped skipped" : '') . ".") . "\n");
