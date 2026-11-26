<?php

/**
 * Registry
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-registry
 */

namespace Temma\Utils;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\IO as TµIOException;

/**
 * Registry object, useful to store global data.
 *
 * Example:
 * <code>
 * // create a new registry
 * $registry = new \Temma\Utils\Registry();
 * // create a registry and set initial data
 * $registry = new \Temma\Utils\Registry([
 *     'foo' => 'bar',
 *     'abc' => 'xyz',
 * ]);
 *
 * // read an INI file and store its data in the registry
 * $registry->readIni('/path/to/file.ini');
 * // read a JSON file and store its data in the registry
 * $registry->readJson('/path/to/file.json');
 * // read an XML file and store its data in a key of the registry
 * $registry->readXml('/path/to/file.xml', 'config');
 *
 * // access data from the registry (three methods, same result)
 * print($registry->get('foo'));
 * print($registry->foo);
 * print($registry['foo']);
 *
 * // add data to the registry (three methods, same result)
 * $registry->set('foo', 'bar');
 * $registry->foo = 'bar';
 * $registry['foo'] = 'bar';
 *
 * // add multiple data in one call
 * $registry->set([
 *     'foo' => 'bar',
 *     'abc' => 'xyz',
 * ]);
 *
 * // check if data exists (three methods, same result)
 * if ($registry->isset('foo'))
 *     print('OK');
 * if (isset($registry->foo))
 *     print('OK');
 * if (isset($registry['foo']))
 *     print('OK');
 *
 * // remove data (three methods, same result)
 * $registry->unset('foo');
 * unset($registry->foo);
 * unset($registry['foo']);
 * </code>
 */
class Registry implements \Iterator, \ArrayAccess {
	/** Associative array that contains the stored data. */
	protected array $_data;

	/* ************************ CONSTRUCTION ********************** */
	/**
	 * Constructor.
	 * @param	array	$data	(optional) Associative array of data used to fill the registry.
	 */
	public function __construct(?array $data=null) {
		$this->_data = $data ?? [];
	}
	/**
	 * Cloning.
	 */
	public function __clone() {
		$this->_data = \Temma\Utils\Misc::clone($this->_data);
	}
	/**
	 * Remove all stored data. Replace with the given data.
	 * @param	array	$data	(optional) Data to use to replace the currently stored data.
	 * @return	\Temma\Utils\Registry	The current object.
	 */
	public function reset(array $data=[]) : \Temma\Utils\Registry {
		$this->_data = $data;
		return ($this);
	}

	/* ********** ITERATOR MANAGEMENT ********** */
	/**
	 * Returns the current element.
	 * @return	mixed	The current element.
	 */
	public function current() : mixed {
		return (current($this->_data));
	}
	/**
	 * Returns the current key.
	 * @return	mixed	The current key.
	 */
	public function key() : mixed {
		return (key($this->_data));
	}
	/**
	 * Move the cursor to the next element.
	 */
	public function next() : void {
		next($this->_data);
	}
	/**
	 * Rewind the cursor to the first element.
	 */
	public function rewind() : void {
		reset($this->_data);
	}
	/**
	 * Tell if the cursor has reached the end of the list.
	 * @return	bool	True while the cursor hasn't reach the end of the list.
	 */
	public function valid() : bool {
		return (key($this->_data) !== null);
	}

	/* ****************** DATA WRITING **************** */
	/**
	 * Add data to the registry.
	 * @param	string|array	$keyOrArray	Index key, or an associatve array of key-value pairs.
	 * @param	mixed		$data		(optional) Associated value. Leave empty if an array is given as first parameter.
	 * @return	\Temma\Utils\Registry	The current object.
	 */
	public function set(string|array $keyOrArray, mixed $data=null) : \Temma\Utils\Registry {
		if (is_array($keyOrArray))
			$this->_data = array_merge($this->_data, $keyOrArray);
		else
			$this->_data[$keyOrArray] = $data;
		return ($this);
	}
	/**
	 * Add data to the registry, object-oriented syntax.
	 * @param	string	$key	Index key.
	 * @param	mixed	$data	Associated value.
	 */
	public function __set(string $key, mixed $data) {
		$this->set($key, $data);
	}
	/**
	 * Add data to the registry, array-like syntax.
	 * @param	mixed	$key	Index key.
	 * @param	mixed	$data	Associated value.
	 */
	public function offsetSet(mixed $key, mixed $data) : void {
		$this->set($key, $data);
	}

	/* ********** DATA READING ********** */
	/**
	 * Returns data from the registry.
	 * @param	string	$key		Index key.
	 * @param	mixed	$default	(optional) Default value that must be returned is the requested key doesn't exist.
	 * @return	mixed	The associated value, or null.
	 */
	public function get(string $key, mixed $default=null) : mixed {
		return ($this->_data[$key] ?? $default);
	}
	/**
	 * Returns data from the registry, object-oriented syntax.
	 * @param	string	$key	Index key.
	 * @return	mixed	The associated value, or null.
	 */
	public function __get(string $key) : mixed {
		return ($this->get($key));
	}
	/**
	 * Returns data from the registry, array-like syntax.
	 * @param	string	$key	Index key.
	 * @return	mixed	The associated value, or null.
	 */
	public function offsetGet(mixed $key) : mixed {
		return ($this->get($key));
	}

