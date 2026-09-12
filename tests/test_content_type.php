#!/usr/bin/php
<?php

/**
 * Validation script for the response content type management.
 * Builds a dummy application whose controllers define the content type of the response
 * (using the _contentType() method or the ContentType attribute), then checks:
 * - the content type stored in the Response object after each request;
 * - the aliases management;
 * - the headers built by the views (Content-Type substitution, charset management).
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;
use \Temma\Exceptions\Framework as TµFrameworkException;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');
\Temma\Base\Log::disable();

/* ********** DUMMY APPLICATION ********** */
$appPath = sys_get_temp_dir() . '/temma-content-type-test-' . getmypid();
foreach (['controllers', 'etc', 'log', 'tmp', 'templates'] as $dir)
	mkdir("$appPath/$dir", 0755, true);
register_shutdown_function(function() use ($appPath) {
	exec('rm -rf ' . escapeshellarg($appPath));
});

$classes = [
	// controller using the _contentType() method
	'Direct' => <<<'EOT'
		class Direct extends \Temma\Web\Controller {
			public function none() { }
			public function text() { $this->_contentType('text/plain'); }
			public function alias() { $this->_contentType('json'); }
			public function reset() {
				$this->_contentType('image/png');
				$this->_contentType(null);
			}
		}
		EOT,
	// controller using the ContentType attribute on its actions
	'Attrmethod' => <<<'EOT'
		use \Temma\Attributes\ContentType as TµContentType;

		class Attrmethod extends \Temma\Web\Controller {
			#[TµContentType('application/json')]
			public function json() { }
			#[TµContentType('csv')]
			public function alias() { }
			#[TµContentType('image/png')]
			public function overridden() { $this->_contentType('image/jpeg'); }
		}
		EOT,
	// controller using the ContentType attribute on the class
	'Attrclass' => <<<'EOT'
		use \Temma\Attributes\ContentType as TµContentType;

		#[TµContentType('text/markdown')]
		class Attrclass extends \Temma\Web\Controller {
			public function inherited() { }
			#[TµContentType]
			public function reset() { }
			#[TµContentType('text/calendar')]
			public function replaced() { }
		}
		EOT,
];
foreach ($classes as $name => $code)
	file_put_contents("$appPath/controllers/$name.php", "<?php\n\n$code\n");

// configuration, with a default header
file_put_contents("$appPath/etc/temma.php", <<<'EOT'
	<?php

	return [
		'application' => [
			'enableSessions' => false,
		],
		'loglevels' => 'CRIT',
		'x-headers' => [
			'default' => [
				'X-Test' => 'default',
			],
		],
	];
	EOT);

// minimal view, used to test the base view behavior
class BaseView extends \Temma\Web\View {
	public function sendBody() : void {
	}
}

/* ********** TEST MICRO-FRAMEWORK ********** */
$count = 0;
$failed = 0;
function check(string $label, bool $ok) : void {
	global $count, $failed;
	$count++;
	if (!$ok)
		$failed++;
	print(TµAnsi::faint(sprintf('%02d', $count)) . ' ' .
	      TµAnsi::color(($ok ? 'green' : 'red'), ($ok ? 'OK' : 'KO')) . ' ' .
	      "$label\n");
}
// executes a request and returns the content type stored in the response
function getContentType(string $url) : ?string {
	global $appPath;
	$test = new \Temma\Web\Test($appPath, "$appPath/etc/temma.php");
	return ($test->execResponse($url)->getContentType());
}
// creates a view with the given response content type, and returns the headers it builds
function buildHeaders(string $viewClass, ?string $contentType, ?array $headers=null) : array {
	global $appPath;
	$config = new \Temma\Web\Config($appPath);
	$config->readConfigurationFile("$appPath/etc/temma.php");
	$response = new \Temma\Web\Response();
	$response->setContentType($contentType);
	$view = new $viewClass([], $config, $response);
	return ($view->buildHeaders($headers));
}
// extracts the Content-Type headers from a list of headers
function contentTypes(array $headers) : array {
	return (array_values(array_filter($headers, fn($header) => str_starts_with(mb_strtolower($header), 'content-type:'))));
}

/* ********** TESTS ********** */
print(TµAnsi::bold("Response object\n"));
$response = new \Temma\Web\Response();
check("no content type by default",
      $response->getContentType() === null);
$response->setContentType('image/png');
check("full MIME type stored as is",
      $response->getContentType() === 'image/png');
$response->setContentType(' json ');
check("alias resolved to the full MIME type (trimmed)",
      $response->getContentType() === 'application/json');
$response->setContentType('CSV');
check("alias resolution is case-insensitive",
      $response->getContentType() === 'text/csv');
$response->setContentType(null);
check("null removes the content type",
      $response->getContentType() === null);
