#!/usr/bin/php
<?php

/**
 * Validation script for the Smarty view and the Smarty utility object.
 * Compatible with Smarty 4 and Smarty 5. By default the test runs with Smarty 5;
 * pass '4' as the first argument (or SMARTY_VERSION=4) to test with Smarty 4.
 * Examples: `php tests/test_smarty.php` or `php tests/test_smarty.php 4`.
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Base\Loader as TµLoader;
use \Temma\Utils\Ansi as TµAnsi;
use \Temma\Utils\Smarty as TµUtilsSmarty;
use \Temma\Views\Smarty as TµViewSmarty;
use \Temma\Utils\Email as TµEmail;
use \Temma\Exceptions\IO as TµIOException;

// initialization of the Temma autoloader
\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');

/* ********** LOCATING SMARTY (4 or 5) ********** */
$wantedVersion = $argv[1] ?? getenv('SMARTY_VERSION') ?: '5';
$extractDir = sys_get_temp_dir() . '/temma-test-smarty-' . getmypid();
if ($wantedVersion == '4' && !class_exists('\Smarty') && !class_exists('\Smarty\Smarty')) {
	// Smarty 4: copy in lib/smarty4, otherwise extraction of the cached archive
	$libs = null;
	if (is_dir(__DIR__ . '/../lib/smarty4'))
		$libs = realpath(__DIR__ . '/../lib/smarty4');
	else if (is_file('/tmp/smarty-4.5.4.tgz')) {
		if (!is_dir($extractDir))
			mkdir($extractDir, 0755, true);
		(new PharData('/tmp/smarty-4.5.4.tgz'))->extractTo($extractDir, null, true);
		$libs = "$extractDir/smarty-4.5.4/libs";
	}
	if (!$libs || !is_dir($libs)) {
		fwrite(STDERR, "Smarty 4 not found. Copy its 'libs/' into lib/smarty4, " .
		               "or put the archive in /tmp/smarty-4.5.4.tgz.\n");
		exit(2);
	}
	include_once("$libs/Autoloader.php");
	include_once("$libs/bootstrap.php");
	require_once("$libs/Smarty.class.php");
} else if (!class_exists('\Smarty\Smarty') && !class_exists('\Smarty')) {
	// Smarty 5: copy in lib/Smarty, otherwise extraction of the cached archive
	$srcDir = null;
	if (is_dir(__DIR__ . '/../lib/Smarty'))
		$srcDir = realpath(__DIR__ . '/../lib/Smarty');
	else if (is_file('/tmp/smarty-5.4.1.tgz')) {
		if (!is_dir($extractDir))
			mkdir($extractDir, 0755, true);
		(new PharData('/tmp/smarty-5.4.1.tgz'))->extractTo($extractDir, null, true);
		$srcDir = "$extractDir/smarty-5.4.1/src";
	}
	if (!$srcDir || !is_dir($srcDir)) {
		fwrite(STDERR, "Smarty 5 not found. Install it via Composer, copy 'src/' into lib/Smarty, " .
		               "or put the archive in /tmp/smarty-5.4.1.tgz.\n");
		exit(2);
	}
	spl_autoload_register(function($class) use ($srcDir) {
		if (str_starts_with($class, 'Smarty\\')) {
			$file = $srcDir . '/' . str_replace('\\', '/', substr($class, 7)) . '.php';
			if (is_file($file))
				require_once($file);
		}
	});
	require_once($srcDir . '/functions.php');
}
$smartyVersion = class_exists('\Smarty\Smarty') ? 5 : 4;
print(TµAnsi::bold("Smarty tests (detected engine: Smarty $smartyVersion)\n"));

/* ********** FAKE APPLICATION DIRECTORY TREE ********** */
$appPath = sys_get_temp_dir() . '/temma-smarty-app-' . getmypid();
$templatesPath = "$appPath/templates";
$tmpPath = "$appPath/tmp";
foreach ([$appPath, $templatesPath, $tmpPath] as $dir) {
	if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
		fwrite(STDERR, "Unable to create the directory '$dir'.\n");
		exit(2);
	}
}
// test templates
file_put_contents("$templatesPath/hello.tpl", 'Hello {$name}!');
file_put_contents("$templatesPath/escape.tpl", '{$html}');
file_put_contents("$templatesPath/trim.tpl", '[{$s|trim}]');
file_put_contents("$templatesPath/nbsp.tpl", '{$s|nbsp}');
file_put_contents("$templatesPath/mail_html.tpl", '<p>{$name}</p>');
file_put_contents("$templatesPath/mail_text.tpl", 'Name: {$name}');
// cleanup at the end of execution
register_shutdown_function(function() use ($appPath, $extractDir) {
	exec('rm -rf ' . escapeshellarg($appPath) . ' ' . escapeshellarg($extractDir));
});
// Temma plugins directory (to test registration via 'pluginsDir')
$temmaPluginsDir = realpath(__DIR__ . '/../lib/smarty-plugins');