	/* ********** DATA CHECK ********** */
	/**
	 * Check if a data exist, object-oriented syntax.
	 * @param	?string	$key	Index key.
	 * @return	bool	True if the key was defined, false otherwise.
	 */
	public function isset(?string $key) : bool {
		return (isset($this->_data[$key]));
	}
	/**
	 * Check if a data exist, "isset()" syntax.
	 * @param	string	$key	Index key.
	 * @return	bool	True if the key was defined, false otherwise.
	 */
	public function __isset(string $key) : bool {
		return ($this->isset($key));
	}
	/**
	 * Check if a data exist, array-like syntax.
	 * @param	mixed	$key	Index key.
	 * @return	bool	True if the key was defined, false otherwise.
	 */
	public function offsetExists(mixed $key) : bool {
		return ($this->isset($key));
	}

	/* ********** DATA DELETION ********** */
	/**
	 * Remove a data from registry, object-oriented syntax.
	 * @param	?string	$key	Index key.
	 * @return	\Temma\Utils\Registry	The current object.
	 */
	public function unset(?string $key) : \Temma\Utils\Registry {
		if (!is_null($key)) {
			$this->_data[$key] = null;
			unset($this->_data[$key]);
		}
		return ($this);
	}
	/**
	 * Remove data from registry, "unset()" syntax.
	 * @param	string	$key	Index key.
	 */
	public function __unset(string $key) : void {
		$this->unset($key);
	}
	/**
	 * Remove data from registry, array-like syntax.
	 * @param	mixed	$key	Index key.
	 */
	public function offsetUnset(mixed $key) : void {
		$this->unset($key);
	}

	/* ********** DATA IMPORT ********** */
	/**
	 * Read an INI file and import its data.
	 * The file is decoded by the INI parser of the Serializer helper (unquoted values are converted like
	 * JSON literals: "true", "false", "null" and numbers are converted, any other value is a string).
	 * @param	string	$path	Path to the INI file.
	 * @param	string	$key	(optional) Name of the key which associated value will contain the INI data.
	 *				If this key is not given, the INI data are merged into the registry data.
	 * @return	\Temma\Utils\Registry	The current object.
	 * @throws	\Temma\Exceptions\IO	If the file doesn't exist (NOT_FOUND), can't be read (UNREADABLE),
	 *					or if its format is not correct (BAD_FORMAT).
	 * @see	\Temma\Utils\Serializer::decodeIni()
	 */
	public function readIni(string $path, ?string $key=null) : \Temma\Utils\Registry {
		// read INI file
		try {
			$result = \Temma\Utils\Serializer::readIni($path);
		} catch (TµIOException $ie) {
			TµLog::log('Temma/Utils', 'WARN', "INI reading error: " . $ie->getMessage());
			throw $ie;
		}
		// store the INI data
		if (is_null($key)) {
			if (is_array($result))
				$this->set($result);
		} else
			$this->set($key, $result);
		return ($this);
	}
	/**
	 * Read a JSON File and import its data.
	 * The file is decoded by the JSON decoder of the Serializer helper (comments and trailing commas are allowed).
	 * @param	string	$path	Path to the JSON file.
	 * @param	string	$key	(optional) Name of the key which associated value will contain the JSON data.
	 *				If this key is not given, the JSON data are merged into the registry data.
	 * @return	\Temma\Utils\Registry	The current object.
	 * @throws	\Temma\Exceptions\IO	If the file doesn't exist (NOT_FOUND), can't be read (UNREADABLE),
	 *					or if its format is not correct (BAD_FORMAT).
	 * @see	\Temma\Utils\Serializer::decodeJson()
	 */
	public function readJson(string $path, ?string $key=null) : \Temma\Utils\Registry {
		// read JSON file
		try {
			$result = \Temma\Utils\Serializer::readJson($path);
		} catch (TµIOException $ie) {
			TµLog::log('Temma/Utils', 'WARN', "JSON reading error: " . $ie->getMessage());
			throw $ie;
		}
		// store the JSON data
		if (is_null($key)) {
			if (is_array($result))
				$this->set($result);
		} else
			$this->set($key, $result);
		return ($this);
	}
	/**
	 * Read an XML file and import its data.
	 * @param	string	$path	Path to the XML file.
	 * @param	string	$key	Name of the key which associated value will contain the XML data.
	 * @return	\Temma\Utils\Registry	The current object.
	 * @throws	\Temma\Exceptions\IO	If the XML file doesn't exist (NOT_FOUND), can't be read (UNREADABLE),
	 *					or if its format is not correct (BAD_FORMAT).
	 */
	public function readXml(string $path, string $key) : \Temma\Utils\Registry {
		// check the file
		if (!is_file($path)) {
			TµLog::log('Temma/Utils', 'WARN', "Unable to find XML file '$path'.");
			throw new TµIOException("Unable to find XML file '$path'.", TµIOException::NOT_FOUND);
		}
		if (!is_readable($path)) {
			TµLog::log('Temma/Utils', 'WARN', "Unable to read XML file '$path'.");
			throw new TµIOException("Unable to read XML file '$path'.", TµIOException::UNREADABLE);
		}
		// read XML file (libxml errors are collected instead of raising PHP warnings)
		$useErrors = libxml_use_internal_errors(true);
		try {
			$xml = simplexml_load_file($path);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($useErrors);
		}
		if ($xml === false) {
			TµLog::log('Temma/Utils', 'WARN', "Unable to decode XML file '$path'.");
			throw new TµIOException("Unable to decode XML file '$path'.", TµIOException::BAD_FORMAT);
		}
		// store the XML data
		$this->set($key, $xml);
		return ($this);
	}
}

