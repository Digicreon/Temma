<?php

/**
 * Dummy
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2012-2023, Amaury Bouchard
 */

namespace Temma\Base\Datasources;

use \Temma\Base\Log as TµLog;

/**
 * Object use to create a data source that does nothing.
 */
class Dummy extends \Temma\Base\Datasource {
	/**
	 * Factory.
	 * @param	string	$dsn	Parameter string (not used).
	 * @return	\Temma\Base\Datasources\Dummy	The created object.
	 */
	static public function factory(string $dsn) : \Temma\Base\Datasources\Dummy {
		TµLog::log('Temma/Base', 'DEBUG', "Creation of a dummy datasource.");
		return (new self());
	}
	/**
	 * Any method called on this object will work.
	 * @param	string	$name	Name of the called method.
	 * @param	array	$args	List of parameters.
	 * @return	null	This method always returns null.
	 */
	public function __call(string $name, array $args) /* : null */ {
		TµLog::log('Temma/Base', 'DEBUG', "Dummy datasource called on method '$name'.");
		return (null);
	}

	/* ********** STANDARD REQUESTS ********** */
	/**
	 * isSet
	 * @param	string	$key	Key.
	 * @return	bool	Always false.
	 */
	public function isSet(string $key) : bool {
		return (false);
	}
	/**
         * remove
         * @param	string	$key	Not used.
         * @return	\Temma\Base\Datasources\Dummy	The current object.
         */
        public function remove(string $key) : \Temma\Base\Datasources\Dummy {
		return( $this);
	}
	/**
	 * mremove
	 * @param	array	$keys	Not used.
	 * @return	\Temma\Base\Datasources\Dummy	The current object.
	 */
	public function mRemove(array $keys) : \Temma\Base\Datasources\Dummy {
		return ($this);
	}
	/**
	 * clear
	 * @param	string	$pattern	Not used.
	 * @return	\Temma\Base\Datasources\Dummy	The current object.
	 */
	public function clear(string $pattern) : \Temma\Base\Datasources\Dummy {
		return ($this);
	}
	/**
	 * flush
	 * @return	\Temma\Base\Datasources\Dummy	The current object.
	 */
	public function flush() : \Temma\Base\Datasources\Dummy {
		return ($this);
	}

	/* ********** RAW REQUESTS ********** */
	/**
         * find
         * @param	string	$pattern	Not used.
         * @param	bool	$getValues	(optional) Not used.
         * @return	array	Empty array.
         */
        public function find(string $pattern, bool $getValues=false) : array {
		return ([]);
	}
	/**
	 * Getter. Returns the data generated by the anonymous function given as parameter.
	 * @param	string	$key			Index key of the data.a
	 * @param	mixed	$defaultOrCallback	(optional) Default scalar value or function called if the data is not found.
	 * @param	mixed	$options		(optional) Options used to store the data returned
	 *						by the callback.
	 * @return	?string	The data returned by the anonymous function, or null.
	 */
	public function read(string $key, mixed $defaultOrCallback=null, mixed $options=null) : ?string {
		if (is_callable($defaultOrCallback))
			return ($defaultOrCallback());
		return ($defaultOrCallback);
	}
	/**
	 * Multiple read.
	 * @param	array	$keys	List of keys.
	 * @return	array	Empty array.
	 */
	public function mRead(array $keys) : array {
		return ([]);
	}
	/**
	 * copyFrom
	 * @param	string	$key			Not used.
	 * @param	string	$localPath		Not used.
	 * @param	mixed	$defaultOrCallback	(optional) Not used.
	 * @param	mixed	$options		(optional) Not used.
	 * @return	bool	Always false.
	 */
	public function copyFrom(string $key, string $localPath, mixed $defaultOrCallback=null, mixed $options=null) : bool {
		return (false);
	}
	/**
	 * mCopyFrom
	 * @param	array	$keys	Not used.
	 * @return	int	Always zero.
	 */
	public function mCopyFrom(array $keys) : int {
		return (0);
	}
	/**
	 * write
	 * @param	string	$key		Not used.
	 * @param	mixed	$value		(optional) Not used.
	 * @param	mixed	$options	(optional) Not used.
	 * @return	\Temma\Base\Datasources\Dummy	The current object.
	 */
	public function write(string $key, mixed $value=null, mixed $options=null) : \Temma\Base\Datasources\Dummy {
		return ($this);
	}
	/**
	 * Multiple write.
	 * @param	array	$data	Not used.
	 * @param	mixed	$options	(optional) Not used.
	 * @return	int	Always zero.
	 */
	public function mWrite(array $data, mixed $options=null) : int {
		return (0);
	}
	/**
	 * copyTo
	 * @param	string	$key		Not used.
	 * @param	string	$localPath	Not used.
	 * @param	mixed	$options	(optional) Not used.
	 * @return	\Temma\Base\Datasources\Dummy	The current object.
	 */
	public function copyTo(string $key, string $localPath, mixed $options=null) : \Temma\Base\Datasources\Dummy {
		return ($this);
	}
	/**
	 * mCopyTo
	 * @param	array	$data		Not used.
	 * @param	mixed	$options        (optional) Not used.
	 * @return	int	Always zero.
	 */
	public function mCopyTo(array $data, mixed $options=null) : int {
		return (0);
	}

	/* ********** KEY-VALUE REQUESTS ********** */
	/**
         * Search
         * @param	string	$pattern	Not used.
         * @param	bool	$getValues	(optional) Not used.
         * @return	array	Empty array.
         */
        public function search(string $pattern, bool $getValues=false) : array {
		return ([]);
	}
	/**
	 * Getter. Returns the default data or the data generated by the anonymous function given as second parameter.
	 * @param	string	$key			Not used.
	 * @param	mixed	$defaultOrCallback	(optional) Default scalar value or function called if the data is not found.
	 * @param	mixed	$options		(optional) Not used.
	 * @return	mixed	The data returned by the anonymous function, or null.
	 */
	public function get(string $key, mixed $defaultOrCallback=null, mixed $options=null) : mixed {
		TµLog::log('Temma/Base', 'DEBUG', "Dummy datasource get() call.");
		if (is_callable($defaultOrCallback))
			return ($defaultOrCallback());
		return ($defaultOrCallback);
	}
	/**
	 * Multiple get.
	 * @param	array	$keys	List of keys.
	 * @return	array	Empty array.
	 */
	public function mGet(array $keys) : array {
		return ([]);
	}
	/**
	 * set
	 * @param	string	$key		Not used.
	 * @param	mixed	$value		Not used.
	 * @param	mixed	$options	(optional) Not used.
	 * @return	\Temma\Base\Datasources\Dummy	The current object.
	 */
	public function set(string $key, mixed $value=null, mixed $options=null) : \Temma\Base\Datasources\Dummy {
		return ($this);
	}
	/**
	 * Multiple set.
	 * @param	array	$data		Not used.
	 * @param	mixed	$options	Not used.
	 * @return	int	Always zero.
	 */
	public function mSet(array $data, mixed $options=null) : int {
		return (0);
	}
}

