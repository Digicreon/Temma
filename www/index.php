<?php

/**
 * Script d'initialisation du framework Temma.
 *
 * @author	Amaury Bouchard <amaury.bouchard@finemedia.fr>
 * @copyright	© 2011, Fine Media
 * @package	Temma
 * @version	$Id: index.php 278 2012-07-04 12:21:30Z abouchard $
 */

// vérification des variables serveur
if (!isset($_SERVER['SCRIPT_FILENAME']) && isset($_SERVER['ORIG_SCRIPT_FILENAME']))
	$_SERVER['SCRIPT_FILENAME'] = $_SERVER['ORIG_SCRIPT_FILENAME'];
// configuration du chemin d'inclusion
set_include_path(dirname($_SERVER['SCRIPT_FILENAME']) . '/../lib' . PATH_SEPARATOR . get_include_path());

// chronométrage du temps d'exécution
require_once('finebase/FineTimer.php');
$timer = new FineTimer();
$timer->start();

// chargement des objets basiques
require_once('finebase/FineLog.php');
require_once('finebase/FineAutoload.php');
// configuration de l'autoloader
FineAutoload::autoload();

// exécution du framework
require_once('Temma/Framework.php');
FineLog::log('temma', FineLog::DEBUG, "Processing URL '" . $_SERVER['REQUEST_URI'] . "'.");
try {
	$temma = new \Temma\Framework($timer);
	$temma->init();
	$temma->process();
} catch (Exception $e) {
	// gestion d'erreur
	FineLog::log('temma', FineLog::CRIT, "Critical error: '" . $e->getMessage() . "'.");
	$errorCode = 404;
	$errorPage = '';
	if (is_a($e, '\Temma\Exceptions\HttpException'))
		$errorCode = $e->getCode();
	if (isset($temma))
		$errorPage = $temma->getErrorPage($errorCode);
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