/* ********** CONFIGURATION FACTORY ********** */
/**
 * Builds a configuration object for the fake application.
 * @param	array	$xtra	Extended configuration sections, e.g. ['smarty' => ['autoEscape' => false]].
 * @return	\Temma\Web\Config	The configuration object.
 */
function makeConfig(array $xtra=[]) : \Temma\Web\Config {
	global $appPath, $templatesPath, $tmpPath;
	$config = new \Temma\Web\Config($appPath, [
		'_tmpPath'       => $tmpPath,
		'_templatesPath' => $templatesPath,
	]);
	foreach ($xtra as $name => $keys) {
		foreach ($keys as $key => $value)
			$config->setXtra($name, $key, $value);
	}
	return ($config);
}
/**
 * Builds a Smarty utility object from a configuration.
 * @param	array	$xtra	Extended configuration sections.
 * @return	\Temma\Utils\Smarty	The utility object.
 */
function makeUtil(array $xtra=[]) : TµUtilsSmarty {
	$loader = new TµLoader(['config' => makeConfig($xtra)]);
	return ($loader['\Temma\Utils\Smarty']);
}

/* ********** TEST MICRO-FRAMEWORK ********** */
$count = 0;
$failed = 0;
function check(string $label, bool $ok) : void {
	global $count, $failed;
	$count++;
	if (!$ok)
		$failed++;
	print(TµAnsi::faint(sprintf('%02d', $count)) . ' ' .
	      TµAnsi::color(($ok ? 'green' : 'red'), ($ok ? 'OK' : 'KO')) . ' ' .
	      "$label\n");
}

/* ********** TESTS: UTILITY OBJECT ********** */
print(TµAnsi::bold("Utility object: basic rendering\n"));
$util = makeUtil();
check("render() of a file template with a variable",
      $util->render('hello.tpl', ['name' => 'World']) === 'Hello World!');
check("eval() of a string template with a variable",
      $util->eval('X={$v}', ['v' => 42]) === 'X=42');
check("templateExists() true for an existing template",
      $util->templateExists('hello.tpl') === true);
check("templateExists() false for a missing template",
      $util->templateExists('nope.tpl') === false);
$exception = false;
try {
	$util->render('nope.tpl', null);
} catch (TµIOException $e) {
	$exception = true;
}
check("render() of a nonexistent template: TµIOException", $exception);

print(TµAnsi::bold("Utility object: plugins and modifiers\n"));
check("native PHP modifier (trim)",
      $util->render('trim.tpl', ['s' => '  hi  ']) === '[hi]');
$utilPlugins = makeUtil(['smarty' => ['pluginsDir' => $temmaPluginsDir, 'autoEscape' => false]]);
check("Temma plugin registered via pluginsDir (nbsp)",
      $utilPlugins->render('nbsp.tpl', ['s' => 'a :']) === 'a&nbsp;:');

print(TµAnsi::bold("Utility object: HTML escaping\n"));
check("escaping enabled by default",
      $util->render('escape.tpl', ['html' => '<b>x</b>']) === '&lt;b&gt;x&lt;/b&gt;');
check("per-call deactivation (autoEscape: false)",
      $util->render('escape.tpl', ['html' => '<b>x</b>'], false) === '<b>x</b>');
check("instance setting restored after a deactivated call",
      $util->render('escape.tpl', ['html' => '<b>x</b>']) === '&lt;b&gt;x&lt;/b&gt;');

/* ********** TESTS: CONFIGURATION ********** */
print(TµAnsi::bold("Escaping configuration\n"));
$utilOff = makeUtil(['smarty' => ['autoEscape' => false]]);
check("x-smarty / autoEscape=false disables escaping",
      $utilOff->render('escape.tpl', ['html' => '<i>y</i>']) === '<i>y</i>');
$utilLegacy = makeUtil(['smarty-view' => ['autoEscape' => false]]);
check("legacy fallback: x-smarty-view / autoEscape=false disables it too",
      $utilLegacy->render('escape.tpl', ['html' => '<i>y</i>']) === '<i>y</i>');
