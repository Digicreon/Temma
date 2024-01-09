<?php

/**
 * Smsmode
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 */

namespace Temma\Datasources;

use \Temma\Base\Log as TµLog;

/**
 * SMS management object (through smsmode.com service).
 *
 * This object is used to send SMS messages using smsmode.com service.
 * It uses their HTTP API (which is deprecated but still usable). This API needs an
 * API key; you can create it on the smsmode.com user settings page
 * (https://ui.smsmode.com/settings/keys).
 *
 * For API details, please refere to its documentation (https://dev.smsmode.com/http-api/reference/).
 *
 * <b>Usage</b>
 * <code>
 * // initialization
 * $sms = \Temma\Datasources\Smsmode::factory('smsmode://API_KEY');
 * $sms = \Temma\Base\Datasource::factory('smsmode://API_KEY');
 *
 * // initialization with a sender name (11 characters-long maximum, no space, no accent)
 * $sms = \Temma\Datasources\Smsmode::factory('smsmode://SENDER_NAME:API_KEY');
 * $sms = \Temma\Base\Datasource::factory('smsmode://SENDER_NAME:API_KEY');
 *
 * // send a text message to the given phone number
 * $sms['33611223344'] = 'Text message';
 * $sms->write('33611223344', 'Text message');
 * $sms->set('33611223344', 'Text message');
 *
 * // send a text message with parameters
 * $sms->write('33611223344', 'Text message', [
 *     'sendDate'  => 'DDMMYYYY-HH:mm',
 *     'reference' =>  'login_1234',
 * ]);
 * $sms->set('33611223344', 'Text message', [
 *     'sendDate'  => 'DDMMYYYY-HH:mm',
 *     'reference' =>  'login_1234',
 * ]);
 * $sms->send('33611223344', 'Text message',
 *            sendDate: 'DDMMYYYY-HH:mm',
 *            reference: 'login_1234');
 *
 * // send a text message with parameters and get back a message ID.
 * $msgId = $sms->send('33611223344', 'Text message',
 *                     sendDate: 'DDMMYYYY-HH:mm',
 *                     reference: 'login_1234');
 *
 * // read a message delivery status (using the message ID)
 * $status = $sms[$msgId];
 * $status = $sms->read($msgId);
 * $status = $sms->get($msgId);
 * if ($status == \Temma\Datasources\Smsmode::STATUS_SUCCESS) {
 *     // ...
 * } else if ($status == \Temma\Datasources\Smsmode::STATUS_RECEIVED) {
 *     // ...
 * } else if ($status == \Temma\Datasources\Smsmode::STATUS_RECEIVED) {
 *     // ...
 * } else if ($status == \Temma\Datasources\Smsmode::STATUS_GATEWAY_RECEIVED ||
 *            $status == \Temma\Datasources\Smsmode::STATUS_GATEWAY_DELIVERED) {
 *     // ...
 * } else if ($status == \Temma\Datasources\Smsmode::STATUS_INTERNAL_ERROR ||
 *            $status == \Temma\Datasources\Smsmode::STATUS_DELIVERY_ERROR) {
 *     // ...
 * }
 *
 * // get organization's balance
 * $balance = $sms->getBalance();
 *
 * // delete a scheduled message (from its message ID)
 * unset($sms[$msgId]);
 * $sqs->remove($msgId);
 * </code>
 */
class Smsmode extends \Temma\Base\Datasource {
	/** URL of smsmode.com API. */
	const API_URL = 'https://api.smsmode.com/http/1.6/';
	/** URL of smsmode.com API in Unicode mode. */
	const API_URL_UNICODE = 'https://api.smsmode.com/http:1.6/';
	/** Request statuses. */
	const STATUS_SUCCESS = 0;
	const STATUS_INTERNAL_ERROR = 31;
	const STATUS_AUTHENTICATION_ERROR = 32;
	const STATUS_INSUFFICIENTE_BALANCE = 33;
	const STATUS_INVALID_PARAMETERS = 35;
	const STATUS_TEMPORARY_UNAVAILABLE = 50;
	const STATUS_SMS_NOT_FOUND = 61;
	const STATUS_MESSAGE_NOT_FOUND = 65;
	/** Message statuses. */
	const STATUS_SENT = 100;
	const STATUS_MSG_INTERNAL_ERROR = 102;
	const STATUS_MSG_RECEIVED = 111;
	const STATUS_GATEWAY_RECEIVED = 113;
	const STATUS_GATEWAY_DELIVERED = 134;
	const STATUS_DELIVERY_ERROR = 135;
	/** API key. */
	private ?string $_apiKey = null;
	/** Sender name. */
	private ?string $_sender = null;

