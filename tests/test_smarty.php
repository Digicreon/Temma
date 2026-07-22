#!/usr/bin/php
<?php

/**
 * Script de validation de la vue Smarty et de l'objet utilitaire Smarty.
 * Compatible Smarty 4 et Smarty 5. Par défaut le test tourne sous Smarty 5 ;
 * passer '4' en premier argument (ou SMARTY_VERSION=4) pour tester sous Smarty 4.
 * Exemples : `php tests/test_smarty.php` ou `php tests/test_smarty.php 4`.
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Base\Loader as TµLoader;
use \Temma\Utils\Ansi as TµAnsi;
use \Temma\Utils\Smarty as TµUtilsSmarty;
use \Temma\Views\Smarty as TµViewSmarty;
use \Temma\Utils\Email as TµEmail;
use \Temma\Exceptions\IO as TµIOException;

// initialisation de l'autoloader Temma
\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');

/* ********** LOCALISATION DE SMARTY (4 ou 5) ********** */
$wantedVersion = $argv[1] ?? getenv('SMARTY_VERSION') ?: '5';
$extractDir = sys_get_temp_dir() . '/temma-test-smarty-' . getmypid();
if ($wantedVersion == '4' && !class_exists('\Smarty') && !class_exists('\Smarty\Smarty')) {
	// Smarty 4 : copie dans lib/smarty4, sinon extraction de l'archive mise en cache
	$libs = null;
	if (is_dir(__DIR__ . '/../lib/smarty4'))
		$libs = realpath(__DIR__ . '/../lib/smarty4');
	else if (is_file('/tmp/smarty-4.5.4.tgz')) {
		if (!is_dir($extractDir))
			mkdir($extractDir, 0755, true);
		(new PharData('/tmp/smarty-4.5.4.tgz'))->extractTo($extractDir, null, true);
		$libs = "$extractDir/smarty-4.5.4/libs";
	}
	if (!$libs || !is_dir($libs)) {
		fwrite(STDERR, "Smarty 4 introuvable. Copie ses 'libs/' dans lib/smarty4, " .
		               "ou place l'archive dans /tmp/smarty-4.5.4.tgz.\n");
		exit(2);
	}
	include_once("$libs/Autoloader.php");
	include_once("$libs/bootstrap.php");
	require_once("$libs/Smarty.class.php");
} else if (!class_exists('\Smarty\Smarty') && !class_exists('\Smarty')) {
	// Smarty 5 : copie dans lib/Smarty, sinon extraction de l'archive mise en cache
	$srcDir = null;
	if (is_dir(__DIR__ . '/../lib/Smarty'))
		$srcDir = realpath(__DIR__ . '/../lib/Smarty');
	else if (is_file('/tmp/smarty-5.4.1.tgz')) {
		if (!is_dir($extractDir))
			mkdir($extractDir, 0755, true);
		(new PharData('/tmp/smarty-5.4.1.tgz'))->extractTo($extractDir, null, true);
		$srcDir = "$extractDir/smarty-5.4.1/src";
	}
	if (!$srcDir || !is_dir($srcDir)) {
		fwrite(STDERR, "Smarty 5 introuvable. Installe-le via Composer, copie 'src/' dans lib/Smarty, " .
		               "ou place l'archive dans /tmp/smarty-5.4.1.tgz.\n");
		exit(2);
	}
	spl_autoload_register(function($class) use ($srcDir) {
		if (str_starts_with($class, 'Smarty\\')) {
			$file = $srcDir . '/' . str_replace('\\', '/', substr($class, 7)) . '.php';
			if (is_file($file))
				require_once($file);
		}
	});
	require_once($srcDir . '/functions.php');
}
$smartyVersion = class_exists('\Smarty\Smarty') ? 5 : 4;
print(TµAnsi::bold("Tests Smarty (moteur détecté : Smarty $smartyVersion)\n"));

