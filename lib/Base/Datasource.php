<?php

/**
 * Datasource
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2012-2019, Amaury Bouchard
 */

namespace Temma\Base;

use \Temma\Base\Log as TµLog;

/**
 * Object for data sources management.
 *
 * Must be used in an autoloader-enabled environment.
 */
abstract class Datasource {
	/**
	 * Factory.
	 * Create a concrete instance of the object, depending of the given parameters.
	 * @param	string	$dsn	Parameter string.
	 * @return	\Temma\Base\Datasource	The created object.
	 * @throws	\Exception	If the given DSN is not correct.
	 */
	static public function factory(string $dsn) : \Temma\Base\Datasource {
		TµLog::log('Temma/Base', 'DEBUG', "Datasource object creation with DSN: '$dsn'.");
		if (substr($dsn, 0, 7) === 'mysqli:' || substr($dsn, 0, 6) === 'mysql:' ||
		    substr($dsn, 0, 6) === 'pgsql:' || substr($dsn, 0, 7) === 'cubrid:' ||
		    substr($dsn, 0, 7) === 'sybase:' || substr($dsn, 0, 6) === 'mssql:' ||
		    substr($dsn, 0, 6) === 'dblib:' || substr($dsn, 0, 9) === 'firebird:' ||
		    substr($dsn, 0, 4) === 'ibm:' || substr($dsn, 0, 9) === 'informix:' ||
		    substr($dsn, 0, 7) === 'sqlsrv:' || substr($dsn, 0, 4) === 'oci:' ||
		    substr($dsn, 0, 5) === 'odbc:' || substr($dsn, 0, 7) === 'sqlite:' ||
		    substr($dsn, 0, 8) === 'sqlite2:' || substr($dsn, 0, 3) === '4D:') {
			return (\Temma\Base\Database::factory($dsn));
		} else if (substr($dsn, 0, 11) === 'memcache://') {
			return (\Temma\Base\Cache::factory($dsn));
		} else if (substr($dsn, 0, 8) === 'redis://' || substr($dsn, 0, 13) === 'redis-sock://') {
			return (\Temma\Base\NDatabase::factory($dsn));
		} else if (substr($dsn, 0, 8) == 'dummy://') {
			return (\Temma\Base\DummyDatasource::factory(''));
		} else if (substr($dsn, 0, 6) == 'env://') {
			$dsn = getenv(substr($dsn, 6));
			return (self::factory($dsn));
		} else
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
}

