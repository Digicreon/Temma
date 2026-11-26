#!/usr/bin/php
<?php

/**
 * Validation script for the Smarty view (data given to the templates).
 * Creates a response object filled with template variables, processes it with the Smarty view
 * (setTemplate(), init() and sendBody()) and checks the variables received by the template.
 * Compatible with Smarty 4 and Smarty 5. By default the test runs with Smarty 5;
 * pass '4' as the first argument (or SMARTY_VERSION=4) to test with Smarty 4.
 * The other features of the Smarty view are tested by the tests/test_smarty.php script.
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;
use \Temma\Exceptions\Framework as TµFrameworkException;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');
\Temma\Base\Log::disable();
// PHP warnings and notices are converted to exceptions, so they make the tests fail
set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline) {
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

/* ********** LOADING SMARTY (4 or 5) ********** */
require_once(__DIR__ . '/smarty_loader.php');
print(TµAnsi::bold("Smarty view tests (detected engine: Smarty $smartyVersion)\n\n"));

/* ********** TEMPLATE ********** */
$appPath = sys_get_temp_dir() . '/temma-view-smarty-test-' . getmypid();
$templatesPath = "$appPath/templates";
$tmpPath = "$appPath/tmp";
mkdir($templatesPath, 0755, true);
mkdir($tmpPath, 0755, true);
register_shutdown_function(function() use ($appPath) {
	exec('rm -rf ' . escapeshellarg($appPath));
});
file_put_contents("$templatesPath/vars.tpl", 'Hello {$name}!');

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
 * Processes the given template variables with the Smarty view.
 * @param	array		$data		Template variables.
 * @param	null|string|array	$contract	(optional) Output validation contract.
 * @return	array	Associative array with the variables assigned to Smarty ('vars' key), the generated
 *			page ('page' key) and the cacheable flag of the view ('cacheable' key).
 */
