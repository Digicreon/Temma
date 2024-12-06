<?php

/**
 * Temma framework bootstrap script.
 *
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2024, Amaury Bouchard
 * @package	Temma
 */

// Composer autoloader
@include_once(__DIR__ . '/../vendor/autoload.php');

// Temma autoloader with include path
@include_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');
\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');

// start the bootloader
\Temma\Web\Bootloader::bootloader();

