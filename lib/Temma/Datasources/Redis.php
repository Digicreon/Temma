<?php

/**
 * Redis
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2012-2023, Amaury Bouchard
 */

namespace Temma\Datasources;

use \Temma\Base\Log as TµLog;

/**
 * Redis database management object.
 *
 * Connection setting is passed using a DSN string:
 * <ul>
 *   <li><pre>redis://host[:port][/base]</pre></li>
 *   <li><pre>redis-sock:///path/to/unix/socket[#base]</pre></li>
 * </ul>
 * Examples:
 * <ul>
 *   <li><tt>redis://localhost/1</tt></li>
 *   <li><tt>redis://db.temma.net:6379/2</tt></li>
 *   <li><tt>redis-sock:///var/run/redis/redis-server.sock</tt></li>
 *   <li><tt>redis-sock:///var/run/redis/redis-server.sock#2</tt></li>
 * </ul>
 *
 * Full example:
 * <code>
 * try {
 *     // object creation
 *     $ndb = \Temma\Datasources\Redis::factory('redis://localhost');
 *     $ndb = \Temma\Base\Datasource::factory('redis://localhost');
 *     // insertion of a key-value pair
 *     $ndb->set('key', 'value');
 *     // insertion of many key-value pairs
 *     $ndb->set([
 *         'key1' => 'val1',
 *         'key2' => 'val2'
 *     ]);
 *     // fetch a value
 *     $result = $ndb->get('key');
 *     print($result);
 *     // remove a key
 *     $ndb->remove('key');
 *     // fetch many values
 *     $result = $ndb->get(['key1', 'key2', 'key3']);
 *     // display results
 *     foreach ($result as $key => $val)
 *         print("$key -> $val\n");
 *     // search values
 *     $result = $ndb->search('ugc:*');
 * } catch (Exception $e) {
 *     print("Database error: " . $e->getMessage());
 * }
 * </code>
 */
class Redis extends \Temma\Base\Datasource {
	/** Default Redis connection port. */
	const DEFAULT_REDIS_PORT = 6379;
	/** Number of the default Redis base. */
	const DEFAULT_REDIS_BASE = 0;
	/** Connection object. */
	protected ?\Redis $_ndb = null;
	/** Connection parameters. */
	protected ?array $_params = null;

	/* ********** CONSTRUCTION ********** */
	/**
	 * Factory
	 * @param	string	$dsn	Connection string.
	 * @return	\Temma\Datasources\Redis	The created object.
	 * @throws	\Exception	If the DSN is not correct.
	 */
	static public function factory(string $dsn) : \Temma\Datasources\Redis {
		TµLog::log('Temma/Base', 'DEBUG', "\\Temma\\Datasources\\Redis object creation with DSN: '$dsn'.");
		// extraction of connection parameters
		$type = $host = $port = null;
		if (preg_match("/^redis-sock:\/\/([^#]+)#?(.*)$/", $dsn, $matches)) {
			$type = 'redis';
			$host = $matches[1];
			$base = $matches[2];
			$port = null;
		} else if (preg_match("/^([^:]+):\/\/([^\/:]+):?(\d+)?\/?(.*)$/", $dsn, $matches)) {
			$type = $matches[1];
			$host = $matches[2];
			$port = (!empty($matches[3]) && ctype_digit($matches[3])) ? $matches[3] : self::DEFAULT_REDIS_PORT;
			$base = $matches[4];
		}
		if ($type != 'redis')
			throw new \Exception("DSN '$dsn' is not valid.");
		$base = !empty($base) ? $base : self::DEFAULT_REDIS_BASE;
		// instance creation
		$instance = new self($host, $base, $port);
		return ($instance);
	}
	/**
	 * Constructor.
	 * @param	string	$host		Hostname, or path to the Unix socket.
	 * @param	string	$base		Name of the base to connect to.
	 * @param	int	$port		(optional) Port number.
	 */
	private function __construct(string $host, string $base, ?int $port=null) {
		$this->_params = [
			'host'	=> $host,
			'base'	=> $base,
			'port'	=> $port,
		];
		$this->_enabled = true;
	}
	/** Destructor. Close the connection. */
	public function __destruct() {
		if ($this->_ndb)
			$this->_ndb->close();
	}

	/* ********** CONNECTION ********** */
	/**
	 * Open the connection.
	 * @throws	\Exception	If something went wrong.
	 */
	protected function _connect() : void {
		if (!$this->_enabled || $this->_ndb)
			return;
		try {
			$this->_ndb = new \Redis();
			$this->_ndb->connect($this->_params['host'], (isset($this->_params['port']) ? $this->_params['port'] : null));
			if ($this->_params['base'] != self::DEFAULT_REDIS_BASE && !$this->_ndb->select($this->_params['base']))
				throw new \Exception("Unable to select dabase '" . $this->_params['base'] . "'.");
		} catch (\Exception $e) {
			throw new \Exception('Redis database connexion error: ' . $e->getMessage());
		}
	}

