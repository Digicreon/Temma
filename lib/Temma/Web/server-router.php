<?php

/**
 * Router script for the PHP built-in web server, used by the 'bin/comma Temma/serve' command.
 * It reproduces the framework's usual rewrite rule: existing files are served as-is,
 * any other request is routed to the front controller.
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/cli
 */

$path = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
// existing file: let the built-in server serve it as-is
if (!str_contains($path, '..') && is_file($_SERVER['DOCUMENT_ROOT'] . $path))
	return (false);
// any other request: front controller
require($_SERVER['DOCUMENT_ROOT'] . '/index.php');
