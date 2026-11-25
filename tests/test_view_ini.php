#!/usr/bin/php
<?php

/**
 * Validation script for the INI view.
 * Creates a response object filled with template variables, initializes the INI view with it and checks
 * the data that will be serialized (read with reflection) and the generated INI stream.
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');
\Temma\Base\Log::disable();
// PHP warnings and notices are converted to exceptions, so they make the tests fail
set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline) {
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

/* ********** HELPERS ********** */
$count = 0;
$failed = 0;
// displays the result of a test
function check(string $label, bool $ok) : void {
	global $count, $failed;
	$count++;
	if (!$ok)
		$failed++;
	print(TµAnsi::faint(sprintf('%02d', $count)) . ' ' .
	      TµAnsi::color(($ok ? 'green' : 'red'), ($ok ? 'OK' : 'KO')) . ' ' .
	      "$label\n");
}
// initializes the INI view with the given template variables, and returns the data that will be serialized
function render(array $data, null|string|array $contract=null) : mixed {
	$config = new \Temma\Web\Config(sys_get_temp_dir());
	$response = new \Temma\Web\Response();
	$response->setData($data);
	$response->setValidationContract($contract);
	$view = new \Temma\Views\Ini([], $config, $response);
	$view->init();
	return ((new \ReflectionProperty($view, '_data'))->getValue($view));
}
// processes the given template variables with the INI view, and returns the generated INI stream
function renderStream(array $data) : string {
	$config = new \Temma\Web\Config(sys_get_temp_dir());
	$response = new \Temma\Web\Response();
	$response->setData($data);
	$view = new \Temma\Views\Ini([], $config, $response);
	$view->init();
	ob_start();
	$view->sendBody();
	return (ob_get_clean());
}
// template variables defined by the framework, and private variables
$frameworkData = [
	'URL'             => '/user/show/12',
	'CONTROLLER'      => 'user',
	'ACTION'          => 'show',
	'_temmaCacheable' => true,
	'_private'        => 'abc',
	'__flash'         => 'def',
	'___triple'       => 'ghi',
];

/* ********** TESTS ********** */
print(TµAnsi::bold("Data source\n"));
$result = render(['data' => ['a' => 1], 'other' => 2] + $frameworkData);
check("data of the 'data' variable are used", $result === ['a' => 1]);
$result = render([
	'@output' => ['b' => 2],
	'data'    => ['a' => 1],
	'other'   => 3,
] + $frameworkData);
check("data of the '@output' variable take precedence over the 'data' variable", $result === ['b' => 2]);
$result = render([
	'foo'       => 'bar',
	'nbr'       => 3,
	'filename'  => 'export.ini',
] + $frameworkData);
check("without '@output' nor 'data', all template variables are used, without 'filename', 'URL', 'CONTROLLER', 'ACTION' and variables starting with '_', '__' or '___'",
      $result === ['foo' => 'bar', 'nbr' => 3]);
$result = render(['@output' => null, 'data' => null, 'foo' => 'bar']);
check("'@output' and 'data' variables set to null are ignored and not used", $result === ['foo' => 'bar']);
$result = render($frameworkData);
check("without any application variable, an empty list is used", $result === []);

print("\n" . TµAnsi::bold("Keys starting with underscores\n"));
$underscoreData = ['_private' => 1, '__flash' => 2, '___triple' => 3, 'name' => 4];
$result = render(['data' => $underscoreData] + $frameworkData);
check("data of the 'data' variable are used as is, including keys starting with '_', '__' or '___'", $result === $underscoreData);
$result = render(['@output' => $underscoreData] + $frameworkData);
check("data of the '@output' variable are used as is, including keys starting with '_', '__' or '___'", $result === $underscoreData);

print("\n" . TµAnsi::bold("Output validation contract\n"));
$result = render(['data' => ['id' => 12, 'name' => 'abc', 'secret' => 'xyz']],
                 ['type' => 'assoc', 'keys' => ['id' => 'int', 'name' => 'string']]);
check("with a contract, validated data are used (undeclared key removed)", $result === ['id' => 12, 'name' => 'abc']);
$result = render(['@output' => ['id' => '12', 'secret' => 'xyz']],
                 ['type' => 'assoc', 'keys' => ['id' => 'int']]);
check("with a contract, '@output' data are validated and converted", $result === ['id' => 12]);
$result = render([
	'id'        => 5,
	'secret'    => 'xyz',
	'filename'  => 'export.ini',
] + $frameworkData, ['type' => 'assoc', 'keys' => ['id' => 'int']]);
check("with a contract, all template variables are validated when there is no '@output' nor 'data'", $result === ['id' => 5]);
$result = render(['id' => 5] + $frameworkData,
                 ['type' => 'assoc', 'keys' => ['id' => 'int', '_private?' => 'string', '__flash?' => 'string', '___triple?' => 'string']]);
check("with a contract, template variables starting with '_', '__' or '___' are removed before validation, even if declared in the contract",
      $result === ['id' => 5]);
$result = render(['data' => ['_private' => '1', '__flash' => '2', 'other' => 3]],
                 ['type' => 'assoc', 'keys' => ['_private' => 'int', '__flash' => 'int']]);
check("with a contract, keys starting with '_' or '__' in the 'data' variable are validated like the other keys",
      $result === ['_private' => 1, '__flash' => 2]);

print("\n" . TµAnsi::bold("INI stream\n"));
$result = renderStream([
	'title' => 'Users',
	'user1' => ['name' => 'Alice', 'age' => 28],
	'user2' => ['name' => 'Bob', 'age' => 54],
] + $frameworkData);
check("first-level arrays become sections, scalar values are written as key=value lines",
      $result === "title=\"Users\"\n[user1]\nname=\"Alice\"\nage=28\n[user2]\nname=\"Bob\"\nage=54\n");
$result = renderStream(['data' => ['section' => ['key' => 1], 'last' => 'x']]);
check("first-level scalar values are written before the sections", $result === "last=\"x\"\n[section]\nkey=1\n");
$data = [
	'quote'   => 'a "quoted" \\ text',
	'empty'   => null,
	'enabled' => true,
	'hidden'  => false,
	'float'   => 1.5,
	'section' => [
		'list'  => ['a', 'b'],
		'assoc' => ['x' => 1, 'y' => 'z'],
	],
];
$result = renderStream(['data' => $data]);
check("the generated stream is read back by Serializer::decodeIni() with the same data and types",
      \Temma\Utils\Serializer::decodeIni($result) === $data);
$result = renderStream(['data' => ['home' => '${HOME}', 'price' => '$12', 'mixed' => 'a\\${x} $$']]);
check('dollar signs are written as is in strings',
      $result === 'home="${HOME}"' . "\n" .
                   'price="$12"' . "\n" .
                   'mixed="a\\\\${x} $$"' . "\n");
check('"${...}" expressions are not interpolated when the stream is read',
      \Temma\Utils\Serializer::decodeIni($result) === ['home' => '${HOME}', 'price' => '$12', 'mixed' => 'a\\${x} $$']);

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
