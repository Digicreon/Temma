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
 * // read a message (wait as long as necessary, until a message is available)
 * $message = $bean[''];
 * $message = $bean->get('');
 * // $message is an associative array with the keys "id" (job identifier) and "data"
 * // read a message, waiting up to 5 seconds (0 to return immediately, 20 seconds at most);
 * // null is returned if no message is available in time
 * $message = $bean->get('', null, 5);
 *
 * // remove a message from queue
 * unset($bean['MESSAGE_ID']);
 * $bean['MESSAGE_ID'] = null;
 * $bean->set('MESSAGE_ID', null);
 * $bean->remove('MESSAGE_ID');
 *
 * // put aside a message whose processing has failed (it is not delivered anymore)
 * $bean->bury('MESSAGE_ID');
 * // put back in the queue a buried message, or all buried messages
 * $bean->kick('MESSAGE_ID');
 * $bean->kick();
 * // put back in the queue a reserved message, to be delivered again in 60 seconds
 * $bean->release('MESSAGE_ID', 60);
 *
 * // statistics of the tube (number of ready, reserved, delayed and buried messages), or of a message
 * $stats = $bean->stats();
 * $stats = $bean->stats('MESSAGE_ID');
 * // read a message without reserving it, or the first buried message
 * $message = $bean->peek('MESSAGE_ID');
 * $message = $bean->peekBuried();
 *
 * // send several messages (null values are ignored)
 * $bean->mSet([$data1, $data2]);
 * $bean->mWrite(['raw message 1', 'raw message 2']);
 * </code>
 */
