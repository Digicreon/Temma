<?php

/**
 * Cache
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2009-2019, Amaury Bouchard
 */

namespace Temma\Base;

/**
 * Cache management object.
 *
 * This object is used to read and write data from a Memcache server.
 *
 * <b>Usage</b>
 * <code>
 * // init
 * $cache = \Temma\Base\Cache::factory('memcache://localhost');
 * // alternative init
 * $cache = \Temma\Base\DataSource('memcache://localhost');
 * // add a variable to cache
 * $cache->set('variable name', $data);
 * // read a variable from cache
 * $data = $cache->get('variable name');
 * // remove variable from cache
 * $cache->set('variable name', null);
 * </code>
 *
 * Connection using Unix socket:
 * <tt>memcache:///var/run/memcached.sock:0</tt>
 */
class Cache extends Datasource {
	/** Constante : Préfixe des variables de cache contenant le "sel" de préfixe. */
	const PREFIX_SALT_PREFIX = '|_cache_salt';
	/** Constante : Numéro de port Memcached par défaut. */
	const DEFAULT_MEMCACHE_PORT = 11211;
	/** Indique si on doit utiliser le cache ou non. */
	protected $_enabled = false;
	/** Objet de connexion au serveur memcache. */
	protected $_memcache = null;
	/** Durée de mise en cache par défaut (24 heures). */
	protected $_defaultExpiration = 86400;
	/** Préfixe de regroupement des variables de cache. */
	protected $_prefix = '';

	/* ************************** CONSTRUCTION ******************** */
	/**
	 * Create a new instance of this class.
	 * @param	string	$dsn	Server connection string.
	 * @return	\Temma\Base\Cache	The created instance.
	 */
	static public function factory(string $dsn) : \Temma\Base\Datasource {
		$instance = new self($dsn);
		return ($instance);
	}
	/**
	 * Constructor. Connect to a Memcache server.
	 * @param	string	$dsn	Server connection string.
	 * @throws	\Exception	If the given DSN is wrong or if the 'memcached' extension is not loaded.
	 */
	private function __construct(string $dsn) {
		if (substr($dsn, 0, 11) !== 'memcache://') {
			throw new \Exception("Invalid cache DSN '$dsn'.");
		}
		$dsn = substr($dsn, 11);
		if (!extension_loaded('memcached') || empty($dsn)) {
			throw new \Exception("The 'memcached' PHP extension is not loaded.");
		}
		$memcache = new \Memcached();
		$memcache->setOption(\Memcached::OPT_COMPRESSION, true);
		$servers = explode(';', $dsn);
		foreach ($servers as &$server) {
			if (empty($server))
				continue;
			if (strpos($server, ':') === false)
				$server = [$server, self::DEFAULT_MEMCACHE_PORT];
			else {
				list($host, $port) = explode(':', $server);
				$server = [
					$host,
					($port ? $port : self::DEFAULT_MEMCACHE_PORT)
				];
			}
		}
		if (count($servers) == 1) {
			if ($memcache->addServer($servers[0][0], $servers[0][1])) {
				$this->_memcache = $memcache;
				$this->_enabled = true;
			}
		} else if (count($servers) > 1) {
			if ($memcache->addServers($servers)) {
				$this->_memcache = $memcache;
				$this->_enabled = true;
			}
		}
	}

	/* ****************** CACHE MANAGEMENT ************ */
	/**
	 * Change the default cache expiration.
	 * @param	int	$expiration	Cache expiration, in seconds. 24 hours by default.
	 * @return	\Temma\Base\Cache	The current object.
	 */
	public function setExpiration(int $expiration=86400) : \Temma\Base\Cache {
		$this->_defaultExpiration = $expiration;
		return ($this);
	}
	/**
	 * Disable the cache temporarily.
	 * @return	\Temma\Base\Cache	The current object.
	 */
	public function disable() : \Temma\Base\Cache {
		$this->_enabled = false;
		return ($this);
	}
	/**
	 * Enable the cache (is the connection to the Memcache server is still working).
	 * @return	\Temma\Base\Cache	The current object.
	 */
	public function enable() : \Temma\Base\Cache {
		$this->_enabled = true;
		return ($this);
	}
	/**
	 * Tell if the cache is active or not.
	 * @return	bool	True if the cache is active.
	 */
	public function isEnabled() : bool {
		return ($this->_enabled);
	}

