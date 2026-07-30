#!/usr/bin/php
<?php

/** Validation script for the Loader (dependency injection container). */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Base\Loader as TµLoader;
use \Temma\Utils\Ansi as TµAnsi;
use \Temma\Exceptions\Loader as TµLoaderException;

// initialization
\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');

/* ********** TEST CLASSES ********** */
// custom loaders
class MyLoader extends \Temma\Base\Loader {
}
class SousLoader extends MyLoader {
}
class AutreLoader extends \Temma\Base\Loader {
}
// non-Loadable class with a Loader-typed constructor (Brevo case)
class NotLoadable {
	public \Temma\Base\Loader $received;
	public function __construct(\Temma\Base\Loader $loader) {
		$this->received = $loader;
	}
}
// equivalent Loadable class (the documented contract)
class YesLoadable implements \Temma\Base\Loadable {
	public \Temma\Base\Loader $received;
	public function __construct(\Temma\Base\Loader $loader) {
		$this->received = $loader;
	}
}
// non-Loadable class with a constructor typed on a custom loader
class NotLoadableCustom {
	public MyLoader $received;
	public function __construct(MyLoader $loader) {
		$this->received = $loader;
	}
}
// class asking for a loader that the current loader can't satisfy
class WantsSousLoader {
	public function __construct(SousLoader $loader) {
	}
}
// same thing, but with a nullable parameter
class WantsNullableSousLoader {
	public ?SousLoader $received;
	public function __construct(?SousLoader $loader=null) {
		$this->received = $loader;
	}
}
// class asking for a Registry (the Loader's parent class)
class WantsRegistry {
	public \Temma\Utils\Registry $received;
	public function __construct(\Temma\Utils\Registry $registry) {
		$this->received = $registry;
	}
}
// classes for the autowiring non-regression tests
class ServiceA {
}
class ServiceB {
}
class WantsServiceA {
	public ServiceA $received;
	public function __construct(ServiceA $serviceA) {
		$this->received = $serviceA;
	}
}
class WantsServiceB {
	public ServiceB $received;
	public function __construct(ServiceB $serviceB) {
		$this->received = $serviceB;
	}
}
class WantsApiKey {
	public string $received;
	public function __construct(string $apiKey) {
		$this->received = $apiKey;
	}
}
class WantsDefault {
	public int $received;
	public function __construct(int $number=42) {
		$this->received = $number;
	}
}
// function used as a callable prefix (array form)
function prefixFunction(TµLoader $loader, string $shortKey) : string {
	return ("fonction:$shortKey");
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

/* ********** TESTS ********** */
print(TµAnsi::bold("Direct access to the current loader\n"));
$loader = new TµLoader(['config' => 'LA-CONFIG']);
check("get('\\Temma\\Base\\Loader') returns the current loader",
      $loader->get('\Temma\Base\Loader') === $loader);
check("get('Temma\\Base\\Loader') returns the current loader",
      $loader->get('Temma\Base\Loader') === $loader);
check("get('TµLoader') returns the current loader",
      $loader->get('TµLoader') === $loader);
check("get('\\TµLoader') returns the current loader",
      $loader->get('\TµLoader') === $loader);
check("array access \$loader['\\Temma\\Base\\Loader']",
      $loader['\Temma\Base\Loader'] === $loader);

print(TµAnsi::bold("Direct access with a custom loader\n"));
$myLoader = new MyLoader(['config' => 'LA-CONFIG']);
check("get('MyLoader') returns the current loader",
      $myLoader->get('MyLoader') === $myLoader);
check("get('\\MyLoader') returns the current loader",
      $myLoader->get('\MyLoader') === $myLoader);
check("get('Temma\\Base\\Loader') returns the current loader (parent class)",
      $myLoader->get('Temma\Base\Loader') === $myLoader);

print(TµAnsi::bold("Injection into a non-Loadable class (Brevo case)\n"));
$obj = $loader['\NotLoadable'];
check("the injected loader is the current loader", $obj->received === $loader);
check("the injected loader contains the configuration",
      $obj->received->get('config', null, false) === 'LA-CONFIG');

print(TµAnsi::bold("Injection with a custom loader\n"));
$obj = $myLoader['\NotLoadableCustom'];
check("constructor typed on the child class: current loader injected", $obj->received === $myLoader);
$obj = $myLoader['\NotLoadable'];
check("constructor typed on the parent class: current loader injected", $obj->received === $myLoader);

print(TµAnsi::bold("Injection into a Loadable class (non-regression)\n"));
$obj = $loader['\YesLoadable'];
check("the injected loader is the current loader", $obj->received === $loader);

print(TµAnsi::bold("Injection into a closure\n"));
$loader->set('lazy', function(\Temma\Base\Loader $l) {
	return ($l);
});
check("Loader-typed closure parameter: current loader injected", $loader->get('lazy') === $loader);

print(TµAnsi::bold("Loader creation is forbidden\n"));
print(TµAnsi::faint("(the 'Unable to instantiate' WARN messages below are expected)\n"));
check("direct get() of an unregistered subclass returns null",
      $loader->get('\SousLoader') === null);
check("direct get() of another unregistered subclass returns null",
      $myLoader->get('\AutreLoader') === null);
$exception = false;
try {
	$obj = $loader['\WantsSousLoader'];
} catch (TµLoaderException $le) {
	$exception = true;
}
check("parameter typed on an unsatisfiable loader: TµLoaderException", $exception);
$obj = $loader['\WantsNullableSousLoader'];
check("nullable parameter typed on an unsatisfiable loader: null injected",
      $obj instanceof WantsNullableSousLoader && $obj->received === null);

print(TµAnsi::bold("The parent Registry is not affected\n"));
$obj = $loader['\WantsRegistry'];
check("a Registry-typed parameter receives a fresh Registry, not the container",
      $obj->received instanceof \Temma\Utils\Registry &&
      !($obj->received instanceof \Temma\Base\Loader) &&
      $obj->received !== $loader);

print(TµAnsi::bold("Autowiring non-regression\n"));
$serviceA = new ServiceA();
$loader->set('ServiceA', $serviceA);
$obj = $loader['\WantsServiceA'];
check("resolution by registered type", $obj->received === $serviceA);
$obj = $loader['\WantsServiceB'];
check("auto-instantiation fallback for ordinary classes", $obj->received instanceof ServiceB);
$loader->set('apiKey', 'SECRET');
$obj = $loader['\WantsApiKey'];
check("scalar resolution by parameter name", $obj->received === 'SECRET');
$obj = $loader['\WantsDefault'];
check("default value used if the parameter can't be resolved", $obj->received === 42);

print(TµAnsi::bold("Prefixes: equivalence of the string and array forms\n"));
$prefixLoader = new TµLoader();
$prefixLoader->prefix('StrA', '\Temma\Utils');
$prefixLoader->prefix('StrB', '\Temma\Utils\\');
$prefixLoader->prefix([
	'TµA' => '\Temma\Utils',
	'TµB' => '\Temma\Utils\\',
	'TµC' => 'Temma\Utils',
	'TµD' => 'Temma\Utils\\',
]);
$ref = $prefixLoader->get('StrATimer');
check("string form without trailing backslash: resolves to \\Temma\\Utils\\Timer",
      $ref instanceof \Temma\Utils\Timer);
check("string form with trailing backslash: same instance",
      $prefixLoader->get('StrBTimer') === $ref);
check("array form without trailing backslash: same instance",
      $prefixLoader->get('TµATimer') === $ref);
check("array form with trailing backslash: same instance",
      $prefixLoader->get('TµBTimer') === $ref);
check("array form without leading nor trailing backslash: same instance",
      $prefixLoader->get('TµCTimer') === $ref);
check("array form with trailing backslash, without leading one: same instance",
      $prefixLoader->get('TµDTimer') === $ref);

print(TµAnsi::bold("Prefixes: removal using the array form\n"));
$delLoader = new TµLoader();
$delLoader->prefix(['Del' => '\Temma\Utils\\']);
check("active prefix: resolution works",
      $delLoader->get('DelTimer') instanceof \Temma\Utils\Timer);
$delLoader->prefix(['Del' => null]);
check("removed prefix: resolution fails",
      $delLoader->get('DelRegistry', null, false) === null);

print(TµAnsi::bold("Prefixes: callables using the array form (non-regression)\n"));
$cbLoader = new TµLoader();
$cbLoader->prefix([
	'Clo' => fn(TµLoader $l, string $shortKey) => "closure:$shortKey",
	'Fun' => 'prefixFunction',
]);
check("closure: executed with the short key",
      $cbLoader->get('CloAbc') === 'closure:Abc');
check("function name: executed with the short key",
      $cbLoader->get('FunXyz') === 'fonction:Xyz');

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