function render(array $data, null|string|array $contract=null) : array {
	global $appPath, $templatesPath, $tmpPath;
	$config = new \Temma\Web\Config($appPath, ['_tmpPath' => $tmpPath, '_templatesPath' => $templatesPath]);
	$response = new \Temma\Web\Response();
	$response->setData($data);
	$response->setValidationContract($contract);
	$view = new \Temma\Views\Smarty([], $config, $response);
	$view->setTemplate($templatesPath, 'vars.tpl');
	$view->init();
	ob_start();
	$view->sendBody();
	$page = ob_get_clean();
	// variables assigned to Smarty (without the global variables defined by Smarty itself)
	$smarty = (new \ReflectionProperty($view, '_smarty'))->getValue($view);
	$vars = $smarty->getTemplateVars();
	unset($vars['SCRIPT_NAME']);
	return ([
		'vars'      => $vars,
		'page'      => $page,
		'cacheable' => (new \ReflectionProperty($view, '_isCacheable'))->getValue($view),
	]);
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
$result = render(['name' => 'Alice', 'other' => 2] + $frameworkData);
check("without '@output', all template variables are transmitted, except those starting with a single '_' ('__' and '___' are transmitted)",
      $result['vars'] === ['name' => 'Alice', 'other' => 2, 'URL' => '/user/show/12', 'CONTROLLER' => 'user',
                           'ACTION' => 'show', '__flash' => 'def', '___triple' => 'ghi']);
check("the template is rendered with the transmitted variables", $result['page'] === 'Hello Alice!');
$result = render([
	'@output' => ['name' => 'Bob', '_private' => 'x', '_temmaCacheable' => true, '__flash' => 'y', '___triple' => 'z'],
	'other'   => 2,
] + $frameworkData);
check("with '@output', only its content is transmitted, except the keys starting with a single '_' ('__' and '___' are transmitted)",
      $result['vars'] === ['name' => 'Bob', '__flash' => 'y', '___triple' => 'z']);
check("the template is rendered with the content of '@output'", $result['page'] === 'Hello Bob!');
$result = render(['@output' => ['name' => 'Bob']] + $frameworkData);
check("with '@output', template variables starting with '__' or '___' are not transmitted", $result['vars'] === ['name' => 'Bob']);
$result = render(['@output' => null, 'name' => 'Alice']);
check("an '@output' variable set to null is ignored and not transmitted", $result['vars'] === ['name' => 'Alice']);

print("\n" . TµAnsi::bold("Cache flag\n"));
$result = render(['name' => 'Alice', '_temmaCacheable' => true]);
check("'_temmaCacheable' is detected without '@output'", $result['cacheable'] === true);
$result = render(['@output' => ['name' => 'Bob'], '_temmaCacheable' => true]);
check("'_temmaCacheable' is detected with '@output'", $result['cacheable'] === true);
$result = render(['@output' => ['name' => 'Bob'], '_temmaCacheable' => true], ['type' => 'assoc', 'keys' => ['name' => 'string']]);
check("'_temmaCacheable' is detected with a validation contract", $result['cacheable'] === true);
$result = render(['@output' => ['name' => 'Bob', '_temmaCacheable' => true]]);
check("'_temmaCacheable' in the content of '@output' is ignored (it must be a template variable)", $result['cacheable'] === false);
$result = render(['name' => 'Alice', '__temmaCacheable' => true]);
check("'__temmaCacheable' (two underscores) doesn't make the page cacheable", $result['cacheable'] === false);
$result = render(['name' => 'Alice', '_temmaCacheable' => 1]);
check("'_temmaCacheable' must be strictly true", $result['cacheable'] === false);

print("\n" . TµAnsi::bold("Bad '@output' variable\n"));
$code = null;
try {
	render(['@output' => 'scalar value']);
} catch (TµFrameworkException $e) {
	$code = $e->getCode();
}
check("an '@output' variable which is not an array raises a Framework exception", $code === TµFrameworkException::BAD_OUTPUT);
foreach ([['Alice', 'Bob'], ['name' => 'Alice', 3 => 'Bob']] as $output) {
	$code = null;
	try {
		render(['@output' => $output]);
	} catch (TµFrameworkException $e) {
		$code = $e->getCode();
	}
	check("an '@output' variable with integer keys (" . json_encode($output) . ") raises a Framework exception", $code === TµFrameworkException::BAD_OUTPUT);
}

print("\n" . TµAnsi::bold("Output validation contract\n"));
$result = render(['name' => 'Alice', 'other' => 2] + $frameworkData,
                 ['type' => 'assoc', 'keys' => ['name' => 'string', '__flash' => 'string']]);
check("with a contract, validated template variables are transmitted (undeclared keys removed)",
      $result['vars'] === ['name' => 'Alice', '__flash' => 'def']);
$result = render(['@output' => ['name' => 'Bob', 'other' => '3', 'secret' => 'x']],
                 ['type' => 'assoc', 'keys' => ['name' => 'string', 'other' => 'int']]);
check("with a contract, '@output' data are validated and converted", $result['vars'] === ['name' => 'Bob', 'other' => 3]);
$result = render(['name' => 'Alice'] + $frameworkData,
                 ['type' => 'assoc', 'keys' => ['name' => 'string', '_private?' => 'string', '_temmaCacheable?' => 'bool']]);
check("with a contract, variables starting with a single '_' are removed before validation, even if declared in the contract",
      $result['vars'] === ['name' => 'Alice']);
$result = render(['name' => 'Alice'] + $frameworkData,
                 ['type' => 'assoc', 'keys' => ['name' => 'string', '___triple' => 'string']]);
check("with a contract, variables starting with '__' or '___' are validated like the other variables (undeclared ones removed)",
      $result['vars'] === ['name' => 'Alice', '___triple' => 'ghi']);
$result = render(['@output' => ['name' => 'Bob', '_private' => 'x', '__flash' => 'y', '___triple' => 'z']],
                 ['type' => 'assoc', 'keys' => ['name' => 'string', '_private?' => 'string', '__flash' => 'string']]);
check("with a contract and '@output', keys starting with a single '_' are removed, those starting with '__' or '___' are validated",
      $result['vars'] === ['name' => 'Bob', '__flash' => 'y']);

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
