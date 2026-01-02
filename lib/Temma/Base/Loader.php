<?php

/**
 * Loader
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2019-2026, Amaury Bouchard
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
class LoaderDynamic {
	/**
	 * Constructor.
	 * @param	\Closure	$closure	Internal closure, used as a factory.
	 */
	public function __construct(public readonly \Closure $closure) {
	}
}
/**
 * Class used to make the difference between aliases and factories.
 */
class LoaderAlias extends LoaderDynamic {
}

/* ********** DEPENDENCY INJECTION CONTAINER ********** */

/**
 * Dependency injection container.
 *
 * Examples:
 * ```php
 * // create a loader
 * $loader = new \Temma\Base\Loader();
 * // create a loader and set initial data
 * $loader = new \Temma\Base\Loader([
 *     'userBo'          => $userBo,
 *     'cardBo'          => $cardBo,
 *     '\MyApp\MainCtrl' => new \MyApp\MainCtrl(),
 * ]);
 * ```
 *
 * Read stored values:
 * ```php
 * // use the loader with an object-oriented syntax
 * $user = $loader->userBo->getFromId(12);
 *
 * // use the loader with an array-like syntax
 * $loader['\MyApp\MainCtrl']->startCountDown();
 *
 * // explicit retrieval, with a given default value
 * $value = $loader->get('user', $defaultUser);
 * ```
 *
 * Add values in the loader:
 * ```php
 * // using object-oriented syntax
 * $loader->walletBo = new \MyApp\WalletBo();
 *
 * // using array-like syntax
 * $loader['walletBo'] = $walletBo;
 *
 * // using explicit syntax
 * $loader->set('walletBo', $walletBo);
 *
 * // add multiple values at once
 * $loader->set([
 *     'walletBo' => $walletBo,
 *     'userDao'  => new UserDao(),
 * ]);
 * ```
 *
 * Lazy instantiation:
 * ```php
 * // Define a callback to create an object later on the fly.
 * // The closure will be executed once, and the result will be stored.
 * $loader->userDao = function($loader) {
 *     return new \MyApp\UserDao($loader->db);
 * };
 *
 * // use the callback transparently
 * $loader->userDao->createUser('John Rambo');
 * ```
 *
 * Dynamic instantiation:
 * ```php
 * // Define a dynamic value.
 * // The closure will be executed each time the object is requested.
 * $loader->dynamic('time', function() {
 *     return time();
 * });
 * // display the current time
 * print($loader->time);
 *
 * // define multiple dynamic values at once
 * $loader->dynamic([
 *     'time' => fn() => time(),
 *     'user' => function() use ($loader) {
 *         return $loader->userDao->getLastUser();
 *     },
 * ]);
 * ```
 *
 * Aliases:
 * ```php
 * // Define an alias.
 * // The aliased element will be returned.
 * $loader->alias('UserService', 'UserBo');
 *
 * // define multiple aliases at once
 * $loader->alias([
 *     'UserService'   => 'UserBo',
 *     'WalletService' => 'WalletBo',
 * ]);
 * ```
 *
 * Autowiring:
 * ```php
 * class UserBo {
 *     // $userDao is automatically injected when UserBo is instantiated.
 *     // If there is no 'UserDao' value in the loader, it will be instantiated on the fly.
 *     public function __construct(private UserDao $userDao) {
 *     }
 *     public function addFriends(int $fromId, int $toId) : void {
 *         $from = $this->userDao->getFromId($fromId);
 *         $to = $this->userDao->getFromId($toId);
 *         if (!$from['canBeFriend'] || !$to['canBeFriend'])
 *             throw new Exception("Users can't be friends.");
 *         $this->userDao->setFriend($fromId, $toId);
 *     }
 * }
 * $loader = new \Temma\Base\Loader(['UserDao' => $userDao]);
 * $loader->UserBo->addFriends(12, 21);
 * ```
 *
 * Prefixes:
 * ```php
 * // define a prefix
 * $loader->prefix('App', '\App\Source\Global');
 *
 * // use the prefix
 * $object = $loader->AppUser;
 * // is equivalent to
 * $object = $loader['\App\Source\GlobalUser'];
 *
 * // another prefix
 * $loader->prefix('Ctrl', '\App\Controllers\\');
 *
 * // use the prefix
 * $object = $loader->CtrlUser;
 * // is equivalent to
 * $object = $loader['\App\Controllers\User'];
 *
 * // define multiple prefixes at once
 * $loader->prefix([
 *     'App'  => '\App\Source\Global',
 *     'Ctrl' => '\App\Controllers\\',
 * ]);
 * ```
 *
 * Builder:
 * ```php
 * // define a builder function
 * $loader->setBuilder(function($loader, $key) {
 *     // instantiate objects depending on their suffix
 *     if (str_ends_with($key, 'Bo')) {
 *         // e.g. UserBo => \MyApp\Bo\User
 *         $classname = mb_substr($key, 0, -2);
 *         return new \MyApp\Bo\$classname($loader);
 *     } else if (str_ends_with($key, 'Dao')) {
 *         // e.g. UserDao => \MyApp\Dao\User
 *         $classname = mb_substr($key, 0, -3);
 *         return new \MyApp\Dao\$classname($loader);
 *     }
 * });
 * // use the builder
 * $loader->userBo->addFriends(12, 21);
 * $user = $loader->userDao->getFromId(17);
 * $card = $loader->cardDao->getFromCode('12ax7');
 *
 * // create a loader, set its initial data, and define a builder
 * $loader = new \Temma\Base\Loader(
 *     ['userBo' => $userBo],
 *     function($loader, $key) {
 *         return new $key($loader);
 *     }
 * );
 *
 * // It is also possible to create a child class with a defined builder:
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
 * $loader->userBo->addFriends(12, 21);
 * ```
 */
