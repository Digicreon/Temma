<?php

/**
 * Minimum configuration file for demonstration.
 *
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2024, Amaury Bouchard
 * @link	https://www.temma.net/en/documentation/configuration
 */
return [
	'application' => [
		// data sources
		'dataSources' => [
			// connection to a MySQL server
			'db' => 'mysql://user:passwd@localhost/mybase',
		],
		// root controller
		'rootController' => 'HomepageController',
	],
	// threshold for log messages
	'loglevels' => 'WARN',
	// HTML page sent when an error occurs
	'errorPages' => 'error404.html',
];

