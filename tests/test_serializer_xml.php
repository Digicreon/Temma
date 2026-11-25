#!/usr/bin/php
<?php

/**
 * Validation script for the XML serialization of the Serializer helper.
 * Checks the generated format, round trips (data encoded to XML, then decoded: the decoded data must be
 * strictly identical to the original data, with the same keys, order and types), the decoding of
 * hand-written XML streams, the reading and writing of XML files, and the error management.
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
// temporary directory for the XML files
$tmpPath = sys_get_temp_dir() . '/temma-serializer-xml-test-' . getmypid();
mkdir($tmpPath, 0755, true);
register_shutdown_function(function() use ($tmpPath) {
	exec('rm -rf ' . escapeshellarg($tmpPath));
});

/**
 * Object which can be converted to a string.
 */
class StringableValue implements \Stringable {
	/**
	 * Returns the string value of the object.
	 * @return	string	The string value.
	 */
	public function __toString() : string {
		return ('stringable value');
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
 * Encodes data to XML and decodes the generated stream.
 * @param	mixed	$data		The data to encode.
 * @param	bool	$prettyPrint	(optional) Pretty print flag given to the encoder. Defaults to true.
 * @return	bool	True if the decoded data are strictly identical to the original data.
 */
function roundTrip(mixed $data, bool $prettyPrint=true) : bool {
	$stream = TµSerializer::encodeXml($data, $prettyPrint);
	return (TµSerializer::decodeXml($stream) === $data);
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
print(TµAnsi::bold("Encoding\n"));
check("encodeXml() returns a well-formed XML document", function() {
	$stream = TµSerializer::encodeXml(['name' => 'Alice']);
	$dom = new \DOMDocument();
	return (str_starts_with($stream, '<?xml') && $dom->loadXML($stream) === true);
});
check("encodeXml() without pretty print doesn't indent the stream", function() {
	$stream = TµSerializer::encodeXml(['user' => ['name' => 'Alice', 'age' => 28]], false);
	return (!str_contains($stream, "\t") && substr_count(trim($stream), "\n") <= 1);
});
check("encode() with the XML type is the same as encodeXml()", function() {
	$data = ['name' => 'Alice', 'list' => [1, 2]];
	return (TµSerializer::encode($data, TµSerializer::XML) === TµSerializer::encodeXml($data));
});

print("\n" . TµAnsi::bold("Format\n"));
check("the generated XML stream follows the format specification", function() {
	$data = [
		'name'   => 'Alice',
		'age'    => 28,
		'ratio'  => 1.0,
		'admin'  => true,
		'note'   => null,
		'empty'  => '',
		'zip'    => '01234',
		'code'   => '28',
		'flag'   => 'false',
		'tags'   => [],
		'users'  => ['Bob', ['name' => 'Carol']],
		'My Key' => 'x',
		'__item' => 'y',
		'item'   => 'z',
		'sparse' => [0 => 'a', 5 => 'b', 6 => 'c'],
		'ctrl'   => "a\x01b",
		'keys'   => ["a\x02" => 1],
	];
	$expected = <<<'XML'
		<?xml version="1.0" encoding="UTF-8"?>
		<data>
			<name>Alice</name>
			<age>28</age>
			<ratio>1.0</ratio>
			<admin>true</admin>
			<note>null</note>
			<empty></empty>
			<zip>01234</zip>
			<code __type="string">28</code>
			<flag __type="string">false</flag>
			<tags __type="array"/>
			<users>
				<__item>Bob</__item>
				<__item>
					<name>Carol</name>
				</__item>
			</users>
			<__item __key="My Key">x</__item>
			<__item __key="__item">y</__item>
			<item>z</item>
			<sparse>
				<__item>a</__item>
				<__item __key="5">b</__item>
				<__item>c</__item>
			</sparse>
			<ctrl __type="base64">YQFi</ctrl>
			<keys>
				<__item __key="YQI=" __keyType="base64">1</__item>
			</keys>
		</data>

		XML;
	return (TµSerializer::encodeXml($data) === $expected);
});
check("a scalar root value is written in the root element", function() {
	return (TµSerializer::encodeXml(42, false) === "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<data>42</data>\n");
});
check("a list at the root is written with '__item' elements", function() {
	return (TµSerializer::encodeXml(['A', 'B'], false) === "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<data><__item>A</__item><__item>B</__item></data>\n");
});

print("\n" . TµAnsi::bold("Round trips: scalar values\n"));
check("strings are preserved", fn() => roundTrip(['a' => 'Alice', 'b' => 'Bob']));
check("integers are preserved", fn() => roundTrip(['a' => 28, 'b' => -5, 'c' => 0, 'd' => PHP_INT_MAX]));
check("floats are preserved (even integral values, big and small values)", fn() => roundTrip(['a' => 3.14, 'b' => -0.5, 'c' => 1.0, 'd' => 0.1 + 0.2, 'e' => 1.0E+25, 'f' => 1.5E-10, 'g' => -0.0]));
check("booleans are preserved", fn() => roundTrip(['a' => true, 'b' => false]));
check("null values are preserved", fn() => roundTrip(['a' => null]));
check("numeric-looking strings stay strings", fn() => roundTrip(['a' => '28', 'b' => '0123', 'c' => '1.0', 'd' => '-3', 'e' => '1e3', 'f' => '+5', 'g' => ' 28']));
check("strings looking like keywords stay strings", fn() => roundTrip(['a' => 'true', 'b' => 'false', 'c' => 'null', 'd' => 'yes', 'e' => 'TRUE', 'f' => 'no']));
check("empty strings are preserved (and not converted to empty arrays)", fn() => roundTrip(['a' => '']));
check("XML special characters are preserved", fn() => roundTrip(['a' => '<b>Tom & "Jerry"</b>', 'b' => "it's", 'c' => ']]>']));
check("leading/trailing spaces and line breaks are preserved", fn() => roundTrip(['a' => '  padded  ', 'b' => "line 1\nline 2\n", 'c' => "\ttab"]));
check("UTF-8 characters are preserved", fn() => roundTrip(['a' => 'été 😀 漢字']));
check("strings containing characters forbidden in XML are preserved", fn() => roundTrip(['a' => "a\x00b\x01c\x1Fd", 'b' => "\x0B\x0C"]));
check("strings which are not valid UTF-8 (binary data) are preserved", fn() => roundTrip(['a' => "\xFF\xFE\x80", 'b' => random_bytes(64)]));
check("a Stringable object is encoded as a string", function() {
	$stream = TµSerializer::encodeXml(['a' => new StringableValue()]);
	return (TµSerializer::decodeXml($stream) === ['a' => 'stringable value']);
});

print("\n" . TµAnsi::bold("Round trips: arrays\n"));
check("associative arrays are preserved (keys and order)", fn() => roundTrip(['zeta' => 1, 'alpha' => 2, 'mid' => 3]));
check("nested associative arrays are preserved", fn() => roundTrip(['db' => ['host' => 'localhost', 'port' => 3306, 'options' => ['ssl' => true]]]));
check("lists are preserved", fn() => roundTrip(['users' => ['Alice', 'Bob', 'Carol']]));
check("a list containing a single element stays a list", fn() => roundTrip(['users' => ['Alice']]));
check("lists of associative arrays are preserved", fn() => roundTrip(['users' => [['name' => 'Alice', 'age' => 28], ['name' => 'Bob', 'age' => 54]]]));
check("lists of lists are preserved", fn() => roundTrip(['matrix' => [[1, 2], [3, 4], []]]));
check("lists of mixed types are preserved", fn() => roundTrip(['mixed' => ['a', 1, 2.5, true, null, ['x' => 1]]]));
check("sparse integer keys are preserved", fn() => roundTrip(['sparse' => [0 => 'a', 5 => 'b', 6 => 'c']]));
check("unordered integer keys are preserved", fn() => roundTrip(['unordered' => [2 => 'a', 0 => 'b', 1 => 'c']]));
check("negative integer keys are preserved", fn() => roundTrip(['neg' => [-1 => 'a', -2 => 'b']]));
check("mixed integer and string keys are preserved", fn() => roundTrip(['mixed' => ['a' => 1, 0 => 'x', 'b' => 2, 1 => 'y']]));
check("empty arrays are preserved (and not converted to empty strings)", fn() => roundTrip(['a' => [], 'b' => ['c' => []]]));
check("deeply nested data are preserved", fn() => roundTrip(['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 'deep']]]]]]));

print("\n" . TµAnsi::bold("Round trips: keys which are not valid XML names\n"));
check("keys containing spaces are preserved", fn() => roundTrip(['My Key' => 1, 'other key' => 2]));
check("keys starting with a digit are preserved", fn() => roundTrip(['1abc' => 1, '2' => 'x']));
check("keys with accents or uppercase letters are preserved", fn() => roundTrip(['Été' => 1, 'camelCase' => 2, 'UPPER' => 3]));
check("keys with special characters are preserved", fn() => roundTrip(['a:b' => 1, 'a/b' => 2, 'a<b' => 3, 'a"b' => 4, 'a.b' => 5, 'a-b' => 6, 'a_b' => 7]));
check("empty keys are preserved", fn() => roundTrip(['' => 'empty key']));
check("keys starting with 'xml' are preserved", fn() => roundTrip(['xmlData' => 1, 'XML' => 2]));
check("keys containing characters forbidden in XML or invalid UTF-8 are preserved", fn() => roundTrip(["a\x01" => 1, "\xFF" => 2, "line\nbreak" => 3, "tab\t" => 4, "cr\r" => 5]));
check("keys which differ only by case or by special characters stay distinct", fn() => roundTrip(['my key' => 1, 'my-key' => 2, 'My Key' => 3]));
check("keys equal to names used by the format itself are preserved", fn() => roundTrip(['__item' => 1, '__type' => 2, '__key' => 3, '__keyType' => 4, 'item' => 5, 'type' => 6, 'key' => 7, 'data' => 8]));

print("\n" . TµAnsi::bold("Round trips: root value\n"));
check("a list at the root is preserved", fn() => roundTrip(['Alice', 'Bob']));
check("an empty array at the root is preserved", fn() => roundTrip([]));
check("a string at the root is preserved", fn() => roundTrip('hello'));
check("an integer at the root is preserved", fn() => roundTrip(42));
check("a boolean at the root is preserved", fn() => roundTrip(false));
check("null at the root is preserved", fn() => roundTrip(null));
check("round trip without pretty print", fn() => roundTrip(['users' => [['name' => ' Alice ', 'tags' => ['a', 'b']]], 'n' => 1.5], false));

print("\n" . TµAnsi::bold("Decoding of hand-written XML\n"));
check("values are converted like JSON literals, whatever the name of the root element", function() {
	$xml = '<?xml version="1.0" encoding="UTF-8"?><config><name>Alice</name><port>3306</port><ratio>0.5</ratio>' .
	       '<big>1e3</big><debug>false</debug><cache>true</cache><domain>null</domain></config>';
	return (TµSerializer::decodeXml($xml) === ['name' => 'Alice', 'port' => 3306, 'ratio' => 0.5, 'big' => 1000.0,
	                                           'debug' => false, 'cache' => true, 'domain' => null]);
});
check("values which are not JSON literals stay strings (no, off, yes, TRUE, 0123, +5, spaces)", function() {
	$xml = '<config><a>no</a><b>off</b><c>yes</c><d>TRUE</d><e>0123</e><f>+5</f><g> 12 </g><h></h><i/></config>';
	return (TµSerializer::decodeXml($xml) === ['a' => 'no', 'b' => 'off', 'c' => 'yes', 'd' => 'TRUE', 'e' => '0123',
	                                           'f' => '+5', 'g' => ' 12 ', 'h' => '', 'i' => '']);
});
check("nested elements are decoded as associative arrays", function() {
	$xml = "<?xml version=\"1.0\"?>\n<root>\n\t<db>\n\t\t<host>localhost</host>\n\t\t<user>admin</user>\n\t</db>\n</root>\n";
	return (TµSerializer::decodeXml($xml) === ['db' => ['host' => 'localhost', 'user' => 'admin']]);
});
check("comments are ignored", function() {
	$xml = '<?xml version="1.0"?><root><!-- comment --><name>Alice</name><!-- other --></root>';
	return (TµSerializer::decodeXml($xml) === ['name' => 'Alice']);
});
check("CDATA sections are decoded as text", function() {
	$xml = '<?xml version="1.0"?><root><html><![CDATA[<b>bold</b> & co]]></html></root>';
	return (TµSerializer::decodeXml($xml) === ['html' => '<b>bold</b> & co']);
});
check("a stream without XML declaration is decoded", function() {
	return (TµSerializer::decodeXml('<root><name>Alice</name></root>') === ['name' => 'Alice']);
});
check("explicitly typed values are decoded (spaces around non-string typed values are ignored)", function() {
	$xml = '<config><port __type="int"> 3306 </port><debug __type="bool">false</debug><ratio __type="float">0.5</ratio>' .
	       '<none __type="null"/><code __type="string">28</code><pad __type="string"> x </pad><list __type="array"/></config>';
	return (TµSerializer::decodeXml($xml) === ['port' => 3306, 'debug' => false, 'ratio' => 0.5, 'none' => null,
	                                           'code' => '28', 'pad' => ' x ', 'list' => []]);
});
check("'__item' elements are decoded as a list", function() {
	$xml = '<config><users><__item>Alice</__item><__item>Bob</__item></users></config>';
	return (TµSerializer::decodeXml($xml) === ['users' => ['Alice', 'Bob']]);
});
check("'item' elements are ordinary elements (RSS feed)", function() {
	$xml = '<rss version="2.0"><channel><title>Blog</title><item><title>A</title></item><item><title>B</title></item></channel></rss>';
	return (TµSerializer::decodeXml($xml) === ['channel' => ['title' => 'Blog', 'item' => [['title' => 'A'], ['title' => 'B']]]]);
});
check("elements whose children all have the same repeated name are decoded as lists", function() {
	$xml = '<config><urlset><url>A</url><url>B</url></urlset><ports><port>80</port><port>443</port></ports></config>';
	return (TµSerializer::decodeXml($xml) === ['urlset' => ['A', 'B'], 'ports' => [80, 443]]);
});
check("repeated elements may contain arrays", function() {
	$xml = '<config><user><name>Alice</name></user><user><name>Bob</name></user></config>';
	return (TµSerializer::decodeXml($xml) === [['name' => 'Alice'], ['name' => 'Bob']]);
});
check("repeated elements mixed with other elements are grouped in a list, under their name", function() {
	$xml = '<data><urlset><url>A</url><url>B</url><timeout>3</timeout></urlset></data>';
	return (TµSerializer::decodeXml($xml) === ['urlset' => ['url' => ['A', 'B'], 'timeout' => 3]]);
});
check("repeated elements mixed with '__item' elements are grouped in a list, under their name", function() {
	$xml = '<data><url>A</url><__item>C</__item><url>B</url></data>';
	return (TµSerializer::decodeXml($xml) === ['url' => ['A', 'B'], 0 => 'C']);
});
check("a single child element is decoded as an associative array (not as a list)", function() {
	$xml = '<config><users><user>Alice</user></users></config>';
	return (TµSerializer::decodeXml($xml) === ['users' => ['user' => 'Alice']]);
});
check("unknown attributes are ignored (including 'type' and 'key')", function() {
	$xml = '<config version="2"><name lang="en">Alice</name><payment type="card">VISA</payment><item key="a">1</item></config>';
	return (TµSerializer::decodeXml($xml) === ['name' => 'Alice', 'payment' => 'VISA', 'item' => 1]);
});

print("\n" . TµAnsi::bold("Files\n"));
check("writeXml() then readXml() preserves the data", function() use ($tmpPath) {
	$data = ['users' => [['name' => 'Alice', 'age' => 28, 'admin' => true]], 'empty' => []];
	TµSerializer::writeXml("$tmpPath/data1.xml", $data);
	return (TµSerializer::readXml("$tmpPath/data1.xml") === $data);
});
check("write() and read() infer the XML format from the file extension", function() use ($tmpPath) {
	$data = ['name' => 'Alice', 'list' => [1, 2, 3]];
	TµSerializer::write("$tmpPath/data2.xml", $data);
	return (TµSerializer::read("$tmpPath/data2.xml") === $data);
});
check("readFromPrefix() finds and reads an XML file", function() use ($tmpPath) {
	$data = ['name' => 'Alice'];
	TµSerializer::writeXml("$tmpPath/data3.xml", $data);
	return (TµSerializer::readFromPrefix("$tmpPath/data3") === $data);
});

print("\n" . TµAnsi::bold("Format detection\n"));
check("decode() with a list of types finds an XML stream", function() {
	$stream = TµSerializer::encodeXml(['name' => 'Alice', 'age' => 28]);
	return (TµSerializer::decode($stream, [TµSerializer::JSON, TµSerializer::XML]) === ['name' => 'Alice', 'age' => 28]);
});
check("decode() with the default list of types finds an XML stream", function() {
	$xml = '<?xml version="1.0"?><root><name>Alice</name></root>';
	return (TµSerializer::decode($xml) === ['name' => 'Alice']);
});

print("\n" . TµAnsi::bold("Errors\n"));
check("decoding an invalid XML stream raises an IO exception (BAD_FORMAT), without PHP warning",
      fn() => (ioExceptionCode(fn() => TµSerializer::decodeXml('<root><a></root>')) === TµIOException::BAD_FORMAT));
check("decoding an empty stream raises an IO exception (BAD_FORMAT), without PHP warning",
      fn() => (ioExceptionCode(fn() => TµSerializer::decodeXml('')) === TµIOException::BAD_FORMAT));
check("decoding an element containing both text and elements raises an IO exception (BAD_FORMAT)",
      fn() => (ioExceptionCode(fn() => TµSerializer::decodeXml('<root><a>text<b>1</b></a></root>')) === TµIOException::BAD_FORMAT));
check("an element with a type and child elements raises an IO exception (BAD_FORMAT)",
      fn() => (ioExceptionCode(fn() => TµSerializer::decodeXml('<root __type="array"><a>1</a></root>')) === TµIOException::BAD_FORMAT));
check("an unknown type raises an IO exception (BAD_FORMAT)",
      fn() => (ioExceptionCode(fn() => TµSerializer::decodeXml('<root><a __type="date">2026-01-01</a></root>')) === TµIOException::BAD_FORMAT));
check("invalid typed values raise an IO exception (BAD_FORMAT)", function() {
	foreach (['<a __type="int">1.5</a>', '<a __type="int">abc</a>', '<a __type="float">abc</a>', '<a __type="bool">yes</a>',
	          '<a __type="null">x</a>', '<a __type="array">x</a>', '<a __type="base64">%%%</a>'] as $xml) {
		if (ioExceptionCode(fn() => TµSerializer::decodeXml($xml)) !== TµIOException::BAD_FORMAT)
			return (false);
	}
	return (true);
});
check("duplicate keys raise an IO exception (BAD_FORMAT)", function() {
	return (ioExceptionCode(fn() => TµSerializer::decodeXml('<root><a>1</a><__item __key="a">2</__item></root>')) === TµIOException::BAD_FORMAT &&
	        ioExceptionCode(fn() => TµSerializer::decodeXml('<root><__item __key="a">1</__item><a>2</a><a>3</a></root>')) === TµIOException::BAD_FORMAT);
});
check("an unknown key type raises an IO exception (BAD_FORMAT)",
      fn() => (ioExceptionCode(fn() => TµSerializer::decodeXml('<root><__item __key="a" __keyType="hex">1</__item></root>')) === TµIOException::BAD_FORMAT));
check("encoding an infinite or NaN float raises an IO exception (BAD_FORMAT)", function() {
	return (ioExceptionCode(fn() => TµSerializer::encodeXml(['a' => INF])) === TµIOException::BAD_FORMAT &&
	        ioExceptionCode(fn() => TµSerializer::encodeXml(['a' => NAN])) === TµIOException::BAD_FORMAT);
});
check("reading a missing XML file raises an IO exception, without PHP warning",
      fn() => (ioExceptionCode(fn() => TµSerializer::readXml('/nonexistent/file.xml')) !== null));
check("encoding a non-Stringable object raises an IO exception (BAD_FORMAT)",
      fn() => (ioExceptionCode(fn() => TµSerializer::encodeXml(['a' => new \stdClass()])) === TµIOException::BAD_FORMAT));
check("encoding a resource raises an IO exception (BAD_FORMAT)", function() {
	$handle = fopen('php://memory', 'r');
	$code = ioExceptionCode(fn() => TµSerializer::encodeXml(['a' => $handle]));
	fclose($handle);
	return ($code === TµIOException::BAD_FORMAT);
});
check("the external entities are not loaded (XXE)", function() use ($tmpPath) {
	file_put_contents("$tmpPath/secret.txt", 'secret content');
	$xml = '<?xml version="1.0"?><!DOCTYPE root [<!ENTITY xxe SYSTEM "file://' . $tmpPath . '/secret.txt">]><root><a>&xxe;</a></root>';
	try {
		$data = TµSerializer::decodeXml($xml);
	} catch (TµIOException $e) {
		return (true);
	}
	return (!str_contains(var_export($data, true), 'secret content'));
});

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
