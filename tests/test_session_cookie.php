#!/usr/bin/php
<?php

/**
 * Validation script for the session cookie domain.
 * - Configuration: the 'cookieDomain' parameter gives null (not configured), false (empty value: cookie restricted
 *   to the current host) or the configured domain.
 * - Native PHP sessions (no data source): the cookie domain is set only if a domain is configured; otherwise, PHP's
 *   configuration is used ('session.cookie_domain', empty by default: cookie restricted to the current host).
 * - Sessions stored in a data source: without configured domain, the level 1 domain name of the current host is used
 *   (except for IP addresses and hosts without dot).
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');
\Temma\Base\Log::disable();

/* ********** DUMMY APPLICATION ********** */
$appPath = sys_get_temp_dir() . '/temma-session-cookie-test-' . getmypid();
foreach (['etc', 'log', 'tmp', 'sessions'] as $dir)
	mkdir("$appPath/$dir", 0755, true);
register_shutdown_function(function() use ($appPath) {
	exec('rm -rf ' . escapeshellarg($appPath));
});
ini_set('session.save_path', "$appPath/sessions");
ini_set('session.use_cookies', '1');
ini_set('session.use_only_cookies', '1');

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
// reads a configuration file whose 'application' section is the given array, and returns the 'cookieDomain' value
function configCookieDomain(array $application) : null|false|string {
	global $appPath;
	file_put_contents("$appPath/etc/temma.php", '<?php return ' . var_export(['application' => $application], true) . ';');
	$config = new \Temma\Web\Config($appPath);
	$config->readConfigurationFile("$appPath/etc/temma.php");
	return ($config->cookieDomain);
}
// starts a native PHP session with the given cookie domain, and returns the cookie parameters and name
function nativeSession(null|false|string $domain, string $iniDomain='') : array {
	ini_set('session.cookie_domain', $iniDomain);
	\Temma\Base\Session::factory(null, 'TestSession', 3600, $domain);
	$result = session_get_cookie_params();
	$result['name'] = session_name();
	session_abort();
	return ($result);
}
// computes the cookie domain of a session stored in a data source
function dataSourceCookieDomain(null|false|string $domain, string $host) : false|string {
	$_SERVER['HTTP_HOST'] = $host;
	$reflection = new \ReflectionClass(\Temma\Base\Session::class);
	$session = $reflection->newInstanceWithoutConstructor();
	$reflection->getProperty('_domain')->setValue($session, $domain);
	return ($reflection->getMethod('_getCookieDomain')->invoke($session));
}

/* ********** NATIVE SESSIONS (executed before any output, because they send headers) ********** */
$_SERVER['HTTP_HOST'] = 'a.b.example.co.uk';
$native = [
	'null'      => nativeSession(null),
	'nullIni'   => nativeSession(null, 'ini.example.org'),
	'false'     => nativeSession(false, 'ini.example.org'),
	'empty'     => nativeSession('', 'ini.example.org'),
	'spaces'    => nativeSession('  ', 'ini.example.org'),
	'domain'    => nativeSession('example.co.uk'),
];

/* ********** TESTS ********** */
print(TµAnsi::bold("Configuration\n"));
check("'cookieDomain' not set gives null",
      configCookieDomain([]) === null);
check("'cookieDomain' set to a domain gives the domain",
      configCookieDomain(['cookieDomain' => 'example.com']) === 'example.com');
check("'cookieDomain' is trimmed",
      configCookieDomain(['cookieDomain' => ' example.com ']) === 'example.com');
check("'cookieDomain' set to false gives false (not an empty string)",
      configCookieDomain(['cookieDomain' => false]) === false);
check("'cookieDomain' set to an empty string gives false",
      configCookieDomain(['cookieDomain' => '']) === false);
check("'cookieDomain' set to spaces gives false",
      configCookieDomain(['cookieDomain' => '   ']) === false);
check("'cookieDomain' set to null gives false",
      configCookieDomain(['cookieDomain' => null]) === false);
$config = new \Temma\Web\Config($appPath);
$config->cookieDomain = '';
check("setting 'cookieDomain' to an empty string through the magic setter gives false",
      $config->cookieDomain === false);
$config->cookieDomain = 'example.org';
check("setting 'cookieDomain' to a domain through the magic setter",
      $config->cookieDomain === 'example.org');

print("\n" . TµAnsi::bold("Native PHP sessions\n"));
check("the session name is used as cookie name",
      $native['null']['name'] === 'TestSession');
check("no configured domain: no domain computed from the host (no 'co.uk' domain)",
      $native['null']['domain'] === '');
check("no configured domain: PHP's 'session.cookie_domain' is kept",
      $native['nullIni']['domain'] === 'ini.example.org');
check("domain set to false: cookie restricted to the current host",
      $native['false']['domain'] === '');
check("domain set to an empty string: cookie restricted to the current host",
      $native['empty']['domain'] === '');
check("domain set to spaces: cookie restricted to the current host",
      $native['spaces']['domain'] === '');
check("configured domain is used",
      $native['domain']['domain'] === 'example.co.uk');
check("cookie is HTTP only, with SameSite=Lax",
      $native['domain']['httponly'] === true && $native['domain']['samesite'] === 'Lax');

print("\n" . TµAnsi::bold("Sessions stored in a data source\n"));
check("no configured domain: level 1 domain of the host",
      dataSourceCookieDomain(null, 'www.example.com') === 'example.com');
check("no configured domain: port number is removed",
      dataSourceCookieDomain(null, 'www.example.com:8080') === 'example.com');
check("no configured domain: host without dot is not extended",
      dataSourceCookieDomain(null, 'localhost') === false);
check("no configured domain: IP address is not extended",
      dataSourceCookieDomain(null, '192.168.1.10') === false);
check("domain set to false: cookie restricted to the current host",
      dataSourceCookieDomain(false, 'www.example.com') === false);
check("configured domain is used",
      dataSourceCookieDomain('example.co.uk', 'a.b.example.co.uk') === 'example.co.uk');

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
