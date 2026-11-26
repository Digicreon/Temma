<?php

/**
 * Sqs
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023-2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/datasource-sqs
 */

namespace Temma\Datasources;

use \Temma\Base\Log as TµLog;

// the AWS SDK is loaded only if it is not already available (Composer, other data source...)
if (!class_exists('\Aws\Sqs\SqsClient'))
	require_once('aws.phar');

/**
 * Amazon SQS management object.
 *
 * This object is used to read and write messages from AWS SQS.
 *
 * To use it, you must copy the "aws.phar" file in the "lib" directory:
 * <tt>wget -O lib/aws.phar https://docs.aws.amazon.com/aws-sdk-php/v3/download/aws.phar</tt>
 *
 * <b>Usage</b>
 * <code>
 * // initialization
 * $sqs = \Temma\Datasources\Sqs::factory('sqs://ACCESS_KEY:SECRET_KEY@QUEUE_URL');
 * $sqs = \Temma\Base\Datasource::factory('sqs://ACCESS_KEY:SECRET_KEY@QUEUE_URL');
 * // QUEUE_URL is everything after the 'https://'. For example: "sqs.eu-west-3.amazonaws.com/123456789012/queue_name"
 * // A long polling wait duration (in seconds, 20 at most) may be added at the end of the DSN: 'sqs://ACCESS_KEY:SECRET_KEY@QUEUE_URL#20'
 *
 * // send message to the queue
 * $sqs[] = $data;
 * $sqs[''] = $data;
 * $sqs->set('', $data);
 *
 * // read a message (null if no message is available)
 * $message = $sqs[''];
 * $message = $sqs->get('');
 * // $message is an associative array with the keys "id" and "data"
 *
 * // read a message, waiting up to 10 seconds (long polling, 20 seconds at most)
 * $message = $sqs->get('', null, 10);
 *
 * // remove a message from queue
 * unset($sqs['MESSAGE_ID']);
 * $sqs['MESSAGE_ID'] = null;
 * $sqs->set('MESSAGE_ID', null);
 * $sqs->remove('MESSAGE_ID');
 *
 * // send several messages (null values are ignored)
 * $sqs->mSet([$data1, $data2]);
 * $sqs->mWrite(['raw message 1', 'raw message 2']);
 * </code>
 */
class Sqs extends \Temma\Base\Datasource {
	/** Constant: Maximum long polling wait duration accepted by SQS, in seconds. */
	const MAX_WAIT_POLLING = 20;
	/** Constant: Connection timeout of the HTTP requests, in seconds. */
	const CONNECT_TIMEOUT = 10;
	/** Constant: Timeout of the HTTP requests, in seconds (must be greater than the maximum long polling wait duration). */
	const REQUEST_TIMEOUT = 30;
	/** AWS SQS client object. */
	private $_sqsClient = null;
	/** Access key. */
	private ?string $_accessKey = null;
	/** Private key. */
	private ?string $_privateKey = null;
	/** Region. */
	private ?string $_region = null;
	/** Queue URL. */
	private ?string $_url = null;
	/** Long polling wait duration in seconds. */
	private ?int $_waitPolling = null;

