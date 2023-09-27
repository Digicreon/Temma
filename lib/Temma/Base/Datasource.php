<?php

/**
 * Datasource
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2012-2023, Amaury Bouchard
 */

namespace Temma\Base;

use \Temma\Base\Log as TµLog;

/**
 * Object for data sources management.
 *
 * Must be used in an autoloader-enabled environment.
 */
abstract class Datasource implements \ArrayAccess {
	/** Database prefixes. */
	const DATABASE_PREFIXES = [
		'mysqli:',
		'mysql:',
		'pgsql:',
		'cubrid:',
		'sybase:',
		'mssql:',
		'dblib:',
		'firebird:',
		'ibm:',
		'informix:',
		'sqlsrv:',
		'oci:',
		'odbc:',
		'sqlite:',
		'sqlite2:',
		'4D:',
	];
	/** Defines if the datasource must be accessed or not. */
	protected bool $_enabled = false;

	/**
	 * Factory.
	 * Create a concrete instance of the object, depending of the given parameters.
	 * @param	string	$dsn	Parameter string.
	 * @return	\Temma\Base\Datasource	The created object.
	 * @throws	\Exception	If the given DSN is not correct.
	 */
	static public function factory(string $dsn) : \Temma\Base\Datasource {
		TµLog::log('Temma/Base', 'DEBUG', "Datasource object creation with DSN: '$dsn'.");
		// manage specified data source objects
		if (preg_match('/\[([^\]]+)\](.*)$/', $dsn, $matches)) {
			$object = $matches[1];
			$dsn = $matches[2];
			if (is_a($object, '\Temma\Base\Datasource', true)) {
				TµLog::log('Temma/Web', 'DEBUG', "Load data source '$object'.");
				return ($object::factory($dsn));
			}
		}
		// SQL databases
		foreach (self::DATABASE_PREFIXES as $prefix) {
			if (str_starts_with($dsn, $prefix))
				return (\Temma\Base\Datasources\Sql::factory($dsn));
		}
		// other data sources managed by Temma
		if (str_starts_with($dsn, 'memcache://'))
			return (\Temma\Base\Datasources\Memcache::factory($dsn));
		if (str_starts_with($dsn, 'redis://') ||
		    str_starts_with($dsn, 'redis-sock://'))
			return (\Temma\Base\Datasources\Redis::factory($dsn));
		if (str_starts_with($dsn, 's3://'))
			return (\Temma\Base\Datasources\S3::factory($dsn));
		if (str_starts_with($dsn, 'dummy://'))
			return (\Temma\Base\Datasources\Dummy::factory(''));
		if (str_starts_with($dsn, 'file://'))
			return (\Temma\Base\Datasources\File::factory($dsn));
		if (str_starts_with($dsn, 'beanstalk://'))
			return (\Temma\Base\Datasources\Beanstalk::factory($dsn));
		if (str_starts_with($dsn, 'sqs://'))
			return (\Temma\Base\Datasources\Sqs::factory($dsn));
		if (str_starts_with($dsn, 'smsmode://'))
			return (\Temma\Base\Datasources\Smsmode::factory($dsn));
		if (str_starts_with($dsn, 'pushover://'))
			return (\Temma\Base\Datasources\Pushover::factory($dsn));
		if (str_starts_with($dsn, 'env://')) {
			$dsn = getenv(substr($dsn, 6));
			return (self::factory($dsn));
		}
		throw new \Exception("No valid DSN provided '$dsn'.");
	}

	/* ********** DATA ACCESS MANAGEMENT ********** */
	/**
	 * For compatibility: Tell if the datasource is enabled or not.
	 * @return	bool	True if the datasource is enabled.
	 * @see		\Temma\Base\Cache
	 */
	public function isEnabled() : bool {
		return ($this->_enabled);
	}
	/**
	 * Enable the datasource.
	 * @return	\Temma\Base\Datasource	The current object.
	 */
	public function enable() : \Temma\Base\Datasource {
		$this->_enabled = true;
		return ($this);
	}
	/**
	 * Disable the datasource temporarily.
	 * @return	\Temma\Base\Datasource	The current object.
	 */
	public function disable() : \Temma\Base\Datasource {
		$this->_enabled = false;
		return ($this);
	}

