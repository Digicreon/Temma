<?php

/**
 * Bootloader
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2024, Amaury Bouchard
 */

namespace Temma\Web;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Framework bootloader object.
 *
 * @see		\Temma\Web\Framework
 */
class Bootloader {
	/**
	 * Framework bootloader.
	 */
	static public function bootloader() {
		global $temma;

		$temma = null;
		// check server variables
		if (!isset($_SERVER['SCRIPT_FILENAME']) && isset($_SERVER['ORIG_SCRIPT_FILENAME']))
			$_SERVER['SCRIPT_FILENAME'] = $_SERVER['ORIG_SCRIPT_FILENAME'];
		// framework startup
		try {
			$temma = new \Temma\Web\Framework();
			$temma->process();
		} catch (\Throwable $e) {
			// error management
			TµLog::log('Temma/Web', 'CRIT', "Critical error [" . $e->getFile() . ':' . $e->getLine() . "]: '" . $e->getMessage() . "'.");
			$httpCode = 500;
			$errorPage = '';
			if (is_a($e, '\Temma\Exceptions\Http')) {
				$httpCode = $e->getCode();
			} else if (is_a($e, '\Temma\Exceptions\Application')) {
				$code = $e->getCode();
				if ($code == TµApplicationException::AUTHENTICATION)
					$httpCode = 401;
				else if ($code == TµApplicationException::UNAUTHORIZED)
					$httpCode = 403;
				else if ($code == TµApplicationException::RETRY)
					$httpCode = 449;
				else
					$httpCode = 400;
			} else
				TµLog::log('Temma/Web', 'CRIT', $e->getTrace());
			if (isset($temma))
				$errorPage = $temma->getErrorPage($httpCode);
			$httpString = self::_httpCodeToString($httpCode);
			header("Status: $httpCode $httpString");
			header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . " $httpCode $httpString", true, $httpCode);
			if (isset($errorPage) && is_file($errorPage))
				readfile($errorPage);
		}
	}
	/**
	 * Returns the string for a given HTTP status code.
	 * @param	int	$code	The HTTP code.
	 * @return	string	The corresponding status string (empty string for unkown code).
	 */
	static private function _httpCodeToString(int $code) : string {
		$httpStrings = [
			// 1XX
			100 => 'Continue',
			101 => 'Switching Protocols',
			102 => 'Processing',
			// 2XX
			201 => 'Created',
			202 => 'Accepted',
			203 => 'Non-Authoritative Information',
			204 => 'No Content',
			205 => 'Reset Content',
			206 => 'Partial Content',
			207 => 'Multi-Status',
			// 3XX
			300 => 'Multiple Choices',
			301 => 'Moved Permanently',
			302 => 'Found',
			303 => 'See Other',
			304 => 'Not Modified',
			305 => 'Use Proxy',
			306 => 'Switch Proxy',
			307 => 'Temporary Redirect',
			308 => 'Permanent Redirect',
			310 => 'Too many Redirects',
			// 4XX
			400 => 'Bad Request',
			401 => 'Unauthorized',
			402 => 'Payment Required',
			403 => 'Forbidden',
			404 => 'Not Found',
			405 => 'Method Not Allowed',
			406 => 'Not Acceptable',
			407 => 'Proxy Authentication Required',
			408 => 'Request Timeout',
			409 => 'Conflict',
			410 => 'Gone',
			411 => 'Length Required',
			412 => 'Precondition Failed',
			413 => 'Request Entity Too Large',
			414 => 'Request-URI Too Long',
			415 => 'Unsupported Media Type',
			416 => 'Requested Range Not Satisfiable',
			417 => 'Expectation Failed',
			418 => "I'm a teapot",
			422 => 'Unprocessable entity',
			423 => 'Locked',
			424 => 'Method failure',
			425 => 'Unordered Collection',
			426 => 'Upgrade Required',
			428 => 'Precondition Required',
			429 => 'Too Many Requests',
			431 => 'Request Header Fields Too Large',
			444 => 'No Response',
			449 => 'Retry With',
			451 => 'Unavailable For Legal Reasons',
			456 => 'Unrecoverable Error',
			495 => 'SSL Certificate Error',
			496 => 'SSL Certificate Required',
			498 => 'Token expired',
			499 => 'Client Closed Request',
			// 5XX
			500 => 'Internal Server Error',
			501 => 'Not Implemented',
			502 => 'Bad Gateway',
			503 => 'Service Unavailable',
			504 => 'Gateway Timeout',
			505 => 'HTTP Version Not Supported',
			506 => 'Variant Also Negotiates',
			507 => 'Insufficient storage',
			508 => 'Loop detected',
			509 => 'Bandwidth Limit Exceeded',
			510 => 'Not extended',
			511 => 'Network authentication required',
			520 => 'Unknown Error',
			521 => 'Web Server Is Down',
			522 => 'Connection Timed Out',
			523 => 'Origin Is Unreachable',
			524 => 'A Timeout Occurred',
			525 => 'SSL Handshake Failed',
			526 => 'Invalid SSL Certificate',
		];
		return ($httpStrings[$code] ?? '');
	}
}