$response->setContentType('image/png');
$response->setContentType('');
check("empty string removes the content type",
      $response->getContentType() === null);
try {
	$response->setContentType('markdown');
	check("unknown alias throws a Framework exception (CONFIG)", false);
} catch (TµFrameworkException $e) {
	check("unknown alias throws a Framework exception (CONFIG)", $e->getCode() === TµFrameworkException::CONFIG);
}

print("\n" . TµAnsi::bold("Controller: _contentType() method\n"));
check("no content type when nothing is defined",
      getContentType('/direct/none') === null);
check("_contentType('text/plain')",
      getContentType('/direct/text') === 'text/plain');
check("_contentType('json') resolves the alias",
      getContentType('/direct/alias') === 'application/json');
check("_contentType(null) resets the content type",
      getContentType('/direct/reset') === null);

print("\n" . TµAnsi::bold("Attribute on actions\n"));
check("#[TµContentType('application/json')] on an action",
      getContentType('/attrmethod/json') === 'application/json');
check("#[TµContentType('csv')] resolves the alias",
      getContentType('/attrmethod/alias') === 'text/csv');
check("_contentType() called in the action overrides the attribute",
      getContentType('/attrmethod/overridden') === 'image/jpeg');

print("\n" . TµAnsi::bold("Attribute on the controller\n"));
check("class-level attribute applies to all actions",
      getContentType('/attrclass/inherited') === 'text/markdown');
check("#[TµContentType] on an action resets the class-level content type",
      getContentType('/attrclass/reset') === null);
check("#[TµContentType('text/calendar')] on an action replaces the class-level content type",
      getContentType('/attrclass/replaced') === 'text/calendar');

print("\n" . TµAnsi::bold("Views: headers\n"));
$headers = buildHeaders(BaseView::class, null);
check("base view: default 'text/html; charset=UTF-8' content type",
      contentTypes($headers) === ['Content-Type: text/html; charset=UTF-8']);
check("base view: Content-Type is the first header",
      str_starts_with($headers[0], 'Content-Type:'));
check("base view: generic headers are sent",
      in_array('Pragma: no-cache', $headers) && in_array('Expires: Mon, 26 Jul 1997 05:00:00 GMT', $headers));
check("base view: default headers from the configuration are sent",
      in_array('X-Test: default', $headers));
$headers = buildHeaders(BaseView::class, 'image/png', ['X-Specific: yes', 'X-Other' => 'value']);
check("response content type replaces the default one, without charset for non-text types",
      contentTypes($headers) === ['Content-Type: image/png']);
check("specific headers are sent (list and associative syntaxes)",
      in_array('X-Specific: yes', $headers) && in_array('X-Other: value', $headers));
check("'text/*' content type without charset gets 'charset=UTF-8'",
      contentTypes(buildHeaders(BaseView::class, 'text/plain')) === ['Content-Type: text/plain; charset=UTF-8']);
check("'text/*' content type with charset is left untouched",
      contentTypes(buildHeaders(BaseView::class, 'text/plain; charset=ISO-8859-1')) === ['Content-Type: text/plain; charset=ISO-8859-1']);
check("charset detection is case-insensitive",
      contentTypes(buildHeaders(BaseView::class, 'TEXT/PLAIN; CHARSET=utf-8')) === ['Content-Type: TEXT/PLAIN; CHARSET=utf-8']);

print("\n" . TµAnsi::bold("Views: default content types\n"));
check("Json view: 'application/json'",
      contentTypes(buildHeaders(\Temma\Views\Json::class, null)) === ['Content-Type: application/json']);
check("Json view: response content type overrides the default one",
      contentTypes(buildHeaders(\Temma\Views\Json::class, 'text/x-json')) === ['Content-Type: text/x-json; charset=UTF-8']);
check("Csv view: 'text/csv; charset=UTF-8'",
      contentTypes(buildHeaders(\Temma\Views\Csv::class, null)) === ['Content-Type: text/csv; charset=UTF-8']);
check("Csv view: no more 'Content-Encoding' header",
      !array_filter(buildHeaders(\Temma\Views\Csv::class, null), fn($header) => str_starts_with(mb_strtolower($header), 'content-encoding:')));
check("Ini view: 'text/ini; charset=UTF-8'",
      contentTypes(buildHeaders(\Temma\Views\Ini::class, null)) === ['Content-Type: text/ini; charset=UTF-8']);
check("ICal view: 'text/calendar; charset=UTF-8'",
      contentTypes(buildHeaders(\Temma\Views\ICal::class, null)) === ['Content-Type: text/calendar; charset=UTF-8']);
check("Rss view: 'application/rss+xml; charset=UTF-8'",
      contentTypes(buildHeaders(\Temma\Views\Rss::class, null)) === ['Content-Type: application/rss+xml; charset=UTF-8']);

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
