<?php

/**
 * Memcache
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2009-2026, Amaury Bouchard
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
 * Data are stored as PHP values (serialized by the Memcached extension), not JSON-encoded:
 * get() and read() return the same values, but read() throws an exception if the value is not a string.
 *
 * When the datasource is disabled, it behaves like an always-empty cache: read() and get() return the default value
 * (or call the callback and return its result), and nothing is stored.
 *
 * Connection using Unix socket:
 * <tt>memcache:///var/run/memcached.sock:0</tt>
 */
class Memcache extends \Temma\Base\Datasource implements \ArrayAccess {
	/** Constant : Prefix of the cache variables which contains the prefix salt. */
	const PREFIX_SALT_PREFIX = '__temma_salt__|';
	/** Constant : Default Memcache port number. */
	const DEFAULT_MEMCACHE_PORT = 11211;
	/** Key-value datasource: data can't be added without key ("$cache[] = $data" throws an exception). */
	protected bool $_keyValue = true;
	/** Server connection configuration list. */
	protected array $_servers;
	/** Connection object to the Memcache server. */
	protected ?\Memcached $_memcache = null;
	/** Default cache duration (24 hours). */
	protected int $_defaultExpiration = 86400;
	/** Super cache variable grouping prefix. */
	protected string $_superPrefix = '';
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
			throw new \Temma\Exceptions\Database("The DSN '" . self::_maskDsn($dsn) . "' is not valid.", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		$chunks = explode('#', $servers);
		$servers = explode(';', $chunks[0]);
		$superPrefix = $chunks[1] ?? null;
		$instance = new self($servers, $superPrefix);
		return ($instance);
	}
	/**
	 * Constructor. Connect to a Memcache server.
	 * @param	array	$servers	List of server configuration data.
	 * @param	?string	$superPrefix	(optional) Super-prefix, used to separate cache depending on virtual hosts.
	 *					Use the current hostname if empty.
	 * @throws	\Temma\Exceptions\Database	If no server is given or if the 'memcached' extension is not loaded.
	 */
	public function __construct(array $servers, ?string $superPrefix=null) {
		if (!extension_loaded('memcached')) {
			throw new \Temma\Exceptions\Database("The 'memcached' PHP extension is not loaded.", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		$superPrefix ??= $_SERVER['HTTP_HOST'] ?? '';
		$this->_superPrefix = ($superPrefix !== '') ? (hash('crc32', $superPrefix) . ']') : '';
		$this->_servers = [];
		foreach ($servers as $server) {
			if (is_array($server)) {
				$this->_servers[] = $server;
				continue;
			}
			if (!is_string($server) || $server === '')
				continue;
			// check port number
			if (str_contains($server, ':')) {
				$server = explode(':', $server);
				$server[1] = (int)$server[1];
			} else
				$server = [$server, self::DEFAULT_MEMCACHE_PORT];
			// check Unix socket
			if (str_contains($server[0], DIRECTORY_SEPARATOR))
				$server[1] = 0;
			$this->_servers[] = $server;
		}
		if (!$this->_servers)
			throw new \Temma\Exceptions\Database('No Memcache server defined.', \Temma\Exceptions\Database::FUNDAMENTAL);
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
		if (((count($this->_servers) == 1) && !$this->_memcache->addServer($this->_servers[0][0], $this->_servers[0][1])) ||
		    ((count($this->_servers) > 1) && !$this->_memcache->addServers($this->_servers))) {
			$this->_memcache = null;
			$this->_enabled = false;
			throw new \Temma\Exceptions\Database('Unable to connect to Memcache server.', \Temma\Exceptions\Database::CONNECTION);
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
		$this->_prefix = ($prefix === '') ? '' : "|$prefix|";
		return ($this);
	}
	/**
	 * Returns the currently used prefix.
	 * @return	string	The current prefix string.
	 */
	public function getPrefix() : string {
		if ($this->_prefix === '')
			return ('');
		return (mb_substr($this->_prefix, 1, -1));
	}

	/* ********** ARRAY-LIKE REQUESTS ********** */
	/**
	 * Return the number of keys stored on the servers (all prefixes included).
	 * @param	?string	$pattern	(optional) Not used.
	 * @return	int	The number of keys.
	 */
	public function count(?string $pattern=null) : int {
		if (!$this->_enabled)
			return (0);
		$this->connect();
		$stats = $this->_memcache->getStats();
		$count = 0;
		foreach ($stats as $stat)
			$count += $stat['curr_items'] ?? 0;
		return ($count);
	}

	/* ********** STANDARD REQUESTS ********** */
	/**
	 * Tell if a variable is set in cache.
	 * @param	string	$key	Index key.
	 * @return	bool	True if the variable is set, false otherwise (or if the server is not reachable).
	 */
	public function isSet(string $key) : bool {
		if ($key === '' || !$this->_enabled)
			return (false);
		$found = false;
		$this->_fetch($key, $found);
		return ($found);
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
		$this->_memcache->delete($key);
	}
	/**
	 * Remove many cache variables at once.
	 * @param	array	$keys	List of keys.
	 */
	public function mRemove(array $keys) : void {
		if (!$this->_enabled || !$keys)
			return;
		$this->connect();
		$saltedPrefix = $this->_getSaltedPrefix();
		$saltedKeys = [];
		foreach ($keys as $key)
			$saltedKeys[] = $saltedPrefix . $key;
		$this->_memcache->deleteMulti($saltedKeys);
	}
	/**
	 * Remove all cache variables of a group (stored while the given prefix was set using setPrefix()).
	 * The variables are not deleted, the group's salt is changed so they can't be reached anymore.
	 * @param	string	$prefix	Prefix string. Nothing will be removed if this parameter is empty.
	 */
	public function clear(string $prefix) : void {
		if (!$this->_enabled || $prefix === '')
			return;
		$this->connect();
		$this->_memcache->set($this->_getSaltKey("|$prefix|"), bin2hex(random_bytes(4)), 0);
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
	 * Read a string data from cache.
	 * If the datasource is disabled, the default value is returned (or the callback is called, and its value is returned).
	 * @param	string	$key			Index key.
	 * @param	mixed	$defaultOrCallback	(optional) Default scalar value or function called if the data is not found.
	 *						If scalar: the value is returned (numbers are converted to strings).
	 *						If callback: the value returned by the function (a string, a number or null)
	 *						is stored in the cache (unless it is null), and returned.
	 * @param	mixed	$expire			(optional) Expiration duration, in seconds. If it is set to zero (or if it's not given),
	 *						it will be set to the default expiration (24 hours). If it is set to -1 or a value greater
	 *						than 2592000 (30 days), it will be set to 30 days. Only used if a callback is given.
	 * @return	?string	The data fetched from the cache (or the default value), or null.
	 * @throws	\Temma\Exceptions\Database	If the stored data, the default value or the callback's returned value is not a string.
	 */
	public function read(string $key, mixed $defaultOrCallback=null, mixed $expire=0) : ?string {
		// fetch the data
		$found = false;
		$data = $this->_fetch($key, $found);
		if ($found) {
			if (!is_string($data))
				throw new \Temma\Exceptions\Database("Data '$key' is not of type string.", \Temma\Exceptions\Database::TYPE);
			return ($data);
		}
		// manage default value
		if (!self::_isCallback($defaultOrCallback))
			return (self::_toRawValue($defaultOrCallback));
		$value = self::_toRawValue($defaultOrCallback());
		if ($value !== null)
			$this->set($key, $value, $expire);
		return ($value);
	}
	/**
	 * Multiple read. Same as mGet(), the data are not converted.
	 * @param	array	$keys	List of keys.
	 * @return	array	Associative array with the keys and their associated values (null for keys not found).
	 */
	public function mRead(array $keys) : array {
		return ($this->mGet($keys));
	}
	/**
	 * Add data in cache.
	 * @param	string	$key		Index key.
	 * @param	?string	$value		(optional) Data value. The data is deleted if the value is not given or if it is null.
	 * @param	mixed	$options	(optional) Expiration duration, in seconds. If it is set to zero (or if it's not given),
	 *					it will be set to the default expiration (24 hours). If it is set to -1 or a value greater
	 *					than 2592000 (30 days), it will be set to 30 days.
	 * @return	bool	True, or false if the datasource is disabled.
	 */
	public function write(string $key, ?string $value=null, mixed $options=0) : bool {
		return ($this->set($key, $value, $options));
	}
	/**
	 * Multiple write. Same as mSet(), the data are not converted.
	 * @param	array	$data	Associative array with keys and their associated values. Keys associated to null are removed.
	 * @param	mixed	$expire	(optional) Expiration duration, in seconds. If it is set to zero (or if it's not given),
	 *				it will be set to the default expiration (24 hours). If it is set to -1 or a value greater
	 *				than 2592000 (30 days), it will be set to 30 days.
	 * @return	int	The number of written data (removed keys are not counted).
	 */
	public function mWrite(array $data, mixed $expire=0) : int {
		return ($this->mSet($data, $expire));
	}

	/* ********** KEY-VALUE REQUESTS ********** */
	/**
	 * Search method.
	 * @param	string			$pattern	Not used.
	 * @param	bool			$getValues	(optional) Not used.
	 * @param	null|bool|string|array	$sort		(optional) Not used.
	 * @param	int			$offset		(optional) Not used.
	 * @param	int			$limit		(optional) Not used.
	 * @return	array	List of keys, or associative array of key-value pairs. Values are JSON-decoded.
	 * @throws	\Temma\Exceptions\Database	Always throws an exception.
	 */
	public function search(string $pattern, bool $getValues=false, null|bool|string|array $sort=null, int $offset=0, int $limit=0) : array {
		throw new \Temma\Exceptions\Database('No search() method on this object.', \Temma\Exceptions\Database::FUNDAMENTAL);
	}
	/**
	 * Get a data from cache.
	 * If the datasource is disabled, the default value is returned (or the callback is called, and its value is returned).
	 * @param	string	$key			Index key.
	 * @param	mixed	$defaultOrCallback	(optional) Default value or function called if the data is not found.
	 *						If it is not a callback: the value is returned.
	 *						If callback: the value returned by the function is stored in the cache
	 *						(unless it is null), and returned.
	 * @param	mixed	$expire			(optional) Expiration duration, in seconds. If it is set to zero (or if it's not given),
	 *						it will be set to the default expiration (24 hours). If it is set to -1 or a value greater
	 *						than 2592000 (30 days), it will be set to 30 days. Only used if a callback is given.
	 * @return	mixed	The data fetched from the cache (or the default value), or null.
	 */
	public function get(string $key, mixed $defaultOrCallback=null, mixed $expire=0) : mixed {
		// fetch the data
		$found = false;
		$data = $this->_fetch($key, $found);
		if ($found)
			return ($data);
		// manage default value
		if (!self::_isCallback($defaultOrCallback))
			return ($defaultOrCallback);
		$value = $defaultOrCallback();
		if ($value !== null)
			$this->set($key, $value, $expire);
		return ($value);
	}
	/**
	 * Multiple get.
	 * @param	array	$keys	List of keys.
	 * @return	array	Associative array with the keys and their associated values (null for keys not found).
	 */
	public function mGet(array $keys) : array {
		if (!$this->_enabled || !$keys)
			return ([]);
		$this->connect();
		$saltedPrefix = $this->_getSaltedPrefix();
		$saltedKeys = [];
		foreach ($keys as $key)
			$saltedKeys[] = $saltedPrefix . $key;
		$data = $this->_memcache->getMulti($saltedKeys);
		if (!is_array($data))
			$data = [];
		$result = [];
		foreach ($keys as $key)
			$result[$key] = $data[$saltedPrefix . $key] ?? null;
		return ($result);
	}
	/**
	 * Add data in cache.
	 * @param	string	$key	Index key.
	 * @param	mixed	$data	(optional) Data value. The data is deleted if the value is not given or if it is null.
	 * @param	mixed	$expire	(optional) Expiration duration, in seconds. If it is set to zero (or if it's not given),
	 *				it will be set to the default expiration (24 hours). If it is set to -1 or a value greater
	 *				than 2592000 (30 days), it will be set to 30 days.
	 * @return	bool	True, or false if the datasource is disabled.
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
		$this->_memcache->set($key, $data, $this->_getExpiration($expire));
		return (true);
	}
	/**
	 * Multiple set.
	 * @param	array	$data	Associative array with keys and their associated values. Keys associated to null are removed.
	 * @param	mixed	$expire	(optional) Expiration duration, in seconds. If it is set to zero (or if it's not given),
	 *				it will be set to the default expiration (24 hours). If it is set to -1 or a value greater
	 *				than 2592000 (30 days), it will be set to 30 days.
	 * @return	int	The number of written data (removed keys are not counted).
	 */
	public function mSet(array $data, mixed $expire=0) : int {
		if (!$this->_enabled || !$data)
			return (0);
		$this->connect();
		$saltedPrefix = $this->_getSaltedPrefix();
		$values = [];
		$removed = [];
		foreach ($data as $key => $value) {
			if (is_null($value))
				$removed[] = $saltedPrefix . $key;
			else
				$values[$saltedPrefix . $key] = $value;
		}
		if ($removed)
			$this->_memcache->deleteMulti($removed);
		if (!$values || !$this->_memcache->setMulti($values, $this->_getExpiration($expire)))
			return (0);
		return (count($values));
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Fetch a data from the cache.
	 * @param	string	$key	Index key.
	 * @param	bool	$found	Set to true if the data was found (even if it is a "falsy" value), false otherwise
	 *				(not found, server not reachable or datasource disabled).
	 * @return	mixed	The fetched data, or null.
	 */
	protected function _fetch(string $key, bool &$found) : mixed {
		$found = false;
		if (!$this->_enabled)
			return (null);
		$this->connect();
		$data = $this->_memcache->get($this->_getSaltedPrefix() . $key);
		// Memcached returns false if the data was not found; the result code distinguishes it from a stored false value
		if ($this->_memcache->getResultCode() != \Memcached::RES_SUCCESS)
			return (null);
		$found = true;
		return ($data);
	}
	/**
	 * Compute the expiration duration of a data.
	 * @param	mixed	$expire	Expiration duration, in seconds. If it is not numeric or set to zero, the default expiration
	 *				is used. If it is set to -1 or a value greater than 2592000 (30 days), it is set to 30 days.
	 * @return	int	The expiration duration.
	 */
	protected function _getExpiration(mixed $expire) : int {
		$expire = is_numeric($expire) ? (int)$expire : 0;
		if (!$expire)
			$expire = $this->_defaultExpiration;
		if ($expire == -1 || $expire > 2592000)
			$expire = 2592000;
		return ($expire);
	}
	/**
	 * Returns the key of the variable which contains the salt of a group prefix.
	 * @param	string	$prefix	Group prefix, as stored in the $_prefix attribute ("|prefix|").
	 * @return	string	The key of the salt variable.
	 */
	protected function _getSaltKey(string $prefix) : string {
		return ($this->_superPrefix . self::PREFIX_SALT_PREFIX . $prefix);
	}
	/**
	 * Returns a salted prefix.
	 * @return	string	The generated salted prefix.
	 */
	protected function _getSaltedPrefix() : string {
		// prefix management
		if ($this->_prefix === '')
			return ($this->_superPrefix);
		// salt fetching
		$saltKey = $this->_getSaltKey($this->_prefix);
		if ($this->_enabled && $this->_memcache)
			$salt = $this->_memcache->get($saltKey);
		// salt generation if needed
		if (!isset($salt) || !is_string($salt)) {
			$salt = bin2hex(random_bytes(4));
			if ($this->_enabled && $this->_memcache)
				$this->_memcache->set($saltKey, $salt, 0);
		}
		return ($this->_superPrefix . $salt . $this->_prefix);
	}
}