	/* ********** CONSTRUCTION ********** */
	/**
	 * Create a new instance of this class.
	 * @param	string	$dsn	Connection string.
	 * @return	\Temma\Datasources\Smsmode	The created instance.
	 * @throws	\Temma\Exceptions\Database	If the DSN is invalid.
	 */
	static public function factory(string $dsn) : \Temma\Datasources\Smsmode {
		TµLog::log('Temma/Base', 'DEBUG', "\\Temma\\Datasources\\Smsmode object creation with DSN: '$dsn'.");
		if (!preg_match('/^smsmode:\/\/(([^:]*):)?(.+)$/', $dsn, $matches)) {
			TµLog::log('Temma/Base', 'WARN', "Invalid Smsmode DSN '$dsn'.");
			throw new \Temma\Exceptions\Database("Invalid Smsmode DSN '$dsn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		$sender = $matches[2] ?? null;
		$apiKey = $matches[3] ?? '';
		if (!$apiKey || ($sender && !preg_match('/^[a-zA-Z0-9]+$/', $sender)))
			throw new \Temma\Exceptions\Database("Invalid Smsmode DSN '$dsn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		return (new self($apiKey, $sender));
	}
	/**
	 * Constructor.
	 * @param	string	$apiKey	API key.
	 * @param	?string	$sender	(optional) Sender name.
	 * @throws	\Temma\Exceptions\Database	If a parameter is invalid.
	 */
	private function __construct(string $apiKey, ?string $sender=null) {
		$this->_apiKey = $apiKey;
		$this->_sender = $sender ?: null;
	}

	/* ********** SPECIAL REQUESTS ********** */
	/**
	 * Send a text message.
	 * @param	string|array	$recipient	Phone number or list of phone numbers.
	 * @param	string		$text		Text message.
	 * @param	?string		$sendDate	(optional) Sending date (format 'DDMMYYYY-HH:mm').
	 * @param	?string		$reference	(optional) Internal reference.
	 * @return	?string	Message identifier or null if an error occurred.
	 */
	public function send(string|array $recipient, string $text=null, ?string $sendDate=null, ?string $reference=null) : ?string {
		if (!$this->_enabled)
			return (null);
		// text
		$text = trim($text);
		if (!$text)
			return (null);
		// unicode
		$unicode = false;
		if (!\Temma\Utils\Text::encodingCompatible($text, 'iso-8859-15'))
			$unicode = true;
		// parameters
		$params = [
			'numero'  => is_array($recipient) ? implode(',', $recipient) : $recipient,
			'message' => $text,
		];
		if ($sendDate)
			$params['sendDate'] = $sendDate;
		if ($reference)
			$params['reference'] = $reference;
		if ($this->_sender)
			$params['emetteur'] = $this->_sender;
		// request
		$response = $this->_request('sendSMS.do', $params, $unicode);
		// response
		if (!preg_match('/^(\d+)(|[^|]+|(.*))?$/', $response, $matches))
			return (null);
		if ($matches[1] != '0' || !($matches[3] ?? null))
			return (null);
		return ($matches[3]);
	}
	/**
	 * Get the organization's balance.
	 * @return	?float	Organization's balance, or null if an error occurred.
	 */
	public function getBalance() : ?float {
		if (!$this->_enabled)
			return (null);
		$response = $this->_request('credit.do');
		if (!mb_strlen($response))
			return (null);
		return ((float)$response);
	}

	/* ********** STANDARD REQUESTS ********** */
	/**
	 * Delete a scheduled message.
	 * @param	string	$msgId	Message identifier.
	 * @throws	\Exception	If an error occurred.
	 */
	public function remove(string $msgId) : void {
		if (!$this->_enabled)
			return;
		$response = $this->_request('deleteSMS.do', ['smsID' => $msgId]);
		if (!mb_strlen($response) || $response !== '0')
			throw new \Exception("Unable to delete text message identified by '$msgId'.");
	}
	/**
	 * Disabled clear.
	 * @param	string	$pattern	Not used.
	 * @throws	\Temma\Exceptions\Database	Always throws an exception.
	 */
	public function clear(string $pattern) : void {
		throw new \Temma\Exceptions\Database("No clear() method on this object.", \Temma\Exceptions\Database::FUNDAMENTAL);
	}

	/* ********** RAW REQUESTS ********** */
	/**
	 * Reads a message status.
	 * @param	string	$msgId			Message identifier.
	 * @param       mixed   $defaultOrCallback      (optional) Not used.
	 * @param	mixed	$options		(optional) Not used.
	 * @return	mixed	A status code or null if an error occurred.
	 */
	public function read(string $msgId, mixed $defaultOrCallback=null, mixed $options=null) : mixed {
		if (!$this->_enabled)
			return (null);
		$response = $this->_request('compteRendu.do', ['smsID' => $msgId]);
		if (ctype_digit($response))
			return ($response);
		if (!preg_match('/^[^ ]+ (\d+)/', $response, $matches))
			return (null);
		$status = $matches[1] ?? null;
		if (!mb_strlen($status))
			return (null);
		return (intval($status) + 100);
	}
	/**
	 * Send a text message. Alias to send() method.
	 * @param	string	$msisdn		Phone number.
	 * @param	string	$text		Text message.
	 * @param	mixed	$options	(optional) Associative array with the 'sendDate' and 'reference' keys.
	 * @return	string	Message identifier.
	 * @throws	\Exception	If an error occured.
	 */
	public function write(string $msisdn, string $text=null, mixed $options=null) : string {
		$sendDate = $options['sendDate'] ?? null;
		$reference = $options['reference'] ?? null;
		$msgId = $this->send($msisdn, $text, $sendDate, $reference);
		if (!$msgId)
			throw new \Exception("Unable to send text message to '$msisdn'.");
		return ($msgId);
	}

	/* ********** KEY-VALUE REQUESTS ********** */
	/**
	 * Disabled search.
	 * @param	string	$pattern	Not used.
	 * @param	bool	$getValues	(optional) Not used.
	 * @return	array	Never returned.
	 * @throws	\Temma\Exceptions\Database	Always throws an exception.
	 */
	public function search(string $pattern, bool $getValues=false) : array {
		throw new \Temma\Exceptions\Database("No search() method on this object.", \Temma\Exceptions\Database::FUNDAMENTAL);
	}
	/**
	 * Get a message status. Alias to the read() method.
	 * @param	string	$msgId			Message identifier.
	 * @param       mixed   $defaultOrCallback      (optional) Not used.
	 * @param	mixed	$options		(optional) Not used.
	 * @return	mixed	A status code or null if an error occurred.
	 */
	public function get(string $msgId, mixed $defaultOrCallback=null, mixed $options=null) : mixed {
		return ($this->read($msgId, $defaultOrCallback, $options));
	}
	/**
	 * Multiple get. Alias to the mRead() method.
	 * @param	array	$msgIds	List of message identifiers.
	 * @return	array	Associative array with the message identifiers and their associated status codes.
	 */
	public function mGet(array $msgIds) : array {
		return ($this->mRead($msgIds));
	}
	/**
	 * Send a text message. Alias to the send() method.
	 * @param	string	$msisdn		The recipient phone number.
	 * @param	mixed	$text		The text message.
	 * @param	mixed	$options	(optional) Associative array with 'sendDate' and/or 'reference' keys.
	 * @return	string	Message identifier.
	 * @throws	\Exception	If an error occured.
	 */
	public function set(string $msisdn, mixed $text=null, mixed $options=null) : string {
		$sendDate = $options['sendDate'] ?? null;
		$reference = $options['reference'] ?? null;
		$msgId = $this->send($msisdn, $text, $sendDate, $reference);
		if (!$msgId)
			throw new \Exception("Unable to send text message to '$msisdn'.");
		return ($msgId);
	}
	/**
	 * Multiple set. Alias to the mWrite() method.
	 * @param	array	$data		Associative array with keys (phone numbers) and their associated values (text messages).
	 * @param	mixed	$options	(optional) Options.
	 * @return	int	The number of sent messages.
	 */
	public function mSet(array $data, mixed $options=null) : int {
		return ($this->mWrite($data, $options));
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Send a request to smsmode.com API.
	 * @param	string	$endpoint	Last chunk of the URL.
	 * @param	array	$parameters	(optional) Associative array with the request parameters.
	 * @param	bool	$unicode	(optional) True for Unicode base URL. (defaults to false)
	 * @return	string	The request answer.
	 */
	private function _request(string $endpoint, array $parameters=[], bool $unicode=false) : string {
		$url = $unicode ? self::API_URL_UNICODE : self::API_URL;
		$url .= $endpoint . '?accessToken=' . $this->_apiKey;
		foreach ($parameters as $param => $value)
			$url .= "&$param=" . urlencode($value);
		TµLog::log('Temma/Base', 'DEBUG', "Smsmode request: '$url'.");
		$result = file_get_contents($url);
		return ($result);
	}
}