class Loader extends \Temma\Utils\Registry {
	/** @var ?callable Builder callback. */
	protected /*?callable*/ $_builder = null;
	/** @var array<string,string> Prefix map. */
	protected array $_prefixes = [];
	/** Static cache for reflection parameter analysis. */
	private static array $_paramCache = [];
	/** Stack for circular dependency detection. */
	private array $_loadingStack = [];

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

	/* ********** FACTORIES, ALIASES AND PREFIXES ********** */
	/**
	 * Add a dynamic parameter (factory pattern), or a list of dynamic parameters.
	 * The closure will be executed each time the data is requested.
	 * @param	string|array	$key		Index key, or associative array of keys and closures.
	 * @param	?\Closure	$closure	(optional) Closure to use as a factory. Could be null to remove the factory.
	 * @return	\Temma\Base\Loader	The current object.
	 */
	public function dynamic(string|array $key, ?\Closure $closure=null) : \Temma\Base\Loader {
		if (is_array($key)) {
			foreach ($key as $dynKey => $closure) {
				$this->dynamic($dynKey, $closure);
			}
			return ($this);
		}
		if (is_null($closure)) {
			// remove the previously created factory
			$value = $this->_data[$key] ?? null;
			if ($value instanceof LoaderDynamic && !$value instanceof LoaderAlias)
				unset($this->_data[$key]);
			return ($this);
		}
		// add the factory as a LoaderDynamic-protected closure
		$this->_data[$key] = new LoaderDynamic($closure);
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
	public function alias(string|array $alias, ?string $aliased=null) : \Temma\Base\Loader {
		if (is_array($alias)) {
			foreach ($alias as $aliasKey => $aliased) {
				$this->alias($aliasKey, $aliased);
			}
			return ($this);
		}
		if (is_null($aliased)) {
			// remove the previously created alias
			if (($this->_data[$alias] ?? null) instanceof LoaderAlias)
				unset($this->_data[$alias]);
			return ($this);
		}
		// add the alias as a LoaderDynamic-protected closure
		$this->_data[$alias] = new LoaderAlias(fn() => $this->get($aliased));
		return ($this);
	}
	/**
	 * Add a prefix, or a list of prefixes.
	 * @param	string|array	$name	If a string is given, it's the name of the prefix, and a prefix should be given.
	 *					If an array is given, its key/value pairs are used to set prefixes, and the
	 *					second parameter is not used.
	 * @param	?string		$prefix	Prefixed namespace. Could be null to remove a prefix.
	 * @return	\Temma\Base\Loader	The current object.
	 */
	public function prefix(string|array $name, ?string $prefix=null) : \Temma\Base\Loader {
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
	 * @param	string	$key			Index key.
	 * @param	mixed	$default		(optional) Default value that must be returned if the requested key doesn't exist.
	 * @param	bool	$autoInstantiate	(optional) True to instantiate the object if it doesn't exist. False to return the default value.
	 * @return	mixed	The associated value, or null.
	 */
	public function get(string $key, mixed $default=null, bool $autoInstantiate=true) : mixed {
		// special cases (Asynk and TµLoader)
		if ($key == 'asynk')
			return (new \Temma\Asynk\Client($this));
		if ($key == '\Temma\Base\Loader' || $key == 'TµLoader')
			return ($this);
		// circular dependency check
		if (isset($this->_loadingStack[$key])) {
			throw new TµLoaderException("Circular dependency detected for key: '$key'.", TµLoaderException::CIRCULAR_DEPENDENCY);
		}
		$this->_loadingStack[$key] = true;
		try {
			// key exists
			if (isset($this->_data[$key])) {
				$item = $this->_data[$key];
				// is it a factory (closure called each time the value is asked)?
				if ($item instanceof LoaderDynamic)
					return ($this->_instantiate($item->closure, $default));
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
				return ($this->_data[$key]);
			}
			// check if auto-instantiation is requested
			if (!$autoInstantiate)
				return ($default);
			// instantiate the object
			$this->_data[$key] = $this->_instantiate($key, $default);
			return ($this->_data[$key]);
		} finally {
			// remove the key from the circular dependency stack
			unset($this->_loadingStack[$key]);
		}
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Instantiate an object.
	 * @param	string|callable	$obj		Object name or callable value.
	 * @param	mixed		$default	(optional) Default value that must be returned is the requested key doesn't exist.
	 * @return	mixed	The associated value, or null.
	 */
	private function _instantiate(string|callable|object $obj, mixed $default=null) : mixed {
		// callable
		if (is_callable($obj) && !$obj instanceof \Temma\Web\Controller) {
			$result = $this->_instantiateCallable($obj);
			if (isset($result))
				return ($result);
		}
		// loadable
		if (is_string($obj) && ($interfaces = @class_implements($obj)) && isset($interfaces[\Temma\Base\Loadable::class])) {
			return (new $obj($this));
		}
		// DAO
		if (is_string($obj) && is_subclass_of($obj, \Temma\Dao\Dao::class) &&
		    isset($this->_data['controller']) && $this->_data['controller'] instanceof \Temma\Web\Controller) {
			return ($this->_data['controller']->_loadDao($obj));
		}
		// object instantiation
		if ((is_string($obj) && class_exists($obj)) || is_object($obj)) {
			$result = $this->_instantiateClass($obj);
			if (isset($result))
				return ($result);
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
	 * Helper to instantiate a callable (Closure or function).
	 * @param	callable	$obj	The callable to instantiate.
	 * @return	mixed	The result of the callable execution, or null on error.
	 */
	private function _instantiateCallable(callable $obj) : mixed {
		try {
			// Detect the type of callable to create the correct Reflection object
			if (is_array($obj)) {
				// Array callable: [$objectOrClass, 'method']
				$rf = new \ReflectionMethod($obj[0], $obj[1]);
			} else if (is_string($obj) && str_contains($obj, '::')) {
				// String static call: "Class::method"
				$rf = new \ReflectionMethod($obj);
			} else if (is_object($obj) && !$obj instanceof \Closure) {
				// Invokable object (has __invoke)
				$rf = new \ReflectionMethod($obj, '__invoke');
			} else {
				// Standard function or Closure
				$rf = new \ReflectionFunction($obj);
			}
		} catch (\ReflectionException $re) {
			return (null);
		}
		// management of attributes
		$this->_triggerActiveAttributes($rf);
		// management of parameters
		$paramArray = $this->_resolveDependencies($rf);
		if ($paramArray === null) {
			// no parameter
			return ($obj());
		}
		return (call_user_func_array($obj, $paramArray));
	}
	/**
	 * Helper to instantiate a class via Reflection.
	 * @param	string|object	$obj	Class name or object instance.
	 * @return	mixed	The instantiated object or null on error.
	 */
	private function _instantiateClass(string|object $obj) : mixed {
		try {
			$rc = new \ReflectionClass($obj);
			// check if abstract
			if ($rc->isAbstract())
				throw new TµLoaderException('Abstract class.', TµLoaderException::ABSTRACT_CLASS);
			// management of object's attributes
			$this->_triggerActiveAttributes($rc);
			// special process for controllers
			if ($rc->isSubclassOf(\Temma\Web\Controller::class)) {
				return (new ($rc->getName())($this, ($this->_data['parentController'] ?? $this->_data['controller'] ?? null)));
			}
			// management of the constructor
			$constructor = $rc->getConstructor();
			if (!$constructor) {
				// no constructor
				$className = $rc->getName();
				return (new $className());
			}
			// get parameters
			$paramArray = $this->_resolveDependencies($constructor);
			$className = $rc->getName();
			if ($paramArray === null) {
				// no parameter
				return (new $className());
			}
			return (new $className(...$paramArray));
		} catch (\ReflectionException $e) {
			return (null);
		}
	}
	/**
	 * Resolves parameters for dependencies.
	 * @param	\ReflectionFunction|\ReflectionMethod	$rf	Reflection object.
	 * @return	?array	Associative array of parameters (name => value), or null if no parameters.
	 */
	private function _resolveDependencies(\ReflectionFunction|\ReflectionMethod $rf) : ?array {
		$params = $this->_extractFunctionParameters($rf);
		if (!$params)
			return (null);
		// process parameters
		$paramArray = [];
		foreach ($params as $param) {
			$value = null;
			$param['types'] ??= [];
			// Try explicitly defined type (without auto-instantiation).
			// Scalar types are not processed ($param['types'] is an empty array).
			foreach ($param['types'] as $type) {
				$val = $this->get($type, null, false);
				if ($val !== null && is_a($val, $type)) {
					$value = $val;
					break;
				}
			}
			// Try parameter name (without auto-instantiation).
			// This is valid for scalars (e.g. dependency injection of a config parameter named 'apiKey').
			if ($value === null) {
				$val = $this->get($param['name'], null, false);
				if ($val !== null) {
					if (!$param['types']) {
						$value = $val;
					} else {
						foreach ($param['types'] as $type) {
							if (is_a($val, $type)) {
								$value = $val;
								break;
							}
						}
					}
				}
			}
			// Fallback: try type with auto-instantiation. Skip this for scalars too.
			$instantiationError = null;
			if ($value === null) {
				foreach ($param['types'] as $type) {
					try {
						// call get() with auto-instantiation enabled
						$val = $this->get($type, null, true);
						if ($val !== null && is_a($val, $type)) {
							$value = $val;
							break;
						}
					} catch (TµLoaderException $le) {
						// we keep the error in case of total failure
						$instantiationError = $le;
					}
				}
			}
			// use default value if nothing found
			if ($value === null && $param['hasDefault'])
				$value = $param['defaultValue'];
			// check if the parameter is nullable
			if ($value === null && !$param['nullable'])
				throw new TµLoaderException("Unable to resolve parameter '\$" . $param['name'] . "'.", TµLoaderException::BAD_PARAM, $instantiationError);
			// prepare the parameter
			$paramArray[$param['name']] = $value;
		}
		return ($paramArray);
	}
	/**
	 * Trigger active attributes for a reflection object.
	 * Attributes are processed only for controllers (class or methods).
	 * @param	\ReflectionClass|\ReflectionFunction|\ReflectionMethod	$ref	Reflection object.
	 */
	private function _triggerActiveAttributes(\ReflectionClass|\ReflectionFunction|\ReflectionMethod $ref) : void {
		// retrieve the class name
		if ($ref instanceof \ReflectionClass) {
			// get object name
			$class = $ref->getName();
		} else if ($ref instanceof \ReflectionMethod) {
			// get the declaring object name
			$class = $ref->getDeclaringClass()->getName();
		} else {
			// function (=closure) can't be a controller
			return;
		}
		// check if the class is a controller
		if (!is_subclass_of($class, \Temma\Web\Controller::class))
			return;
		// fetch the attributes
		$attributes = $ref->getAttributes(\Temma\Web\Attribute::class, \ReflectionAttribute::IS_INSTANCEOF);
		if ($ref instanceof \ReflectionClass) {
			// for objects (not methods nor functions), check parent classes attributes
			for ($parent = $ref->getParentClass(); $parent; $parent = $parent->getParentClass()) {
				$parentAttributes = $parent->getAttributes(\Temma\Web\Attribute::class, \ReflectionAttribute::IS_INSTANCEOF);
				$attributes = array_merge($attributes, $parentAttributes);
			}
		}
		// process the attributes
		foreach ($attributes as $attribute) {
			// instantiate the attribute
			$instance = $attribute->newInstance();
			// initialize the attribute
			$instance->init($this);
			// apply the attribute
			$instance->apply($ref);
		}
	}
	/**
	 * Returns the parameters list of a function/method.
	 * @param	\ReflectionMethod|\ReflectionFunction	$rf	The reflection object created from the function/method.
	 * @return	array	A list of associative arrays.
	 */
	private function _extractFunctionParameters(\ReflectionMethod|\ReflectionFunction $rf) : array {
		// manage cache key generation
		if ($rf instanceof \ReflectionMethod) {
			$id = $rf->getDeclaringClass()->getName() . '::' . $rf->getName();
		} else {
			// for closures, getName() returns "{closure}", causing collisions. We add file/line info.
			$id = $rf->getName();
			if ($id === '{closure}')
				$id .= ':' . $rf->getFileName() . ':' . $rf->getStartLine();
		}
		// check cache
		if (isset(self::$_paramCache[$id]))
			return (self::$_paramCache[$id]);
		// list of scalar types that should not be looked up in the loader (as types) nor instantiated
		static $scalars = [
			'void'     => true, 'never'    => true,
			'null'     => true,
			'false'    => true, 'true'     => true,
			'bool'     => true, 'boolean'  => true,
			'int'      => true, 'integer'  => true,
			'float'    => true, 'double'   => true,
			'string'   => true,
			'array'    => true, 'iterable' => true,
			'object'   => true, 'callable' => true,
			'resource' => true,
			'mixed'    => true,
		];
		// process parameters
		$params = [];
		foreach ($rf->getParameters() as $p) {
			$param = [
				'name'         => $p->getName(),
				'types'        => [],
				'nullable'     => $p->allowsNull(),
				'hasDefault'   => $p->isDefaultValueAvailable(),
				'defaultValue' => $p->isDefaultValueAvailable() ? $p->getDefaultValue() : null,
			];
			$rt = $p->getType();
			if ($rt === null) {
				// noop
			} else if ($rt instanceof \ReflectionUnionType) {
				foreach ($rt->getTypes() as $tt) {
					// don't keep the type if it's a scalar
					if (!isset($scalars[$tt->getName()]))
						$param['types'][] = $tt->getName();
				}
			} else if ($rt instanceof \ReflectionNamedType) {
				// don't keep the type if it's a scalar
				if (!isset($scalars[$rt->getName()]))
					$param['types'][] = $rt->getName();
			} else if ($rt instanceof \ReflectionIntersectionType) {
				throw new TµLoaderException("Intersection types are not supported by autowiring.", TµLoaderException::UNSUPPORTED_TYPE);
			}
			$params[] = $param;
		}
		self::$_paramCache[$id] = $params;
		return ($params);
	}
}

