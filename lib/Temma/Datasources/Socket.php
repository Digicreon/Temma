<?php

/**
 * Socket
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 * @link	https://www.temma.net/documentation/datasource-socket
 */

namespace Temma\Datasources;

use \Temma\Base\Log as TµLog;

/**
 * Socket connection management object.
 *
 * This object is used to read and write messages over an IP connection.
 *
 * <b>Usage</b>
 * <code>
 * // initialization
 * $sock = \Temma\Datasources\Socket::factory('socket://HOST:PORT');
 * $sock = \Temma\Base\Datasource::factory('socket://HOST:PORT');
 *
 * // send data to the server
 * $sock[''] = $data;
 * $sock->write('', $data);
 *
 * // read a message
 * $line = $sock[''];
 * $message = $bean->read('');
 *
 * // close the connection
 * unset($sock['']);
 * $bean->remove('');
 * </code>
 */
class Socket extends \Temma\Base\Datasource {
	/** Constant: Socket prefixes. */
	const SOCKET_TYPES = ['tcp', 'udp', 'ssl', 'sslv2', 'sslv3', 'tls', 'unix'];
	/** Socket type. */
	private string $_type;
	/** Host. */
	private string $_host;
	/** Port. */
	private ?int $_port;
	/** Connection timeout. */
	private ?int $_connectionTimeout;
	/** Socket. */
	private $_sock = null;

