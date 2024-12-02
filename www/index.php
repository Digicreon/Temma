<?php

/**
 * Temma framework bootstrap script.
 *
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2024, Amaury Bouchard
 * @package	Temma
 */

// include path configuration
set_include_path(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'lib' . PATH_SEPARATOR . get_include_path());

// Temma autoloader
@include_once('Temma/Base/Autoload.php');

// Composer autoloader
@include_once(__DIR__ . '/../vendor/autoload.php');

// Temma autoloader init
\Temma\Base\Autoload::autoload();

\Temma\Web\Bootloader::bootloader();

