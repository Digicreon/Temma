<?php

/**
 * Script d'initialisation du framework Temma.
 *
 * @author	Amaury Bouchard <amaury.bouchard@finemedia.fr>
 * @copyright	© 2011, Fine Media
 * @package	Temma
 * @version	$Id: index.php 262 2012-03-15 10:47:10Z abouchard $
 */

// chronométrage du temps d'exécution
require_once('finebase/FineTimer.php');
$timer = new FineTimer();
$timer->start();

// vérification des variables serveur
if (!isset($_SERVER['SCRIPT_FILENAME']) && isset($_SERVER['ORIG_SCRIPT_FILENAME']))
	$_SERVER['SCRIPT_FILENAME'] = $_SERVER['ORIG_SCRIPT_FILENAME'];

// configuration du répertoire d'inclusion
$libPath = realpath(dirname($_SERVER['SCRIPT_FILENAME']) . "/../lib");
set_include_path($libPath . PATH_SEPARATOR . get_include_path());

// chargement des objets basiques
require_once("finebase/FineLog.php");
require_once("Temma/Framework.php");

// configuration de l'autoloader
spl_autoload_register(function($name) {
	// transformation du namespace en chemin
	$name = trim($name, '\\');
	$name = str_replace('\\', DIRECTORY_SEPARATOR, $name);
	$name = str_replace('_', DIRECTORY_SEPARATOR, $name);
	// désactivation des logs de warning, pour gérer les objets introuvables
	$errorReporting = error_reporting();
	error_reporting($errorReporting & ~E_WARNING);
	if (!include("$name.php"))
		\FineLog::log("temma", \FineLog::DEBUG, "Unable to load object '$name'.");
	// remise en place de l'ancien niveau de rapport d'erreurs
	error_reporting($errorReporting);
}, true, true);

// exécution du framework
try {
	$application = new \Temma\Framework($timer);
	$application->process();
} catch (Exception $e) {
	// gestion d'erreur
	FineLog::log("temma", FineLog::CRIT, "Critical error: '" . $e->getMessage() . "'");
	$errorCode = 404;
	$errorPage = '';
	if (is_a($e, '\Temma\Exceptions\HttpException'))
		$errorCode = $e->getCode();
	if (isset($application))
		$errorPage = $application->getErrorPage($errorCode);
	$errorStrings = array(
		400	=> 'Bad Request',
		401	=> 'Unauthorized',
		402	=> 'Payment Required',
		403	=> 'Forbidden',
		404	=> 'Not Found',
		405	=> 'Method Not Allowed',
		406	=> 'Not Acceptable',
		407	=> 'Proxy Authentication Required',
		408	=> 'Request Timeout',
		409	=> 'Conflict',
		410	=> 'Gone',
		411	=> 'Length Required',
		412	=> 'Precondition Failed',
		413	=> 'Request Entity Too Large',
		414	=> 'Request-URI Too Long',
		415	=> 'Unsupported Media Type',
		416	=> 'Requested Range Not Satisfiable',
		417	=> 'Expectation Failed',
		500	=> 'Internal Server Error',
		501	=> 'Not Implemented',
		502	=> 'Bad Gateway',
		503	=> 'Service Unavailable',
		504	=> 'Gateway Timeout',
		505	=> 'HTTP Version Not Supported'
	);
	$errorString = $errorStrings[$errorCode];
	header("Status: $errorCode $errorString");
	header("HTTP/1.1 $errorCode $errorString", true, $errorCode);
	if (isset($errorPage) && is_file($errorPage))
		readfile($errorPage);
}

?>
