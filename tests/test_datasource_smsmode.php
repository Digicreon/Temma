#!/usr/bin/php
<?php

/**
 * Validation script for the Smsmode data source.
 * Checks that the requests sent to the smsmode HTTP API (version 1.6) use the documented parameters,
 * and that the API responses are correctly interpreted.
 * No request is sent to smsmode: the API calls are captured by a subclass.
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;
use \Temma\Datasources\Smsmode as TµSmsmode;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');
\Temma\Base\Log::disable();

/* ********** TEST DOUBLE ********** */
/** Smsmode data source whose API calls are captured, and which returns predefined responses. */
class CaptureSmsmode extends TµSmsmode {
	/** List of requests (each one is an array with the endpoint, the parameters and the Unicode flag). */
	public array $requests = [];
	/** List of responses to return. */
	public array $responses = [];
	/**
	 * Capture a request and return the next predefined response.
	 * @param	string	$endpoint	Last chunk of the URL.
	 * @param	array	$parameters	(optional) Request parameters.
	 * @param	bool	$unicode	(optional) True for Unicode base URL.
	 * @return	string	The predefined response.
	 */
	protected function _request(string $endpoint, array $parameters=[], bool $unicode=false) : string {
		$this->requests[] = [$endpoint, $parameters, $unicode];
		return (array_shift($this->responses) ?? '');
	}
}

/* ********** HELPERS ********** */
$count = 0;
$failed = 0;
/**
 * Executes a test and displays its result.
 * @param	string		$label	Description of the test.
 * @param	callable	$test	Function which returns true if the test is successful.
 */
function check(string $label, callable $test) : void {
	global $count, $failed;
	$count++;
	$error = null;
	try {
		$ok = ($test() === true);
	} catch (\Throwable $t) {
		$ok = false;
		$error = get_class($t) . ': ' . $t->getMessage();
	}
	if (!$ok)
		$failed++;
	print(TµAnsi::faint(sprintf('%02d', $count)) . ' ' .
	      TµAnsi::color(($ok ? 'green' : 'red'), ($ok ? 'OK' : 'KO')) . ' ' .
	      "$label\n");
	if ($error)
		print('   ' . TµAnsi::faint($error) . "\n");
}
/**
 * Creates a data source returning the given responses.
 * @param	array	$responses	List of responses.
 * @param	?string	$sender		(optional) Sender name.
 * @return	CaptureSmsmode	The data source.
 */
function smsmode(array $responses, ?string $sender=null) : CaptureSmsmode {
	$sms = new CaptureSmsmode('KEY', $sender);
	$sms->responses = $responses;
	return ($sms);
}

/* ********** TESTS ********** */
print(TµAnsi::bold("Smsmode: DSN\n"));
check('the sender name is limited to 11 alphanumeric characters', function() {
	$invalid = 0;
	foreach (['smsmode://ABCDEFGHIJKL:KEY', 'smsmode://AB CD:KEY', 'smsmode://Été:KEY'] as $dsn) {
		try {
			TµSmsmode::factory($dsn);
		} catch (\Temma\Exceptions\Database $e) {
			$invalid++;
		}
	}
	return (($invalid === 3) && (TµSmsmode::factory('smsmode://ABCDEFGHIJK:KEY') instanceof TµSmsmode));
});

print(TµAnsi::bold("Smsmode: sending\n"));
check('send() uses the documented parameters (numero, message, date_envoi, refClient, emetteur)', function() {
	$sms = smsmode(['0 | Accepté | abc123'], 'SENDER');
	$msgId = $sms->send(['33611223344', '33622334455'], 'Bonjour', '31122029-12:50', 'login_1234');
	[$endpoint, $params] = $sms->requests[0];
	return (($msgId === 'abc123') && ($endpoint === 'sendSMS.do') &&
	        ($params === ['numero' => '33611223344,33622334455', 'message' => 'Bonjour', 'date_envoi' => '31122029-12:50',
	                      'refClient' => 'login_1234', 'emetteur' => 'SENDER']));
});
check('a text compatible with ISO-8859-15 is converted, and sent to the standard URL without message class', function() {
	$sms = smsmode(['0 | Accepté | abc123']);
	$sms->send('33611223344', 'Été €');
	[, $params, $unicode] = $sms->requests[0];
	return (($params['message'] === mb_convert_encoding('Été €', 'iso-8859-15', 'utf-8')) && !isset($params['classe_msg']) && !$unicode);
});
check('another text is sent in UTF-8 to the Unicode URL, as a Unicode message (classe_msg=9)', function() {
	$sms = smsmode(['0 | Accepté | abc123']);
	$sms->send('33611223344', 'Привет ☺');
	[, $params, $unicode] = $sms->requests[0];
	return (($params['message'] === 'Привет ☺') && ($params['classe_msg'] === 9) && $unicode);
});
check('an error response gives null with send(), and an exception with write()', function() {
	$sms = smsmode(['32 | Erreur d\'authentification', '33 | Crédits insuffisants']);
	if ($sms->send('33611223344', 'Bonjour') !== null)
		return (false);
	try {
		$sms->write('33611223344', 'Bonjour');
	} catch (\Temma\Exceptions\Database $e) {
		return (true);
	}
	return (false);
});
check('write() options are given to the API', function() {
	$sms = smsmode(['0 | Accepté | abc123']);
	$sms->write('33611223344', 'Bonjour', ['sendDate' => '31122029-12:50', 'reference' => 'ref']);
	$params = $sms->requests[0][1];
	return (($params['date_envoi'] === '31122029-12:50') && ($params['refClient'] === 'ref'));
});

print(TµAnsi::bold("Smsmode: delivery reports, balance, deletion\n"));
check('read() returns the status of the first recipient, plus 100', function() {
	$sms = smsmode(['33600000000 11 | 33611111111 13', '33600000000 34']);
	return (($sms->read('abc') === TµSmsmode::STATUS_MSG_RECEIVED) && ($sms->read('abc') === TµSmsmode::STATUS_ROUTING_ERROR) &&
	        ($sms->requests[0] === ['compteRendu.do', ['smsID' => 'abc'], false]));
});
check('read() returns the request status when the API returns an error', function() {
	$sms = smsmode(['61', '35 | Paramètre manquant', 'unexpected']);
	return (($sms->read('abc') === TµSmsmode::STATUS_SMS_NOT_FOUND) && ($sms->read('abc') === TµSmsmode::STATUS_INVALID_PARAMETERS) &&
	        ($sms->read('abc') === null));
});
check('getBalance() returns the balance (negative for a post-paid account), null on error', function() {
	$sms = smsmode(['212.5', '-3', '32 | Erreur d\'authentification', '']);
	return (($sms->getBalance() === 212.5) && ($sms->getBalance() === -3.0) && ($sms->getBalance() === null) &&
	        ($sms->getBalance() === null) && ($sms->requests[0][0] === 'credit.do'));
});
check('remove() calls deleteSMS.do and raises an exception on error', function() {
	$sms = smsmode(['0', '61 | SMS non trouvé']);
	$sms->remove('abc');
	try {
		$sms->remove('def');
	} catch (\Temma\Exceptions\Database $e) {
		return ($sms->requests[0] === ['deleteSMS.do', ['smsID' => 'abc'], false]);
	}
	return (false);
});

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