/* ********** ARBORESCENCE D'APPLICATION FACTICE ********** */
$appPath = sys_get_temp_dir() . '/temma-smarty-app-' . getmypid();
$templatesPath = "$appPath/templates";
$tmpPath = "$appPath/tmp";
foreach ([$appPath, $templatesPath, $tmpPath] as $dir) {
	if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
		fwrite(STDERR, "Impossible de créer le répertoire '$dir'.\n");
		exit(2);
	}
}
// templates de test
file_put_contents("$templatesPath/hello.tpl", 'Hello {$name}!');
file_put_contents("$templatesPath/escape.tpl", '{$html}');
file_put_contents("$templatesPath/trim.tpl", '[{$s|trim}]');
file_put_contents("$templatesPath/nbsp.tpl", '{$s|nbsp}');
file_put_contents("$templatesPath/mail_html.tpl", '<p>{$name}</p>');
file_put_contents("$templatesPath/mail_text.tpl", 'Name: {$name}');
// nettoyage en fin d'exécution
register_shutdown_function(function() use ($appPath, $extractDir) {
	exec('rm -rf ' . escapeshellarg($appPath) . ' ' . escapeshellarg($extractDir));
});
// répertoire des plugins Temma (pour tester l'enregistrement via 'pluginsDir')
$temmaPluginsDir = realpath(__DIR__ . '/../lib/smarty-plugins');

/* ********** FABRIQUE DE CONFIGURATION ********** */
/**
 * Construit un objet de configuration pour l'application factice.
 * @param	array	$xtra	Sections de configuration étendue, ex. ['smarty' => ['autoEscape' => false]].
 * @return	\Temma\Web\Config	L'objet de configuration.
 */
function makeConfig(array $xtra=[]) : \Temma\Web\Config {
	global $appPath, $templatesPath, $tmpPath;
	$config = new \Temma\Web\Config($appPath, [
		'_tmpPath'       => $tmpPath,
		'_templatesPath' => $templatesPath,
	]);
	foreach ($xtra as $name => $keys) {
		foreach ($keys as $key => $value)
			$config->setXtra($name, $key, $value);
	}
	return ($config);
}
/**
 * Construit un objet utilitaire Smarty à partir d'une configuration.
 * @param	array	$xtra	Sections de configuration étendue.
 * @return	\Temma\Utils\Smarty	L'objet utilitaire.
 */