	/* ********** STANDARD REQUESTS ********** */
	/**
	 * Squeleton method for a data existence checker implemented by derived classes.
	 * @param	string	$key	Key of the data.
	 * @return	bool	True if the data exists, false otherwise.
	 * @throws	\Temma\Exceptions\Database	Always throws an exception.
	 */
	public function isSet(string $key) : bool {
		throw new \Temma\Exceptions\Database("No isSet() method on this object.", \Temma\Exceptions\Database::FUNDAMENTAL);
	}
	/**
	 * Squeleton method for a deleter method implemented by derived classes.
	 * @param	string	$key	The key to remove.
	 * @return	\Temma\Base\Datasource	The current object.
	 * @throws	\Temma\Exceptions\Database	Always throws an exception.
	 */
	public function remove(string $key) : \Temma\Base\Datasource {
		throw new \Temma\Exceptions\Database("No remove() method on this object.", \Temma\Exceptions\Database::FUNDAMENTAL);
	}
	/**
	 * Multiple remove.
	 * @param	array	$keys	List of keys to remove.
	 * @return	\Temma\Base\Datasource	The current object.
	 */
	public function mRemove(array $keys) : \Temma\Base\Datasource {
		if (!$this->_enabled)
			return ($this);
		foreach ($keys as $key)
			$this->remove($key);
		return ($this);
	}
	/**
	 * Squeleton method for a remover method implemented by derived classes.
	 * @param	string	$pattern	The pattern to match or the key prefix. The syntax depends on the datasource.
	 * @return	\Temma\Base\Datasource	The current object.
	 */
	public function clear(string $pattern) : \Temma\Base\Datasource {
		if (!$this->_enabled)
			return ($this);
		$keys = $this->search($pattern);
		$this->mRemove($keys);
		return ($this);
	}
	/**
	 * Squeleton method for a flusher method implemented by derived classes.
	 * @return	\Temma\Base\Datasource	The current object.
	 */
	public function flush() : \Temma\Base\Datasource {
		throw new \Temma\Exceptions\Database("No flush() method on this object.", \Temma\Exceptions\Database::FUNDAMENTAL);
	}

