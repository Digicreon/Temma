#!/usr/bin/php
<?php

/** Script de validation du Loader (conteneur d'injection de dépendances). */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Base\Loader as TµLoader;
use \Temma\Utils\Ansi as TµAnsi;
use \Temma\Exceptions\Loader as TµLoaderException;

// initialisation
\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');

/* ********** CLASSES DE TEST ********** */
// loaders personnalisés
class MyLoader extends \Temma\Base\Loader {
}
class SousLoader extends MyLoader {
}
class AutreLoader extends \Temma\Base\Loader {
}
// classe non-Loadable avec un constructeur typé Loader (cas Brevo)
class NotLoadable {
	public \Temma\Base\Loader $received;
	public function __construct(\Temma\Base\Loader $loader) {
		$this->received = $loader;
	}
}
// classe Loadable équivalente (le contrat documenté)
class YesLoadable implements \Temma\Base\Loadable {
	public \Temma\Base\Loader $received;
	public function __construct(\Temma\Base\Loader $loader) {
		$this->received = $loader;
	}
}
// classe non-Loadable avec un constructeur typé sur un loader personnalisé
class NotLoadableCustom {
	public MyLoader $received;
	public function __construct(MyLoader $loader) {
		$this->received = $loader;
	}
}
// classe demandant un loader que le loader courant ne satisfait pas
class WantsSousLoader {
	public function __construct(SousLoader $loader) {
	}
}
// même chose, mais avec un paramètre nullable
class WantsNullableSousLoader {
	public ?SousLoader $received;
	public function __construct(?SousLoader $loader=null) {
		$this->received = $loader;
	}
}
// classe demandant un Registry (classe mère du Loader)
class WantsRegistry {
	public \Temma\Utils\Registry $received;
	public function __construct(\Temma\Utils\Registry $registry) {
		$this->received = $registry;
	}
}
// classes pour la non-régression de l'autowiring
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
// fonction utilisée comme préfixe callable (forme tableau)
function prefixFunction(TµLoader $loader, string $shortKey) : string {
	return ("fonction:$shortKey");
}

/* ********** MICRO-FRAMEWORK DE TEST ********** */
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
print(TµAnsi::bold("Accès direct au loader courant\n"));
$loader = new TµLoader(['config' => 'LA-CONFIG']);
check("get('\\Temma\\Base\\Loader') retourne le loader courant",
      $loader->get('\Temma\Base\Loader') === $loader);
check("get('Temma\\Base\\Loader') retourne le loader courant",
      $loader->get('Temma\Base\Loader') === $loader);
check("get('TµLoader') retourne le loader courant",
      $loader->get('TµLoader') === $loader);
check("get('\\TµLoader') retourne le loader courant",
      $loader->get('\TµLoader') === $loader);
check("accès tableau \$loader['\\Temma\\Base\\Loader']",
      $loader['\Temma\Base\Loader'] === $loader);

print(TµAnsi::bold("Accès direct avec un loader personnalisé\n"));
$myLoader = new MyLoader(['config' => 'LA-CONFIG']);
check("get('MyLoader') retourne le loader courant",
      $myLoader->get('MyLoader') === $myLoader);
check("get('\\MyLoader') retourne le loader courant",
      $myLoader->get('\MyLoader') === $myLoader);
check("get('Temma\\Base\\Loader') retourne le loader courant (classe mère)",
      $myLoader->get('Temma\Base\Loader') === $myLoader);

print(TµAnsi::bold("Injection dans une classe non-Loadable (cas Brevo)\n"));
$obj = $loader['\NotLoadable'];
check("le loader injecté est le loader courant", $obj->received === $loader);
check("le loader injecté contient la configuration",
      $obj->received->get('config', null, false) === 'LA-CONFIG');

print(TµAnsi::bold("Injection avec un loader personnalisé\n"));
$obj = $myLoader['\NotLoadableCustom'];
check("constructeur typé sur la classe fille : loader courant injecté", $obj->received === $myLoader);
$obj = $myLoader['\NotLoadable'];
check("constructeur typé sur la classe mère : loader courant injecté", $obj->received === $myLoader);

print(TµAnsi::bold("Injection dans une classe Loadable (non-régression)\n"));
$obj = $loader['\YesLoadable'];
check("le loader injecté est le loader courant", $obj->received === $loader);

