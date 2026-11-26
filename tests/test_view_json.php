#!/usr/bin/php
<?php

/**
 * Validation script for the JSON view.
 * Creates a response object filled with template variables, processes it with the JSON view
 * (init() and sendBody()) and checks the generated JSON stream.
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
// processes the given template variables with the JSON view, and returns the decoded JSON stream
function render(array $data, null|string|array $contract=null) : mixed {
	return (json_decode(renderStream($data, $contract), true));
}
// processes the given template variables with the JSON view, and returns the JSON stream
function renderStream(array $data, null|string|array $contract=null) : string {
	$config = new \Temma\Web\Config(sys_get_temp_dir());
	$response = new \Temma\Web\Response();
	$response->setData($data);
	$response->setValidationContract($contract);
	$view = new \Temma\Views\Json([], $config, $response);
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
$result = render(['json' => ['a' => 1], 'other' => 2] + $frameworkData);
check("data of the 'json' variable are sent", $result === ['a' => 1]);
$result = render([
	'@output' => ['b' => 2],
	'json'    => ['a' => 1],
	'other'   => 3,
] + $frameworkData);
check("data of the '@output' variable take precedence over the 'json' variable", $result === ['b' => 2]);
$result = render(['@output' => 'scalar value', 'json' => ['a' => 1]]);
check("the '@output' variable may contain a scalar value", $result === 'scalar value');
$result = render([
	'foo'       => 'bar',
	'nbr'       => 3,
	'filename'  => 'export.json',
	'jsonDebug' => true,
] + $frameworkData);
check("without '@output' nor 'json', all template variables are sent, without 'filename', 'jsonDebug', 'URL', 'CONTROLLER', 'ACTION' and variables starting with '_', '__' or '___'",
      $result === ['foo' => 'bar', 'nbr' => 3]);
$result = render(['@output' => null, 'json' => null, 'foo' => 'bar']);
check("'@output' and 'json' variables set to null are ignored and not sent", $result === ['foo' => 'bar']);
$result = renderStream($frameworkData);
check("without any application variable, an empty object is sent", $result === '{}');
$result = renderStream(['json' => []]);
check("an empty 'json' variable is sent as an empty list", $result === '[]');
$result = renderStream(['@output' => []]);
check("an empty '@output' variable is sent as an empty list", $result === '[]');

print("\n" . TµAnsi::bold("Keys starting with underscores\n"));
$underscoreData = ['_private' => 1, '__flash' => 2, '___triple' => 3, 'name' => 4];
$result = render(['json' => $underscoreData] + $frameworkData);
check("data of the 'json' variable are sent as is, including keys starting with '_', '__' or '___'", $result === $underscoreData);
$result = render(['@output' => $underscoreData] + $frameworkData);
check("data of the '@output' variable are sent as is, including keys starting with '_', '__' or '___'", $result === $underscoreData);

print("\n" . TµAnsi::bold("Output validation contract\n"));
$result = render(['json' => ['id' => 12, 'name' => 'abc', 'secret' => 'xyz']],
                 ['type' => 'assoc', 'keys' => ['id' => 'int', 'name' => 'string']]);
check("with a contract, validated data are sent (undeclared key removed)", $result === ['id' => 12, 'name' => 'abc']);
$result = render(['@output' => ['id' => '12', 'secret' => 'xyz']],
                 ['type' => 'assoc', 'keys' => ['id' => 'int']]);
check("with a contract, '@output' data are validated and converted", $result === ['id' => 12]);
$result = render([
	'id'        => 5,
	'secret'    => 'xyz',
	'jsonDebug' => true,
] + $frameworkData, ['type' => 'assoc', 'keys' => ['id' => 'int']]);
check("with a contract, all template variables are validated when there is no '@output' nor 'json'", $result === ['id' => 5]);
$result = render(['id' => 5] + $frameworkData,
                 ['type' => 'assoc', 'keys' => ['id' => 'int', '_private?' => 'string', '__flash?' => 'string', '___triple?' => 'string']]);
check("with a contract, template variables starting with '_', '__' or '___' are removed before validation, even if declared in the contract",
      $result === ['id' => 5]);
$result = render(['json' => ['_private' => '1', '__flash' => '2', 'other' => 3]],
                 ['type' => 'assoc', 'keys' => ['_private' => 'int', '__flash' => 'int']]);
check("with a contract, keys starting with '_' or '__' in the 'json' variable are validated like the other keys",
      $result === ['_private' => 1, '__flash' => 2]);

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
