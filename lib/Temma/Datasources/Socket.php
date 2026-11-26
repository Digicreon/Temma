<?php

/**
 * Socket
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023-2026, Amaury Bouchard
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
 * $sock = \Temma\Datasources\Socket::factory('tcp://HOST:PORT');
 * $sock = \Temma\Base\Datasource::factory('tcp://HOST:PORT');
 * // TLS connection, IPv6 address, connection timeout of 5 seconds
 * $sock = \Temma\Base\Datasource::factory('tls://[fe80::1]:443#5');
 * // Unix socket
 * $sock = \Temma\Base\Datasource::factory('unix:///path/to/socket');
 *
 * // send a line to the server (JSON-encoded if it is not a string, followed by CRLF)
 * $sock[''] = $data;
 * $sock->set('', $data);
 * // send raw data
 * $sock->write('', $rawData);
 *
 * // read a line
 * $line = $sock[''];
 * $line = $sock->get('');
 * // read raw data
 * $data = $sock->read('');
 *
 * // close the connection
 * unset($sock['']);
 * $sock->remove('');
 * </code>
 *
 * DSN syntax: <tt>TYPE://HOST:PORT#TIMEOUT</tt> (TYPE is one of "tcp", "udp", "ssl", "tls", "tlsv1.2", "tlsv1.3"; HOST
 * is a host name, an IPv4 address or an IPv6 address between square brackets; the optional TIMEOUT is the connection
 * timeout in seconds), or <tt>unix:///path/to/socket</tt>.
 */
