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
		303	=> 'See Other',
		304	=> 'Not Modified',
		305	=> 'Use Proxy',
		306	=> 'Switch Proxy',
		307	=> 'Temporary Redirect',
		308	=> 'Permanent Redirect',
		310	=> 'Too many Redirects',
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
		422	=> 'Unprocessable entity',
		423	=> 'Locked',
		424	=> 'Method failure',
		425	=> 'Unordered Collection',
		426	=> 'Upgrade Required',
		428	=> 'Precondition Required',
		429	=> 'Too Many Requests',
		431	=> 'Request Header Fields Too Large',
		444	=> 'No Response',
		449	=> 'Retry With',
		451	=> 'Unavailable For Legal Reasons',
		456	=> 'Unrecoverable Error',
		495	=> 'SSL Certificate Error',
		496	=> 'SSL Certificate Required',
		498	=> 'Token expired',
		499	=> 'Client Closed Request',
		500	=> 'Internal Server Error',
		501	=> 'Not Implemented',
		502	=> 'Bad Gateway',
		503	=> 'Service Unavailable',
		504	=> 'Gateway Timeout',
		505	=> 'HTTP Version Not Supported',
		506	=> 'Variant Also Negotiates',
		507	=> 'Insufficient storage',
		508	=> 'Loop detected',
		509	=> 'Bandwidth Limit Exceeded',
		510	=> 'Not extended',
		511	=> 'Network authentication required',
		520	=> 'Unknown Error',
		521	=> 'Web Server Is Down',
		522	=> 'Connection Timed Out',
		523	=> 'Origin Is Unreachable',
		524	=> 'A Timeout Occurred',
		525	=> 'SSL Handshake Failed',
		526	=> 'Invalid SSL Certificate',
	);
	$errorString = $errorStrings[$errorCode];
	header("Status: $errorCode $errorString");
	header("HTTP/1.1 $errorCode $errorString", true, $errorCode);
	if (isset($errorPage) && is_file($errorPage))
		readfile($errorPage);
}

?>
