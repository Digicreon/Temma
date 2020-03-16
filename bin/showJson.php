#!/usr/bin/php
<?php

/**
 * Helper script to visualize the content of a JSON file.
 *
 * @author	Amaury Bouchard <amaury@iamaury.net>
 * @copyright	Copyright (c) 2007-2019, Amaury Bouchard
 */

// param check
if ($_SERVER['argc'] != 2 || $_SERVER['argv'][1] == '-h' || $_SERVER['argv'][1] == '--help') {
	print("Usage: showJson.php file.json\n");
	exit(1);
}

print(json_encode(json_decode(file_get_contents($_SERVER['argv'][1]), true), JSON_PRETTY_PRINT));

