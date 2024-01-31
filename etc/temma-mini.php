<?php

// minimum configuration file for demonstration
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

