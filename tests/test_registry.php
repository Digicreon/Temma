#!/usr/bin/php
<?php

/**
 * Validation script for the file reading methods of the Registry helper (readIni(), readJson(), readXml()).
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;
use \Temma\Utils\Registry as TµRegistry;
use \Temma\Exceptions\IO as TµIOException;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');
\Temma\Base\Log::disable();
// PHP warnings and notices are converted to exceptions, so they make the tests fail
set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline) {
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});
// temporary directory for the files
$tmpPath = sys_get_temp_dir() . '/temma-registry-test-' . getmypid();
mkdir($tmpPath, 0755, true);
register_shutdown_function(function() use ($tmpPath) {
	exec('chmod -R u+rwx ' . escapeshellarg($tmpPath) . '; rm -rf ' . escapeshellarg($tmpPath));
});
$isRoot = (function_exists('posix_geteuid') && posix_geteuid() === 0);

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
	} catch (TµIOException $ie) {
		return ($ie->getCode());
	}
	return (null);
}

/* ********** TESTS ********** */
print(TµAnsi::bold("INI files\n"));
check("readIni() uses the INI parser of the Serializer (values converted like JSON literals)", function() use ($tmpPath) {
	file_put_contents("$tmpPath/conf.ini", "name = Alice\nport = 3306\ndebug = true\nmode = on\nnothing = null\n[db]\nhost = \"localhost\"\nlist[] = 1\nlist[] = two\n");
	$registry = new TµRegistry(['existing' => 1]);
	$registry->readIni("$tmpPath/conf.ini");
	return ($registry->get('existing') === 1 && $registry->get('name') === 'Alice' && $registry->get('port') === 3306 &&
	        $registry->get('debug') === true && $registry->get('mode') === 'on' && $registry->isset('nothing') === false &&
	        $registry->get('db') === ['host' => 'localhost', 'list' => [1, 'two']]);
});
check("readIni() with a key stores the data under this key", function() use ($tmpPath) {
	file_put_contents("$tmpPath/key.ini", "a = 1\n");
	$registry = new TµRegistry();
	$registry->readIni("$tmpPath/key.ini", 'conf');
	return ($registry->get('conf') === ['a' => 1]);
});
check("readIni() on a missing file raises an IO exception (NOT_FOUND), without PHP warning",
      fn() => (ioExceptionCode(fn() => (new TµRegistry())->readIni("$tmpPath/missing.ini")) === TµIOException::NOT_FOUND));
check("readIni() on an unreadable file raises an IO exception (UNREADABLE), without PHP warning", function() use ($tmpPath) {
	file_put_contents("$tmpPath/unreadable.ini", "a = 1\n");
	chmod("$tmpPath/unreadable.ini", 0);
	return (ioExceptionCode(fn() => (new TµRegistry())->readIni("$tmpPath/unreadable.ini")) === TµIOException::UNREADABLE);
}, !$isRoot);
check("readIni() on a malformed file raises an IO exception (BAD_FORMAT)", function() use ($tmpPath) {
	file_put_contents("$tmpPath/bad.ini", "a = \"unterminated\n");
	return (ioExceptionCode(fn() => (new TµRegistry())->readIni("$tmpPath/bad.ini")) === TµIOException::BAD_FORMAT);
});

print("\n" . TµAnsi::bold("JSON files\n"));
check("readJson() merges the data into the registry", function() use ($tmpPath) {
	file_put_contents("$tmpPath/conf.json", '{"a": 1, "b": [true, null]}');
	$registry = new TµRegistry(['existing' => 1]);
	$registry->readJson("$tmpPath/conf.json");
	return ($registry->get('existing') === 1 && $registry->get('a') === 1 && $registry->get('b') === [true, null]);
});
check("readJson() stores the data under the given key, comments and trailing commas allowed", function() use ($tmpPath) {
	file_put_contents("$tmpPath/conf2.json", "{\n// comment\n\"a\": [1, 2,],\n}");
	$registry = new TµRegistry();
	$registry->readJson("$tmpPath/conf2.json", 'conf');
	return ($registry->get('conf') === ['a' => [1, 2]]);
});
check("readJson() on a missing file raises an IO exception (NOT_FOUND), without PHP warning",
      fn() => (ioExceptionCode(fn() => (new TµRegistry())->readJson("$tmpPath/missing.json")) === TµIOException::NOT_FOUND));
check("readJson() on a malformed file raises an IO exception (BAD_FORMAT)", function() use ($tmpPath) {
	file_put_contents("$tmpPath/bad.json", '{"a": ');
	return (ioExceptionCode(fn() => (new TµRegistry())->readJson("$tmpPath/bad.json")) === TµIOException::BAD_FORMAT);
});

print("\n" . TµAnsi::bold("XML files\n"));
check("readXml() stores a SimpleXMLElement under the given key", function() use ($tmpPath) {
	file_put_contents("$tmpPath/conf.xml", '<config><name>Alice</name></config>');
	$registry = new TµRegistry();
	$registry->readXml("$tmpPath/conf.xml", 'conf');
	return ($registry->get('conf') instanceof \SimpleXMLElement && (string)$registry->get('conf')->name === 'Alice');
});
check("readXml() on a missing file raises an IO exception (NOT_FOUND), without PHP warning",
      fn() => (ioExceptionCode(fn() => (new TµRegistry())->readXml("$tmpPath/missing.xml", 'conf')) === TµIOException::NOT_FOUND));
check("readXml() on an unreadable file raises an IO exception (UNREADABLE), without PHP warning", function() use ($tmpPath) {
	file_put_contents("$tmpPath/unreadable.xml", '<config/>');
	chmod("$tmpPath/unreadable.xml", 0);
	return (ioExceptionCode(fn() => (new TµRegistry())->readXml("$tmpPath/unreadable.xml", 'conf')) === TµIOException::UNREADABLE);
}, !$isRoot);
check("readXml() on a malformed file raises an IO exception (BAD_FORMAT), without PHP warning", function() use ($tmpPath) {
	file_put_contents("$tmpPath/bad.xml", '<config><name>');
	return (ioExceptionCode(fn() => (new TµRegistry())->readXml("$tmpPath/bad.xml", 'conf')) === TµIOException::BAD_FORMAT);
});

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed (" . ($count - $skipped) . ")" . ($skipped ? ", $skipped skipped" : '') . ".") . "\n");
