<?php

/**
 * ZeroMQ
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
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

	/* ********** CONSTRUCTION ********** */
	/**
	 * Factory
	 * @param	string	$dsn	Connection string.
	 * @return	\Temma\Datasources\ZeroMQ	The created object.
	 * @throws	\Exception	If the DSN is not correct.
	 */
	static public function factory(string $dsn) : \Temma\Datasources\ZeroMQ {
		TµLog::log('Temma/Base', 'DEBUG', "\\Temma\\Datasources\\oobject creation with DSN: '$dsn'.");
		// extraction of connection parameters
		$connectionType = $socketType = $host = null;
		if (preg_match("/^zmq([^:]*):\/\/([^@]+)@(.*)$/", $dsn, $matches)) {
			if ($matches[1] && $matches[1] != '-bind')
				throw new \Exception("DSN '$dsn' is not valid.");
			$connectionType = ($matches[1] == '-bind') ? 'bind' : 'connect';
			$socketType= $matches[2];
			$host = $matches[3];
		} else
			throw new \Exception("DSN '$dsn' is not valid.");
		$hosts = explode(';', $host);
		// instance creation
		$instance = new self($connectionType, $socketType, $hosts);
		return ($instance);
	}
	/**
	 * Constructor.
	 * @param	string	$connectionType	'bind' or 'connect'.
	 * @param	string	$socketType	'REQ', 'REP', 'PUSH', 'PULL', 'PUB' or 'SUB'.
	 * @param	array	$hosts		Array of IP addess + port number.
	 * @throws	\Exception	If the parameters are not correct.
	 */
	private function __construct(string $connectionType, string $socketType, array $hosts) {
		if (!in_array($connectionType, ['bind', 'connect']) ||
		    !in_array($socketType, ['REQ', 'REP', 'PUSH', 'PULL', 'PUB', 'SUB']) ||
		    !$hosts)
			throw new \Exception("Bad parameters.");
		$this->_connectionType = $connectionType;
		$this->_socketType = $socketType;
		$this->_hosts = $hosts;
		$this->_enabled = true;
	}
	/** Destructor. Close the connection. */
	public function __destruct() {
		if (!$this->_socket)
			return;
		unset($this->_socket);
		$this->_socket = null;
	}

	/* ********** CONNECTION ********** */
	/**
	 * Open the connection.
	 * @throws	\Exception	If something went wrong.
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
			if ($this->_connectionType == 'connect')
				$this->_socket->connect("tcp://$host");
			else
				$this->_socket->bind("tcp://$host");
		}
	}
	/** Reconnection. */
	public function reconnect() {
		$this->disconnect();
	}
	/** Disconnection. */
	public function disconnect() {
		unset($this->_socket);
		$this->_socket = null;
	}

	/* ********** STANDARD REQUESTS ********** */
	/**
	 * Disbled clear.
	 * @param	string	$pattern	Not used.
	 * @throws	\Temma\Exceptions\Database	Always throws an exception.
	 */
	public function clear(string $pattern) : void {
		throw new \Temma\Exceptions\Database("No clear() method on this object.", \Temma\Exceptions\Database::FUNDAMENTAL);
	}

	/* ********** RAW REQUESTS ********** */
	/**
	 * Get a message from a ZeroMQ socket.
	 * @param	string	$key			Not used, should be an empty string.
	 * @param	mixed	$defaultOrCallback	(optional) Not used.
	 * @param	mixed	$options		(optional) Not used.
	 * @return	string	The received message.
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
	 * @param	string	$id		Not usedi, should be an empty string.
	 * @param	string	$data		Message data.
	 * @param	mixed	$options	(optional) Not used.
	 * @return	mixed	Always null.
	 */
	public function write(string $id, string $data, mixed $options=null) : mixed {
		if (!$this->_enabled)
			return (null);
		$this->connect();
		$this->_socket->send($data);
		return (null);
	}
	/**
	 * Multiple write.
	 * @param	array	$data		List of string messages to send.
	 * @param	mixed	$options	(optional) Not used.
	 * @return	int	The number of set data.
	 */
	public function mWrite(array $data, mixed $options=null) : int {
		if (!$this->_enabled)
			return (0);
		$this->connect();
		$output = [];
		foreach ($data as $datum) {
			if (is_string($datum))
				$output[] = $datum;
		}
		$this->_socket->sendmulti($output);
		return (count($output));
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
	 * Get a JSON-serialized message from a ZeroMQ socket.
	 * @param	string	$key			Not used, should be an empty string.
	 * @param	mixed	$defaultOrCallback	(optional) Not used.
	 * @param	mixed	$options		(optional) Not used.
	 * @return	mixed	The JSON-decoded message.
	 */
	public function get(string $key, mixed $defaultOrCallback=null, mixed $options=null) : mixed {
		if (!$this->_enabled)
			return (null);
		$this->connect();
		$message = $this->read('');
		$message = json_decode($message, true, JSON_THROW_ON_ERROR);
		return ($message);
	}
	/**
	 * Send a JSON-serialized message to a ZeroMQ socket.
	 * @param	string	$key		Not used, should be an empty string.
	 * @param	mixed	$data		(optional) Data to send.
	 * @param	mixed	$options	(optional) Not used.
	 * @return	bool	Always true.
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
	 * Multiple set.
	 * @param	array	$data		List of data to send.
	 * @param	mixed	$timeout	(optional) Not used.
	 * @return	int	The number of set data.
	 */
	public function mSet(array $data, mixed $timeout=0) : int {
		if (!$this->_enabled)
			return (0);
		$this->connect();
		$data = array_map('json_encode', $data);
		return ($this->mwrite($data));
	}
}

