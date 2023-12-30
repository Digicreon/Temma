<?php

/**
 * Sqs
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 */

namespace Temma\Datasources;

use \Temma\Base\Log as TµLog;

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
 *
 * // send message to the queue
 * $sqs[] = $data;
 * $sqs[''] = $data;
 * $sqs->set('', $data);
 *
 * // read a message
 * $message = $sqs[''];
 * $message = $sqs->get('');
 * // $message is an associative array with the keys "id" and "data"
 *
 * // remove a message from queue
 * unset($sqs['MESSAGE_ID']);
 * $sqs['MESSAGE_ID'] = null;
 * $sqs->set('MESSAGE_ID', null);
 * $sqs->remove('MESSAGE_ID');
 * </code>
 */
class Sqs extends \Temma\Base\Datasource {
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

	/* ********** CONSTRUCTION ********** */
	/**
	 * Create a new instance of this class.
	 * @param	string	$dsn	Connection string.
	 * @return	\Temma\Datasources\Sqs	The created instance.
	 * @throws	\Temma\Exceptions\Database	If the DSN is invalid.
	 */
	static public function factory(string $dsn) : \Temma\Datasources\Sqs {
		TµLog::log('Temma/Base', 'DEBUG', "\\Temma\\Datasources\\Sqs object creation with DSN: '$dsn'.");
		if (!preg_match('/^([^:]+):\/\/([^:]+):([^@]+)@(sqs\.)?([^\.]+)\.amazonaws\.com\/(.*)$/', $dsn, $matches)) {
			TµLog::log('Temma/Base', 'WARN', "Invalid Sqs DSN '$dsn'.");
			throw new \Temma\Exceptions\Database("Invalid Sqs DSN '$dsn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		$type = $matches[1] ?? null;
		$accessKey = $matches[2] ?? '';
		$privateKey = $matches[3] ?? '';
		$region = $matches[5] ?? '';
		$path = $matches[6] ?? '';
		if ($type != 'sqs' || !$accessKey || !$privateKey || !$region || !$path)
			throw new \Temma\Exceptions\Database("Invalid Sqs DSN '$dsn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		$url = "https://sqs.$region.amazonaws.com/$path";
		return (new self($accessKey, $privateKey, $region, $url));
	}
	/**
	 * Constructor.
	 * @param	string	$accessKey	AWS access key.
	 * @param	string	$privateKey	AWS private key.
	 * @param	string	$region		SQS region.
	 * @param	string	$url		SQS queue URL.
	 * @throws	\Temma\Exceptions\Database	If a parameter is invalid.
	 */
	private function __construct(string $accessKey, string $privateKey, string $region, string $url) {
		$this->_accessKey = $accessKey;
		$this->_privateKey = $privateKey;
		$this->_region = $region;
		$this->_url = $url;
		$this->_enabled = true;
	}

	/* ********** CONNECTION ********** */
	/**
	 * Creation of the SQS client object.
	 * @throws      \Exception      If an error occured.
	 */
	private function _connect() {
		if (!$this->_enabled || $this->_sqsClient)
			return;
		$this->_sqsClient = new \Aws\Sqs\SqsClient([
			'version'     => 'latest',
			'region'      => $this->_region,
			'credentials' => [
				'key'    => $this->_accessKey,
				'secret' => $this->_privateKey,
			],
		]);
	}

	/* ********** ARRAY-LIKE REQUESTS ********** */
	/**
	 * Return the number of waiting tasks.
	 * @return	int	The number of tasks.
	 */
	public function count() : int {
		if (!$this->_enabled)
			return (0);
		$this->_connect();
		try {
			$res = $this->_sqsClient->getQueueAttributes([
				'QueueUrl'       => $this->_url,
				'AttributeNames' => ['ApproximateNumberOfMessages'],
			]);
		} catch (\Exception $e) {
			throw new \Temma\Exceptions\Database("Unable to get SQS queue attributes.", \Temma\Exceptions\Database::QUERY);
		}
		return ($res['attributes']['ApproximateNumberOfMessages'] ?? 0);
	}

	/* ********** STANDARD REQUESTS ********** */
	/**
	 * Remove a message from SQS.
	 * @param	string	$id	Message identifier.
	 */
	public function remove(string $id) : void {
		if (!$this->_enabled)
			return;
		$this->_connect();
		if (!([$identifier, $handle] = json_decode($id, true)) || !$identifier || !$handle) {
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
	 */
	public function flush() : void {
		if (!$this->_enabled)
			return;
		$this->_connect();
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
	 * @param       mixed   $defaultOrCallback      (optional) Not used.
	 * @param	mixed	$options		(optional) Not used.
	 * @return	?array	An associative array with the keys "id" (temporary message identifier that can be used to delete the message)
	 *			and "data" (raw message data).
	 * @throws	\Exception	If an error occured.
	 */
	public function read(string $key, mixed $defaultOrCallback=null, mixed $options=null) : ?array {
		$this->_connect();
		if (!$this->_enabled)
			return (null);
		// fetch the message
		try {
			$result = $this->_sqsClient->receiveMessage([
				'QueueUrl' => $this->_url,
			]);
			$messages = $result->get('Messages');
			if ($messages && count($messages)) {
				$id = [
					$messages[0]['MessageId'],
					$messages[0]['ReceiptHandle'],
				];
				return ([
					'id'   => json_encode($id),
					'data' => $messages[0]['Body'],
				]);
			}
		} catch (\Exception $e) {
		}
		return (null);
	}
	/**
	 * Disabled multiple read.
	 * @param	array	$keys	Not used.
	 * @throws	\Temma\Exceptions\Database	Always throws an exception.
	 */
	public function mRead(array $keys) : array {
		throw new \Temma\Exceptions\Database("No mGet() method on this object.", \Temma\Exceptions\Database::FUNDAMENTAL);
	}
	/**
	 * Disabled multiple copyFrom.
	 * @param	array	$keys	Not used.
	 * @return	int	Never returned.
	 * @throws	\Temma\Exceptions\Database	Always throws an exception.
	 */
	public function mCopyFrom(array $keys) : int {
		throw new \Temma\Exceptions\Database("No mCopyFrom() method on this object.", \Temma\Exceptions\Database::FUNDAMENTAL);
	}
	/**
	 * Add a message in SQS.
	 * @param	string	$id		Not used.
	 * @param	string	$data		Message data.
	 * @param	mixed	$options	(optional) Not used.
	 * @return	?string	Message identifier.
	 * @throws	\Exception	If an error occured.
	 */
	public function write(string $id, string $data, mixed $options=null) : ?string {
		if (!$this->_enabled)
			return (null);
		$this->_connect();
		// add message
		try {
			$result = $this->_sqsClient->sendMessage([
				'QueueUrl' => $this->_url,
				'Body'     => $data,
			]);
		} catch (\Exception $e) {
			TµLog::log('Temma/Base', 'INFO', "Can't send message to AWS SQS. Queue='{$this->_url}'.");
			throw $e;
		}
		return ($result['MessageId']);
	}
	/**
	 * Multiple write.
	 * @param	array	$data		List of messages to send.
	 * @param	mixed	$options	(optional) Not used.
	 * @return	int	The number of set data.
	 */
	public function mWrite(array $data, mixed $options=null) : int {
		if (!$this->_enabled)
			return (0);
		$this->_connect();
		$params = [
			'QueueUrl' => $this->_url,
			'Entries'  => [],
		];
		foreach ($data as $key => $datum) {
			$params['Entries'][] = [
				'Id'          => (string)$key,
				'MessageBody' => (string)$datum,
			];
		}
		try {
			$result = $this->_sqsClient->sendMessageBatch($params);
			if (($result['Successful'] ?? null) && is_array($result['Successful']))
				return (count($result['Successful']));
		} catch (\Exception $e) {
			TµLog::log('Temma/Base', 'INFO', "Can't send multiple messages to AWS SQS. Queue='{$this->_url}'.");
			throw $e;
		}
		return (0);
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
	 * Get a message from SQS.
	 * @param	string	$key			Not used, should be an empty string.
	 * @param       mixed   $defaultOrCallback      (optional) Not used.
	 * @param	mixed	$options		(optional) Not used.
	 * @return	mixed	An associative array with the keys "id" (temporary message identifier that can be used to delete the message)
	 *			and "data" (JSON-decoded message data).
	 * @throws	\Exception	If an error occured.
	 */
	public function get(string $key, mixed $defaultOrCallback=null, mixed $options=null) : mixed {
		if (!$this->_enabled)
			return (null);
		$message = $this->read($key, $defaultOrCallback, $options);
		if (($message['data'] ?? null))
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
		throw new \Temma\Exceptions\Database("No mGet() method on this object.", \Temma\Exceptions\Database::FUNDAMENTAL);
	}
	/**
	 * Add a message in SQS.
	 * @param	string	$id		Message identifier, only used if the second parameter is null (remove the message).
	 * @param	mixed	$data		(optional) Message data. The data is deleted if the value is not given or if it is null.
	 * @param	mixed	$options	(optional) Not used.
	 * @return	?string	Message identifier.
	 * @throws	\Exception	If an error occured.
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
}

