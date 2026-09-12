#!/usr/bin/php
<?php

/**
 * Validation script for the action dispatch of controllers.
 * Builds a dummy application and checks which methods are callable as actions through the URL:
 * - public methods declared by the application are callable;
 * - framework helpers (_redirect(), _view(), _httpCode(), _subProcess()...), magic methods (__sleep()...),
 *   methods declared by the framework's base classes (offsetSet()...) and protected methods are not
 *   (they were reachable, allowing an open redirect through "/controller/_redirect/<url>");
 * - the root action, the default action (__call) and the proxy action (__proxy) keep working.
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');
\Temma\Base\Log::disable();

/* ********** DUMMY APPLICATION ********** */
$appPath = sys_get_temp_dir() . '/temma-action-dispatch-test-' . getmypid();
foreach (['controllers', 'etc', 'log', 'tmp', 'templates'] as $dir)
	mkdir("$appPath/$dir", 0755, true);
register_shutdown_function(function() use ($appPath) {
	exec('rm -rf ' . escapeshellarg($appPath));
});

$classes = [
	// regular controller: root action, a public action, a protected method
	'Direct' => <<<'EOT'
		class Direct extends \Temma\Web\Controller {
			public function __invoke() { $this['trace'] = 'root'; }
			public function hello() { $this['trace'] = 'hello'; }
			public function sub() { $this->_subProcess('Direct'); }
			protected function internal() { $this['trace'] = 'internal'; }
		}
		EOT,
	// controller with a default action
	'Fallback' => <<<'EOT'
		class Fallback extends \Temma\Web\Controller {
			public function __call($name, $args) { $this['trace'] = "call:$name"; }
		}
		EOT,
	// controller with a proxy action
	'Proxied' => <<<'EOT'
		class Proxied extends \Temma\Web\Controller {
			public function __proxy($action, $params) { $this['trace'] = "proxy:$action"; }
		}
		EOT,
	// controller without root action nor default action
	'Norooted' => <<<'EOT'
		class Norooted extends \Temma\Web\Controller {
			public function hello() { $this['trace'] = 'hello'; }
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
// executes a request and returns the produced trace, or "http:<code>" if an HTTP exception was thrown
function trace(string $url) : ?string {
	global $appPath;
	$test = new \Temma\Web\Test($appPath, "$appPath/etc/temma.php");
	try {
		$test->execData($url);
	} catch (\Temma\Exceptions\Http $e) {
		while (ob_get_level())
			ob_end_clean();
		return ('http:' . $e->getCode());
	}
	return ($test->getLoader()->response['trace']);
}

/* ********** TESTS ********** */
print(TµAnsi::bold("Callable actions\n"));
check("public action declared by the application", trace('/direct/hello') === 'hello');
check("root action (__invoke) when no action is given", trace('/direct') === 'root');
check("root action through a sub-process without action name", trace('/direct/sub') === 'root');
check("default action (__call) handles unknown actions", trace('/fallback/anything') === 'call:anything');
check("default action (__call) handles the root action", trace('/fallback') === 'call:__invoke');
check("proxy action (__proxy) receives the raw action name", trace('/proxied/anything') === 'proxy:anything');

print("\n" . TµAnsi::bold("Framework methods are not actions\n"));
check("_httpCode() is not callable", trace('/direct/_httpCode/201') === 'http:404');
check("_redirect() is not callable (open redirect)", trace('/direct/_redirect/https:%2F%2Fevil.example') === 'http:404');
check("_view() is not callable", trace('/direct/_view/Foo') === 'http:404');
check("_subProcess() is not callable", trace('/direct/_subProcess/Direct/hello') === 'http:404');
check("_loadDao() is not callable", trace('/direct/_loadDao') === 'http:404');
check("__sleep() is not callable", trace('/direct/__sleep') === 'http:404');
check("__wakeup() is not callable", trace('/direct/__wakeup') === 'http:404');
check("offsetSet() (declared by the framework) is not callable", trace('/direct/offsetSet/foo/bar') === 'http:404');
check("offsetGet() (declared by the framework) is not callable", trace('/direct/offsetGet/foo') === 'http:404');
check("offsetSet() is not callable even with a default action", trace('/fallback/offsetSet/foo/bar') === 'http:404');
check("underscore-prefixed names don't reach the default action", trace('/fallback/_anything') === 'http:404');

print("\n" . TµAnsi::bold("Other rejected actions\n"));
check("protected method declared by the application", trace('/direct/internal') === 'http:404');
check("action starting with an upper-case letter", trace('/direct/Hello') === 'http:404');
check("unknown action without default action", trace('/direct/unknown') === 'http:404');
check("root action without __invoke() nor __call()", trace('/norooted') === 'http:404');

print("\n" . TµAnsi::bold("Proxy action: the dispatch is up to the proxy\n"));
check("underscore-prefixed name is given to the proxy", trace('/proxied/_anything') === 'proxy:_anything');
check("framework method name is given to the proxy", trace('/proxied/offsetSet') === 'proxy:offsetSet');

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
