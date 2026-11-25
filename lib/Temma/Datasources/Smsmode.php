<?php

/**
 * Smsmode
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023-2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/datasource-smsmode
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
 * For API details, please refer to its documentation (https://dev.smsmode.com/api-http/).
 * Texts compatible with the ISO-8859-15 character set are sent as standard messages (160 characters
 * per message); other texts are sent as Unicode messages (70 characters per message).
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
 * if ($status == \Temma\Datasources\Smsmode::STATUS_SENT) {
 *     // ...
 * } else if ($status == \Temma\Datasources\Smsmode::STATUS_MSG_RECEIVED) {
 *     // ...
 * } else if ($status == \Temma\Datasources\Smsmode::STATUS_GATEWAY_RECEIVED) {
 *     // ...
 * } else if ($status == \Temma\Datasources\Smsmode::STATUS_MSG_INTERNAL_ERROR ||
 *            $status == \Temma\Datasources\Smsmode::STATUS_ROUTING_ERROR ||
 *            $status == \Temma\Datasources\Smsmode::STATUS_DELIVERY_ERROR) {
 *     // ...
 * }
 *
 * // get organization's balance
 * $balance = $sms->getBalance();
 *
 * // delete a scheduled message (from its message ID)
 * unset($sms[$msgId]);
 * $sms->remove($msgId);
 * </code>
 */