	/* ********** STANDARD REQUESTS ********** */
	/**
	 * Tell if a variable is set in Redis.
	 * @param	string	$key	Index key.
	 * @return	bool	True if the variable is set, false otherwise.
	 */
	public function isSet(string $key) : bool {
		if (!$this->_enabled)
			return (false);
		$this->_connect();
		return ($this->_ndb->exists($key) ? true : false);
	}
	/**
	 * Remove one or many key-value pairs.
	 * @param	string	$key	Key to remove.
	 * @return	\Temma\Datasources\Redis	The current object.
	 */
	public function remove(string $key) : \Temma\Datasources\Redis {
		if (!$this->_enabled)
			return ($this);
		$this->_connect();
		$this->_ndb->delete($key);
		return ($this);
	}
	/**
	 * Multiple remove.
	 * @param	array	$keys	List of keys.
	 * @return	\Temma\Datasources\Redis	The current object.
	 */
	public function mRemove(array $keys) : \Temma\Datasources\Redis {
		if (!$this->_enabled)
			return ($this);
		$this->_connect();
		$this->_ndb->delete($keys);
		return ($this);
	}
	/**
	 * Remove keys from a pattern.
	 * @param	string	$pattern	Search pattern.
	 * @return	\Temma\Datasources\Redis	The current object.
	 */
	public function clear(string $pattern) : \Temma\Datasources\Redis {
		if (!$this->_enabled)
			return ($this);
		$this->_connect();
		$it = null;
		while (($keys = $this->_ndb->scan($it, $pattern))) {
			$this->mRemove($keys);
		}
		return ($this);
	}
	/**
	 * Remove all data from Redis.
	 * @return	\Temma\Datasources\Redis	The current object.
	 */
	public function flush() : \Temma\Datasources\Redis {
		if (!$this->_enabled)
			return ($this);
		$this->_connect();
		$this->_ndb->flushDb();
		return ($this);
	}

	/* ********** RAW REQUESTS ********** */
	/**
	 * Search keys from a pattern.
	 * @param	string	$pattern	The pattern to match.
	 * @param	bool	$getValues	(optional) True to fetch the associated values. False by default.
	 * @return	array	List of keys, or associative array of key-value pairs.
	 */
	public function find(string $pattern, bool $getValues=false) : array {
		if (!$this->_enabled)
			return ([]);
		$result = [];
		$it = null;
		while (($keys = $this->_ndb->scan($it, $pattern))) {
			if ($getValues)
				$keys = $this->mRead($keys);
			$result = array_merge($result, $keys);
		}
		return ($result);
	}
	/**
	 * Fetch a key-value pair.
	 * @param	string	$key			Key.
	 * @param	mixed	$defaultOrCallback	(optional) Default scalar value or function called if the data is not found.
	 *						If scalar: the value is written in the local file.
	 *						If callback: the value returned by the function is stored in the data source, and written in the file.
	 * @param	mixed	$options		(optional) Options used to store the data returned by the callback.
	 * @return	?string	The value associated to the key.
	 */
	public function read(string $key, mixed $defaultOrCallback=null, mixed $options=null) : ?string {
		$this->_connect();
		$value = false;
		if ($this->_enabled)
			$value = $this->_ndb->get($key);
		if ($value !== false)
			return ($value);
		if ($defaultOrCallback === null)
			return (null);
		$value = $defaultOrCallback;
		if (is_callable($defaultOrCallback)) {
			$value = $defaultOrCallback();
			$this->set($key, $value, $options);
		}
		return ($value);
	}
	/**
	 * Multiple read.
	 * @param	array	$keys	List of keys.
	 * @return	array	Associative array with the keys and their associated values.
	 */
	public function mRead(array $keys) : array {
		if (!$this->_enabled)
			return ([]);
		$this->_connect();
		$values = $this->_ndb->mget($keys);
		$result = array_combine($keys, $values);
		return ($result);
	}
	/**
	 * Add a key-value pair. If the given value is null, the key is removed.
	 * @param	string	$key		Key.
	 * @param	mixed	$value		(optional) Value associated to the key.
	 * @param	mixed	$timeout	(optional) Key expiration timeout. 0 by default, to set no expiration.
	 * @return	\Temma\Datasources\Redis	The current object.
	 * @throws	\Exception	If something went wrong.
	 */
	public function write(string|array $key, mixed $value=null, mixed $timeout=0) : \Temma\Datasources\Redis {
		if (!$this->_enabled)
			return ($this);
		$this->_connect();
		// manage options
		$timeout = (int)$timeout;
		if ($timeout > 0)
			$this->_ndb->setex($key, $timeout, $value);
		else
			$this->_ndb->set($key, $value);
		return ($this);
	}
	/**
	 * Multiple write.
	 * @param	array	$data		Associative array with keys and their associated values.
	 * @param	mixed	$timeout	(optional) Key expiration timeout. 0 by default, to set no expiration.
	 * @return	int	The number of set data.
	 */
	public function mWrite(array $data, mixed $timeout=0) : int {
		if (!$this->_enabled)
			return (0);
		if (!$this->_ndb->mset($data))
			return (0);
		// manage timeouts
		$timeout = (int)$timeout;
		if ($timeout > 0) {
			foreach ($data as $key => $value)
				$this->_ndb->expire($key, $timeout);
		}
		return (count($data));
	}