$utilConflict = makeUtil([
	'smarty'      => ['autoEscape' => true],
	'smarty-view' => ['autoEscape' => false],
]);
check("x-smarty takes precedence over x-smarty-view in case of conflict",
      $utilConflict->render('escape.tpl', ['html' => '<i>y</i>']) === '&lt;i&gt;y&lt;/i&gt;');
$utilLegacyPlugins = makeUtil(['smarty-view' => ['pluginsDir' => $temmaPluginsDir], 'smarty' => ['autoEscape' => false]]);
check("legacy fallback: pluginsDir via x-smarty-view",
      $utilLegacyPlugins->render('nbsp.tpl', ['s' => 'a :']) === 'a&nbsp;:');

/* ********** TESTS: VIEW ********** */
print(TµAnsi::bold("Smarty view\n"));
/**
 * Renders a template through the Smarty view and returns the captured output.
 * @param	string	$template	Template name.
 * @param	array	$data		Template data.
 * @param	array	$xtra		Extended configuration.
 * @return	string	The rendered output.
 */
function renderView(string $template, array $data, array $xtra=[]) : string {
	global $templatesPath;
	$config = makeConfig($xtra);
	$response = new \Temma\Web\Response();
	$response->setData($data);
	$view = new TµViewSmarty([], $config, $response);
	$view->setTemplate($templatesPath, $template);
	$view->init();
	ob_start();
	$view->sendBody();
	return (ob_get_clean());
}
check("rendering of a template with a variable",
      renderView('hello.tpl', ['name' => 'Temma']) === 'Hello Temma!');
check("escaping enabled by default in the view",
      renderView('escape.tpl', ['html' => '<b>z</b>']) === '&lt;b&gt;z&lt;/b&gt;');
check("escaping disabled via x-smarty / autoEscape=false",
      renderView('escape.tpl', ['html' => '<b>z</b>'], ['smarty' => ['autoEscape' => false]]) === '<b>z</b>');
$exception = false;
try {
	$config = makeConfig();
	$view = new TµViewSmarty([], $config, new \Temma\Web\Response());
	$view->setTemplate($templatesPath, 'nope.tpl');
} catch (TµIOException $e) {
	$exception = true;
}
check("setTemplate() of a nonexistent template: TµIOException", $exception);

/* ********** TESTS: CONSTANTS ********** */
print(TµAnsi::bold("View constant aliases\n"));
check("COMPILED_DIR aliased to the utility object",
      TµViewSmarty::COMPILED_DIR === TµUtilsSmarty::COMPILED_DIR);
check("CACHE_DIR aliased to the utility object",
      TµViewSmarty::CACHE_DIR === TµUtilsSmarty::CACHE_DIR);
check("PLUGINS_DIR aliased to the utility object",
      TµViewSmarty::PLUGINS_DIR === TµUtilsSmarty::PLUGINS_DIR);
check("DEFAULT_AUTO_ESCAPE aliased to the utility object",
      TµViewSmarty::DEFAULT_AUTO_ESCAPE === TµUtilsSmarty::DEFAULT_AUTO_ESCAPE);

/* ********** TESTS: TEMPLATED EMAIL ********** */
print(TµAnsi::bold("Templated email: HTML vs text escaping\n"));
// test subclass that captures the generated bodies instead of sending an email
class CapturingEmail extends TµEmail {
	public string $capturedHtml = '';
	public ?string $capturedText = null;
	public function mimeMail(string $from, string|array $to, string $title='', string $html='', ?string $text=null,
	                         ?array $attachments=null, string|array $cc='', string|array $bcc='',
	                         ?string $unsubscribe=null, ?string $envelopeSender=null) : void {
		$this->capturedHtml = $html;
		$this->capturedText = $text;
	}
}
$loader = new TµLoader(['config' => makeConfig()]);
$email = new CapturingEmail($loader);
$email->templatedMail('from@test.com', 'to@test.com', 'Sujet', 'mail_html.tpl', 'mail_text.tpl',
                      ['name' => '<script>']);
check("HTML template escaped (instance setting)",
      $email->capturedHtml === '<p>&lt;script&gt;</p>');
$email2 = new CapturingEmail($loader);
$email2->templatedMail('from@test.com', 'to@test.com', 'Sujet', null, 'mail_text.tpl',
                       ['name' => 'R&D']);
check("text template never escaped",
      $email2->capturedText === 'Name: R&D');

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
