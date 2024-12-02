<?php

/**
 * autoload.php
 *
 * This file is used for testing purposes with PHPUnit (see documentation).
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023-2024, Amaury Bouchard
 * @link	https://www.temma.net/en/documentation/tests
 */

// include path configuration
set_include_path(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'lib' . PATH_SEPARATOR . get_include_path());

// Temma autoloader
@include_once('Temma/Base/Autoload.php');

// Composer autoloader
@include_once(__DIR__ . '/../vendor/autoload.php');

// Temma autoloader init
\Temma\Base\Autoload::autoload();

// log init
\Temma\Base\Log::logToStdOut();

