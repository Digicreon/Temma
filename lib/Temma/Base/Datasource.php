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
abstract class Datasource {
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

	/**
	 * Factory.
	 * Create a concrete instance of the object, depending of the given parameters.
	 * @param	string	$dsn	Parameter string.
	 * @return	\Temma\Base\Datasource	The created object.
	 * @throws	\Exception	If the given DSN is not correct.
	 */
	static public function factory(string $dsn) : \Temma\Base\Datasource {
		TµLog::log('Temma/Base', 'DEBUG', "Datasource object creation with DSN: '$dsn'.");
		foreach (self::DATABASE_PREFIXES as $prefix) {
			if (str_starts_with($dsn, $prefix))
				return (\Temma\Base\Database::factory($dsn));
		}
		if (str_starts_with($dsn, 'memcache://'))
			return (\Temma\Base\Cache::factory($dsn));
		if (str_starts_with($dsn, 'redis://') ||
		    str_starts_with($dsn, 'redis-sock://'))
			return (\Temma\Base\NDatabase::factory($dsn));
		if (str_starts_with($dsn, 'dummy://'))
			return (\Temma\Base\DummyDatasource::factory(''));
		if (str_starts_with($dsn, 'env://')) {
			$dsn = getenv(substr($dsn, 6));
			return (self::factory($dsn));
		}
		throw new \Exception("No valid DSN provided '$dsn'.");
	}
	/**
	 * For compatibility: Tell if the cache is enabled or not.
	 * @return	bool	True if the cache is enabled.
	 * @see		\Temma\Base\Cache
	 */
	public function isEnabled() : bool {
		return (true);
	}
	/**
	 * Squeleton method for a getter method implemented by derived classes.
	 * @param	string	$key	Key of the data.
	 * @return	mixed	The fetched data.
	 * @throws	\Temma\Exceptions\Database	Always throws an exception.
	 */
	public function get(string $key) : mixed {
		throw new \Temma\Exceptions\Database("No get() method on this object.", \Temma\Exceptions\Database::FUNDAMENTAL);
	}
	/**
	 * Squeleton method for a setter method implemented by derived classes.
	 * @param	string	$key		Key of the new data.
	 * @param	mixed	$value		Value of the data.
	 * @param	int	$expires	Expiration duration.
	 * @return	mixed	The old value for the key.
	 * @throws 	\Temma\Exceptions\Database	Always throws an exception.
	 */
	public function set(string $key, mixed $value=null, int $expires=0) : mixed {
		throw new \Temma\Exceptions\Database("No set() method on this object.", \Temma\Exceptions\Database::FUNDAMENTAL);
	}
}