	/* ********** CONSTRUCTION ********** */
	/**
	 * Create a new instance of this class.
	 * @param	string	$dsn	Connection string.
	 * @return	\Temma\Datasources\Sqs	The created instance.
	 * @throws	\Temma\Exceptions\Database	If the DSN is invalid.
	 */
	static public function factory(string $dsn) : \Temma\Datasources\Sqs {
		$maskedDsn = self::_maskDsn($dsn);
		TµLog::log('Temma/Base', 'DEBUG', "\\Temma\\Datasources\\Sqs object creation with DSN: '$maskedDsn'.");
		if (!preg_match('/^([^:]+):\/\/([^:]+):([^@]+)@(sqs\.)?([^\.]+)\.amazonaws\.com\/([^#]*)(?:#(\d*))?$/', $dsn, $matches)) {
			TµLog::log('Temma/Base', 'WARN', "Invalid Sqs DSN '$maskedDsn'.");
			throw new \Temma\Exceptions\Database("Invalid Sqs DSN '$maskedDsn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		$type = $matches[1] ?? null;
		$accessKey = $matches[2] ?? '';
		$privateKey = $matches[3] ?? '';
		$region = $matches[5] ?? '';
		$path = $matches[6] ?? '';
		$waitPolling = (($matches[7] ?? '') !== '') ? intval($matches[7]) : null;
		if ($type != 'sqs' || $accessKey === '' || $privateKey === '' || $region === '' || $path === '')
			throw new \Temma\Exceptions\Database("Invalid Sqs DSN '$maskedDsn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		$url = "https://sqs.$region.amazonaws.com/$path";
		return (new self($accessKey, $privateKey, $region, $url, $waitPolling));
	}
	/**
	 * Constructor.
	 * @param	string	$accessKey	AWS access key.
	 * @param	string	$privateKey	AWS private key.
	 * @param	string	$region		SQS region.
	 * @param	string	$url		SQS queue URL.
	 * @param	?int	$waitPolling	(optional) Long polling wait duration in seconds (limited to 20 seconds).
	 */
	public function __construct(string $accessKey, string $privateKey, string $region, string $url, ?int $waitPolling=null) {
		$this->_accessKey = $accessKey;
		$this->_privateKey = $privateKey;
		$this->_region = $region;
		$this->_url = $url;
		$this->_waitPolling = ($waitPolling === null) ? null : self::_limitWaitPolling($waitPolling);
	}

	/* ********** CONNECTION ********** */
	/** Creation of the SQS client object. */
	public function connect() {
		if (!$this->_enabled || $this->_sqsClient)
			return;
		$this->reconnect();
	}
	/** Reconnection. */
	public function reconnect() {
		$this->disconnect();
		$this->_sqsClient = new \Aws\Sqs\SqsClient([
			'version'     => 'latest',
			'region'      => $this->_region,
			'credentials' => [
				'key'    => $this->_accessKey,
				'secret' => $this->_privateKey,
			],
			'http'        => [
				'connect_timeout' => self::CONNECT_TIMEOUT,
				'timeout'         => self::REQUEST_TIMEOUT,
			],
		]);
	}
	/** Disconnection. */
	public function disconnect() {
		unset($this->_sqsClient);
		$this->_sqsClient = null;
	}

	/* ********** ARRAY-LIKE REQUESTS ********** */
	/**
	 * Return the approximate number of waiting messages.
	 * @param	?string	$pattern	(optional) Not used.
	 * @return	int	The number of messages.
	 * @throws	\Temma\Exceptions\Database	If the queue attributes couldn't be fetched.
	 */
	public function count(?string $pattern=null) : int {
		if (!$this->_enabled)
			return (0);
		$this->connect();
		try {
			$res = $this->_sqsClient->getQueueAttributes([
				'QueueUrl'       => $this->_url,
				'AttributeNames' => ['ApproximateNumberOfMessages'],
			]);
		} catch (\Exception $e) {
			TµLog::log('Temma/Base', 'INFO', "Unable to get AWS SQS queue attributes. Queue='{$this->_url}'.");
			throw new \Temma\Exceptions\Database('Unable to get SQS queue attributes.', \Temma\Exceptions\Database::QUERY, $e);
		}
		return ((int)($res['Attributes']['ApproximateNumberOfMessages'] ?? 0));
	}

	/* ********** STANDARD REQUESTS ********** */
	/**
	 * Remove a message from SQS.
	 * @param	string	$id	Message identifier, as returned by read() or get().
	 * @throws	\Temma\Exceptions\Database	If the identifier is not valid.
	 * @throws	\Exception	If the message couldn't be removed.
	 */
	public function remove(string $id) : void {
		if (!$this->_enabled)
			return;
		$this->connect();
		$idParts = json_decode($id, true);
		$handle = is_array($idParts) ? ($idParts[1] ?? null) : null;
		if (!is_string($handle) || $handle === '') {
			TµLog::log('Temma/Base', 'INFO', "Bad identifier for SQS message ('$id').");
			throw new \Temma\Exceptions\Database("Bad identifier for SQS message ('$id').", \Temma\Exceptions\Database::QUERY);
		}
		try {
			$this->_sqsClient->deleteMessage([
				'QueueUrl'      => $this->_url,
				'ReceiptHandle' => $handle,
			]);
		} catch (\Exception $e) {
			TµLog::log('Temma/Base', 'INFO', "Unable to remove an AWS SQS message. Queue='{$this->_url}'.");
			throw $e;
		}
	}
	/**
	 * Remove all messages from an SQS queue.
	 * @throws	\Exception	If the queue couldn't be purged.
	 */
	public function flush() : void {
		if (!$this->_enabled)
			return;
		$this->connect();
		try {
			$this->_sqsClient->purgeQueue([
				'QueueUrl' => $this->_url,
			]);
		} catch (\Exception $e) {
			TµLog::log('Temma/Base', 'INFO', "Unable to flush an AWS SQS queue. Queue='{$this->_url}'.");
			throw $e;
		}
	}

	/* ********** RAW REQUESTS ********** */
	/**
	 * Get a message from SQS.
	 * @param	string	$key			Not used, should be an empty string.
	 * @param	mixed	$defaultOrCallback	(optional) Not used.
	 * @param	mixed	$options		(optional) The number of seconds to wait for a message (long polling, 20 seconds at most).
	 *						If it is not given (or zero), the wait duration given in the DSN is used.
	 * @return	?array	An associative array with the keys "id" (temporary message identifier that can be used to delete the message)
	 *			and "data" (raw message data), or null if there is no message (or if the datasource is disabled).
	 * @throws	\Exception	If the message couldn't be fetched (network error, bad credentials...).
	 */
	public function read(string $key, mixed $defaultOrCallback=null, mixed $options=null) : ?array {
		if (!$this->_enabled)
			return (null);
		$this->connect();
		// fetch the message
		try {
			$params = [
				'QueueUrl'            => $this->_url,
				'MaxNumberOfMessages' => 1,
			];
			if (is_int($options) && $options)
				$params['WaitTimeSeconds'] = self::_limitWaitPolling($options);
			else if ($this->_waitPolling !== null)
				$params['WaitTimeSeconds'] = $this->_waitPolling;
			$result = $this->_sqsClient->receiveMessage($params);
		} catch (\Exception $e) {
			TµLog::log('Temma/Base', 'INFO', "Unable to read an AWS SQS message. Queue='{$this->_url}'.");
			throw $e;
		}
		// no message (empty queue)
		$messages = $result->get('Messages');
		if (!$messages)
			return (null);
		$id = [
			$messages[0]['MessageId'],
			$messages[0]['ReceiptHandle'],
		];
		return ([
			'id'   => json_encode($id),
			'data' => $messages[0]['Body'],
		]);
	}
	/**
	 * Disabled multiple read.
	 * @param	array	$keys	Not used.
	 * @throws	\Temma\Exceptions\Database	Always throws an exception.
	 */
	public function mRead(array $keys) : array {
		throw new \Temma\Exceptions\Database('No mRead() method on this object.', \Temma\Exceptions\Database::FUNDAMENTAL);
	}
	/**
	 * Disabled multiple copyFrom.
	 * @param	array	$keys	Not used.
	 * @return	int	Never returned.
	 * @throws	\Temma\Exceptions\Database	Always throws an exception.
	 */
	public function mCopyFrom(array $keys) : int {
		throw new \Temma\Exceptions\Database('No mCopyFrom() method on this object.', \Temma\Exceptions\Database::FUNDAMENTAL);
	}
	/**
	 * Add a message in SQS.
	 * @param	string	$id		Not used.
	 * @param	string	$data		Message data.
	 * @param	mixed	$options	(optional) Not used.
	 * @return	?string	Message identifier (null if the datasource is disabled).
	 * @throws	\Exception	If the message couldn't be sent.
	 */
	public function write(string $id, string $data, mixed $options=null) : ?string {
		if (!$this->_enabled)
			return (null);
		$this->connect();
		// add message
		try {
			$result = $this->_sqsClient->sendMessage([
				'QueueUrl'    => $this->_url,
				'MessageBody' => $data,
			]);
		} catch (\Exception $e) {
			TµLog::log('Temma/Base', 'INFO', "Can't send message to AWS SQS. Queue='{$this->_url}'.");
			throw $e;
		}
		return ($result['MessageId']);
	}
	/**
	 * Multiple write. Messages are sent by batches of 10 (SQS limit).
	 * @param	array	$data		List of raw messages to send (strings; numbers are converted to strings).
	 *					Null values are ignored (no message is removed).
	 * @param	mixed	$options	(optional) Not used.
	 * @return	int	The number of sent messages.
	 * @throws	\Temma\Exceptions\Database	If a message is not a string or a number (nothing is sent).
	 * @throws	\Exception	If a batch couldn't be sent (the previous batches have been sent).
	 */
	public function mWrite(array $data, mixed $options=null) : int {
		if (!$this->_enabled)
			return (0);
		// check the messages before sending anything
		$messages = [];
		foreach ($data as $datum) {
			if (($datum = self::_toRawValue($datum)) !== null)
				$messages[] = $datum;
		}
		if (!$messages)
			return (0);
		$this->connect();
		$sent = 0;
		foreach (array_chunk($messages, 10) as $chunk) {
			$params = [
				'QueueUrl' => $this->_url,
				'Entries'  => [],
			];
			// the identifiers must be unique in a batch (the array keys may not be valid identifiers)
			foreach ($chunk as $i => $datum) {
				$params['Entries'][] = [
					'Id'          => (string)$i,
					'MessageBody' => $datum,
				];
			}
			try {
				$result = $this->_sqsClient->sendMessageBatch($params);
			} catch (\Exception $e) {
				TµLog::log('Temma/Base', 'INFO', "Can't send multiple messages to AWS SQS. Queue='{$this->_url}'.");
				throw $e;
			}
			if (is_array($result['Successful'] ?? null))
				$sent += count($result['Successful']);
		}
		return ($sent);
	}

	/* ********** KEY-VALUE REQUESTS ********** */
	/**
	 * Disabled search. The $sort parameter is not pertinent on a queue datasource.
	 * @param	string			$pattern	Not used.
	 * @param	bool			$getValues	(optional) Not used.
	 * @param	null|bool|string|array	$sort		(optional) Not pertinent on this datasource.
	 * @param	int			$offset		(optional) Not used.
	 * @param	int			$limit		(optional) Not used.
	 * @return	array	Never returned.
	 * @throws	\Temma\Exceptions\Database	Always throws an exception.
	 */
	public function search(string $pattern, bool $getValues=false, null|bool|string|array $sort=null, int $offset=0, int $limit=0) : array {
		throw new \Temma\Exceptions\Database('No search() method on this object.', \Temma\Exceptions\Database::FUNDAMENTAL);
	}
	/**
	 * Get a message from SQS.
	 * @param	string	$key			Not used, should be an empty string.
	 * @param	mixed	$defaultOrCallback	(optional) Not used.
	 * @param	mixed	$options		(optional) Number of seconds to wait for a message (long polling, 20 seconds at most).
	 *						If it is not given (or zero), the wait duration given in the DSN is used.
	 * @return	?array	An associative array with the keys "id" (temporary message identifier that can be used to delete the message)
	 *			and "data" (JSON-decoded message data), or null if there is no message (or if the datasource is disabled).
	 * @throws	\Exception	If the message couldn't be fetched (network error, bad credentials...).
	 */
	public function get(string $key, mixed $defaultOrCallback=null, mixed $options=null) : ?array {
		if (!$this->_enabled)
			return (null);
		$message = $this->read($key, $defaultOrCallback, $options);
		if (is_string($message['data'] ?? null))
			$message['data'] = json_decode($message['data'], true);
		return ($message);
	}
	/**
	 * Disabled multiple get.
	 * @param	array	$keys	Not used.
	 * @return	array	Never returned.
	 * @throws	\Temma\Exceptions\Database	Always throws an exception.
	 */
	public function mGet(array $keys) : array {
		throw new \Temma\Exceptions\Database('No mGet() method on this object.', \Temma\Exceptions\Database::FUNDAMENTAL);
	}
	/**
	 * Add a message in SQS.
	 * @param	string	$id		Message identifier, only used if the second parameter is null (remove the message).
	 * @param	mixed	$data		(optional) Message data. The data is deleted if the value is not given or if it is null.
	 * @param	mixed	$options	(optional) Not used.
	 * @return	?string	Message identifier (null if the message was removed or if the datasource is disabled).
	 * @throws	\Exception	If the message couldn't be sent or removed.
	 */
	public function set(string $id, mixed $data=null, mixed $options=null) : ?string {
		if (!$this->_enabled)
			return (null);
		if (is_null($data)) {
			$this->remove($id);
			return (null);
		}
		return ($this->write($id, json_encode($data), $options));
	}
	/**
	 * Add multiple messages in SQS. Each message is JSON-encoded.
	 * @param	array	$data		List of message data. Null values are ignored (no message is removed).
	 * @param	mixed	$options	(optional) Not used.
	 * @return	int	The number of sent messages (0 if the datasource is disabled).
	 * @throws	\Exception	If a batch couldn't be sent (the previous batches have been sent).
	 */
	public function mSet(array $data, mixed $options=null) : int {
		return (parent::mSet($data, $options));
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Limit a long polling wait duration to the range accepted by SQS (0 to 20 seconds).
	 * @param	int	$waitPolling	Wait duration, in seconds.
	 * @return	int	The limited wait duration.
	 */
	static private function _limitWaitPolling(int $waitPolling) : int {
		return (max(0, min($waitPolling, self::MAX_WAIT_POLLING)));
	}
}
