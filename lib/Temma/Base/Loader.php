<?php

/**
 * Loader
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2019-2023, Amaury Bouchard
 */

namespace Temma\Base;

use \Temma\Base\Log as TµLog;

/**
 * Dependency injection container.
 *
 * Examples:
 * <code>
 * // create a loader
 * $loader = new \Temma\Base\Loader();
 * // create a loader and set initial data
 * $loader = new \Temma\Base\Loader([
 *     'userBo'          => $userBo,
 *     'cardBo'          => $cardBo,
 *     '\MyApp\MainCtrl' => new \MyApp\MainCtrl(),
 * ]);
 *
 * // use the loader with an object-oriented syntax
 * $user = $loader->userBo->getFromId(12);
 * // use the loader with an array-like syntax
 * $loader['\MyApp\MainCtrl']->startCountDown();
 *
 * // define a callback to create an object later on the fly
 * $loader->userDao = function($loader) {
 *     return new \MyApp\UserDao($loader->db);
 * };
 * // use the callback transparently
 * $loader->userDao->createUser('John Rambo');
 *
 * // define a builder function
 * $loader->setBuilder(function($loader, $key) {
 *     if (substr($key, -2) == 'Bo') {
 *         $classname = substr($key, 0, -2);
 *         return new \MyApp\Bo\$classname($loader);
 *     } else if (substr($key, -3) == 'Dao') {
 *         $classname = substr($key, 0, -3);
 *         return new \MyApp\Dao\$classname($loader);
 *     }
 * });
 * // use the builder
 * $loader->userBo->addFriends();
 * $user = $loader->userDao->getFromId(17);
 * $card = $loader->cardDao->getFromCode('12ax37');
 *
 * // create a loader, set its initial data, and define a builder
 * $loader = new \Temma\Base\Loader(['userBo' => $userBo], function($loader, $key) {
 *     return new $key($loader);
 * });
 * </code>
 *
 * It is also possible to create a child class with a defined builder:
 * <code>
 * class MyLoader extends \Temma\Base\Loader {
 *     protected function builder(string $key) {
 *         $key = ucfirst($key);
 *         return new $key($this);
 *     }
 * }
 *
 * // dynamically creates an instance of UserDao
 * $user = $loader->userDao->getFromId(21);
 * // dynamically creates an instance of UserBo
 * $loader->userBo->addFriends();
 * </code>
 */
class Loader extends \Temma\Utils\Registry {
	/** Builder function. */
	protected /*?callable*/ $_builder = null;

	/* ********** CONSTRUCTION ********** */
	/**
	 * Constructor.
	 * @param	array		$data		(optional) Associative array of data used to fill the registry.
	 * @param	?callable	$builder	(optional) Builder function.
	 */
	public function __construct(?array $data=null, ?callable $builder=null) {
		$this->_builder = $builder;
		parent::__construct($data);
	}
	/**
	 * Define the builder function.
	 * @param	callable	$builder	Builder function.
	 * @return	\Temma\Base\Loader	The current object.
	 */
	public function setBuilder(callable $builder) : \Temma\Base\Loader {
		$this->_builder = $builder;
		return ($this);
	}

	/* ********** DATA WRITING ********** */
	/**
	 * Add data to the loader.
	 * @param	string|array	$key	If a string is given, it's the index key, and a value should be given.
	 *					If an array is given, its key/value pairs are used to set loader's values,
	 *					and the second parameter is not used.
	 * @param	mixed		$data	Value associated to the key.
	 *					If this value is null, the key is removed.
	 *					If the value is a scalar (int, float, string, bool, array) or an array,
	 *					it will be simply returned when fetched. If the value is an anonymous
	 *					function, this function will be executed the first time the data is fetched,
	 *					and its returned value will be stored as the value, and returned.
	 * @return	\Temma\Base\Loader	The current object.
	 */
	public function set(string|array $key, mixed $data=null) : \Temma\Base\Loader {
		if (is_array($key))
			$this->_data = array_merge($this->_data, $key);
		else if (is_null($data))
			unset($this->_data[$key]);
		else
			$this->_data[$key] = $data;
		return ($this);
	}

	/* ********** DATA READING ********** */
	/**
	 * Returns data from the loader.
	 * @param	string	$key		Index key.
	 * @param	mixed	$default	(optional) Default value that must be returned is the requested key doesn't exist.
	 * @return	mixed	The associated value, or null.
	 */
	public function get(string $key, mixed $default=null) : mixed {
		// special processing for Asynk
		if ($key == 'Asynk') {
			return (new \Temma\Asynk\Client($this));
		}
		// key exists
		if (isset($this->_data[$key])) {
			if ($this->_data[$key] instanceof \Closure)
				$this->_data[$key] = $this->_data[$key]($this);
			if (isset($this->_data[$key]))
				return ($this->_data[$key]);
		}
		// loadable
		if (($interfaces = @class_implements($key)) && isset($interfaces['Temma\Base\Loadable'])) {
			$this->_data[$key] = new $key($this);
			if (isset($this->_data[$key]))
				return ($this->_data[$key]);
		}
		// DAO
		if (is_subclass_of($key, '\Temma\Dao\Dao') && isset($this->_data['controller']) && $this->_data['controller'] instanceof \Temma\Web\Controller) {
			$this->_data[$key] = $this->_data['controller']->_loadDao($key);
			if (isset($this->_data[$key]))
				return ($this->_data[$key]);
		}
		// controller
		if (is_subclass_of($key, '\Temma\Web\Controller')) {
			// management of controller's attributes
			$controllerReflection = new \ReflectionClass($key);
			$attributes = $controllerReflection->getAttributes(null, \ReflectionAttribute::IS_INSTANCEOF);
			for ($parentClass = $controllerReflection->getParentClass(); $parentClass; $parentClass = $parentClass->getParentClass())
				$attributes = array_merge($attributes, $parentClass->getAttributes(null, \ReflectionAttribute::IS_INSTANCEOF));
			foreach ($attributes as $attribute) {
				TµLog::log('Temma/Web', 'DEBUG', "Controller attribute '{$attribute->getName()}'.");
				$attribute->newInstance();
			}
			// instanciation of the controller
			$this->_data[$key] = new $key($this, ($this->_data['parentController'] ?? $this->_data['controller'] ?? null));
			if (isset($this->_data[$key]))
				return ($this->_data[$key]);
		}
		// call builder function
		if (isset($this->_builder)) {
			$builder = $this->_builder;
			$this->_data[$key] = $builder($this, $key);
			if (isset($this->_data[$key]))
				return ($this->_data[$key]);
		}
		// call loader's builder method
		if (method_exists($this, 'builder')) {
			$this->_data[$key] = $this->builder($key);
			if (isset($this->_data[$key]))
				return ($this->_data[$key]);
		}
		// if the default value is a callback, execute it
		if ($default instanceof \Closure) {
			$default = $default($this);
		}
		return ($default);
	}
}

