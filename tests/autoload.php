<?php

/**
 * autoload.php
 *
 * This file is used for testing purposes with PHPUnit (see documentation).
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023-2024, Amaury Bouchard
 * @link	https://www.temma.net/documentation/tests
 */

// Composer autoloader
@include_once(__DIR__ . '/../vendor/autoload.php');

// Temma autoloader with include path
@include_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');
\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');

// log init
\Temma\Base\Log::logToStdOut();

