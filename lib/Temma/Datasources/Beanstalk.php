<?php

/**
 * Beanstalk
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023-2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/datasource-beanstalk
 */

namespace Temma\Datasources;

use \Temma\Base\Log as TµLog;

/**
 * Beanstalkd management object.
 *
 * This object is used to read and write messages on a Beanstalkd server.
 *
 * To use it, you must install the "Pheanstalk" package (version 5) using Composer:
 * <tt>composer require pda/pheanstalk:^5</tt>
 *
 * <b>Usage</b>
 * <code>
 * // initialization (the port number is optional, 11300 by default)
 * $bean = \Temma\Datasources\Beanstalk::factory('beanstalk://HOST:PORT/TUBE_NAME');
 * $bean = \Temma\Base\Datasource::factory('beanstalk://HOST:PORT/TUBE_NAME');
 *
 * // send message to the queue
 * $bean[] = $data;
 * $bean[''] = $data;
 * $bean->set('', $data);
 *
 * // read a message (wait up to 20 seconds; null is returned if no message is available)
 * $message = $bean[''];
 * $message = $bean->get('');
 * // $message is an associative array with the keys "id" (job identifier) and "data"
 * // read a message, waiting up to 5 seconds (0 to return immediately)
 * $message = $bean->get('', null, 5);
 *
 * // remove a message from queue
 * unset($bean['MESSAGE_ID']);
 * $bean['MESSAGE_ID'] = null;
 * $bean->set('MESSAGE_ID', null);
 * $bean->remove('MESSAGE_ID');
 * </code>
 */
class Beanstalk extends \Temma\Base\Datasource {
	/** Constant: Default Beanstalkd port number. */
	const DEFAULT_BEANSTALK_PORT = 11300;
	/** Constant: Maximum (and default) wait duration of read() and get(), in seconds. */
	const MAX_RESERVE_TIMEOUT = 20;
	/** Constant: Reception timeout of the connection, in seconds (must be greater than the maximum wait duration). */
	const RECEIVE_TIMEOUT = 30;
	/** Pheanstalk object. */
	private ?\Pheanstalk\Pheanstalk $_pheanstalk = null;
	/** Host. */
	private string $_host;
	/** Port. */
	private int $_port = self::DEFAULT_BEANSTALK_PORT;
	/** Tube (queue) name. */
	private string $_tube;