	/* ********** CONSTRUCTION ********** */
	/**
	 * Create a new instance of this class.
	 * @param	string	$dsn	Connection string.
	 * @return	\Temma\Datasources\Socket	The created instance.
	 * @throws	\Temma\Exceptions\Database	If the DSN is invalid.
	 */
	static public function factory(string $dsn) : \Temma\Datasources\Socket {
		TµLog::log('Temma/Base', 'DEBUG', "\\Temma\\Datasources\\Socket object creation with DSN: '$dsn'.");
		$type = null;
		$host = null;
		$port = null;
		$timeout = null;
		if (str_starts_with($dsn, 'unix://')) {
			$type = 'unix';
			$host = mb_substr($dsn, mb_strlen('unix://'));
		} else if (preg_match('/^([^:]+):\/\/([^\/:]+):?(\d*)#?(\d*)$/', $dsn, $matches)) {
			$type = $matches[1];
			$host = $matches[2];
			$port = isset($matches[3]) ? intval($matches[3]) : null;
			$timeout = isset($matches[4]) ? intval($matches[4]) : null;
		}
		return (new self($type, $host, $port, $timeout));
	}
	/**
	 * Constructor.
	 * @param	string	$type		Socket type.
	 * @param	string	$host		Host name or IP address.
	 * @param	?int	$port		Port number.
	 * @param	?int	$timeout	Connection timeout.
	 * @throws	\Temma\Exceptions\Database	If the socket type is invalid.
	 */
	public function __construct(string $type, string $host, ?int $port, ?int $timeout) {
		if (!in_array($type, self::SOCKET_TYPES)) {
			throw new \Temma\Exceptions\Database("Invalid socket type '$type'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		$this->_type = $type;
		$this->_host = $host;
		$this->_port = $port ?? -1;
		$this->_connectionTimeout = $timeout;
	}

	/* ********** CONNECTION ********** */
	/**
	 * Creation of the client socket.
	 * @throws      \Exception      If an error occured.
	 */
	public function connect() : void {
		if (!$this->_enabled || $this->_sock)
			return;
		$this->reconnect();
	}
	/** Reconnect. */
	public function reconnect() : void {
		if (!$this->_enabled)
			return;
		$this->disconnect();
		$host = $this->_type . '://' . $this->_host;
		$null = null;
		$this->_sock = fsockopen($host, $this->_port, $null, $null, $this->_connectionTimeout);
		if (!$this->_sock)
			throw new \Exception("Unable to open socket.");
	}
	/** Disconnection. */
	public function disconnect() : void {
		if (!$this->_sock)
			return;
		fclose($this->_sock);
		$this->_sock = null;
	}

	/* ********** STANDARD REQUESTS ********** */
	/**
	 * Disconnect.
	 * @param	string	$key	Not used.
	 */
	public function remove(string $key) : void {
		if (!$this->_enabled)
			return;
		$this->disconnect();
	}
	/**
	 * Disabled multiple remove.
	 * @param	array	$keys	Not used.
	 * @throws	\Temma\Exceptions\Database	Always throws an exception.
	 */
	public function mRemove(array $keys) : void {
		throw new \Temma\Exceptions\Database("No mRemove() method on this object.", \Temma\Exceptions\Database::FUNDAMENTAL);
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
	 * Read data from the socket.
	 * @param	string			$key			Not used, should be an empty string.
	 * @param       mixed   		$defaultOrCallback      (optional) Not used.
	 * @param	null|bool|int|array	$options		(optional) Options. Can be a bool, an int or an array.
	 *								- bool: false for non blocking reading (defaults to true).
	 *								- int: Reading timeout in seconds.
	 *								- array: associative array with the 'blocking' and 'timeout' keys.
	 * @return	?string	The raw data, or null if the connection was closed.
	 * @throws	\Exception	If an error occured.
	 */
	public function read(string $key, mixed $defaultOrCallback=null, mixed $options=null) : ?string {
		if (!$this->_enabled)
			return (null);
		$this->connect();
		if (feof($this->_sock))
			return (null);
		// options
		$nonBlocking = false;
		if ($options === false || ($options['blocking'] ?? null) === false) {
			$nonBlocking = true;
			stream_set_blocking($this->_sock, false);
		} else
			stream_set_blocking($this->_sock, true);
		if (is_int($options))
			stream_set_timeout($this->_sock, $options);
		else if (is_int(($options['timeout'] ?? null)))
			stream_set_timeout($this->_sock, $options['timeout']);
		// wait for the first bytes
		$s = fread($this->_sock, 4096);
		// quit if it timed out
		if (stream_get_meta_data($this->_sock)['timed_out'])
			return (null);
		// quit if the connection was closed
		if (feof($this->_sock))
			return (null);
		// non blocking mode: read as long as there are more bytes
		if (!$nonBlocking) {
			stream_set_blocking($this->_sock, false);
			while (($buff = fread($this->_sock, 4096)))
				$s .= $buff;
			// back to blocking mode
			stream_set_blocking($this->_sock, true);
		}
		return ($s);
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
	 * Send data to a socket.
	 * @param	string	$id		Not used, should be an empty string.
	 * @param	string	$data		Data.
	 * @param	mixed	$options	(optional) Not used.
	 * @return	bool	Always true.
	 * @throws	\Exception	If an error occured.
	 */
	public function write(string $id, string $data, mixed $options=null) : mixed {
		if (!$this->_enabled)
			return (false);
		$this->connect();
		fwrite($this->_sock, $data);
		return (true);
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
	 * Read a line from the socket.
	 * @param	string	$key			Not used, should be an empty string.
	 * @param       mixed   $defaultOrCallback      (optional) Not used.
	 * @param	null|bool|int|array	$options		(optional) Options. Can be a bool, an int or an array.
	 *								- bool: false for non blocking reading (defaults to true).
	 *								- int: Reading timeout in seconds.
	 *								- array: associative array with the 'blocking' and 'timeout' keys.
	 * @return	?string	Next line read from the socket or null if the connection was closed.
	 *			Any CR ("\r") and LF ("\n") characters at the end of the line are removed.
	 * @throws	\Exception	If an error occured.
	 */
	public function get(string $key, mixed $defaultOrCallback=null, mixed $options=null) : ?string {
		if (!$this->_enabled)
			return (null);
		$this->connect();
		if (feof($this->_sock))
			return (null);
		// options
		if ($options === false || ($options['blocking'] ?? null) === false)
			stream_set_blocking($this->_sock, false);
		else
			stream_set_blocking($this->_sock, true);
		if (is_int($options) || is_int(($options['timeout'] ?? null)))
			stream_set_timeout($this->_sock, (is_int($options) ? $options : $options['timeout']));
		// read a line
		$s = fgets($this->_sock);
		// quit if it timed out
		if (!$s && stream_get_meta_data($this->_sock)['timed_out'])
			return (null);
		// quit if the connection was closed
		if (!$s && feof($this->_sock))
			return (null);
		// return
		$s = rtrim($s, "\r\n");
		return ($s);
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
	 * Send a line of data to the socket.
	 * If the data is not a string, it is JSON-encoded.
	 * If the data is a string ending with one or more CR ("\r") or LF ("\n"), they are removed.
	 * In any case, a CRLF sequence ("\r\n") is added at the end of the data.
	 * @param	string	$id		Not used, should be an empty string.
	 * @param	mixed	$data		(optional) Message data. If the data is not a string, it is JSON-encoded.
	 * @param	mixed	$options	(optional) Not used.
	 * @return	bool	Always true.
	 * @throws	\Exception	If an error occured.
	 */
	public function set(string $id, mixed $data=null, mixed $options=null) : bool {
		if (!$this->_enabled)
			return (false);
		if (!$data)
			return (false);
		$data = is_string($data) ? $data : json_encode($data);
		$data = rtrim($data, "\r\n") . "\r\n";
		$this->write($id, $data, $options);
		return (true);
	}
	/**
	 * Send multiple lines of data to the socket.
	 * For each line, if the data is not a string, it is JSON-encoded.
	 * If the data is a string ending with one or more CR ("\r") or LF ("\n"), they are removed.
	 * In any case, a CRLF sequence ("\r\n") is added at the end of the data.
	 * @param	array	$data		Array of data.
	 * @param	mixed	$options	(optional) Not used.
	 * @return	int	The number of writtent data.
	 */
	public function mSet(array $data, mixed $options=null) : int {
		if (!$this->_enabled)
			return (0);
		$this->connect();
		$written = 0;
		foreach ($data as $line) {
			$this->set('', $line, $options);
			$written++;
		}
		return ($written);
	}
}

