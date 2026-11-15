<?php

/**
 * Loads the Smarty library (version 4 or 5) for the validation scripts that need it.
 * By default Smarty 5 is loaded; pass '4' as the first argument of the script (or SMARTY_VERSION=4) to load Smarty 4.
 * Smarty is searched in the lib/ directory (lib/Smarty for Smarty 5, lib/smarty4 for Smarty 4), otherwise extracted
 * from the archive cached in /tmp (/tmp/smarty-5.4.1.tgz or /tmp/smarty-4.5.4.tgz).
 * Defines the $smartyVersion variable (4 or 5). Must be included after the initialization of the Temma autoloader.
 */

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
// cleanup of the extracted archive at the end of execution
register_shutdown_function(function() use ($extractDir) {
	exec('rm -rf ' . escapeshellarg($extractDir));
});