function makeUtil(array $xtra=[]) : TµUtilsSmarty {
	$loader = new TµLoader(['config' => makeConfig($xtra)]);
	return ($loader['\Temma\Utils\Smarty']);
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

/* ********** TESTS : OBJET UTILITAIRE ********** */
print(TµAnsi::bold("Objet utilitaire : rendu de base\n"));
$util = makeUtil();
check("render() d'un template fichier avec variable",
      $util->render('hello.tpl', ['name' => 'World']) === 'Hello World!');
check("eval() d'un template en chaîne avec variable",
      $util->eval('X={$v}', ['v' => 42]) === 'X=42');
check("templateExists() vrai pour un template présent",
      $util->templateExists('hello.tpl') === true);
check("templateExists() faux pour un template absent",
      $util->templateExists('nope.tpl') === false);
$exception = false;
try {
	$util->render('nope.tpl', null);
} catch (TµIOException $e) {
	$exception = true;
}
check("render() d'un template inexistant : TµIOException", $exception);

print(TµAnsi::bold("Objet utilitaire : plugins et modificateurs\n"));
check("modificateur PHP natif (trim)",
      $util->render('trim.tpl', ['s' => '  hi  ']) === '[hi]');
$utilPlugins = makeUtil(['smarty' => ['pluginsDir' => $temmaPluginsDir, 'autoEscape' => false]]);
check("plugin Temma enregistré via pluginsDir (nbsp)",
      $utilPlugins->render('nbsp.tpl', ['s' => 'a :']) === 'a&nbsp;:');

print(TµAnsi::bold("Objet utilitaire : échappement HTML\n"));
check("échappement actif par défaut",
      $util->render('escape.tpl', ['html' => '<b>x</b>']) === '&lt;b&gt;x&lt;/b&gt;');
check("débrayage par appel (autoEscape: false)",
      $util->render('escape.tpl', ['html' => '<b>x</b>'], false) === '<b>x</b>');
check("réglage de l'instance restauré après un appel débrayé",
      $util->render('escape.tpl', ['html' => '<b>x</b>']) === '&lt;b&gt;x&lt;/b&gt;');

/* ********** TESTS : CONFIGURATION ********** */
print(TµAnsi::bold("Configuration de l'échappement\n"));
$utilOff = makeUtil(['smarty' => ['autoEscape' => false]]);
check("x-smarty / autoEscape=false désactive l'échappement",
      $utilOff->render('escape.tpl', ['html' => '<i>y</i>']) === '<i>y</i>');
$utilLegacy = makeUtil(['smarty-view' => ['autoEscape' => false]]);
check("repli hérité : x-smarty-view / autoEscape=false désactive aussi",
      $utilLegacy->render('escape.tpl', ['html' => '<i>y</i>']) === '<i>y</i>');
$utilConflict = makeUtil([
	'smarty'      => ['autoEscape' => true],
	'smarty-view' => ['autoEscape' => false],
]);
check("x-smarty prioritaire sur x-smarty-view en cas de conflit",
      $utilConflict->render('escape.tpl', ['html' => '<i>y</i>']) === '&lt;i&gt;y&lt;/i&gt;');
$utilLegacyPlugins = makeUtil(['smarty-view' => ['pluginsDir' => $temmaPluginsDir], 'smarty' => ['autoEscape' => false]]);
check("repli hérité : pluginsDir via x-smarty-view",
      $utilLegacyPlugins->render('nbsp.tpl', ['s' => 'a :']) === 'a&nbsp;:');

/* ********** TESTS : VUE ********** */
print(TµAnsi::bold("Vue Smarty\n"));
/**
 * Rend un template via la vue Smarty et retourne le flux capturé.
 * @param	string	$template	Nom du template.
 * @param	array	$data		Données de template.
 * @param	array	$xtra		Configuration étendue.
 * @return	string	Le rendu.
 */
function renderView(string $template, array $data, array $xtra=[]) : string {
	global $templatesPath;
	$config = makeConfig($xtra);
	$response = new \Temma\Web\Response();
	$response->setData($data);
	$view = new TµViewSmarty([], $config, $response);
	$view->setTemplate($templatesPath, $template);
	$view->init();
	ob_start();
	$view->sendBody();
	return (ob_get_clean());
}
check("rendu d'un template avec variable",
      renderView('hello.tpl', ['name' => 'Temma']) === 'Hello Temma!');
check("échappement actif par défaut dans la vue",
      renderView('escape.tpl', ['html' => '<b>z</b>']) === '&lt;b&gt;z&lt;/b&gt;');
check("échappement désactivé via x-smarty / autoEscape=false",
      renderView('escape.tpl', ['html' => '<b>z</b>'], ['smarty' => ['autoEscape' => false]]) === '<b>z</b>');
$exception = false;
try {
	$config = makeConfig();
	$view = new TµViewSmarty([], $config, new \Temma\Web\Response());
	$view->setTemplate($templatesPath, 'nope.tpl');
} catch (TµIOException $e) {
	$exception = true;
}
check("setTemplate() d'un template inexistant : TµIOException", $exception);

/* ********** TESTS : CONSTANTES ********** */
print(TµAnsi::bold("Alias de constantes de la vue\n"));
check("COMPILED_DIR aliasée sur l'utilitaire",
      TµViewSmarty::COMPILED_DIR === TµUtilsSmarty::COMPILED_DIR);
check("CACHE_DIR aliasée sur l'utilitaire",
      TµViewSmarty::CACHE_DIR === TµUtilsSmarty::CACHE_DIR);
check("PLUGINS_DIR aliasée sur l'utilitaire",
      TµViewSmarty::PLUGINS_DIR === TµUtilsSmarty::PLUGINS_DIR);
check("DEFAULT_AUTO_ESCAPE aliasée sur l'utilitaire",
      TµViewSmarty::DEFAULT_AUTO_ESCAPE === TµUtilsSmarty::DEFAULT_AUTO_ESCAPE);

/* ********** TESTS : EMAIL TEMPLATÉ ********** */
print(TµAnsi::bold("Email templaté : échappement HTML vs texte\n"));
// sous-classe de test qui capture les corps générés au lieu d'envoyer un email
class CapturingEmail extends TµEmail {
	public string $capturedHtml = '';
	public ?string $capturedText = null;
	public function mimeMail(string $from, string|array $to, string $title='', string $html='', ?string $text=null,
	                         ?array $attachments=null, string|array $cc='', string|array $bcc='',
	                         ?string $unsubscribe=null, ?string $envelopeSender=null) : void {
		$this->capturedHtml = $html;
		$this->capturedText = $text;
	}
}
$loader = new TµLoader(['config' => makeConfig()]);
$email = new CapturingEmail($loader);
$email->templatedMail('from@test.com', 'to@test.com', 'Sujet', 'mail_html.tpl', 'mail_text.tpl',
                      ['name' => '<script>']);
check("template HTML échappé (réglage de l'instance)",
      $email->capturedHtml === '<p>&lt;script&gt;</p>');
$email2 = new CapturingEmail($loader);
$email2->templatedMail('from@test.com', 'to@test.com', 'Sujet', null, 'mail_text.tpl',
                       ['name' => 'R&D']);
check("template texte jamais échappé",
      $email2->capturedText === 'Name: R&D');

// résumé
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) en échec sur $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "Tous les tests ont réussi ($count).") . "\n");
