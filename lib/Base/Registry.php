<?php

namespace Temma\Base;

use \Temma\Base\Log as TµLog;

/**
 * Registry object, useful to store global data.
 *
 * Example:
 * <code>
 * // create a new registry
 * $registry = new \Temma\Base\Registry();
 * // create a registry and set initial data
 * $registry = new \Temma\Base\Registry([
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
 *
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2019, Amaury Bouchard
 * @package	Temma
 * @subpackage	Base
 */
class Registry implements \ArrayAccess {
	/** Associative array that contains the stored data. */
	protected $_data = null;

	/* ************************ CONSTRUCTION ********************** */
	/**
	 * Constructor.
	 * @param	array	$data	(optional) Associative array of data used to fill the registry.
	 */
	public function __construct(array $data=null) {
		$this->_data = isset($data) ? $data : [];
	}
	/** Destructor. */
	public function __destruct() {
		$this->_data = null;
	}
	/**
	 * Remove all stored data. Replace with the given data.
	 * @param	array	$data	(optional) Data to use to replace the currently stored data.
	 * @return	\Temma\Base\Registry	The current object.
	 */
	public function reset(array $data=null) {
		$this->_data = $data;
	}

	/* ****************** DATA WRITING **************** */
	/**
	 * Add data to the registry.
	 * @param	string|array	$keyOrArray	Index key, or an associatve array of key-value pairs.
	 * @param	mixed		$data		(optional) Associated value. Leave empty if an array is given as first parameter.
	 * @return	\Temma\Base\Registry	The current object.
	 */
	public function set($keyOrArray, $data=null) : \Temma\Base\Registry {
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
	public function __set(string $key, $data) {
		$this->set($key, $data);
	}
	/**
	 * Add data to the registry, array-like syntax.
	 * @param	string	$key	Index key.
	 * @param	mixed	$data	Associated value.
	 */
	public function offsetSet($key, $data) {
		$this->set($key, $data);
	}

	/* ********** DATA READING ********** */
	/**
	 * Returns data from the registry.
	 * @param	string	$key		Index key.
	 * @param	mixed	$default	(optional) Default value that must be returned is the requested key doesn't exist.
	 * @return	mixed	The associated value, or null.
	 */
	public function get(string $key, $default=null) {
		if (array_key_exists($key, $this->_data))
			return ($this->_data[$key]);
		return ($default);
	}
	/**
	 * Returns data from the registry, object-oriented syntax.
	 * @param	string	$key	Index key.
	 * @return	mixed	The associated value, or null.
	 */
	public function __get(string $key) {
		return ($this->get($key));
	}
	/**
	 * Returns data from the registry, array-like syntax.
	 * @param	string	$key	Index key.
	 * @return	mixed	The associated value, or null.
	 */
	public function offsetGet($key) {
		return ($this->get($key));
	}

	/* ********** DATA CHECK ********** */
	/**
	 * Check if a data exist, object-oriented syntax.
	 * @param	string	$key	Index key.
	 * @return	bool	True if the key was defined, false otherwise.
	 */
	public function isset(string $key) {
		return (array_key_exists($key, $this->_data));
	}
	/**
	 * Check if a data exist, "isset()" syntax.
	 * @param	string	$key	Index key.
	 * @return	bool	True if the key was defined, false otherwise.
	 */
	public function __isset(string $key) {
		return ($this->isset($key));
	}
	/**
	 * Check if a data exist, array-like syntax.
	 * @param	string	$key	Index key.
	 * @return	bool	True if the key was defined, false otherwise.
	 */
	public function offsetExists($key) {
		return ($this->isset($key));
	}

	/* ********** DATA DELETION ********** */
	/**
	 * Remove a data from registry, object-oriented syntax.
	 * @param	string	$key	Index key.
	 * @return	FineRegistry	The current object.
	 */
	public function unset(string $key) {
		$this->_data[$key] = null;
		unset($this->_data[$key]);
		return ($this);
	}
	/**
	 * Remove data from registry, "unset()" syntax.
	 * @param	string	$key	Index key.
	 */
	public function __unset(string $key) {
		$this->unset($key);
	}
	/**
	 * Remove data from registry, array-like syntax.
	 * @param	string	$key	Index key.
	 */
	public function offsetUnset($key) {
		$this->unset($key);
	}

	/* ********** DATA IMPORT ********** */
	/**
	 * Read an INI file and import its data.
	 * @param	string	$path	Path to the INI file.
	 * @param	string	$key	(optional) Name of the key which associated value will contain the INI data.
	 *				If this key is not given, the whole INI data will replace all registry data.
	 * @return	\Temma\Base\Registry	The current object.
	 * @throws	\Exception	If something went wrong.
	 */
	public function readIni(string $path, string $key=null) {
		// readINI file
		$result = parse_ini_file($path, true);
		if ($result === false) {
			TµLog::log('Temma\Base', 'WARN', "INI reading error.");
			throw new \Exception("INI reading error.");
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
	 * @param	string	$path	Path to the JSON file.
	 * @param	string	$key	(optional) Name of the key which associated value will contain the JSON data.
	 *				If this key is not given, the whole JSON data will replace all registry data.
	 * @return	\Temma\Base\Registry	The current object.
	 * @throws	\JsonException	If something went wrong.
	 */
	public function readJson(string $path, string $key=null) {
		// read JSON file
		try {
			$result = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			TµLog::log('Temma\Base', 'WARN', "JSON reading error: " . $e->getMessage());
			throw $e;
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
	 * @return	\Temma\Base\Registry	The current object.
	 * @throws	\Temma\Exceptions\IOException	If the XML file can't be read.
	 */
	public function readXml(string $path, string $key) {
		// read XML file
		$xml = simplexml_load_file($path);
		if ($xml === false) {
			TµLog::log('Temma\Base', 'WARN', "Unable to read XML file '$path'.");
			throw new \Temma\Exceptions\IOException("Unable to read XML file '$path'.", \Temma\Exceptions\IOException::BAD_FORMAT);
		}
		// store the XML data
		$this->set($key, $xml);
		return ($this);
	}
}

