#!/usr/bin/php
<?php

/**
 * Validation script for the RSS view.
 * Creates a response object filled with template variables, processes it with the RSS view
 * (init() and sendBody()) and checks the generated RSS stream.
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');
\Temma\Base\Log::disable();
// PHP warnings and notices are converted to exceptions, so they make the tests fail
set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline) {
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

/* ********** HELPERS ********** */
$count = 0;
$failed = 0;
// displays the result of a test
function check(string $label, bool $ok) : void {
	global $count, $failed;
	$count++;
	if (!$ok)
		$failed++;
	print(TµAnsi::faint(sprintf('%02d', $count)) . ' ' .
	      TµAnsi::color(($ok ? 'green' : 'red'), ($ok ? 'OK' : 'KO')) . ' ' .
	      "$label\n");
}
// processes the given template variables with the RSS view, and returns the generated stream
function render(array $data) : string {
	$config = new \Temma\Web\Config(sys_get_temp_dir());
	$response = new \Temma\Web\Response();
	$response->setData($data);
	$view = new \Temma\Views\Rss([], $config, $response);
	$view->init();
	ob_start();
	$view->sendBody();
	return (ob_get_clean());
}
// RSS data
$feed1 = [
	'domain'      => 'https://www.example.com',
	'title'       => 'First feed',
	'description' => 'Description of the first feed',
	'articles'    => [
		[
			'title' => 'First article',
			'url'   => 'https://www.example.com/article/1',
		],
	],
];
$feed2 = [
	'domain'      => 'https://www.example.org',
	'title'       => 'Second feed',
	'description' => 'Description of the second feed',
	'articles'    => [
		[
			'title' => 'Second article',
			'url'   => 'https://www.example.org/article/2',
		],
	],
];
// template variables defined by the framework, and private variables
$frameworkData = [
	'URL'             => '/user/show/12',
	'CONTROLLER'      => 'user',
	'ACTION'          => 'show',
	'_temmaCacheable' => true,
	'_private'        => 'abc',
	'__flash'         => 'def',
];

/* ********** TESTS ********** */
print(TµAnsi::bold("Data source\n"));
$result = render($feed1 + $frameworkData);
check("without '@output', template variables are used",
      str_contains($result, '<title>First feed</title>') &&
      str_contains($result, '<description>Description of the first feed</description>') &&
      str_contains($result, '<link>https://www.example.com/</link>') &&
      str_contains($result, '<title>First article</title>'));
$result = render(['@output' => $feed2] + $feed1 + $frameworkData);
check("data of the '@output' variable take precedence over template variables",
      str_contains($result, '<title>Second feed</title>') &&
      str_contains($result, '<title>Second article</title>') &&
      !str_contains($result, 'First'));
$result = render(['@output' => null] + $feed1);
check("an '@output' variable set to null is ignored", str_contains($result, '<title>First feed</title>'));

print("\n" . TµAnsi::bold("Articles\n"));
$data = $feed1;
unset($data['articles']);
$result = render($data);
check("without articles, the feed is sent without items",
      str_contains($result, '<title>First feed</title>') && !str_contains($result, '<item>') && str_ends_with($result, "</channel>\n</rss>"));
$images = [];
foreach (['jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'] as $type => $mimetype) {
	$images[$type] = [
		'title'     => "Article $type",
		'url'       => "https://www.example.com/article/$type",
		'image'     => "https://www.example.com/image.$type",
		'imageType' => $type,
	];
}
$result = render(['articles' => array_values($images)] + $feed1);
check("the MIME type of the articles' images depends on their type (jpg, png, gif, webp)",
      str_contains($result, '<enclosure type="image/jpeg" length="10000" url="https://www.example.com/image.jpg"/>') &&
      str_contains($result, '<enclosure type="image/png" length="10000" url="https://www.example.com/image.png"/>') &&
      str_contains($result, '<enclosure type="image/gif" length="10000" url="https://www.example.com/image.gif"/>') &&
      str_contains($result, '<enclosure type="image/webp" length="10000" url="https://www.example.com/image.webp"/>'));

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
