<?php

/**
 * Beanstalk
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 */

namespace Temma\Datasources;

use \Temma\Base\Log as TµLog;

/**
 * Beanstalkd management object.
 *
 * This object is used to read and write messages on a Beanstalkd server.
 *
 * To use it, you must install the "Pheanstalk" package using Composer:
 * <tt>composer require pda/pheanstalk</tt>
 *
 * <b>Usage</b>
 * <code>
 * // initialization
 * $bean = \Temma\Datasources\Beanstalk::factory('beanstalk://HOST:PORT/TUBE_NAME');
 * $bean = \Temma\Base\Datasource::factory('beanstalk://HOST:PORT/TUBE_NAME');
 *
 * // send message to the queue
 * $bean[] = $data;
 * $bean[''] = $data;
 * $bean->set('', $data);
 *
 * // read a message
 * $message = $bean[''];
 * $message = $bean->get('');
 * // $message is an associative array with the keys "id" and "data"
 *
 * // remove a message from queue
 * unset($bean['MESSAGE_ID']);
 * $bean['MESSAGE_ID'] = null;
 * $bean->set('MESSAGE_ID', null);
 * $bean->remove('MESSAGE_ID');
 * </code>
 */
class Beanstalk extends \Temma\Base\Datasource {
	/** Pheanstalk object. */
	private $_pheanstalk = null;
	/** Host. */
	private string $_host;
	/** Port. */
	private int $_port = 11300;
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
		TµLog::log('Temma/Base', 'DEBUG', "\\Temma\\Datasources\\Beanstalk object creation with DSN: '$dsn'.");
		if (!preg_match('/^beanstalk:\/\/([^\/:]+):?([^\/]+)?\/(.*)$/', $dsn, $matches)) {
			TµLog::log('Temma/Base', 'WARN', "Invalid Beanstalk DSN '$dsn'.");
			throw new \Temma\Exceptions\Database("Invalid Beanstalk DSN '$dsn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		$host = $matches[1] ?? null;
		$port = $matches[2] ?? null;
		$tube = $matches[3] ?? '';
		if (!$host || !$tube || ($port && !ctype_digit($port)))
			throw new \Temma\Exceptions\Database("Invalid Beanstalk DSN '$dsn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		return (new self($host, $port, $tube));
	}
	/**
	 * Constructor.
	 * @param	string	$host	Pheanstalkd host name.
	 * @param	int	$port	Port number.
	 * @param	string	$tube	Tube name.
	 */
	private function __construct(string $host, int $port, string $tube) {
		$this->_host = $host;
		$this->_port = $port;
		$this->_tube = $tube;
		$this->_enabled = true;
	}

	/* ********** CONNECTION ********** */
	/**
	 * Creation of the Pheanstalk client object.
	 * @throws      \Exception      If an error occured.
	 */
	private function _connect() {
		if (!$this->_enabled || $this->_pheanstalk)
			return;
		$this->_pheanstalk = \Pheanstalk\Pheanstalk::create($this->_host, $this->_port);
	}

	/* ********** SPECIAL REQUESTS ********** */
	/**
	 * Tell Beanstalkd that the given job is still processing,
	 * avoiding to reschedule it.
	 * @param	string	$id	Job identifier.
	 * @return	\Temma\Datasources\Beanstalk	The current object.
	 */
	public function touch(string $id) : \Temma\Datasources\Beanstalk {
		if (!$this->_enabled)
			return ($this);
		$this->_connect();
		$job = new \Pheanstalk\Values\Job(new \Pheanstalk\Values\JobId($id), '');
		$this->_pheanstalk->touch($job);
		return ($this);
	}

	/* ********** STANDARD REQUESTS ********** */
	/**
	 * Remove a message from SQS.
	 * @param	string	$id	Job identifier.
	 */
	public function remove(string $id) : void {
		if (!$this->_enabled)
			return;
		$this->_connect();
		$job = new \Pheanstalk\Values\Job(new \Pheanstalk\Values\JobId($id), '');
		$this->_pheanstalk->delete($job);
	}
	/**
	 * Disable clear().
	 * @param	string	$pattern	Not used.
	 * @throws	\Temma\Exceptions\Database	Always throws an exception.
	 */
	public function clear(string $pattern) : void {
		throw new \Temma\Exceptions\Database("No clear() method on this object.", \Temma\Exceptions\Database::FUNDAMENTAL);
	}

	/* ********** RAW REQUESTS ********** */
	/**
	 * Get a message from Beanstalkd.
	 * @param	string	$key			Not used, should be an empty string.
	 * @param       mixed   $defaultOrCallback      (optional) Not used.
	 * @param	mixed	$options		(optional) Not used.
	 * @return	?array	An associative array with the keys "id" (Pheanstalk job object)	and "data" (raw message data).
	 * @throws	\Exception	If an error occured.
	 */
	public function read(string $key, mixed $defaultOrCallback=null, mixed $options=null) : ?array {
		$this->_connect();
		if (!$this->_enabled)
			return (null);
		// fetch the message
		$job = $this->_pheanstalk->watch($this->_tube)->reserve();
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
		throw new \Temma\Exceptions\Database("No mRead() method on this object.", \Temma\Exceptions\Database::FUNDAMENTAL);
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
	 * @param	string	$id		Message identifier, only used if the second parameter is null (remove the message).
	 * @param	string	$data		(optional) Message data.
	 * @param	mixed	$options	(optional) Not used.
	 * @return	?string	Job identifier.
	 * @throws	\Exception	If an error occured.
	 */
	public function write(string $id, string $data=null, mixed $options=null) : ?string {
		if (!$this->_enabled)
			return (null);
		$this->_connect();
		// add message
		$job = $this->_pheanstalk->useTube($this->_tube)->put($data);
		return ($job->getId());
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
	 * @return	?string	Job identifier.
	 * @throws	\Exception	If an error occured.
	 */
	public function set(string $id, mixed $data=null, mixed $options=null) : ?string {
		if (!$this->_enabled)
			return (null);
		return ($this->write($id, json_encode($data), $options));
	}
}