class Smsmode extends \Temma\Base\Datasource {
	/** URL of smsmode.com API. */
	const API_URL = 'https://api.smsmode.com/http/1.6/';
	/** URL of smsmode.com API in Unicode mode (as written in smsmode's documentation). */
	const API_URL_UNICODE = 'https://api.smsmode.com/http:1.6/';
	/** Message class used to send Unicode text messages. */
	const UNICODE_MESSAGE_CLASS = 9;
	/** Maximum length of the sender name. */
	const SENDER_MAX_LENGTH = 11;
	/** Request statuses. */
	const STATUS_SUCCESS = 0;
	const STATUS_INTERNAL_ERROR = 31;
	const STATUS_AUTHENTICATION_ERROR = 32;
	const STATUS_INSUFFICIENTE_BALANCE = 33;
	const STATUS_INVALID_PARAMETERS = 35;
	const STATUS_UNAUTHORIZED_IP = 42;
	const STATUS_TEMPORARY_UNAVAILABLE = 50;
	const STATUS_SMS_NOT_FOUND = 61;
	const STATUS_MESSAGE_NOT_FOUND = 65;
	/** Message statuses (status returned by the API, plus 100). */
	const STATUS_SENT = 100;
	const STATUS_MSG_INTERNAL_ERROR = 102;
	const STATUS_MSG_RECEIVED = 111;
	/** Message delivered to the recipient's operator (phone switched off or out of network). */
	const STATUS_GATEWAY_RECEIVED = 113;
	/** Routing error (recipient's network not recognized). */
	const STATUS_ROUTING_ERROR = 134;
	/** @deprecated	Wrong name, this status is a routing error. Use STATUS_ROUTING_ERROR instead. */
	const STATUS_GATEWAY_DELIVERED = 134;
	/** Reception error (message not delivered by the operator to the recipient's phone). */
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
		TµLog::log('Temma/Base', 'DEBUG', "\\Temma\\Datasources\\Smsmode object creation with DSN: '" . self::_maskDsn($dsn) . "'.");
		if (!preg_match('/^smsmode:\/\/(([^:]*):)?(.+)$/', $dsn, $matches)) {
			TµLog::log('Temma/Base', 'WARN', "Invalid Smsmode DSN '" . self::_maskDsn($dsn) . "'.");
			throw new \Temma\Exceptions\Database("Invalid Smsmode DSN '" . self::_maskDsn($dsn) . "'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		$sender = $matches[2] ?? null;
		$apiKey = $matches[3] ?? '';
		if (!$apiKey || ($sender && !preg_match('/^[a-zA-Z0-9]{1,' . self::SENDER_MAX_LENGTH . '}$/', $sender)))
			throw new \Temma\Exceptions\Database("Invalid Smsmode DSN '" . self::_maskDsn($dsn) . "'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		return (new self($apiKey, $sender));
	}
	/**
	 * Constructor.
	 * @param	string	$apiKey	API key.
	 * @param	?string	$sender	(optional) Sender name.
	 * @throws	\Temma\Exceptions\Database	If a parameter is invalid.
	 */
	public function __construct(string $apiKey, ?string $sender=null) {
		$this->_apiKey = $apiKey;
		$this->_sender = $sender ?: null;
	}

	/* ********** SPECIAL REQUESTS ********** */
	/**
	 * Send a text message.
	 * @param	string|array	$recipient	Phone number or list of phone numbers.
	 * @param	?string		$text		Text message.
	 * @param	?string		$sendDate	(optional) Sending date (format 'DDMMYYYY-HH:mm').
	 * @param	?string		$reference	(optional) Internal reference, sent back in the delivery report notifications.
	 * @return	?string	Message identifier or null if an error occurred.
	 * @throws	\Temma\Exceptions\Database	If the request to the API failed.
	 */
	public function send(string|array $recipient, ?string $text=null, ?string $sendDate=null, ?string $reference=null) : ?string {
		if (!$this->_enabled)
			return (null);
		// text
		$text = trim($text ?? '');
		if ($text === '')
			return (null);
		// parameters
		$params = [
			'numero'  => is_array($recipient) ? implode(',', $recipient) : $recipient,
			'message' => $text,
		];
		// text compatible with ISO-8859-15: converted; otherwise: sent in UTF-8 as a Unicode message
		$unicode = false;
		if (\Temma\Utils\Text::encodingCompatible($text, 'iso-8859-15')) {
			$params['message'] = mb_convert_encoding($text, 'iso-8859-15', 'utf-8');
		} else {
			$unicode = true;
			$params['classe_msg'] = self::UNICODE_MESSAGE_CLASS;
		}
		if ($sendDate)
			$params['date_envoi'] = $sendDate;
		if ($reference)
			$params['refClient'] = $reference;
		if ($this->_sender)
			$params['emetteur'] = $this->_sender;
		// request
		$response = $this->_request('sendSMS.do', $params, $unicode);
		// response ("code | description | message identifier")
		if (!preg_match('/^(\d+)\s*\|[^|]*\|\s*(\S+)/', $response, $matches) || ($matches[1] !== '0'))
			return (null);
		return ($matches[2]);
	}
	/**
	 * Get the organization's balance.
	 * @return	?float	Organization's balance, or null if an error occurred.
	 * @throws	\Temma\Exceptions\Database	If the request to the API failed.
	 */
	public function getBalance() : ?float {
		if (!$this->_enabled)
			return (null);
		$response = trim($this->_request('credit.do'));
		// the response is a number (negative for a post-paid account); anything else is an error
		if (!preg_match('/^-?\d+(\.\d+)?$/', $response))
			return (null);
		return ((float)$response);
	}

	/* ********** STANDARD REQUESTS ********** */
	/**
	 * Delete a scheduled message.
	 * @param	string	$msgId	Message identifier.
	 * @throws	\Temma\Exceptions\Database	If an error occurred.
	 */
	public function remove(string $msgId) : void {
		if (!$this->_enabled)
			return;
		$response = $this->_request('deleteSMS.do', ['smsID' => $msgId]);
		// return code 0 alone, or followed by a description ("0 | description")
		if (!preg_match('/^0\s*(\||$)/', trim($response)))
			throw new \Temma\Exceptions\Database("Unable to delete text message identified by '$msgId'.", \Temma\Exceptions\Database::FUNDAMENTAL);
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
	 * @param	mixed	$defaultOrCallback	(optional) Not used.
	 * @param	mixed	$options		(optional) Not used.
	 * @return	?int	A message status (STATUS_SENT, STATUS_MSG_RECEIVED...; status of the first recipient if the message
	 *			was sent to several recipients), a request status if the API returned an error (STATUS_SMS_NOT_FOUND...),
	 *			or null if the response is not understood.
	 * @throws	\Temma\Exceptions\Database	If the request to the API failed.
	 */
	public function read(string $msgId, mixed $defaultOrCallback=null, mixed $options=null) : ?int {
		if (!$this->_enabled)
			return (null);
		// response: "phone_number status | phone_number status | ...", or an error code
		$response = trim($this->_request('compteRendu.do', ['smsID' => $msgId]));
		if (preg_match('/^(\d+)\s*(\||$)/', $response, $matches))
			return (intval($matches[1]));
		if (!preg_match('/^\S+\s+(\d+)/', $response, $matches))
			return (null);
		return (intval($matches[1]) + 100);
	}
	/**
	 * Send a text message. Alias to send() method.
	 * @param	string	$msisdn		Phone number.
	 * @param	?string	$text		Text message.
	 * @param	mixed	$options	(optional) Associative array with the 'sendDate' and 'reference' keys.
	 * @return	string	Message identifier.
	 * @throws	\Temma\Exceptions\Database	If an error occured.
	 */
	public function write(string $msisdn, ?string $text=null, mixed $options=null) : string {
		$sendDate = $options['sendDate'] ?? null;
		$reference = $options['reference'] ?? null;
		$msgId = $this->send($msisdn, $text, $sendDate, $reference);
		if (!$msgId)
			throw new \Temma\Exceptions\Database("Unable to send text message to '$msisdn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		return ($msgId);
	}

	/* ********** KEY-VALUE REQUESTS ********** */
	/**
	 * Disabled search. The $sort parameter is not pertinent on a messaging datasource.
	 * @param	string			$pattern	Not used.
	 * @param	bool			$getValues	(optional) Not used.
	 * @param	null|bool|string|array	$sort		(optional) Not pertinent on this datasource.
	 * @param	int			$offset		(optional) Not used.
	 * @param	int			$limit		(optional) Not used.
	 * @return	array	Never returned.
	 * @throws	\Temma\Exceptions\Database	Always throws an exception.
	 */
	public function search(string $pattern, bool $getValues=false, null|bool|string|array $sort=null, int $offset=0, int $limit=0) : array {
		throw new \Temma\Exceptions\Database("No search() method on this object.", \Temma\Exceptions\Database::FUNDAMENTAL);
	}
	/**
	 * Get a message status. Alias to the read() method.
	 * @param	string	$msgId			Message identifier.
	 * @param	mixed	$defaultOrCallback	(optional) Not used.
	 * @param	mixed	$options		(optional) Not used.
	 * @return	mixed	A status code or null if an error occurred.
	 * @throws	\Temma\Exceptions\Database	If the request to the API failed.
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
	 * @throws	\Temma\Exceptions\Database	If an error occured.
	 */
	public function set(string $msisdn, mixed $text=null, mixed $options=null) : string {
		$sendDate = $options['sendDate'] ?? null;
		$reference = $options['reference'] ?? null;
		$msgId = $this->send($msisdn, $text, $sendDate, $reference);
		if (!$msgId)
			throw new \Temma\Exceptions\Database("Unable to send text message to '$msisdn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
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

	/* ********** PROTECTED METHODS ********** */
	/**
	 * Send a request to smsmode.com API.
	 * @param	string	$endpoint	Last chunk of the URL.
	 * @param	array	$parameters	(optional) Associative array with the request parameters.
	 * @param	bool	$unicode	(optional) True for Unicode base URL. (defaults to false)
	 * @return	string	The request answer.
	 * @throws	\Temma\Exceptions\Database	If the request failed.
	 */
	protected function _request(string $endpoint, array $parameters=[], bool $unicode=false) : string {
		$url = ($unicode ? self::API_URL_UNICODE : self::API_URL) . $endpoint;
		$query = '';
		foreach ($parameters as $param => $value)
			$query .= "&$param=" . urlencode($value);
		// the access token and the parameters (phone numbers, messages) are not written in the log
		TµLog::log('Temma/Base', 'DEBUG', "Smsmode request: '$url'.");
		$curl = curl_init("$url?accessToken=" . urlencode($this->_apiKey) . $query);
		curl_setopt_array($curl, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT        => 10,
		]);
		$result = curl_exec($curl);
		if ($result === false) {
			$error = curl_error($curl);
			curl_close($curl);
			throw new \Temma\Exceptions\Database("cURL error while calling Smsmode API: $error", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		curl_close($curl);
		return ($result);
	}
}

