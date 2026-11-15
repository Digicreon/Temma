#!/usr/bin/php
<?php

/**
 * Validation script for the CSV view.
 * Creates a response object filled with template variables, processes it with the CSV view
 * (init() and sendBody()) and checks the generated CSV stream.
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
/**
 * Initializes the CSV view with the given template variables.
 * @param	array	$data	Template variables.
 * @return	\Temma\Views\Csv	The initialized view.
 */
function initView(array $data) : \Temma\Views\Csv {
	$config = new \Temma\Web\Config(sys_get_temp_dir());
	$response = new \Temma\Web\Response();
	$response->setData($data);
	$view = new \Temma\Views\Csv([], $config, $response);
	$view->init();
	return ($view);
}
// processes the given template variables with the CSV view, and returns the generated stream
function render(array $data) : string {
	$view = initView($data);
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
];

/* ********** TESTS ********** */
print(TµAnsi::bold("Data source\n"));
$result = render(['csv' => [['a', 'b'], [1, 2]], 'other' => 3] + $frameworkData);
check("data of the 'csv' variable are sent", $result === "a,b\n1,2\n");
$result = render([
	'@output' => [['c', 'd']],
	'csv'     => [['a', 'b']],
	'other'   => 3,
] + $frameworkData);
check("data of the '@output' variable take precedence over the 'csv' variable", $result === "c,d\n");
$result = render(['@output' => null, 'csv' => [['a', 'b']]]);
check("an '@output' variable set to null is ignored", $result === "a,b\n");
$view = initView(['foo' => 'bar'] + $frameworkData);
check("without '@output' nor 'csv', no data is used (template variables are not used)",
      (new \ReflectionProperty($view, '_csv'))->getValue($view) === null);
$result = render(['foo' => 'bar'] + $frameworkData);
check("without '@output' nor 'csv', nothing is sent", $result === '');

print("\n" . TµAnsi::bold("Options\n"));
$result = render(['csv' => [['a', 'b'], [1, 2]], 'separator' => ';']);
check("the 'separator' variable defines the separator character", $result === "a;b\n1;2\n");
$view = initView(['csv' => [['a']], 'filename' => 'export.csv']);
check("the 'filename' variable defines the name of the downloaded file",
      (new \ReflectionProperty($view, '_filename'))->getValue($view) === 'export.csv');

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
