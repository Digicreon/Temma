#!/usr/bin/env php
<?php

/**
 * Script used to generate a configuration object fron the JSON configuration file of a Temma project.
 *
 * Usage: ./configObjectGenerator.php /path/to/the/project/root/directory
 *
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2012-2022
 */

require_once('Temma/Base/Autoload.php');
\Temma\Base\Autoload::autoload();

// check parameter
if ($_SERVER['argc'] != 2 || $_SERVER['argv'][1] === '--help' || $_SERVER['argv'][1] === '-h' ||
    !($appPath = realpath($_SERVER['argv'][1])) || !is_readable("$appPath/etc/temma.json")) {
	print("Usage: {$_SERVER['argv'][0]} /path/to/the/project/root/directory\n");
	exit(1);
}

// move the previously created files
$filePath = "$appPath/etc/" . \Temma\Web\Config::PHP_CONFIG_FILE_NAME;
if (file_exists($filePath)) {
	for ($i = 1; true; $i++) {
		if (!file_exists("$filePath.$i"))
			break;
	}
	for (; $i; $i--) {
		$oldName = $filePath . (($i > 1) ? ('.' . ($i - 1)) : '');
		$newName = "$filePath.$i";
		rename($oldName, $newName);
	}
}

// read the configuration
$conf = new \Temma\Web\Config($appPath);
$conf->readConfigurationFile();
// generate the code
$php = '<' . "?php\n\n// Configuration object generated from the 'temma.json' file, using the 'configObjectGenerator.php' program\n" .
       '// Generation date: ' . date('c') . "\n\n" .
       '$_globalTemmaConfig = ' . var_export($conf, true) . ';';
// write the generated code
file_put_contents($filePath, $php);

