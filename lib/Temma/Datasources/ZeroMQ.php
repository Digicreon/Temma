<?php

/**
 * ZeroMQ
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023-2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/datasource-zeromq
 */

namespace Temma\Datasources;

use \Temma\Base\Log as TµLog;

/**
 * ZeroMQ connection management object.
 *
 * Connection setting is passed using a DSN string.
 * - Connected socket: `zmq://REQ@127.0.0.1:5000`
 * - Bound socket:     `zmq-bind://REP@*:5000`
 */
class ZeroMQ extends \Temma\Base\Datasource {
	/** ZeroMQ socket. */
	protected ?\ZMQSocket $_socket = null;
	/** Connection type. */
	protected string $_connectionType;
	/** Socket type. */
	protected string $_socketType;
	/** Hosts list. */
	protected array $_hosts;
	/** List of the endpoints on which the socket is bound (with the real port numbers, if wildcards were used). */
	protected array $_boundEndpoints = [];

	/* ********** CONSTRUCTION ********** */
	/**
	 * Factory
	 * @param	string	$dsn	Connection string.
	 * @return	\Temma\Datasources\ZeroMQ	The created object.
	 * @throws	\Temma\Exceptions\Database	If the DSN is not correct.
	 */
	static public function factory(string $dsn) : \Temma\Datasources\ZeroMQ {
		$maskedDsn = self::_maskDsn($dsn);
		TµLog::log('Temma/Base', 'DEBUG', "\\Temma\\Datasources\\ZeroMQ object creation with DSN: '$maskedDsn'.");
		// extraction of connection parameters
		if (!preg_match('/^zmq([^:]*):\/\/([^@]+)@(.+)$/', $dsn, $matches) ||
		    ($matches[1] !== '' && $matches[1] != '-bind')) {
			TµLog::log('Temma/Base', 'WARN', "Invalid ZeroMQ DSN '$maskedDsn'.");
			throw new \Temma\Exceptions\Database("Invalid ZeroMQ DSN '$maskedDsn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		$connectionType = ($matches[1] == '-bind') ? 'bind' : 'connect';
		$socketType = $matches[2];
		$hosts = explode(';', $matches[3]);
		// instance creation
		$instance = new self($connectionType, $socketType, $hosts);
		return ($instance);
	}
	/**
	 * Constructor.
	 * @param	string	$connectionType	'bind' or 'connect'.
	 * @param	string	$socketType	'REQ', 'REP', 'PUSH', 'PULL', 'PUB' or 'SUB'.
	 * @param	array	$hosts		Array of IP addess + ':' + port number.
	 * @throws	\Temma\Exceptions\Database	If the parameters are not correct.
	 */
	public function __construct(string $connectionType, string $socketType, array $hosts) {
		if (!in_array($connectionType, ['bind', 'connect']) ||
		    !in_array($socketType, ['REQ', 'REP', 'PUSH', 'PULL', 'PUB', 'SUB']) ||
		    !$hosts)
			throw new \Temma\Exceptions\Database('Bad ZeroMQ parameters.', \Temma\Exceptions\Database::FUNDAMENTAL);
		$this->_connectionType = $connectionType;
		$this->_socketType = $socketType;
		$this->_hosts = $hosts;
	}
	/** Destructor. Close the connection. */
	public function __destruct() {
		$this->disconnect();
	}

	/* ********** CONNECTION ********** */
	/**
	 * Open the connection.
	 * @throws	\ZMQSocketException	If something went wrong.
	 */
	public function connect() : void {
		if (!$this->_enabled || $this->_socket)
			return;
		$type = 0;
		if ($this->_socketType == 'REQ')
			$type = \ZMQ::SOCKET_REQ;
		else if ($this->_socketType == 'REP')
			$type = \ZMQ::SOCKET_REP;
		else if ($this->_socketType == 'PUSH')
			$type = \ZMQ::SOCKET_PUSH;
		else if ($this->_socketType == 'PULL')
			$type = \ZMQ::SOCKET_PULL;
		else if ($this->_socketType == 'PUB')
			$type = \ZMQ::SOCKET_PUB;
		else if ($this->_socketType == 'SUB')
			$type = \ZMQ::SOCKET_SUB;
		// socket creation
		$this->_socket = new \ZMQSocket(new \ZMQContext(), $type);
		// options for PUB/SUB sockets
		if ($this->_socketType == 'PUB') {
			$this->_socket->setSockOpt(\ZMQ::SOCKOPT_LINGER, 0);
			$this->_socket->setSockOpt(\ZMQ::SOCKOPT_SNDHWM, 1);
		} else if ($this->_socketType == 'SUB') {
			$this->_socket->setSockOpt(\ZMQ::SOCKOPT_SUBSCRIBE, '');
			$this->_socket->setSockOpt(\ZMQ::SOCKOPT_LINGER, 0);
		}
		// connection or binding
		foreach ($this->_hosts as $host) {
			$host = trim($host);
			if ($this->_connectionType == 'connect') {
				$this->_socket->connect("tcp://$host");
				continue;
			}
			$this->_socket->bind("tcp://$host");
			// keep the real endpoint (the port number may be a wildcard), to unbind the socket at disconnection
			$this->_boundEndpoints[] = $this->_socket->getSockOpt(\ZMQ::SOCKOPT_LAST_ENDPOINT);
		}
	}
	/**
	 * Reconnection.
	 * @throws	\ZMQSocketException	If something went wrong.
	 */
	public function reconnect() {
		$this->disconnect();
		$this->connect();
	}
	/**
	 * Disconnection. A bound socket is unbound first, so it can be bound again immediately on the same address.
	 */
	public function disconnect() {
		if (!$this->_socket)
			return;
		foreach ($this->_boundEndpoints as $endpoint) {
			try {
				$this->_socket->unbind($endpoint);
			} catch (\ZMQSocketException $e) {
				TµLog::log('Temma/Base', 'INFO', "Unable to unbind ZeroMQ socket from '$endpoint'.");
			}
		}
		$this->_boundEndpoints = [];
		unset($this->_socket);
		$this->_socket = null;
	}

	/* ********** STANDARD REQUESTS ********** */
	/**
	 * Disabled clear.
	 * @param	string	$pattern	Not used.
	 * @throws	\Temma\Exceptions\Database	Always throws an exception.
	 */
	public function clear(string $pattern) : void {
		throw new \Temma\Exceptions\Database('No clear() method on this object.', \Temma\Exceptions\Database::FUNDAMENTAL);
	}

	/* ********** RAW REQUESTS ********** */
	/**
	 * Get a message from a ZeroMQ socket.
	 * @param	string	$key			Not used, should be an empty string.
	 * @param	mixed	$defaultOrCallback	(optional) Not used.
	 * @param	mixed	$options		(optional) Not used.
	 * @return	string	The received message (empty string if the datasource is disabled).
	 * @throws	\ZMQSocketException	If something went wrong.
	 */
	public function read(string $key, mixed $defaultOrCallback=null, mixed $options=null) : string {
		if (!$this->_enabled)
			return ('');
		$this->connect();
		$msg = $this->_socket->recv();
		return ($msg);
	}
	/**
	 * Send a message over a ZeroMQ socket.
	 * @param	string	$id		Not used, should be an empty string.
	 * @param	string	$data		Message data.
	 * @param	mixed	$options	(optional) Not used.
	 * @return	mixed	Always null.
	 * @throws	\ZMQSocketException	If something went wrong.
	 */
	public function write(string $id, string $data, mixed $options=null) : mixed {
		if (!$this->_enabled)
			return (null);
		$this->connect();
		$this->_socket->send($data);
		return (null);
	}
	/**
	 * Multiple write. The strings are sent as the parts of a single multipart message.
	 * @param	array	$data		List of string messages to send. Values which are not strings are ignored.
	 * @param	mixed	$options	(optional) Not used.
	 * @return	int	The number of sent strings.
	 * @throws	\ZMQSocketException	If something went wrong.
	 */
	public function mWrite(array $data, mixed $options=null) : int {
		if (!$this->_enabled)
			return (0);
		$output = [];
		foreach ($data as $datum) {
			if (is_string($datum))
				$output[] = $datum;
		}
		if (!$output)
			return (0);
		$this->connect();
		$this->_socket->sendmulti($output);
		return (count($output));
	}

	/* ********** KEY-VALUE REQUESTS ********** */
	/**
	 * Disabled search.
	 * @param	string			$pattern	Not used.
	 * @param	bool			$getValues	(optional) Not used.
	 * @param	null|bool|string|array	$sort		(optional) Not used.
	 * @param	int			$offset		(optional) Not used.
	 * @param	int			$limit		(optional) Not used.
	 * @return	array	Never returned.
	 * @throws	\Temma\Exceptions\Database	Always throws an exception.
	 */
	public function search(string $pattern, bool $getValues=false, null|bool|string|array $sort=null, int $offset=0, int $limit=0) : array {
		throw new \Temma\Exceptions\Database('No search() method on this object.', \Temma\Exceptions\Database::FUNDAMENTAL);
	}
	/**
	 * Get a JSON-serialized message from a ZeroMQ socket.
	 * @param	string	$key			Not used, should be an empty string.
	 * @param	mixed	$defaultOrCallback	(optional) Not used.
	 * @param	mixed	$options		(optional) Not used.
	 * @return	mixed	The JSON-decoded message.
	 * @throws	\Temma\Exceptions\Database	If the message is not valid JSON.
	 * @throws	\ZMQSocketException	If something went wrong.
	 */
	public function get(string $key, mixed $defaultOrCallback=null, mixed $options=null) : mixed {
		if (!$this->_enabled)
			return (null);
		$message = $this->read('');
		try {
			return (json_decode($message, true, 512, JSON_THROW_ON_ERROR));
		} catch (\JsonException $e) {
			TµLog::log('Temma/Base', 'WARN', 'Invalid JSON message received from ZeroMQ socket.');
			throw new \Temma\Exceptions\Database('Invalid JSON message received from ZeroMQ socket.', \Temma\Exceptions\Database::TYPE);
		}
	}
	/**
	 * Send a JSON-serialized message to a ZeroMQ socket.
	 * @param	string	$key		Not used, should be an empty string.
	 * @param	mixed	$data		(optional) Data to send.
	 * @param	mixed	$options	(optional) Not used.
	 * @return	bool	True, or false if the datasource is disabled.
	 * @throws	\ZMQSocketException	If something went wrong.
	 */
	public function set(string $key, mixed $data=null, mixed $options=0) : bool {
		if (!$this->_enabled)
			return (false);
		$this->connect();
		$json = json_encode($data);
		$this->write('', $json);
		return (true);
	}
	/**
	 * Multiple set. The JSON-encoded data are sent as the parts of a single multipart message.
	 * @param	array	$data		List of data to send.
	 * @param	mixed	$timeout	(optional) Not used.
	 * @return	int	The number of sent data.
	 * @throws	\ZMQSocketException	If something went wrong.
	 */
	public function mSet(array $data, mixed $timeout=0) : int {
		if (!$this->_enabled)
			return (0);
		$data = array_map('json_encode', $data);
		return ($this->mWrite($data));
	}
}