	/* ************************ DATA MANAGEMENT ****************** */
	/**
	 * Set the prefix used to group data.
	 * @param	string	$prefix	(optional) Prefix string. Let it empty to disable prefixes.
	 * @return	\Temma\Base\Cache	The current object.
	 */
	public function setPrefix(string $prefix='') : \Temma\Base\Cache {
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
	/**
	 * Add data in cache.
	 * @param	string	$key	Index key.
	 * @param	mixed	$data	(optional) Data value. The data is deleted is the value is not given or if it is null.
	 * @param	int	$expire	(optional) Expiration duration, in seconds. If it is set to zero (or if it's not given),
	 *				it will be set to 24 hours. If it is set to -1 or a value greater than 2592000 (30 days),
	 *				it will be set to 30 days.
	 * @return	mixed	The old value for the given index key.
	 */
	public function set(string $key, /* mixed */ $data=null, int $expire=0) /* : mixed */ {
		$oldValue = $this->get($key);
		$key = $this->_getSaltedPrefix() . $key;
		if (is_null($data)) {
			// deletion
			if ($this->_enabled && $this->_memcache)
				$this->_memcache->delete($key, 0);
			return ($oldValue);
		}
		// add data to cache
		$expire = (!is_numeric($expire) || !$expire) ? $this->_defaultExpiration : $expire;
		$expire = ($expire == -1 || $expire > 2592000) ? 2592000 : $expire;
		if ($this->_enabled && $this->_memcache)
			$this->_memcache->set($key, $data, $expire);
		return ($oldValue);
	}
	/**
	 * Get a data from cache.
	 * @param	string		$key		Index key.
	 * @param	\Closure	$callback	(optional) Callback function called if the data is not found in cache.
	 *						Data returned by this function will be added into cache, and returned by the method.
	 * @param	int		$expire		(optional) Expiration duration, in seconds. If it is set to zero (or if it's not given),
	 *						it will be set to 24 hours. If it is set to -1 or a value greater than 2592000 (30 days),
	 *						it will be set to 30 days. This parameter is used only if the '$callback' parameter is given.
	 * @return	mixed	The data fetched from the cache (and returned by the callback if needed), or null.
	 */
	public function get(string $key, ?\Closure $callback=null, int $expire=0) /* : mixed */ {
		$origPrefix = $this->_prefix;
		$origKey = $key;
		$key = $this->_getSaltedPrefix() . $key;
		$data = null;
		if ($this->_enabled && $this->_memcache) {
			$data = $this->_memcache->get($key);
			if ($data === false && $this->_memcache->getResultCode() != \Memcached::RES_SUCCESS)
				$data = null;
		}
		if (is_null($data) && isset($callback)) {
			$data = $callback();
			$this->_prefix = $origPrefix;
			$this->set($origKey, $data, $expire);
		}
		return ($data);
	}
	/**
	 * Remove all cache variables matching a given prefix.
	 * @param	string	$prefix	Prefix string. Nothing will be removed if this parameter is empty.
	 * @return	\Temma\Base\Cache	The current object.
	 */
	public function clear(string $prefix) : \Temma\Base\Cache {
		if (empty($prefix))
			return ($this);
		$saltKey = self::PREFIX_SALT_PREFIX . "|$prefix|";
		$salt = substr(hash('md5', time() . mt_rand()), 0, 8);
		if ($this->_enabled && $this->_memcache)
			$this->_memcache->set($saltKey, $salt, 0);
		return ($this);
	}
	/**
	 * Tell if a variable is set in cache.
	 * @param	string	$key	Index key.
	 * @return	bool	True if the variable is set, false otherwise.
	 */
	public function isSet(string $key) : bool {
		if (empty($key) || !$this->_enabled || !$this->_memcache)
			return (false);
		$origPrefix = $this->_prefix;
		$origKey = $key;
		$key = $this->_getSaltedPrevix() . $key;
		if ($this->_memcache->get($key) === false && $this->_memcache->getResultCode() == \Memcached::RES_NOTFOUND)
			return (false);
		return (true);
	}

	/* ************************** PRIVATE METHODS ******************** */
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