	/* ********** RAW REQUESTS ********** */
	/**
	 * Squeleton method for a lister method implemented by derived classes.
	 * @param	string	$pattern	The pattern to match. The syntax depends on the datasource.
	 * @param	bool	$getValues	(optional) True to fetch the associated values. False by default.
	 * @return	array	List of keys, or associative array of key-value pairs.
	 * @throws	\Temma\Exceptions\Database	Always throws an exception.
	 */
	public function find(string $pattern, bool $getValues=false) : array {
		throw new \Temma\Exceptions\Database("No find() method on this object.", \Temma\Exceptions\Database::FUNDAMENTAL);
	}
	/**
	 * Squeleton method for a reader method implemented by derived classes.
	 * @param	string	$key			Key of the data.
	 * @param	mixed	$defaultOrCallback	(optional) Default scalar value or function called if the data is not found.
	 *						If scalar: the value is written in the local file.
	 *						If callback: the value returned by the function is stored in the data source, and written in the file.
	 * @param	mixed	$options		(optional) Options used to store the data returned by the callback.
	 * @return	mixed	The fetched data.
	 * @throws	\Temma\Exceptions\Database	Always throws an exception.
	 */
	public function read(string $key, mixed $defaultOrCallback=null, mixed $options=null) : mixed {
		throw new \Temma\Exceptions\Database("No get() method on this object.", \Temma\Exceptions\Database::FUNDAMENTAL);
	}
	/**
	 * Multiple read.
	 * @param	array	$keys	List of keys.
	 * @return	array	Associative array with the keys and their associated values.
	 */
	public function mRead(array $keys) : array {
		if (!$this->_enabled)
			return ([]);
		$result = [];
		foreach ($keys as $key)
			$result[$key] = $this->read($key);
		return ($result);
	}
	/**
	 * Squeleton method for a getter method (writing to a local file) implemented by derived classes.
	 * @param	string	$key			Key of the data.
	 * @param	string	$localPath		Path to the local file where the data will be written.
	 * @param	mixed	$defaultOrCallback	(optional) Default scalar value or function called if the data is not found.
	 *						If scalar: the value is written in the local file.
	 *						If callback: the value returned by the function is stored in the data source, and written in the file.
	 * @param	mixed	$options		(optional) Options used to store the data returned by the callback.
	 * @return	bool	True if the key has been found and its content has been written locally.
	 * @throws	\Temma\Exceptions\IO	If the local file is not writable.
	 */
	public function copyFrom(string $key, string $localPath, mixed $defaultOrCallback=null, mixed $options=null) : bool {
		if (!$this->_enabled)
			return (false);
		// check if the local path is writable
		$dirname = dirname($localPath);
		if (($dirname && !file_exists($dirname) && !mkdir($dirname, 0777, true)) ||
		    !is_writeable($localPath)) {
			TµLog::log('Temma/Base', 'INFO', "Unable to write file '$localPath'.");
			throw new \Temma\Exceptions\IO("Unable to write file '$localPath'.", \Temma\Exceptions\IO::UNWRITABLE);
		}
		// get the file's data
		$data = $this->read($key, $defaultOrCallback, $options);
		if ($data === null)
			return (false);
		if (!is_string($data))
			$data = json_encode($data);
		file_put_contents($localPath, $data);
		return (true);
	}
	/**
	 * Multiple copyFrom.
	 * @param	array	$keys	Associative array with keys and the path where their associated data must be written.
	 * @return	int	The number of written files.
	 */
	public function mCopyFrom(array $keys) : int {
		if (!$this->_enabled)
			return (0);
		$fetched = 0;
		foreach ($keys as $key => $path) {
			try {
				$this->copyFrom($key, $path);
				$fetched++;
			} catch (\Exception $e) {
			}
		}
		return ($fetched);
	}
	/**
	 * Squeleton method for a writer method implemented by derived classes.
	 * @param	string	$key		Key of the new data.
	 * @param	string	$value		Value of the data.
	 * @param	mixed	$options	(optional) Options (like expiration duration).
	 * @return	\Temma\Base\Datasource	The current object.
	 * @throws 	\Temma\Exceptions\Database	Always throws an exception.
	 */
	public function write(string $key, string $value, mixed $options=null) : \Temma\Base\Datasource {
		throw new \Temma\Exceptions\Database("No set() method on this object.", \Temma\Exceptions\Database::FUNDAMENTAL);
	}
	/**
	 * Multiple write.
	 * @param	array	$data		Associative array with keys and their associated values.
	 * @param	mixed	$options	(optional) Options.
	 * @return	int	The number of written data.
	 */
	public function mWrite(array $data, mixed $options=null) : int {
		if (!$this->_enabled)
			return (0);
		$written = 0;
		foreach ($data as $key => $value) {
			try {
				$this->write($key, $value, $options);
				$written++;
			} catch (\Exception $e) {
			}
		}
		return ($written);
	}
	/**
	 * Squeleton method for a setter method (reading from a local file) implemented by derived classes.
	 * @param	string	$key		Key of the new data.
	 * @param	string	$localPath	Path to the local file.
	 * @param	mixed	$options	(optional) Options (like expiration duration).
	 * @return	\Temma\Base\Datasource	The current object.
	 * @throws	\Temma\Exceptions\IO	If the local file is not readable.
	 */
	public function copyTo(string $key, string $localPath, mixed $options=null) : \Temma\Base\Datasource {
		if (!$this->_enabled)
			return ($this);
		if (!is_readable($localPath))
			throw new \Temma\Exceptions\IO("Unable to read file '$localPath'.", \Temma\Exceptions\IO::UNREADABLE);
		$data = file_get_contents($localPath);
		$this->write($key, $data, $options);
		return ($this);
	}
	/**
	 * Multiple copyTo.
	 * @param	array	$data		Associative array with keys and the associated source file's path.
	 * @param	mixed	$options	(optional) Options (like expiration duration).
	 * @return	int	The number of stored data.
	 */
	public function mCopyTo(array $data, mixed $options=null) : int {
		if (!$this->_enabled)
			return (0);
		$put = 0;
		foreach ($data as $key => $path) {
			try {
				$this->copyTo($key, $path, $options);
				$put++;
			} catch (\Exception $e) {
			}
		}
		return ($put);
	}

