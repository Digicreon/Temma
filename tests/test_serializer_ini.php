#!/usr/bin/php
<?php

/**
 * Validation script for the INI serialization of the Serializer helper.
 * Checks the INI syntax accepted by the decoder, the conversion of unquoted values (like JSON literals),
 * round trips (data encoded to INI, then decoded), the reading and writing of INI files, and the error management.
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
// temporary directory for the INI files
$tmpPath = sys_get_temp_dir() . '/temma-serializer-ini-test-' . getmypid();
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
 * Encodes data to INI and decodes the generated stream.
 * @param	array	$data	The data to encode.
 * @return	bool	True if the decoded data are strictly identical to the original data.
 */
function roundTrip(array $data) : bool {
	return (TµSerializer::decodeIni(TµSerializer::encodeIni($data)) === $data);
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
print(TµAnsi::bold("Syntax\n"));
check("keys before the first section are first-level values, sections contain their values", function() {
	$ini = "name = Alice\n[db]\nhost = localhost\nport = 3306\n[cache]\nhost = memcache\n";
	return (TµSerializer::decodeIni($ini) === ['name' => 'Alice', 'db' => ['host' => 'localhost', 'port' => 3306], 'cache' => ['host' => 'memcache']]);
});
check("empty lines and comments (';' and '#') are ignored, inline comments too", function() {
	$ini = "; comment\n\n# other comment\n   \n[s] ; section comment\na = 1 ; inline comment\nb = \"x ; not a comment\" ; comment\n";
	return (TµSerializer::decodeIni($ini) === ['s' => ['a' => 1, 'b' => 'x ; not a comment']]);
});
check("spaces around keys, values and section names are ignored", function() {
	$ini = "  [ section ]  \n  key   =   value   \n\tother\t=\t2\t\n";
	return (TµSerializer::decodeIni($ini) === ['section' => ['key' => 'value', 'other' => 2]]);
});
check("Windows and old Mac line endings are accepted", function() {
	return (TµSerializer::decodeIni("a = 1\r\n[s]\r\nb = 2\rc = 3") === ['a' => 1, 's' => ['b' => 2, 'c' => 3]]);
});
check("a UTF-8 BOM at the beginning of the stream is ignored", function() {
	return (TµSerializer::decodeIni("\xEF\xBB\xBFa = 1\n") === ['a' => 1]);
});
check("'key[] = value' lines create lists, 'key[sub] = value' lines create associative arrays", function() {
	$ini = "[s]\nlist[] = a\nlist[] = b\nmap[x] = 1\nmap[y] = 2\n";
	return (TµSerializer::decodeIni($ini) === ['s' => ['list' => ['a', 'b'], 'map' => ['x' => 1, 'y' => 2]]]);
});
check("a section defined twice is merged, a key defined twice keeps the last value", function() {
	return (TµSerializer::decodeIni("[s]\na = 1\nb = 2\n[t]\nc = 3\n[s]\na = 4\n") === ['s' => ['a' => 4, 'b' => 2], 't' => ['c' => 3]]);
});
check("an empty value is an empty string", function() {
	return (TµSerializer::decodeIni("a =\nb = \"\"\n") === ['a' => '', 'b' => '']);
});
check("an empty stream gives an empty array", fn() => (TµSerializer::decodeIni('') === [] && TµSerializer::decodeIni("; comment\n") === []));

print("\n" . TµAnsi::bold("Strings\n"));
check("double-quoted values are strings, with \\\", \\\\ and \\$ unescaped (other backslashes kept)", function() {
	$ini = 'a = "say \\"hi\\" \\\\ \\$HOME C:\\dir"' . "\n";
	return (TµSerializer::decodeIni($ini) === ['a' => 'say "hi" \\ $HOME C:\\dir']);
});
check("double-quoted values may span several lines", function() {
	return (TµSerializer::decodeIni("a = \"line 1\nline 2\"\nb = 1\n") === ['a' => "line 1\nline 2", 'b' => 1]);
});
check("single-quoted values are strings, written as is", function() {
	return (TµSerializer::decodeIni("a = 'raw \\n \"x\"'\n") === ['a' => 'raw \\n "x"']);
});
check("quoted values are never converted", function() {
	$ini = "a = \"true\"\nb = \"28\"\nc = 'null'\nd = \"1.5\"\n";
	return (TµSerializer::decodeIni($ini) === ['a' => 'true', 'b' => '28', 'c' => 'null', 'd' => '1.5']);
});
check("unquoted values may contain quotes and spaces", function() {
	return (TµSerializer::decodeIni("a = Bob's page\nb = say \"hi\"\n") === ['a' => "Bob's page", 'b' => 'say "hi"']);
});
check("no interpolation of variables or constants", function() {
	return (TµSerializer::decodeIni("a = \${HOME}\nb = PHP_VERSION\nc = \"\${PATH}\"\n") === ['a' => '${HOME}', 'b' => 'PHP_VERSION', 'c' => '${PATH}']);
});

print("\n" . TµAnsi::bold("Conversion of unquoted values (like JSON literals)\n"));
check("'true', 'false' and 'null' are converted", function() {
	return (TµSerializer::decodeIni("a = true\nb = false\nc = null\n") === ['a' => true, 'b' => false, 'c' => null]);
});
check("integers and floats are converted (JSON syntax)", function() {
	$ini = "a = 28\nb = -5\nc = 0\nd = 1.5\ne = -0.25\nf = 1e3\ng = 1.0E+25\nh = 99999999999999999999\n";
	return (TµSerializer::decodeIni($ini) === ['a' => 28, 'b' => -5, 'c' => 0, 'd' => 1.5, 'e' => -0.25, 'f' => 1000.0, 'g' => 1.0E+25, 'h' => 1.0E+20]);
});
check("'yes', 'no', 'on', 'off', 'none' and uppercase keywords stay strings (no Norway problem)", function() {
	$ini = "a = yes\nb = no\nc = on\nd = off\ne = none\nf = NO\ng = TRUE\nh = Null\n";
	return (TµSerializer::decodeIni($ini) === ['a' => 'yes', 'b' => 'no', 'c' => 'on', 'd' => 'off', 'e' => 'none', 'f' => 'NO', 'g' => 'TRUE', 'h' => 'Null']);
});
check("numbers which don't follow the JSON syntax stay strings", function() {
	$ini = "a = 0123\nb = +5\nc = .5\nd = 0x1A\ne = 1.2.3\nf = 5.\n";
	return (TµSerializer::decodeIni($ini) === ['a' => '0123', 'b' => '+5', 'c' => '.5', 'd' => '0x1A', 'e' => '1.2.3', 'f' => '5.']);
});
check("values of 'key[]' lines are converted too", function() {
	return (TµSerializer::decodeIni("[s]\nl[] = 1\nl[] = true\nl[] = \"2\"\n") === ['s' => ['l' => [1, true, '2']]]);
});

print("\n" . TµAnsi::bold("Round trips\n"));
check("first-level scalar values and sections are preserved", fn() => roundTrip(['title' => 'Users', 'user1' => ['name' => 'Alice', 'age' => 28]]));
check("types are preserved (string, int, float, bool, null)", fn() => roundTrip(['s' => ['a' => 'x', 'b' => 28, 'c' => 1.5, 'd' => true, 'e' => false, 'f' => null, 'g' => 1.0, 'h' => 1.0E+25, 'i' => -0.0]]));
check("strings looking like other types stay strings", fn() => roundTrip(['s' => ['a' => '28', 'b' => 'true', 'c' => 'null', 'd' => '1.5', 'e' => '', 'f' => 'no']]));
check("special characters are preserved (quotes, backslashes, dollars, semicolons, line breaks)", fn() => roundTrip(['s' => ['a' => 'say "hi" \\ $x ${y} ; z', 'b' => "line 1\nline 2", 'c' => "tab\t"]]));
check("third-level lists and associative arrays are preserved", fn() => roundTrip(['s' => ['list' => ['a', 1, true], 'map' => ['x' => 1, 'y' => 'z']]]));
check("a Stringable object is encoded as a string", function() {
	$object = new class implements \Stringable {
		/**
		 * Returns the string value of the object.
		 * @return	string	The string value.
		 */
		public function __toString() : string {
			return ('text');
		}
	};
	return (TµSerializer::decodeIni(TµSerializer::encodeIni(['a' => $object])) === ['a' => 'text']);
});

print("\n" . TµAnsi::bold("Files\n"));
check("writeIni() then readIni() preserves the data", function() use ($tmpPath) {
	$data = ['name' => 'app', 'db' => ['host' => 'localhost', 'port' => 3306, 'debug' => false]];
	TµSerializer::writeIni("$tmpPath/data1.ini", $data);
	return (TµSerializer::readIni("$tmpPath/data1.ini") === $data);
});
check("write() and read() infer the INI format from the file extension", function() use ($tmpPath) {
	$data = ['s' => ['list' => [1, 2]]];
	TµSerializer::write("$tmpPath/data2.ini", $data);
	return (TµSerializer::read("$tmpPath/data2.ini") === $data);
});
check("reading a missing INI file raises an IO exception (NOT_FOUND), without PHP warning",
      fn() => (ioExceptionCode(fn() => TµSerializer::readIni('/nonexistent/file.ini')) === TµIOException::NOT_FOUND));

print("\n" . TµAnsi::bold("Errors\n"));
check("a line without '=' raises an IO exception (BAD_FORMAT)",
      fn() => (ioExceptionCode(fn() => TµSerializer::decodeIni("[s]\nname: Alice\n")) === TµIOException::BAD_FORMAT));
check("an unterminated quoted value raises an IO exception (BAD_FORMAT)",
      fn() => (ioExceptionCode(fn() => TµSerializer::decodeIni("a = \"unterminated\n")) === TµIOException::BAD_FORMAT));
check("text after a quoted value raises an IO exception (BAD_FORMAT)",
      fn() => (ioExceptionCode(fn() => TµSerializer::decodeIni("a = \"x\" y\n")) === TµIOException::BAD_FORMAT));
check("an empty key or an empty section name raises an IO exception (BAD_FORMAT)", function() {
	return (ioExceptionCode(fn() => TµSerializer::decodeIni("= 1\n")) === TµIOException::BAD_FORMAT &&
	        ioExceptionCode(fn() => TµSerializer::decodeIni("[]\na = 1\n")) === TµIOException::BAD_FORMAT);
});
check("the line number of the syntax error is given in the message", function() {
	try {
		TµSerializer::decodeIni("a = 1\n\n[s]\nbad line\n");
	} catch (TµIOException $e) {
		return (str_contains($e->getMessage(), 'line 4'));
	}
	return (false);
});
check("encoding non-array data, integer first-level keys, objects or infinite floats raises an IO exception (BAD_FORMAT)", function() {
	foreach (['text', [0 => 'a'], ['a' => new \stdClass()], ['a' => INF], ['s' => ['a' => ['b' => ['c' => 1]]]]] as $data) {
		if (ioExceptionCode(fn() => TµSerializer::encodeIni($data)) !== TµIOException::BAD_FORMAT)
			return (false);
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
