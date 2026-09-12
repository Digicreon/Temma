#!/usr/bin/php
<?php

/**
 * Validation script for the response helpers of controllers and attributes.
 * Builds a dummy application whose controller and attribute use the setters (_view(), _template(),
 * _templatePrefix(), _redirect(), _header(), _contentType()) and store the values returned by the
 * matching getters (_getView(), _getTemplate(), ...) in template variables, then checks these variables.
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');
\Temma\Base\Log::disable();

/* ********** DUMMY APPLICATION ********** */
$appPath = sys_get_temp_dir() . '/temma-response-helpers-test-' . getmypid();
foreach (['controllers', 'etc', 'log', 'tmp', 'templates'] as $dir)
	mkdir("$appPath/$dir", 0755, true);
register_shutdown_function(function() use ($appPath) {
	exec('rm -rf ' . escapeshellarg($appPath));
});

$classes = [
	// attribute which defines values and stores what the getters return
	'GetterAttr' => <<<'EOT'
		#[\Attribute(\Attribute::TARGET_METHOD)]
		class GetterAttr extends \Temma\Web\Attribute {
			public function apply(\Reflector $context) : void {
				$this['attrDefaultView'] = $this->_getView();
				$this['attrDefaultTemplate'] = $this->_getTemplate();
				$this['attrDefaultTemplatePrefix'] = $this->_getTemplatePrefix();
				$this['attrDefaultRedirect'] = $this->_getRedirect();
				$this['attrDefaultHeaders'] = $this->_getHeaders();
				$this['attrDefaultContentType'] = $this->_getContentType();
				$this->_view('\Temma\Views\Json');
				$this->_template('attr/template.tpl');
				$this->_templatePrefix('attrprefix');
				$this->_contentType('csv');
				$this->_redirect('/attr-redirect');
				$this['attrView'] = $this->_getView();
				$this['attrTemplate'] = $this->_getTemplate();
				$this['attrTemplatePrefix'] = $this->_getTemplatePrefix();
				$this['attrRedirect'] = $this->_getRedirect();
				$this['attrContentType'] = $this->_getContentType();
			}
		}
		EOT,
	// controller which stores what the getters return, before and after using the setters
	'Helpers' => <<<'EOT'
		class Helpers extends \Temma\Web\Controller {
			public function defaults() {
				$this['view'] = $this->_getView();
				$this['template'] = $this->_getTemplate();
				$this['templatePrefix'] = $this->_getTemplatePrefix();
				$this['redirect'] = $this->_getRedirect();
				$this['headers'] = $this->_getHeaders();
				$this['contentType'] = $this->_getContentType();
			}
			public function setters() {
				$this->_view('\Temma\Views\Json');
				$this->_template('some/template.tpl');
				$this->_templatePrefix('prefix');
				$this->_header('X-One: 1');
				$this->_header('X-Two: 2');
				$this->_contentType('json');
				$this->_redirect('/somewhere');
				$this['view'] = $this->_getView();
				$this['template'] = $this->_getTemplate();
				$this['templatePrefix'] = $this->_getTemplatePrefix();
				$this['redirect'] = $this->_getRedirect();
				$this['headers'] = $this->_getHeaders();
				$this['contentType'] = $this->_getContentType();
			}
			#[\Temma\Attributes\View(false)]
			public function disabledView() {
				$this['view'] = $this->_getView();
			}
			#[GetterAttr]
			public function attribute() {
				// values defined by the attribute must be visible from the action
				$this['view'] = $this->_getView();
				$this['redirect'] = $this->_getRedirect();
				$this['contentType'] = $this->_getContentType();
			}
		}
		EOT,
];
foreach ($classes as $name => $code)
	file_put_contents("$appPath/controllers/$name.php", "<?php\n\n$code\n");

file_put_contents("$appPath/etc/temma.php", <<<'EOT'
	<?php

	return [
		'application' => [
			'enableSessions' => false,
		],
		'loglevels' => 'CRIT',
	];
	EOT);

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
// executes a request and returns the response object (the view is not processed, so a redirection doesn't exit)
function getResponse(string $url) : \Temma\Web\Response {
	global $appPath;
	$test = new \Temma\Web\Test($appPath, "$appPath/etc/temma.php");
	$test->execData($url);
	return ($test->getLoader()->response);
}

/* ********** TESTS ********** */
print(TµAnsi::bold("Controller getters: default values\n"));
$response = getResponse('/helpers/defaults');
check("_getView() is null", $response['view'] === null);
check("_getTemplate() is null", $response['template'] === null);
check("_getTemplatePrefix() is null", $response['templatePrefix'] === null);
check("_getRedirect() is null", $response['redirect'] === null);
check("_getHeaders() is an empty array", $response['headers'] === []);
check("_getContentType() is null", $response['contentType'] === null);

print("\n" . TµAnsi::bold("Controller getters: after the setters\n"));
$response = getResponse('/helpers/setters');
check("_getView() returns the view defined by _view()", $response['view'] === '\Temma\Views\Json');
check("_getTemplate() returns the template defined by _template()", $response['template'] === 'some/template.tpl');
check("_getTemplatePrefix() returns the prefix defined by _templatePrefix()", $response['templatePrefix'] === 'prefix');
check("_getRedirect() returns the URL defined by _redirect()", $response['redirect'] === '/somewhere');
check("_getHeaders() returns the headers defined by _header()", $response['headers'] === ['X-One: 1', 'X-Two: 2']);
check("_getContentType() returns the resolved content type defined by _contentType()", $response['contentType'] === 'application/json');
check("getters and Response object agree", $response->getRedirection() === '/somewhere' && $response->getView() === '\Temma\Views\Json');
$response = getResponse('/helpers/disabledView');
check("_getView() returns false when the view is disabled", $response['view'] === false);

print("\n" . TµAnsi::bold("Attribute getters\n"));
$response = getResponse('/helpers/attribute');
check("default values are null (or empty array for headers)",
      $response['attrDefaultView'] === null && $response['attrDefaultTemplate'] === null &&
      $response['attrDefaultTemplatePrefix'] === null && $response['attrDefaultRedirect'] === null &&
      $response['attrDefaultHeaders'] === [] && $response['attrDefaultContentType'] === null);
check("_getView() after _view()", $response['attrView'] === '\Temma\Views\Json');
check("_getTemplate() after _template()", $response['attrTemplate'] === 'attr/template.tpl');
check("_getTemplatePrefix() after _templatePrefix()", $response['attrTemplatePrefix'] === 'attrprefix');
check("_getRedirect() after _redirect()", $response['attrRedirect'] === '/attr-redirect');
check("_getContentType() after _contentType() with an alias", $response['attrContentType'] === 'text/csv');
check("values defined by the attribute are visible from the action's getters",
      $response['view'] === '\Temma\Views\Json' && $response['redirect'] === '/attr-redirect' && $response['contentType'] === 'text/csv');

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
