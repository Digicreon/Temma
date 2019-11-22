<?php

/**
 * NDatabase
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2012-2019, Amaury Bouchard
 */

namespace Temma\Base;

use \Temma\Base\Log as TµLog;

/**
 * NoSQL database management object.
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
 *     $ndb = \Temma\Base\NDatabase::factory('redis://localhost');
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
class NDatabase extends \Temma\Base\Datasource {
	/** Default Redis connection port. */
	const DEFAULT_REDIS_PORT = 6379;
	/** Number of the default Redis base. */
	const DEFAULT_REDIS_BASE = 0;
	/** Connection object. */
	protected $_ndb = null;
	/** Connection parameters. */
	protected $_params = null;

	/* ************************ CONSTRUCTION ********************** */
	/**
	 * Factory
	 * @param	string	$dsn	Connection string.
	 * @return	\Temma\Base\NDatabase	The created object.
	 * @throws	\Exception	If the DNS is not correct.
	 */
	static public function factory(string $dsn) : \Temma\Base\Datasource {
		TµLog::log('Temma/Base', 'DEBUG', "\Temma\Base\NDatabase object creation with DSN: '$dsn'.");
		// extraction of connection parameters
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
	}
	/** Destructor. Close the connection. */
	public function __destruct() {
		if ($this->_ndb)
			$this->_ndb->close();
	}

	/* ***************************** CONNEXION / DECONNEXION ************************ */
	/**
	 * Open the connection.
	 * @throws	\Exception	If something went wrong.
	 */
	protected function _connect() : void {
		if ($this->_ndb)
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
	/** Close the connection. */
	public function close() : void {
		if (isset($this->_ndb))
			$this->_ndb->close();
	}

	/* ********************** REQUESTS *********************** */
	/**
	 * Add one or many key-value pairs.
	 * @param	string|array	$key		Key, or an associative array of key-value pairs.
	 * @param	mixed		$value		(optional) Value associated to the key.
	 * @param	int		$timeout	(optional) Key expiration timeout. 0 by default, to set no expiration.
	 * @param	bool		$createOnly	(optional) True to add the key only if it doesn't exist yet. False by default.
	 * @return	\Temma\Base\NDatabase	L'objet courant.
	 * @throws	\Exception	If something went wrong.
	 */
	public function set(/* mixed */ $key, /* mixed */ $value=null, int $timeout=0, bool $createOnly=false) : \Temma\Base\NDatabase {
		$this->_connect();
		if (!is_array($key)) {
			$value = json_encode($value);
			if ($createOnly)
				$this->_ndb->setnx($key, $value);
			else if (isset($timeout) && is_numeric($timeout) && $timeout > 0)
				$this->_ndb->setex($key, $timeout, $value);
			else
				$this->_ndb->set($key, $value);
		} else {
			$key = array_map('json_encode', $key);
			if ($createOnly)
				$this->_ndb->msetnx($key);
			else
				$this->_ndb->mset($key);
		}
		return ($this);
	}
	/**
	 * Fetch one or many key-value pairs.
	 * @param	string|array	$key		Key or list of keys.
	 * @param	\Closure	$callback	(optional) Function called if the data is not found, only if the key is unique.
	 *						The data returned by this function will be added to the database, and returned by the method.
	 * @return	mixed	The value associated to the key, or an associative array with key-value pairs. If a value doesn't exists, it is null.
	 */
	public function get(/* mixed */ $key, ?\Closure $callback=null) /* : mixed */ {
		$this->_connect();
		if (is_array($key)) {
			$values = $this->_ndb->mget($key);
			$result = array_combine($key, $values);
			foreach ($result as $k => &$v)
				$v = ($v === false) ? null : json_decode($v, true);
			return ($result);
		}
		$value = $this->_ndb->get($key);
		if ($value === false && isset($callback)) {
			$value = $callback();
			$this->set($key, $value);
			return ($value);
		}
		return (($value === false) ? null : json_decode($value, true));
	}
	/**
	 * Return a list of keys that match a pattern.
	 * @param	string	$pattern	The pattern to match. Use the asterisk as wildcard.
	 * @param	bool	$getValues	(optional) True to fetch the associated values. False by default.
	 * @return	array	List of keys, or associative array of key-value pairs.
	 */
	public function search(string $pattern, bool $getValues=false) : array {
		$this->_connect();
		$keys = $this->_ndb->keys($pattern);
		if (!$getValues)
			return ($keys);
		$values = $this->get($keys);
		return ($values);
	}
	/**
	 * Remove one or many key-value pairs.
	 * @param	string|array	$key	Key or a list of keys.
	 * @return	\Temma\Base\NDatabase	The current object.
	 */
	public function remove(/* mixed */ $key) : \Temma\Base\NDatabase {
		$this->_connect();
		$this->_ndb->delete($key);
		return ($this);
	}
}