class Socket extends \Temma\Base\Datasource {
	/** Constant: Socket prefixes (PHP stream transports). */
	const SOCKET_TYPES = ['tcp', 'udp', 'ssl', 'tls', 'tlsv1.2', 'tlsv1.3', 'unix'];
	/** Socket type. */
	private string $_type;
	/** Host. */
	private string $_host;
	/** Port. */
	private ?int $_port;
	/** Connection timeout, in seconds (null to use the "default_socket_timeout" PHP setting). */
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
		$maskedDsn = self::_maskDsn($dsn);
		TµLog::log('Temma/Base', 'DEBUG', "\\Temma\\Datasources\\Socket object creation with DSN: '$maskedDsn'.");
		$port = null;
		$timeout = null;
		if (str_starts_with($dsn, 'unix://')) {
			$type = 'unix';
			$host = mb_substr($dsn, mb_strlen('unix://'));
		} else if (preg_match('/^([^:]+):\/\/(\[[0-9a-fA-F:.]+(?:%[^\]]+)?\]|[^\/:#\[\]]+)(?::(\d*))?(?:#(\d*))?$/', $dsn, $matches)) {
			// the host may be an IPv6 address between square brackets (they are kept, fsockopen() needs them)
			$type = $matches[1];
			$host = $matches[2];
			$port = (($matches[3] ?? '') !== '') ? intval($matches[3]) : null;
			$timeout = (($matches[4] ?? '') !== '') ? intval($matches[4]) : null;
		} else {
			TµLog::log('Temma/Base', 'WARN', "Invalid socket DSN '$maskedDsn'.");
			throw new \Temma\Exceptions\Database("Invalid socket DSN '$maskedDsn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		if ($host === '')
			throw new \Temma\Exceptions\Database("Invalid socket DSN '$maskedDsn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		return (new self($type, $host, $port, $timeout));
	}
	/**
	 * Constructor.
	 * @param	string	$type		Socket type.
	 * @param	string	$host		Host name, IP address (IPv6 address between square brackets) or socket path (Unix socket).
	 * @param	?int	$port		Port number (null for Unix sockets).
	 * @param	?int	$timeout	Connection timeout in seconds (null to use the "default_socket_timeout" PHP setting).
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
	 * @throws	\Temma\Exceptions\Database	If the connection failed.
	 */
	public function connect() : void {
		if (!$this->_enabled || $this->_sock)
			return;
		$this->reconnect();
	}
	/**
	 * Reconnect.
	 * @throws	\Temma\Exceptions\Database	If the connection failed.
	 */
	public function reconnect() : void {
		if (!$this->_enabled)
			return;
		$this->disconnect();
		$host = $this->_type . '://' . $this->_host;
		$errorCode = 0;
		$errorMessage = '';
		$this->_sock = @fsockopen($host, $this->_port, $errorCode, $errorMessage, $this->_connectionTimeout);
		if (!$this->_sock) {
			$this->_sock = null;
			TµLog::log('Temma/Base', 'WARN', "Unable to open socket '$host' ($errorCode: $errorMessage).");
			throw new \Temma\Exceptions\Database("Unable to open socket '$host' ($errorMessage).", \Temma\Exceptions\Database::CONNECTION);
		}
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
		throw new \Temma\Exceptions\Database('No mRemove() method on this object.', \Temma\Exceptions\Database::FUNDAMENTAL);
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
	 * Read data from the socket.
	 * @param	string			$key			Not used, should be an empty string.
	 * @param	mixed			$defaultOrCallback	(optional) Not used.
	 * @param	null|bool|int|array	$options		(optional) Options. Can be a bool, an int or an array.
	 *								- bool: false for non blocking reading (defaults to true).
	 *								- int: Reading timeout in seconds.
	 *								- array: associative array with the 'blocking' and 'timeout' keys.
	 * @return	?string	The raw data, or null if the connection was closed (and all data were read) or if it timed out.
	 *			In non-blocking mode, an empty string is returned if no data is available.
	 * @throws	\Temma\Exceptions\Database	If the connection failed.
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
		if ($s === false)
			$s = '';
		if ($s === '') {
			// quit if it timed out or if the connection was closed
			if (stream_get_meta_data($this->_sock)['timed_out'] || feof($this->_sock))
				return (null);
			return ($s);
		}
		// blocking mode: read without blocking as long as there are more bytes available
		if (!$nonBlocking) {
			stream_set_blocking($this->_sock, false);
			while ((($buff = fread($this->_sock, 4096)) !== false) && $buff !== '')
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
	 * Send data to a socket. The socket is set in blocking mode, and the method returns when all the data has been written.
	 * @param	string	$id		Not used, should be an empty string.
	 * @param	string	$data		Data.
	 * @param	mixed	$options	(optional) Not used.
	 * @return	bool	True, or false if the datasource is disabled.
	 * @throws	\Temma\Exceptions\Database	If the connection failed or if the data couldn't be written.
	 */
	public function write(string $id, string $data, mixed $options=null) : bool {
		if (!$this->_enabled)
			return (false);
		$this->connect();
		// blocking mode (the socket may have been set in non-blocking mode by a previous reading)
		stream_set_blocking($this->_sock, true);
		// write the data, as long as some bytes remain (fwrite() may write only a part of the data)
		$length = mb_strlen($data, 'ascii');
		$written = 0;
		while ($written < $length) {
			$chunk = ($written === 0) ? $data : mb_substr($data, $written, null, 'ascii');
			$result = @fwrite($this->_sock, $chunk);
			if (!$result) {
				TµLog::log('Temma/Base', 'WARN', "Unable to write on socket ($written bytes written out of $length).");
				throw new \Temma\Exceptions\Database('Unable to write on socket.', \Temma\Exceptions\Database::QUERY);
			}
			$written += $result;
		}
		return (true);
	}
	/**
	 * Send multiple raw data to the socket, one after the other.
	 * @param	array	$data		List of raw data (strings; numbers are converted to strings). Null values are ignored
	 *					(they don't close the connection).
	 * @param	mixed	$options	(optional) Not used.
	 * @return	int	The number of written data.
	 * @throws	\Temma\Exceptions\Database	If a value is not a string or a number (nothing is sent), if the connection
	 *						failed or if the data couldn't be written.
	 */
	public function mWrite(array $data, mixed $options=null) : int {
		if (!$this->_enabled)
			return (0);
		// check the data before sending anything
		$chunks = [];
		foreach ($data as $datum) {
			if (($datum = self::_toRawValue($datum)) !== null)
				$chunks[] = $datum;
		}
		// send the data
		foreach ($chunks as $chunk)
			$this->write('', $chunk, $options);
		return (count($chunks));
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
	 * Read a line from the socket.
	 * @param	string			$key			Not used, should be an empty string.
	 * @param	mixed			$defaultOrCallback	(optional) Not used.
	 * @param	null|bool|int|array	$options		(optional) Options. Can be a bool, an int or an array.
	 *								- bool: false for non blocking reading (defaults to true).
	 *								- int: Reading timeout in seconds.
	 *								- array: associative array with the 'blocking' and 'timeout' keys.
	 * @return	?string	Next line read from the socket or null if the connection was closed (and all data were read)
	 *			or if it timed out. In non-blocking mode, an empty string is returned if no line is available.
	 *			Any CR ("\r") and LF ("\n") characters at the end of the line are removed.
	 * @throws	\Temma\Exceptions\Database	If the connection failed.
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
		if ($s === false) {
			// quit if it timed out or if the connection was closed
			if (stream_get_meta_data($this->_sock)['timed_out'] || feof($this->_sock))
				return (null);
			return ('');
		}
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
		throw new \Temma\Exceptions\Database('No mGet() method on this object.', \Temma\Exceptions\Database::FUNDAMENTAL);
	}
	/**
	 * Send a line of data to the socket.
	 * If the data is not a string, it is JSON-encoded.
	 * If the data is a string ending with one or more CR ("\r") or LF ("\n"), they are removed.
	 * In any case, a CRLF sequence ("\r\n") is added at the end of the data.
	 * @param	string	$id		Not used, should be an empty string.
	 * @param	mixed	$data		(optional) Message data. If the data is not a string, it is JSON-encoded.
	 *					Nothing is sent if the data is null.
	 * @param	mixed	$options	(optional) Not used.
	 * @return	bool	True if the data was sent, false if the data is null or if the datasource is disabled.
	 * @throws	\Temma\Exceptions\Database	If the connection failed or if the data couldn't be written.
	 */
	public function set(string $id, mixed $data=null, mixed $options=null) : bool {
		if (!$this->_enabled || is_null($data))
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
	 * @param	array	$data		Array of data. Null values are not sent.
	 * @param	mixed	$options	(optional) Not used.
	 * @return	int	The number of written data.
	 * @throws	\Temma\Exceptions\Database	If the connection failed or if the data couldn't be written.
	 */
	public function mSet(array $data, mixed $options=null) : int {
		if (!$this->_enabled)
			return (0);
		$this->connect();
		$written = 0;
		foreach ($data as $line) {
			if ($this->set('', $line, $options))
				$written++;
		}
		return ($written);
	}
}