class Beanstalk extends \Temma\Base\Datasource {
	/** Constant: Default Beanstalkd port number. */
	const DEFAULT_BEANSTALK_PORT = 11300;
	/**
	 * Constant: Maximum wait duration of a single reservation request, in seconds. It is also the maximum wait
	 * duration that can be given to read() and get(); without wait duration, they send reservation requests in loop.
	 */
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
	 * Return the name of the tube used by the data source.
	 * @return	string	The tube name.
	 */
	public function getTube() : string {
		return ($this->_tube);
	}
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
	/**
	 * Bury a job: it is kept by Beanstalkd, but it is not delivered anymore until it is kicked (see kick()).
	 * Useful to put aside a job whose processing has failed, instead of removing it.
	 * The job must have been reserved (with read() or get()) by this object, without disconnection since.
	 * @param	string	$id	Job identifier.
	 * @return	\Temma\Datasources\Beanstalk	The current object.
	 * @throws	\Pheanstalk\Exception\JobNotFoundException	If the job doesn't exist or is not reserved by this object.
	 * @throws	\Exception	If an error occurred.
	 */
	public function bury(string $id) : \Temma\Datasources\Beanstalk {
		if (!$this->_enabled)
			return ($this);
		$this->connect();
		$this->_pheanstalk->bury(new \Pheanstalk\Values\JobId($id));
		return ($this);
	}
	/**
	 * Put buried jobs back in the queue: they are delivered again by read() and get().
	 * @param	?string	$id	(optional) Identifier of the job to kick; a delayed job (see release()) can also be
	 *				kicked, it is then ready without waiting for the end of its delay. If null (by default),
	 *				all buried jobs of the tube are kicked.
	 * @return	int	The number of kicked jobs (0 if the given job doesn't exist or is not buried nor delayed, or if
	 *			the datasource is disabled).
	 * @throws	\Exception	If an error occurred.
	 */
	public function kick(?string $id=null) : int {
		if (!$this->_enabled)
			return (0);
		$this->connect();
		// kick the given job
		if ($id !== null) {
			if (!self::isValidId($id))
				return (0);
			try {
				$this->_pheanstalk->kickJob(new \Pheanstalk\Values\JobId($id));
			} catch (\Pheanstalk\Exception\JobNotFoundException $e) {
				return (0);
			}
			return (1);
		}
		// kick all the buried jobs of the tube (if there is no buried job, the "kick" command would kick delayed jobs)
		try {
			$stats = $this->_pheanstalk->statsTube(new \Pheanstalk\Values\TubeName($this->_tube));
		} catch (\Pheanstalk\Exception\TubeNotFoundException $e) {
			// the tube doesn't exist if no job was ever put in it
			return (0);
		}
		if (!$stats->currentJobsBuried)
			return (0);
		$this->_pheanstalk->useTube(new \Pheanstalk\Values\TubeName($this->_tube));
		return ($this->_pheanstalk->kick($stats->currentJobsBuried));
	}
	/**
	 * Put a reserved job back in the queue, optionally with a delay before it is delivered again.
	 * Useful to process again a job whose processing has failed, after a while.
	 * The job must have been reserved (with read() or get()) by this object, without disconnection since.
	 * @param	string	$id	Job identifier.
	 * @param	int	$delay	(optional) Number of seconds to wait before the job is delivered again (0 by default).
	 * @return	\Temma\Datasources\Beanstalk	The current object.
	 * @throws	\Pheanstalk\Exception\JobNotFoundException	If the job doesn't exist or is not reserved by this object.
	 * @throws	\Exception	If an error occurred.
	 */
	public function release(string $id, int $delay=0) : \Temma\Datasources\Beanstalk {
		if (!$this->_enabled)
			return ($this);
		$this->connect();
		$this->_pheanstalk->release(new \Pheanstalk\Values\JobId($id), \Pheanstalk\Contract\PheanstalkPublisherInterface::DEFAULT_PRIORITY, max(0, $delay));
		return ($this);
	}
	/**
	 * Return statistics about the tube, or about a job.
	 * @param	?string	$id	(optional) Job identifier. If null (by default), the statistics of the tube are returned.
	 * @return	?array	For the tube, an associative array with the keys "ready", "reserved", "delayed" and "buried" (number
	 *			of jobs in each state; all zero if the tube doesn't exist). For a job, an associative array with
	 *			the keys "tube" (name of the job's tube, which may be different from the data source's tube),
	 *			"state" ("ready", "reserved", "delayed" or "buried"), "age" (number of seconds since the
	 *			job's creation), "delay" (delay given when the job was put or released), "timeLeft" (number of
	 *			seconds before a reserved job is put back in the queue, or before a delayed job is ready),
	 *			"reserves", "releases", "buries", "kicks" and "timeouts" (number of times the job was reserved,
	 *			released, buried, kicked, and put back in the queue because its reservation expired), or null if
	 *			the job doesn't exist. Null if the data source is disabled.
	 * @throws	\Exception	If an error occurred.
	 */
	public function stats(?string $id=null) : ?array {
		if (!$this->_enabled)
			return (null);
		$this->connect();
		// statistics of a job
		if ($id !== null) {
			if (!self::isValidId($id))
				return (null);
			try {
				$stats = $this->_pheanstalk->statsJob(new \Pheanstalk\Values\JobId($id));
			} catch (\Pheanstalk\Exception\JobNotFoundException $e) {
				return (null);
			}
			return ([
				'tube'     => $stats->tube->value,
				'state'    => $stats->state->value,
				'age'      => $stats->age,
				'delay'    => $stats->delay,
				'timeLeft' => $stats->timeLeft,
				'reserves' => $stats->reserves,
				'releases' => $stats->releases,
				'buries'   => $stats->buries,
				'kicks'    => $stats->kicks,
				'timeouts' => $stats->timeouts,
			]);
		}
		// statistics of the tube
		try {
			$stats = $this->_pheanstalk->statsTube(new \Pheanstalk\Values\TubeName($this->_tube));
		} catch (\Pheanstalk\Exception\TubeNotFoundException $e) {
			// the tube doesn't exist if no job was ever put in it
			return (['ready' => 0, 'reserved' => 0, 'delayed' => 0, 'buried' => 0]);
		}
		return ([
			'ready'    => $stats->currentJobsReady,
			'reserved' => $stats->currentJobsReserved,
			'delayed'  => $stats->currentJobsDelayed,
			'buried'   => $stats->currentJobsBuried,
		]);
	}
	/**
	 * Read a job without reserving it.
	 * @param	string	$id	Job identifier.
	 * @return	?array	An associative array with the keys "id" and "data" (raw job data), or null if the job doesn't
	 *			exist (or if the data source is disabled). The job may belong to another tube.
	 * @throws	\Exception	If an error occurred.
	 */
	public function peek(string $id) : ?array {
		if (!$this->_enabled || !self::isValidId($id))
			return (null);
		$this->connect();
		try {
			$job = $this->_pheanstalk->peek(new \Pheanstalk\Values\JobId($id));
		} catch (\Pheanstalk\Exception\JobNotFoundException $e) {
			return (null);
		}
		return ([
			'id'   => $job->getId(),
			'data' => $job->getData(),
		]);
	}
	/**
	 * Read the first buried job of the tube, without reserving it.
	 * @return	?array	An associative array with the keys "id" and "data" (raw job data), or null if there is no buried
	 *			job (or if the data source is disabled).
	 * @throws	\Exception	If an error occurred.
	 */
	public function peekBuried() : ?array {
		if (!$this->_enabled)
			return (null);
		$this->connect();
		$this->_pheanstalk->useTube(new \Pheanstalk\Values\TubeName($this->_tube));
		$job = $this->_pheanstalk->peekBuried();
		if (!$job)
			return (null);
		return ([
			'id'   => $job->getId(),
			'data' => $job->getData(),
		]);
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
	 * Get a message from Beanstalkd.
	 * Without wait duration, the method waits as long as necessary, until a message is available (it is returned as
	 * soon as it is available). With a wait duration, null is returned if no message is available in time.
	 * @param	string	$key			Not used, should be an empty string.
	 * @param	mixed	$defaultOrCallback	(optional) Not used.
	 * @param	mixed	$options		(optional) Number of seconds to wait for a message (0 to return immediately,
	 *						20 seconds at most). If it is not an integer (null by default), the method
	 *						waits until a message is available.
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
		// without wait duration, reservation requests are sent in loop until a message is available (a single request
		// without timeout can't be used, the connection would time out if no message is available for a long time)
		$blocking = !is_int($options);
		$timeout = $blocking ? self::MAX_RESERVE_TIMEOUT : max(0, min($options, self::MAX_RESERVE_TIMEOUT));
		do {
			try {
				$job = $this->_pheanstalk->reserveWithTimeout($timeout);
			} catch (\Pheanstalk\Exception\DeadlineSoonException $e) {
				// a job reserved by this client must be processed (or touched) soon
				if (!$blocking)
					return (null);
				// blocking mode: wait for the job to be released (or deleted) before trying again
				$job = null;
				sleep(1);
			}
		} while (!$job && $blocking);
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
	 * @param	mixed	$options	(optional) Associative array with the optional keys "priority" (0 is the most urgent,
	 *					1024 by default), "delay" (number of seconds before the message is ready, 0 by
	 *					default) and "ttr" (time to run: number of seconds a worker can keep the message
	 *					reserved before it is put back in the queue, 60 by default).
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
		$options = is_array($options) ? $options : [];
		$jobId = $this->_pheanstalk->put(
			$data,
			(int)($options['priority'] ?? \Pheanstalk\Contract\PheanstalkPublisherInterface::DEFAULT_PRIORITY),
			max(0, (int)($options['delay'] ?? \Pheanstalk\Contract\PheanstalkPublisherInterface::DEFAULT_DELAY)),
			max(1, (int)($options['ttr'] ?? \Pheanstalk\Contract\PheanstalkPublisherInterface::DEFAULT_TTR)),
		);
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
	 * Get a message from Beanstalkd.
	 * Without wait duration, the method waits as long as necessary, until a message is available (it is returned as
	 * soon as it is available). With a wait duration, null is returned if no message is available in time.
	 * @param	string	$key			Not used, should be an empty string.
	 * @param	mixed	$defaultOrCallback	(optional) Not used.
	 * @param	mixed	$options		(optional) Number of seconds to wait for a message (0 to return immediately,
	 *						20 seconds at most). If it is not an integer (null by default), the method
	 *						waits until a message is available.
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
	 * @param	mixed	$options	(optional) Options of the message (see write()).
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
	/**
	 * Add multiple messages in Beanstalkd. Each message is JSON-encoded.
	 * @param	array	$data		List of message data. Null values are ignored (no message is removed).
	 * @param	mixed	$options	(optional) Not used.
	 * @return	int	The number of sent messages (0 if the datasource is disabled).
	 * @throws	\Exception	If an error occurred (the previous messages have been sent).
	 */
	public function mSet(array $data, mixed $options=null) : int {
		return (parent::mSet($data, $options));
	}

	/* ********** TOOLS ********** */
	/**
	 * Tell if a string is a valid job identifier (positive integer, 20 digits at most).
	 * @param	string	$id	The identifier.
	 * @return	bool	True if the identifier is valid.
	 */
	static public function isValidId(string $id) : bool {
		return (ctype_digit($id) && (strlen($id) <= 20));
	}
}