	/* ********** CONSTRUCTION ********** */
	/**
	 * Create a new instance of this class.
	 * @param	string	$dsn	Connection string.
	 * @return	\Temma\Datasources\Beanstalk	The created instance.
	 * @throws	\Temma\Exceptions\Database	If the DSN is invalid.
	 */
	static public function factory(string $dsn) : \Temma\Datasources\Beanstalk {
		$maskedDsn = self::_maskDsn($dsn);
		TµLog::log('Temma/Base', 'DEBUG', "\\Temma\\Datasources\\Beanstalk object creation with DSN: '$maskedDsn'.");
		if (!preg_match('/^beanstalk:\/\/([^\/:]+)(?::(\d*))?\/(.+)$/', $dsn, $matches)) {
			TµLog::log('Temma/Base', 'WARN', "Invalid Beanstalk DSN '$maskedDsn'.");
			throw new \Temma\Exceptions\Database("Invalid Beanstalk DSN '$maskedDsn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		$host = $matches[1];
		$port = ($matches[2] !== '') ? intval($matches[2]) : self::DEFAULT_BEANSTALK_PORT;
		$tube = $matches[3];
		return (new self($host, $port, $tube));
	}
	/**
	 * Constructor.
	 * @param	string	$host	Beanstalkd host name.
	 * @param	int	$port	Port number.
	 * @param	string	$tube	Tube name.
	 */
	public function __construct(string $host, int $port, string $tube) {
		$this->_host = $host;
		$this->_port = $port;
		$this->_tube = $tube;
	}

	/* ********** CONNECTION ********** */
	/**
	 * Creation of the Pheanstalk client object. The connection is opened when the first request is sent.
	 * @throws	\Temma\Exceptions\Database	If the Pheanstalk library is not available.
	 */
	public function connect() {
		if (!$this->_enabled || $this->_pheanstalk)
			return;
		$this->reconnect();
	}
	/**
	 * Reopen the connection.
	 * @throws	\Temma\Exceptions\Database	If the Pheanstalk library is not available.
	 */
	public function reconnect() {
		if (!$this->_enabled)
			return;
		$this->disconnect();
		if (!class_exists('\Pheanstalk\Pheanstalk')) {
			TµLog::log('Temma/Base', 'ERROR', 'The Pheanstalk library is not available.');
			throw new \Temma\Exceptions\Database('The Pheanstalk library is not available.', \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		$this->_pheanstalk = \Pheanstalk\Pheanstalk::create($this->_host, $this->_port, null, new \Pheanstalk\Values\Timeout(self::RECEIVE_TIMEOUT));
	}
	/** Close the connection. */
	public function disconnect() {
		// the connection is closed when the Pheanstalk object is destroyed
		$this->_pheanstalk = null;
	}

	/* ********** SPECIAL REQUESTS ********** */
	/**
	 * Tell Beanstalkd that the given job is still processing,
	 * avoiding to reschedule it.
	 * @param	string	$id	Job identifier.
	 * @return	\Temma\Datasources\Beanstalk	The current object.
	 * @throws	\Exception	If an error occurred.
	 */
	public function touch(string $id) : \Temma\Datasources\Beanstalk {
		if (!$this->_enabled)
			return ($this);
		$this->connect();
		$this->_pheanstalk->touch(new \Pheanstalk\Values\JobId($id));
		return ($this);
	}

	/* ********** ARRAY-LIKE REQUESTS ********** */
	/**
	 * Return the number of jobs ready in the tube.
	 * @param	?string	$pattern	(optional) Not used.
	 * @return	int	The queue size.
	 * @throws	\Exception	If an error occurred.
	 */
	public function count(?string $pattern=null) : int {
		if (!$this->_enabled)
			return (0);
		$this->connect();
		try {
			$stats = $this->_pheanstalk->statsTube(new \Pheanstalk\Values\TubeName($this->_tube));
		} catch (\Pheanstalk\Exception\TubeNotFoundException $e) {
			// the tube doesn't exist if no job was ever put in it
			return (0);
		}
		return ($stats->currentJobsReady);
	}

	/* ********** STANDARD REQUESTS ********** */
	/**
	 * Remove a message from Beanstalkd.
	 * @param	string	$id	Job identifier.
	 * @throws	\Exception	If an error occurred.
	 */
	public function remove(string $id) : void {
		if (!$this->_enabled)
			return;
		$this->connect();
		$this->_pheanstalk->delete(new \Pheanstalk\Values\JobId($id));
	}
	/**
	 * Disable clear().
	 * @param	string	$pattern	Not used.
	 * @throws	\Temma\Exceptions\Database	Always throws an exception.
	 */
	public function clear(string $pattern) : void {
		throw new \Temma\Exceptions\Database('No clear() method on this object.', \Temma\Exceptions\Database::FUNDAMENTAL);
	}

	/* ********** RAW REQUESTS ********** */
	/**
	 * Get a message from Beanstalkd. Wait until a message is available, for 20 seconds at most.
	 * @param	string	$key			Not used, should be an empty string.
	 * @param	mixed	$defaultOrCallback	(optional) Not used.
	 * @param	mixed	$options		(optional) Number of seconds to wait for a message (0 to return immediately;
	 *						20 seconds at most, which is the default).
	 * @return	?array	An associative array with the keys "id" (job identifier, used to remove the message) and "data"
	 *			(raw message data), or null if no message was available in time (or if the datasource is disabled).
	 * @throws	\Exception	If an error occurred.
	 */
	public function read(string $key, mixed $defaultOrCallback=null, mixed $options=null) : ?array {
		if (!$this->_enabled)
			return (null);
		$this->connect();
		// watch only the configured tube
		$this->_pheanstalk->watch(new \Pheanstalk\Values\TubeName($this->_tube));
		if ($this->_tube != 'default')
			$this->_pheanstalk->ignore(new \Pheanstalk\Values\TubeName('default'));
		// fetch the message
		$timeout = is_int($options) ? max(0, min($options, self::MAX_RESERVE_TIMEOUT)) : self::MAX_RESERVE_TIMEOUT;
		try {
			$job = $this->_pheanstalk->reserveWithTimeout($timeout);
		} catch (\Pheanstalk\Exception\DeadlineSoonException $e) {
			// a job reserved by this client must be processed (or touched) soon
			return (null);
		}
		if (!$job)
			return (null);
		return ([
			'id'   => $job->getId(),
			'data' => $job->getData(),
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
	 * Add a message in Beanstalkd.
	 * @param	string	$id		Job identifier, only used if the second parameter is null (remove the message).
	 * @param	?string	$data		(optional) Message data. The message is removed if the value is not given or if it is null.
	 * @param	mixed	$options	(optional) Not used.
	 * @return	?string	Job identifier (null if the message was removed or if the datasource is disabled).
	 * @throws	\Exception	If an error occurred.
	 */
	public function write(string $id, ?string $data=null, mixed $options=null) : ?string {
		if (!$this->_enabled)
			return (null);
		if (is_null($data)) {
			$this->remove($id);
			return (null);
		}
		$this->connect();
		// add message
		$this->_pheanstalk->useTube(new \Pheanstalk\Values\TubeName($this->_tube));
		$jobId = $this->_pheanstalk->put($data);
		return ($jobId->getId());
	}
	/**
	 * Add multiple messages in Beanstalkd.
	 * @param	array	$data		List of raw messages (strings; numbers are converted to strings).
	 *					Null values are ignored (no message is removed).
	 * @param	mixed	$options	(optional) Not used.
	 * @return	int	The number of sent messages.
	 * @throws	\Temma\Exceptions\Database	If a message is not a string or a number (nothing is sent).
	 * @throws	\Exception	If an error occurred (the previous messages have been sent).
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
		// send the messages
		foreach ($messages as $message)
			$this->write('', $message, $options);
		return (count($messages));
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
	 * Get a message from Beanstalkd. Wait until a message is available, for 20 seconds at most.
	 * @param	string	$key			Not used, should be an empty string.
	 * @param	mixed	$defaultOrCallback	(optional) Not used.
	 * @param	mixed	$options		(optional) Number of seconds to wait for a message (0 to return immediately;
	 *						20 seconds at most, which is the default).
	 * @return	?array	An associative array with the keys "id" (job identifier, used to remove the message) and "data"
	 *			(JSON-decoded message data), or null if no message was available in time (or if the datasource is disabled).
	 * @throws	\Exception	If an error occurred.
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
	 * Add a message in Beanstalkd.
	 * @param	string	$id		Job identifier, only used if the second parameter is null (remove the message).
	 * @param	mixed	$data		(optional) Message data, JSON-encoded. The message is removed if the value is not given or if it is null.
	 * @param	mixed	$options	(optional) Not used.
	 * @return	?string	Job identifier (null if the message was removed or if the datasource is disabled).
	 * @throws	\Exception	If an error occurred.
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
