<?php

/**
 * Memcache
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2009-2024, Amaury Bouchard
 * @link	https://www.temma.net/documentation/datasource-memcache
 */

namespace Temma\Datasources;

use \Temma\Base\Log as TµLog;

/**
 * Cache management object.
 *
 * This object is used to read and write data from a Memcache server.
 *
 * <b>Usage</b>
 * <code>
 * // init
 * $cache = \Temma\Datasources\Memcache::factory('memcache://localhost');
 * // alternative init
 * $cache = \Temma\Base\DataSource::factory('memcache://localhost');
 *
 * // add a variable to cache
 * $cache['variable name'] = $data;
 * $cache->set('variable name', $data);
 *
 * // read a variable from cache
 * $data = $cache['variable name'];
 * $data = $cache->get('variable name');
 *
 * // remove variable from cache
 * unset($cache['variable name']);
 * $cache->set('variable name', null);
 * </code>
 *
 * Connection using Unix socket:
 * <tt>memcache:///var/run/memcached.sock:0</tt>
 */
class Memcache extends \Temma\Base\Datasource implements \ArrayAccess {
	/** Constant : Prefix of the cache variables which contains the prefix salt. */
	const PREFIX_SALT_PREFIX = '|_cache_salt';
	/** Constant : Default Memcache port number. */
	const DEFAULT_MEMCACHE_PORT = 11211;
	/** Server connection configuration list. */
	protected array $_servers;
	/** Connection object to the Memcache server. */
	protected ?\Memcached $_memcache = null;
	/** Default cache duration (24 hours). */
	protected int $_defaultExpiration = 86400;
	/** Cache variable grouping prefix. */
	protected string $_prefix = '';