	/* ********** KEY-VALUE REQUESTS ********** */
	/**
	 * Squeleton method for a lister method implemented by derived classes.
	 * @param	string	$pattern	The pattern to match. The syntax depends on the datasource.
	 * @param	bool	$getValues	(optional) True to fetch the associated values. False by default.
	 * @return	array	List of keys, or associative array of key-value pairs. Values are JSON-decoded.
	 */
	public function search(string $pattern, bool $getValues=false) : array {
		if (!$this->_enabled)
			return ([]);
		$data = $this->find($pattern, $getValues);
		if ($getValues) {
			array_walk($data, function(&$value, $key) {
				if (is_string($value))
					$value = json_decode($value, true);
			});
		}
		return ($data);
	}
	/**
	 * Squeleton method for a getter method implemented by derived classes.
	 * @param	string	$key			Key of the data.
	 * @param	mixed	$defaultOrCallback	(optional) Default scalar value or function called if the data is not found.
	 *						If scalar: the value is returned.
	 *						If callback: the value returned by the function is stored in the data source, and returned.
	 * @param	mixed	$options		(optional) Options used to store the data returned by the callback.
	 * @return	mixed	The fetched data.
	 */
	public function get(string $key, mixed $defaultOrCallback=null, mixed $options=null) : mixed {
		if (!$this->_enabled)
			return (null);
		$data = $this->read($key, $defaultOrCallback, $options);
		if (!$data)
			return ($data);
		return (json_decode($data, true));
	}
	/**
	 * Multiple get.
	 * @param	array	$keys	List of keys.
	 * @return	array	Associative array with the keys and their associated values.
	 */
	public function mGet(array $keys) : array {
		if (!$this->_enabled)
			return ([]);
		$result = $this->mRead($keys);
		if (!$result)
			return ($result);
		array_walk($result, function(&$value, $key) {
			$value = json_decode($value, true);
		});
		return ($result);
	}
	/**
	 * Squeleton method for a setter method implemented by derived classes.
	 * @param	string	$key		Key of the new data.
	 * @param	mixed	$value		Value of the data. Remove the data if null.
	 * @param	mixed	$options	(optional) Options (like expiration duration).
	 * @return	\Temma\Base\Datasource	The current object.
	 */
	public function set(string $key, mixed $value=null, mixed $options=null) : \Temma\Base\Datasource {
		if (!$this->_enabled)
			return ($this);
		if (is_null($value))
			$this->remove($key);
		else
			$this->write($key, json_encode($value), $options);
		return ($this);
	}
	/**
	 * Multiple set.
	 * @param	array	$data		Associative array with keys and their associated values.
	 * @param	mixed	$options	(optional) Options (like expiration duration).
	 * @return	int	The number of set data.
	 */
	public function mSet(array $data, mixed $options=null) : int {
		if (!$this->_enabled)
			return (0);
		array_walk($data, function(&$value, $key) {
			$value = json_encode($value);
		});
		return ($this->mWrite($data, $options));
	}

	/* ********** ARRAY SYNTAX ********** */
	/** 
	 * Add data in source, array-like syntax.
	 * @param       mixed   $key    Index key.
	 * @param       mixed   $data   Data value. The data is deleted if the value is null.
	 */
	public function offsetSet(mixed $key, mixed $data) : void {
		$this->set($key, $data);
	}   
	/** 
	 * Remove data from source, array-like syntax.
	 * @param       mixed   $key    Index key.
	 */
	public function offsetUnset(mixed $key) : void {
		$this->remove($key);
	}   
	/** 
	 * Get a data from source, array-like syntax.
	 * @param       mixed   $key    Index key.
	 * @return      mixed   The data fetched from the cache, or null.
	 */
	public function offsetGet(mixed $key) : mixed {
		return ($this->get($key));
	}   
	/** 
	 * Tell if a file exists in source, array-like syntax.
	 * @param       mixed   $key    Index key.
	 * @return      bool    True if the variable is set, false otherwise.
	 */
	public function offsetExists(mixed $key) : bool {
		return ($this->isSet($key));
	}
}