print(TµAnsi::bold("Injection dans une closure\n"));
$loader->set('lazy', function(\Temma\Base\Loader $l) {
	return ($l);
});
check("paramètre de closure typé Loader : loader courant injecté", $loader->get('lazy') === $loader);

print(TµAnsi::bold("Interdiction de fabriquer un loader\n"));
print(TµAnsi::faint("(les WARN « Unable to instantiate » ci-dessous sont attendus)\n"));
check("get() direct d'une sous-classe non enregistrée retourne null",
      $loader->get('\SousLoader') === null);
check("get() direct d'une autre sous-classe non enregistrée retourne null",
      $myLoader->get('\AutreLoader') === null);
$exception = false;
try {
	$obj = $loader['\WantsSousLoader'];
} catch (TµLoaderException $le) {
	$exception = true;
}
check("paramètre typé sur un loader non satisfaisable : TµLoaderException", $exception);
$obj = $loader['\WantsNullableSousLoader'];
check("paramètre nullable typé sur un loader non satisfaisable : null injecté",
      $obj instanceof WantsNullableSousLoader && $obj->received === null);

print(TµAnsi::bold("Le Registry parent n'est pas concerné\n"));
$obj = $loader['\WantsRegistry'];
check("un paramètre typé Registry reçoit un Registry neuf, pas le conteneur",
      $obj->received instanceof \Temma\Utils\Registry &&
      !($obj->received instanceof \Temma\Base\Loader) &&
      $obj->received !== $loader);

print(TµAnsi::bold("Non-régression de l'autowiring\n"));
$serviceA = new ServiceA();
$loader->set('ServiceA', $serviceA);
$obj = $loader['\WantsServiceA'];
check("résolution par type enregistré", $obj->received === $serviceA);
$obj = $loader['\WantsServiceB'];
check("fallback d'auto-instanciation des classes ordinaires", $obj->received instanceof ServiceB);
$loader->set('apiKey', 'SECRET');
$obj = $loader['\WantsApiKey'];
check("résolution d'un scalaire par nom de paramètre", $obj->received === 'SECRET');
$obj = $loader['\WantsDefault'];
check("valeur par défaut utilisée si paramètre irrésoluble", $obj->received === 42);

print(TµAnsi::bold("Préfixes : équivalence des formes chaîne et tableau\n"));
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
check("forme chaîne sans backslash final : résolution en \\Temma\\Utils\\Timer",
      $ref instanceof \Temma\Utils\Timer);
check("forme chaîne avec backslash final : même instance",
      $prefixLoader->get('StrBTimer') === $ref);
check("forme tableau sans backslash final : même instance",
      $prefixLoader->get('TµATimer') === $ref);
check("forme tableau avec backslash final : même instance",
      $prefixLoader->get('TµBTimer') === $ref);
check("forme tableau sans backslash initial ni final : même instance",
      $prefixLoader->get('TµCTimer') === $ref);
check("forme tableau avec backslash final, sans initial : même instance",
      $prefixLoader->get('TµDTimer') === $ref);

print(TµAnsi::bold("Préfixes : suppression via la forme tableau\n"));
$delLoader = new TµLoader();
$delLoader->prefix(['Del' => '\Temma\Utils\\']);
check("préfixe actif : la résolution fonctionne",
      $delLoader->get('DelTimer') instanceof \Temma\Utils\Timer);
$delLoader->prefix(['Del' => null]);
check("préfixe supprimé : la résolution échoue",
      $delLoader->get('DelRegistry', null, false) === null);

print(TµAnsi::bold("Préfixes : callables via la forme tableau (non-régression)\n"));
$cbLoader = new TµLoader();
$cbLoader->prefix([
	'Clo' => fn(TµLoader $l, string $shortKey) => "closure:$shortKey",
	'Fun' => 'prefixFunction',
]);
check("closure : exécutée avec la clé courte",
      $cbLoader->get('CloAbc') === 'closure:Abc');
check("nom de fonction : exécuté avec la clé courte",
      $cbLoader->get('FunXyz') === 'fonction:Xyz');

// résumé
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) en échec sur $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "Tous les tests ont réussi ($count).") . "\n");