	/* ********** CONSTRUCTION ********** */
	/**
	 * Create a new instance of this class.
	 * @param	string	$dsn	Server connection string.
	 * @return	\Temma\Datasources\Memcache	The created instance.
	 * @throws	\Temma\Exceptions\Database	If the DSN is not valid or if the 'memcached' extension is not loaded.
	 */
	static public function factory(string $dsn) : \Temma\Datasources\Memcache {
		if (!str_starts_with($dsn, 'memcache://') ||
		    !($servers = mb_substr($dsn, mb_strlen('memcache://')))) {
			throw new \Temma\Exceptions\Database("The DSN '$dsn' is not valid.", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		$servers = explode(';', $servers);
		$instance = new self($servers);
		return ($instance);
	}
	/**
	 * Constructor. Connect to a Memcache server.
	 * @param	array	$servers	List of server configuration data.
	 * @throws	\Temma\Exceptions\Database	If the given server configuration is wrong or if the 'memcached' extension is not loaded.
	 */
	public function __construct(array $servers) {
		if (!extension_loaded('memcached')) {
			throw new \Temma\Exceptions\Database("The 'memcached' PHP extension is not loaded.", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		foreach ($servers as &$server) {
			if (!$server || is_array($server))
				continue;
			// check port number
			if (str_contains($server, ':'))
				$server = explode(':', $server);
			else
				$server = [$server, self::DEFAULT_MEMCACHE_PORT];
			// check Unix socket
			if (str_contains($server[0], DIRECTORY_SEPARATOR))
				$server[1] = 0;
		}
		$this->_servers = $servers;
	}

	/* ********** CONNECTION ********** */
	/**
	 * Connection.
	 * @throws	\Temma\Exceptions\Database	If the connection failed.
	 */
	public function connect() {
		if (!$this->_enabled || $this->_memcache)
			return;
		$this->reconnect();
	}
	/**
	 * Re-connection.
	 * @throws	\Temma\Exceptions\Database	If the connection failed.
	 */
	public function reconnect() {
		if (!$this->_enabled)
			return;
		unset($this->_memcache);
		$this->_memcache = new \Memcached();
		$this->_memcache->setOption(\Memcached::OPT_COMPRESSION, true);
		if ((count($this->_servers) == 1 && !$this->_memcache->addServer($this->_servers[0][0], $this->_servers[0][1])) ||
		    (count($this->_servers) > 1 && !$this->_memcache->addServers($this->_servers))) {
			$this->_memcache = null;
			$this->_enabled = false;
			throw new \Temma\Exceptions\Database("Unable to connect to Memcache server.", \Temma\Exceptions\Database::CONNECTION);
		}
	}
	/** Disconnection. */
	public function disconnect() {
		unset($this->_memcache);
		$this->_memcache = null;
	}

	/* ********** CACHE MANAGEMENT ********** */
	/**
	 * Change the default cache expiration.
	 * @param	int	$expiration	Cache expiration, in seconds. 24 hours by default.
	 * @return	\Temma\Datasources\Memcache	The current object.
	 */
	public function setExpiration(int $expiration=86400) : \Temma\Datasources\Memcache {
		$this->_defaultExpiration = $expiration;
		return ($this);
	}
	/**
	 * Returns the current cache expiration delay.
	 * @return	int	The current expiration delay.
	 */
	public function getExpiration() : int {
		return ($this->_defaultExpiration);
	}

	/* ********** DATA MANAGEMENT ********** */
	/**
	 * Set the prefix used to group data.
	 * @param	string	$prefix	(optional) Prefix string. Let it empty to disable prefixes.
	 * @return	\Temma\Datasources\Memcache	The current object.
	 */
	public function setPrefix(string $prefix='') : \Temma\Datasources\Memcache {
		$this->_prefix = (empty($prefix) || !is_string($prefix)) ? '' : "|$prefix|";
		return ($this);
	}
	/**
	 * Returns the currently used prefix.
	 * @return	string	The current prefix string.
	 */
	public function getPrefix() : string {
		if (empty($this->_prefix))
			return ('');
		$res = trim($this->_prefix, "|");
		return ($res);
	}

	/* ********** ARRAY-LIKE REQUESTS ********** */
	/**
	 * Return the number of keys.
	 * @return	int	The number of keys.
	 */
	public function count() : int {
		if (!$this->_enabled)
			return (0);
		$this->connect();
		$stats = $this->_memcache->getStats();
		$nbr = 0;
		foreach ($stats as $stat)
			$nbr += $stat['curr_items'] ?? 0;
		return ($nbr);
	}

	/* ********** STANDARD REQUESTS ********** */
	/**
	 * Tell if a variable is set in cache.
	 * @param	string	$key	Index key.
	 * @return	bool	True if the variable is set, false otherwise.
	 */
	public function isSet(string $key) : bool {
		if (empty($key) || !$this->_enabled)
			return (false);
		$this->connect();
		$origPrefix = $this->_prefix;
		$origKey = $key;
		$key = $this->_getSaltedPrefix() . $key;
		if ($this->_memcache->get($key) === false && $this->_memcache->getResultCode() == \Memcached::RES_NOTFOUND)
			return (false);
		return (true);
	}
	/**
	 * Remove a cache variable.
	 * @param	string	$key	Key to remove.
	 */
	public function remove(string $key) : void {
		if (!$this->_enabled)
			return;
		$this->connect();
		$key = $this->_getSaltedPrefix() . $key;
		$this->_memcache?->delete($key, 0);
	}
	/**
	 * Remove many cache variables at once.
	 * @param	array	$keys	List of keys.
	 */
	public function mRemove(array $keys) : void {
		if (!$this->_enabled)
			return;
		$this->connect();
		array_walk($keys, function(&$value, $key) {
			$value = $this->_getSaltedPrefix() . $key;
		});
		$this->_memcache->deleteMulti($keys);
	}
	/**
	 * Remove all cache variables matching a given prefix.
	 * @param	string	$prefix	Prefix string. Nothing will be removed if this parameter is empty.
	 */
	public function clear(string $prefix) : void {
		if (!$this->_enabled || empty($prefix))
			return;
		$this->connect();
		$saltKey = self::PREFIX_SALT_PREFIX . "|$prefix|";
		$salt = substr(hash('md5', time() . mt_rand()), 0, 8);
		$this->_memcache->set($saltKey, $salt, 0);
	}
	/**
	 * Flush all cache variables.
	 */
	public function flush() : void {
		if (!$this->_enabled)
			return;
		$this->connect();
		$this->_memcache->flush();
	}

	/* ********** RAW REQUESTS ********** */
	/**
	 * Read a data from cache.
	 * @param	string	$key			Index key.
	 * @param       mixed   $defaultOrCallback      (optional) Default scalar value or function called if the data is not found.
	 *						If scalar: the value is written in the local file.
	 *						If callback: the value returned by the function is stored in the data source, and written in the file.
	 * @param	mixed	$expire			(optional) Expiration duration, in seconds. If it is set to zero (or if it's not given),
	 *						it will be set to 24 hours. If it is set to -1 or a value greater than 2592000 (30 days),
	 *						it will be set to 30 days. This parameter is used only if the '$callback' parameter is given.
	 * @return	?string	The data fetched from the cache (and returned by the callback if needed), or null.
	 * @throws	\Temma\Exceptions\Database	If the data is not a string.
	 */
	public function read(string $key, mixed $defaultOrCallback=null, mixed $expire=0) : ?string {
		$data = $this->get($key, $defaultOrCallback, $expire);
		if (is_string($data) || $data === null)
			return ($data);
		throw new \Temma\Exceptions\Database("Data '$key' is not of type string.", \Temma\Exceptions\Database::TYPE);
	}
	/*
	 * Multiple read.
	 * @param	array	$keys	List of keys.
	 * @return	array	Associative array with the keys and their associated values.
	 */
	public function mRead(array $keys) : array {
		return ($this->mGet($keys));
	}
	/**
	 * Add data in cache.
	 * @param	string	$key		Index key.
	 * @param	string	$value		(optional) Data value. The data is deleted if the value is not given or if it is null.
	 * @param	mixed	$options	(optional) Expiration duration, in seconds. If it is set to zero (or if it's not given),
	 *					it will be set to 24 hours. If it is set to -1 or a value greater than 2592000 (30 days),
	 *					it will be set to 30 days.
	 * @return	bool	Always true.
	 */
	public function write(string $key, string $value=null, mixed $options=0) : bool {
		return ($this->set($key, $value, $options));
	}
	/**
	 * Multiple write.
	 * @param	array	$data	Associative array with keys and their associated values.
	 * @param	mixed	$expire	(optional) Expiration duration, in seconds. If it is set to zero (or if it's not given),
	 *				it will be set to 24 hours. If it is set to -1 or a value greater than 2592000 (30 days),
	 *				it will be set to 30 days.
	 * @return	int	The number of written data.
	 */
	public function mWrite(array $data, mixed $expire=0) : int {
		return ($this->mSet($data, $expire));
	}

	/* ********** KEY-VALUE REQUESTS ********** */
	/**
	 * Search method.
	 * @param	string	$pattern	Not used.
	 * @param	bool	$getValues	(optional) Not used.
	 * @return	array	List of keys, or associative array of key-value pairs. Values are JSON-decoded.
	 * @throws	\Temma\Exceptions\Database	Always throws an exception.
	 */
	public function search(string $pattern, bool $getValues=false) : array {
		throw new \Temma\Exceptions\Database("No search() method on this object.", \Temma\Exceptions\Database::FUNDAMENTAL);
	}
	/**
	 * Get a data from cache.
	 * @param	string	$key			Index key.
	 * @param       mixed   $defaultOrCallback      (optional) Default scalar value or function called if the data is not found.
	 *						If scalar: the value is written in the local file.
	 *						If callback: the value returned by the function is stored in the data source, and written in the file.
	 * @param	mixed	$expire			(optional) Expiration duration, in seconds. If it is set to zero (or if it's not given),
	 *						it will be set to 24 hours. If it is set to -1 or a value greater than 2592000 (30 days),
	 *						it will be set to 30 days. This parameter is used only if the '$callback' parameter is given.
	 * @return	mixed	The data fetched from the cache (and returned by the callback if needed), or null.
	 */
	public function get(string $key, mixed $defaultOrCallback=null, mixed $expire=0) : mixed {
		if ($this->_enabled)
			$this->connect();
		$origPrefix = $this->_prefix;
		$origKey = $key;
		$key = $this->_getSaltedPrefix() . $key;
		$data = null;
		if ($this->_enabled && $this->_memcache) {
			$data = $this->_memcache->get($key);
			if ($data === false && $this->_memcache->getResultCode() != \Memcached::RES_SUCCESS)
				$data = null;
		}
		if ($data)
			return ($data);
		$value = $defaultOrCallback;
		if (is_callable($defaultOrCallback)) {
			$value = $defaultOrCallback();
			$this->_prefix = $origPrefix;
			$this->set($origKey, $value, $expire);
		}
		return ($value);
	}
	/*
	 * Multiple get.
	 * @param	array	$keys	List of keys.
	 * @return	array	Associative array with the keys and their associated values.
	 */
	public function mGet(array $keys) : array {
		if (!$this->_enabled)
			return ([]);
		$this->connect();
		array_walk($keys, function(&$value, $key) {
			$value = $this->_getSaltedPrefix() . $value;
		});
		$data = $this->_memcache->getMulti($keys);
		if ($data === false && $this->_memcache->getResultCode() != \Memcached::RES_SUCCESS)
			$data = [];
		return ($data);
	}
	/**
	 * Add data in cache.
	 * @param	string	$key	Index key.
	 * @param	mixed	$data	(optional) Data value. The data is deleted if the value is not given or if it is null.
	 * @param	mixed	$expire	(optional) Expiration duration, in seconds. If it is set to zero (or if it's not given),
	 *				it will be set to 24 hours. If it is set to -1 or a value greater than 2592000 (30 days),
	 *				it will be set to 30 days.
	 * @return	bool	Always true.
	 */
	public function set(string $key, mixed $data=null, mixed $expire=0) : bool {
		if (!$this->_enabled)
			return (false);
		$this->connect();
		if (is_null($data)) {
			// deletion
			$this->remove($key);
			return (true);
		}
		// add data to cache
		$key = $this->_getSaltedPrefix() . $key;
		$expire = (!is_numeric($expire) || !$expire) ? $this->_defaultExpiration : $expire;
		$expire = ($expire == -1 || $expire > 2592000) ? 2592000 : $expire;
		$this->_memcache->set($key, $data, $expire);
		return (true);
	}
	/**
	 * Multiple set.
	 * @param	array	$data	Associative array with keys and their associated values.
	 * @param	mixed	$expire	(optional) Options (like expiration duration).
	 * @return	int	The number of set data.
	 */
	public function mSet(array $data, mixed $expire=0) : int {
		if (!$this->_enabled)
			return (0);
		$this->connect();
		$values = [];
		foreach ($data as $key => $value) {
			$key = $this->_getSaltedPrefix() . $key;
			$values[$key] = $value;
		}
		if ($this->_memcache->setMulti($values, $expire))
			return (count($values));
		return (0);
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Returns a salted prefix.
	 * @return	string	The generated salted prefix.
	 */
	protected function _getSaltedPrefix() : string {
		// prefix management
		if (empty($this->_prefix))
			return ('');
		// salt fetching
		$saltKey = self::PREFIX_SALT_PREFIX . $this->_prefix;
		if ($this->_enabled && $this->_memcache)
			$salt = $this->_memcache->get($saltKey);
		// salt generation if needed
		if (!isset($salt) || !is_string($salt)) {
			$salt = substr(hash('md5', time() . mt_rand()), 0, 8);
			if ($this->_enabled && $this->_memcache)
				$this->_memcache->set($saltKey, $salt, 0);
		}
		return ("[$salt" . $this->_prefix);
	}
}

