<?php

/**
 * Minimum configuration file for API demonstration.
 *
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2024, Amaury Bouchard
 * @link	https://www.temma.net/documentation/configuration
 */
return [
	'application' => [
		// data sources
		'dataSources' => [
			// connection to a MySQL server
			'db' => 'mysql://user:passwd@localhost/mybase',
		],
	],
	// threshold for log messages
	'loglevels' => 'WARN',
	// plugins
	'plugins' => [
		'_pre' => [
			'\Temma\Plugins\Api',
		],
	],
];

