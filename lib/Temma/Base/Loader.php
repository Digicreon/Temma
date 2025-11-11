<?php

/**
 * Loader
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2019-2025, Amaury Bouchard
 * @link	https://www.temma.net/documentation/loader
 */

namespace Temma\Base;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Loader as TµLoaderException;

/* ********** WRAPPER CLASSES ********** */

/**
 * Class used to encapsulate a factory anonymous function.
 * Factory anonymous functions are executed each time their corresponding key is fetched
 * from the loader (while regular anonymous functions are executed once, and their returned
 * values replace them in the loader).
 */
final class LoaderDynamic {
	/**
	 * Constructor.
	 * @param	\Closure	$closure	Internal closure, used as a factory.
	 */
	public function __construct(public readonly \Closure $closure) {
	}
}

/**
 * Class used to encapsulate an anonymous function for lazily-générated objects.
 */
final class LoaderLazy {
	/**
	 * Constructor.
	 * @param	\Closure	$closure	Internal closure.
	 */
	public function __construct(public readonly \Closure $closure) {
	}
}

/* ********** DEPENDENCY INJECTION CONTAINER ********** */

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
	/** @var ?callable Builder callback. */
	protected /*?callable*/ $_builder = null;
	/** @var array<string,string> Prefix map. */
	protected array $_prefixes = [];
	/** Static cache for reflection parameter analysis */
	private static array $_paramCache = [];

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

	/* ********** LAZY, ALIASES AND PREFIXES ********** */
	/**
	 * Add a lazily-loaded data.
	 * @param	string	$key	Index key.
	 * @param	mixed	$data	Value associated to the key.
	 * @return	\Temma\Base\Loader	The current object.
	 */
	public function setLazy(string $key, mixed $data=null) : \Temma\Base\Loader {
		if (is_null($data)) {
			if (($this->_data[$key] ?? null) instanceof LoaderLazy)
				unset($this->_data[$key]);
			return ($this);
		}
		// add the key
		$this->_data[$key] = new LoaderLazy(fn() => $data);
		return ($this);
	}
	/**
	 * Add an alias, or a list of aliases.
	 * @param	string|array	$alias	If a string is given, it's the index key, and a value should be given.
	 *					If an array is given, its key/value pairs are used to set loader's values,
	 *					and the second parameter is not used.
	 * @param	?string	$aliased	(optional) Name of the aliased element. Could be null to remove the alias.
	 * @return	\Temma\Base\Loader	The current object.
	 */
	public function setAlias(string|array $alias, ?string $aliased=null) : \Temma\Base\Loader {
		if (is_array($alias)) {
			foreach ($alias as $aliasKey => $aliased) {
				$this->setAlias($aliasKey, $aliased);
			}
			return ($this);
		}
		if (is_null($aliased)) {
			// remove the previously created alias
			if (($this->_data[$alias] ?? null) instanceof LoaderDynamic)
				unset($this->_data[$alias]);
			return ($this);
		}
		// add the alias as a LoaderDynamic-protected closure
		$this->_data[$alias] = new LoaderDynamic(fn() => $this->get($aliased));
		return ($this);
	}
	/**
	 * Add aliases.
	 * @param	array	$aliases	Associative array of aliases.
	 * @return	\Temma\Base\Loader	The current object.
	 */
	public function setAliases(array $aliases) : \Temma\Base\Loader {
		return ($this->setAlias($aliases));
	}
	/**
	 * Add a prefix.
	 * @param	string|array	$name	If a string is given, it's the name of the prefix, and a prefix should be given.
	 *					If an array is given, its key/value pairs are used to set prefixes, and the
	 *					second parameter is not used.
	 * @param	?string		$prefix	Prefixed namespace. Could be null to remove a prefix.
	 * @return	\Temma\Base\Loader	The current object.
	 */
	public function setPrefix(string|array $name, ?string $prefix=null) : \Temma\Base\Loader {
		if (is_array($name)) {
			$this->_prefixes = array_merge($this->_prefixes, $name);
			return ($this);
		}
		if (!$prefix)
			unset($this->_prefixes[$name]);
		else
			$this->_prefixes[$name] = $prefix;
		return ($this);
	}
	/**
	 * Add prefixes.
	 * @param	array	$prefixes	Associative array of prefixes.
	 * @return	\Temma\Base\Loader	The current object.
	 */
	public function setPrefixes(array $prefixes) : \Temma\Base\Loader {
		return ($this->setPrefix($prefixes));
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
	/**
	 * Create a factory. A factory is a closure wich will be executed each time the value is requested
	 * (not like plain closures which are executed once to set the value).
	 * @param	\Closure	$closure	Closure to use as a factory.
	 * @return	LoaderDynamic	The protected closure.
	 */
	static public function dynamic(\Closure $closure) : LoaderDynamic {
		return new LoaderDynamic($closure);
	}

	/* ********** DATA READING ********** */
	/**
	 * Returns data from the loader.
	 * @param	string	$key		Index key.
	 * @param	mixed	$default	(optional) Default value that must be returned is the requested key doesn't exist.
	 * @return	mixed	The associated value, or null.
	 */
	public function get(string $key, mixed $default=null) : mixed {
		// special cases (Asynk and TµLoader)
		if ($key == 'asynk')
			return (new \Temma\Asynk\Client($this));
		if ($key == '\Temma\Base\Loader' || $key == 'TµLoader')
			return ($this);
		// key exists
		if (isset($this->_data[$key])) {
			$item = $this->_data[$key];
			// is it a factory (closure called each time the value is asked)?
			if ($item instanceof LoaderDynamic)
				return ($this->_instantiate($item->closure, $default));
			// is it a lazily-generated value?
			if ($item instanceof LoaderLazy) {
				$this->_data[$key] = $this->_instantiate($item->closure(), $default);
				return ($this->_data[$key]);
			}
			// is it a callable?
			if (is_callable($item) && !$item instanceof \Temma\Web\Controller)
				$this->_data[$key] = $this->_instantiate($this->_data[$key]);
			// is the value still defined?
			if (isset($this->_data[$key]))
				return ($this->_data[$key]);
		}
		// loop on prefixes
		foreach ($this->_prefixes as $prefix => $value) {
			if (!str_starts_with($key, $prefix) || !isset($value))
				continue;
			$shortKey = mb_substr($key, mb_strlen($prefix));
			if (is_callable($value) && !$value instanceof \Temma\Web\Controller) {
				// it's a callback, execute it
				$this->_data[$key] = $value($this, $shortKey);
			} else {
				// it's hopefully a string
				$this->_data[$key] = $this->_instantiate("$value$shortKey", $default);
			}
			if (isset($this->_data[$key]))
				break;
		}
		if (isset($this->_data[$key])) {
			$item = $this->_data[$key];
			if ($item instanceof LoaderDynamic)
				return ($this->_instantiate($item->closure, $default));
			if ($item instanceof LoaderLazy) {
				$this->_data[$key] = $this->_instantiate($item->closure(), $default);
				return ($this->_data[$key]);
			}
			return ($this->_data[$key]);
		}
		// instantiate the object
		$this->_data[$key] = $this->_instantiate($key, $default);
		return ($this->_data[$key]);
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Instantiate an object.
	 * @param	string|callable	$obj		Object name or callable value.
	 * @param	mixed		$default	(optional) Default value that must be returned is the requested key doesn't exist.
	 * @return	mixed	The associated value, or null.
	 */
	private function _instantiate(string|callable $obj, mixed $default=null) : mixed {
		$result = null;
		// callable
		if (is_callable($obj) && !$obj instanceof \Temma\Web\Controller) {
			try {
				$rf = new \ReflectionFunction($obj);
			} catch (\ReflectionException $re) {
				try {
					$rf = new \ReflectionMethod($obj);
				} catch (\ReflectionException $re) {
					$rf = null;
				}
			}
			if ($rf) {
				// management of attributes
				$attributes = $rf->getAttributes(null, \ReflectionAttribute::IS_INSTANCEOF);
				foreach ($attributes as $attribute) {
					$attribute->newInstance();
				}
				// management of parameters
				$params = $this->_extractFunctionParameters($rf);
				if (!$params) {
					// no parameter
					$result = $obj();
				} else {
					// some parameters
					$paramArray = [];
					foreach ($params as $param) {
						// search value
						$value = null;
						// loop on types to find the value
						foreach ($param['types'] as $type) {
							try {
								if (($value = $this->get($type)))
									break;
							} catch (TµLoaderException $le) {
							}
						}
						// no value fetched from the type, search from the parameter's name
						if ($value === null) {
							if (($val = $this->get($param['name'])) &&
							    (!$param['types'] || self::checkType($val, $param['types'])))
								$value = $val;
						}
						// check if the parameter is nullable
						if ($value === null && !$param['nullable'])
							throw new TµLoaderException("Bad parameter '\$" . $param['name'] . "'.", tµLoaderException::BAD_PARAM);
						// prepare the parameter
						$paramArray[$param['name']] = $value;
					}
					$result = call_user_func_array($obj, $paramArray);
				}
			}
			if (isset($result))
				return ($result);
		}
		// loadable
		if (($interfaces = @class_implements($obj)) && isset($interfaces['Temma\Base\Loadable'])) {
			return (new $obj($this));
		}
		// DAO
		if (is_subclass_of($obj, '\Temma\Dao\Dao') && isset($this->_data['controller']) && $this->_data['controller'] instanceof \Temma\Web\Controller) {
			return ($this->_data['controller']->_loadDao($obj));
		}
		// object instanciation
		try {
			$rc = new \ReflectionClass($obj);
			// check if abstract
			if ($rc->isAbstract())
				throw new TµLoaderException('Abastract class.', TµLoaderException::ABSTRACT_CLASS);
			// management of object's attributes
			$attributes = $rc->getAttributes(null, \ReflectionAttribute::IS_INSTANCEOF);
			for ($parentClass = $rc->getParentClass(); $parentClass; $parentClass = $parentClass->getParentClass())
				$attributes = array_merge($attributes, $parentClass->getAttributes(null, \ReflectionAttribute::IS_INSTANCEOF));
			foreach ($attributes as $attribute) {
				$attribute->newInstance();
			}
			// special process for controllers
			if (is_subclass_of($obj, '\Temma\Web\Controller')) {
				return (new $obj($this, ($this->_data['parentController'] ?? $this->_data['controller'] ?? null)));
			}
			// management of the constructor
			$constructor = $rc->getConstructor();
			if (!$constructor) {
				// no constructor
				return (new $obj());
			}
			// get parameters
			$params = $this->_extractFunctionParameters($constructor);
			if (!$params) {
				// no parameter
				return (new $obj());
			}
			$paramArray = [];
			foreach ($params as $param) {
				// search value
				$value = null;
				// loop on types to find the value
				$param['types'] ??= [];
				foreach ($param['types'] as $type) {
					try {
						if (($val = $this->get($type)) && self::checkType($val, $type)) {
							$value = $val;
							break;
						}
					} catch (TµLoaderException $le) {
					}
				}
				// no value fetched from the type, search from the parameter's name
				if ($value === null &&
				    ($val = $this->get($param['name'])) &&
				    (!$param['types'] || self::checkType($val, $param['types']))) {
					$value = $val;
				}
				// check if the parameter is nullable
				if ($value === null && !$param['nullable'])
					throw new TµLoaderException("Bad parameter '\$" . $param['name'] . "'.", TµLoaderException::BAD_PARAM);
				// prepare the parameter
				$paramArray[$param['name']] = $value;
			}
			return (new $obj(...$paramArray));
		} catch (\ReflectionException $e) {
		}
		// call builder function
		if (isset($this->_builder)) {
			$builder = $this->_builder;
			$result = $builder($this, $obj);
			if (isset($result))
				return ($result);
		}
		// call loader's builder method
		if (method_exists($this, 'builder')) {
			$result = $this->builder($obj);
			if (isset($result))
				return ($result);
		}
		// if the default value is a callback, execute it
		if (is_callable($default) && !$default instanceof \Temma\Web\Controller) {
			$default = $this->_instantiate($default);
		}
		return ($default);
	}
	/**
	 * Returns the parameters list of a function/method.
	 * @param	\ReflectionMethod|\ReflectionFunction	$rf	The reflection object created from the function/method.
	 * @return	array	A list of associative arrays.
	 */
	private function _extractFunctionParameters(\ReflectionMethod|\ReflectionFunction $rf) : array {
		// manage cache
		$id = $rf instanceof ReflectionMethod ?
		      $rf->getDeclaringClass()->getName() . '::' . $rf->getName() :
		      $rf->getName();
		if (isset(self::$_paramCache[$id]))
			return self::$_paramCache[$id];
		// process parameters
		$params = [];
		foreach ($rf->getParameters() as $p) {
			$param = [
				'name' => $p->getName(),
				'types' => [],
				'nullable' => false,
				'hasDefault' => $p->isDefaultValueAvailable(),
				'defaultValue' => $p->isDefaultValueAvailable() ? $p->getDefaultValue() : null,
			];
			$rt = $p->getType();
			if ($rt === null) {
				// noop
			} else if ($rt instanceof \ReflectionUnionType) {
				$nullable = false;
				foreach ($rt->getTypes() as $tt) {
					$param['types'][] = $tt->getName();
					if ($tt->allowsNull() || ($tt->getName() == 'mixed'))
						$param['nullable'] = true;
				}
			} else if ($rt->isBuiltin() || $rt instanceof \ReflectionNamedType) {
				$param['types'][] = $rt->getName();
				$param['nullable'] = $rt->allowsNull() || ($rt->getName() == 'mixed');
			} else if ($rt instanceof \ReflectionIntersectionType) {
				throw new TµLoaderException("Intersection types are not supported for autowiring.", TµLoaderException::UNSUPPORTED_TYPE);
			}
			$params[] = $param;
		}
		self::$_paramCache[$id] = $params;
		return ($params);
	}
	/**
	 * Tell if a variable is of the given type. The type might be complex (null|int|Foo).
	 * @param	mixed		$value	The variable to check.
	 * @param	string|array	$type	The type(s) to validate.
	 * @return	bool	True if the type matches.
	 */
	static public function checkType(mixed $value, string|array $type) : bool {
		if (is_array($type)) {
			foreach ($type as $t) {
				if (self::checkType($value, $t))
					return (true);
			}
			return (false);
		}
		$type = trim($type);
		// nullable prefix: ?T
		if (str_starts_with($type, '?')) {
			return ($value === null || self::checkType($value, substr($type, 1)));
		}
		// union type: null|A|B
		if (str_contains($type, '|')) {
			foreach (explode('|', $type) as $part) {
				if (self::checkType($value, trim($part)))
					return (true);
			}
			return (false);
		}
		// Intersection types: A&B (objets uniquement)
		if (str_contains($type, '&')) {
			foreach (explode('&', $type) as $part) {
				if (!self::checkType($value, trim($part)))
					return (false);
			}
			return (true);
		}
		// scalars and pseudo-types
		$t = strtolower($type);
		switch ($t) {
			case 'int':
			case 'integer':  return (is_int($value));
			case 'string':   return (is_string($value));
			case 'bool':
			case 'boolean':  return (is_bool($value));
			case 'float':
			case 'double':
			case 'real':     return (is_float($value));
			case 'array':    return (is_array($value));
			case 'object':   return (is_object($value));
			case 'callable': return (is_callable($value));
			case 'iterable': return (is_iterable($value));
			case 'resource': return (is_resource($value));
			case 'null':     return (is_null($value));
			case 'scalar':   return (is_scalar($value));     // int|float|string|bool
			case 'numeric':  return (is_numeric($value));    // not a real PHP type
			case 'mixed':    return (true);
		}
		// classes and interfaces
		if (is_object($value)) {
			return (is_a($value, $type));
		}
		// class-string
		if (is_string($value) && (class_exists($value) || interface_exists($value) || enum_exists($value))) {
			return (is_a($value, $type, true));
		}
		return (false);
	}
}

