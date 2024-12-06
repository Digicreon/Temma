<?php

/**
 * Extended configuration file for demonstration.
 *
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2024, Amaury Bouchard
 * @link	https://www.temma.net/documentation/configuration
 */
return [
	'application' => [
		// data sources
		'dataSources' => [
			'db'    => 'mysql://user:passwd@localhost/mybase',
			'ndb'   => 'redis://localhost:6379/0',
			'cache' => 'memcache://192.168.0.1:11211;192.168.0.2:11211',
		],
		'enableSessions'    => true,
		'sessionName'       => 'TemmaSession',
		'sessionSource'     => 'ndb',
		'cookieDomain'      => 'admin.mydomain.com',
		'defaultNamespace'  => '\MyApp\Controllers',
		'rootController'    => 'Homepage',
		'defaultController' => 'NotFound',
		'proxyController'   => 'Main',
		'defaultView'       => '\Temma\Views\SmartyView',
		'loader'            => 'MyLoader',
		'logFile'           => 'log/temma.log',
		'logManager'        => [ 'ElasticLogManager', 'SentryLogManager' ]
	],
	'loglevels' => [
		'Temma/Base'  => 'ERROR',
		'Temma/Web'   => 'WARN',
		'Temma/Asynk' => 'INFO',
		'myapp'       => 'DEBUG',
		'default'     => 'NOTE',
	],
	'routes' => [
		'sitemap.xml'          => 'SitemapController',
		'robots.txt'           => 'RobotsController',
		'sitemap.extended.xml' => 'sitemap.xml',
	],
	'plugins' => [
		'_pre' => [
			'CheckRequestPlugin',
			'UserGrantPlugin',
		],
		'_post' => [ 'AddCrossLinksPlugin' ],
		'BobControler' => [
			'_pre' => [ 'SomethingPlugin' ],
			'_post' => [ 'SomethingElsePlugin' ],
			'index' => [
				'_pre' => [ "AaaPlugin" ],
				'_post' => [ "BbbPlugin" ],
			],
			'setData' => [
				'_pre' => [ "CccPlugin" ],
			],
		],
	],
	'errorPages' => [
		'404'     => 'path/to/page.html',
		'500'     => 'path/to/page.html',
		'default' => 'path/to/page.html',
	],
	'includePaths' => [
		'/opt/some_library/lib',
		'/opt/other_lib',
	],
	'autoimport' => [
		'googleId'          => 'azeazeaez',
		'googleAnalyticsId' => 'azeazeazeaze',
	],
	'x-homepage' => [
		'title'       => 'Site title',
		'description' => 'Site description',
	],
	'x-email' => [
		'senderAddress' => 'admin@localhost.localdomain',
		'senderName'    => 'Administrateur',
	],
];