	/* ********** KEY-VALUE REQUESTS ********** */
	/**
	 * Return a list of keys that match a pattern.
	 * @param	string	$pattern	The pattern to match. Use the asterisk as wildcard.
	 * @param	bool	$getValues	(optional) True to fetch the associated values. False by default.
	 * @return	array	List of keys, or associative array of key-value pairs. Values are JSON-decoded.
	 */
	public function search(string $pattern, bool $getValues=false) : array {
		if (!$this->_enabled)
			return ([]);
		$result = [];
		$it = null;
		while (($keys = $this->_ndb->scan($it, $pattern))) {
			if ($getValues)
				$keys = $this->mRead($keys);
			$result = array_merge($result, $keys);
		}
		array_walk($result, function(&$value, $key) {
			if ($value === false)
				$value = null;
			else if (is_string($value))
				$value = json_decode($value, true);
		});
		return ($result);
	}
	/**
	 * Fetch a key-value pair.
	 * @param	string	$key			Key.
	 * @param	mixed	$defaultOrCallback	(optional) Default scalar value or function called if the data is not found.
	 *						If scalar: the value is written in the local file.
	 *						If callback: the value returned by the function is stored in the data source, and written in the file.
	 * @param	mixed	$options		(optional) Options used to store the data returned by the callback.
	 * @return	mixed	The JSON-decoded value associated to the key.
	 */
	public function get(string $key, mixed $defaultOrCallback=null, mixed $options=null) : mixed {
		$this->_connect();
		$value = false;
		if ($this->_enabled)
			$value = $this->_ndb->get($key);
		if ($value !== false)
			return (json_decode($value, true));
		if ($defaultOrCallback === null)
			return (null);
		$value = $defaultOrCallback;
		if (is_callable($defaultOrCallback)) {
			$value = $defaultOrCallback();
			$this->set($key, $value, $options);
		}
		return ($value);
	}
	/**
	 * Multiple get.
	 * @param	array	$keys	List of keys.
	 * @return	array	Associative array with the keys and their JSON-decoded associated values.
	 */
	public function mGet(array $keys) : array {
		if (!$this->_enabled)
			return ([]);
		$this->_connect();
		$values = $this->_ndb->mget($keys);
		$result = array_combine($keys, $values);
		array_walk($result, function(&$value, $key) {
			if ($value === false)
				$value = null;
			else if (is_string($value))
				$value = json_decode($value);
		});
		return ($result);
	}
	/**
	 * Add a key-value pair. If the given value is null, the key is removed.
	 * @param	string	$key		Key.
	 * @param	mixed	$value		(optional) Value associated to the key.
	 * @param	mixed	$timeout	(optional) Key expiration timeout. 0 by default, to set no expiration.
	 * @return	\Temma\Datasources\Redis	The current object.
	 * @throws	\Exception	If something went wrong.
	 */
	public function set(string|array $key, mixed $value=null, mixed $timeout=0) : \Temma\Datasources\Redis {
		if (!$this->_enabled)
			return ($this);
		$this->_connect();
		// remove key
		if (is_null($value)) {
			$this->remove($key);
			return ($this);
		}
		// manage options
		$timeout = (int)$timeout;
		// set value
		$value = json_encode($value);
		if ($timeout > 0)
			$this->_ndb->setex($key, $timeout, $value);
		else
			$this->_ndb->set($key, $value);
		return ($this);
	}
	/**
	 * Multiple set.
	 * @param	array	$data		Associative array with keys and their associated values.
	 * @param	mixed	$timeout	(optional) Key expiration timeout. 0 by default, to set no expiration.
	 * @return	int	The number of set data.
	 */
	public function mSet(array $data, mixed $timeout=0) : int {
		if (!$this->_enabled)
			return (0);
		$data = array_map('json_encode', $data);
		if (!$this->_ndb->mset($data))
			return (0);
		// manage timeouts
		$timeout = (int)$timeout;
		if ($timeout > 0) {
			foreach ($data as $key => $value)
				$this->_ndb->expire($key, $timeout);
		}
		return (count($data));
	}
}

