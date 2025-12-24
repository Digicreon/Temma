<?php

/**
 * Config
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2024, Amaury Bouchard
 */

namespace Temma\Web;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Framework as TµFrameworkException;

/**
 * Object used to store the configuration of a Temma application.
 */
class Config {
	/** Prefix of the local configuration file. */
	const CONFIG_FILE_PREFIX = 'temma';
	/** Environment variable which contains the platform type. */
	const ENV_PLATFORM = 'ENVIRONMENT';
	/** Default log level. */
	const LOG_LEVEL = 'WARN';
	/** Name of the cookie which contains the session ID. */
	const SESSION_NAME = 'TemmaSession';
	/** Default session duration (one year). */
	const SESSION_DURATION = 31536000;
	/** Default secure parameter for sessions. */
	const SESSION_SECURE = false;
	/** Name of the directory which contains the application's configuration files. */
	const ETC_DIR = 'etc';
	/** Name of the log directory. */
	const LOG_DIR = 'log';
	/** Name of the log file. */
	const LOG_FILE = 'temma.log';
	/** Name of the libraries directory. */
	const INCLUDES_DIR = 'lib';
	/** Name of the controllers directory. */
	const CONTROLLERS_DIR = 'controllers';
	/** Name of the views directory. */
	const VIEWS_DIR = 'views';
	/** Name of the templates directory. */
	const TEMPLATES_DIR = 'templates';
	/** Name of the temporary files directory. */
	const TEMP_DIR = 'tmp';
	/** Name of the "var" directory. */
	const VAR_DIR = 'var';
	/** Name of the web directory. */
	const WEB_DIR = 'www';
	/** Name of the default loader (dependency injection container). */
	const DEFAULT_LOADER = '\Temma\Base\Loader';
	/** Name of the default controller. */
	const DEFAULT_CONTROLLER = '\Temma\Web\Controller';
	/** Name of the default view. */
	const DEFAULT_VIEW = '\Temma\Views\Smarty';
	/** Prefix of extended configuration section in the temma.json file. */
	const XTRA_CONFIG_PREFIX = 'x-';
	/** Instance of the real configuration object that is used underneath this one. */
	protected ?\Temma\Web\Config $_executorConfig = null;
	/** List of error pages. */
	protected array $_errorPages = [];
	/** List of data sources. */
	protected ?array $_dataSources = null;
	/** List of routes. */
	protected ?array $_routes = null;
	/** List of plugins. */
	protected ?array $_plugins = [];
	/** Tell if sessions are enabled. */
	protected bool $_enableSessions = true;
	/** Name of the cookie which stores the session ID. */
	protected ?string $_sessionName = null;
	/** Name of the data source used to store session data. */
	protected ?string $_sessionSource = null;
	/** Tell if session cookies must be sent only on HTTPS connections. */
	protected bool $_sessionSecure = false;
	/** Session duration. */
	protected ?int $_sessionDuration = null;
	/**
	 * Session's cookie domain name.
	 * null  = domain is not set, use the default (level 1 domain name of the current host)
	 * false = domain should not be defined, so the navigator will use the strict host
	 * other = use the defined value as cookie domain
	 */
	protected ?string $_cookieDomain = null;
	/** Path to the application root directory. */
	protected ?string $_appPath = null;
	/** Path to the configuration directory. */
	protected ?string $_etcPath = null;
	/** Path to the log directory. */
	protected ?string $_logPath = null;
	/** Path to the temporary directory. */
	protected ?string $_tmpPath = null;
	/** Path to the includes directory. */
	protected ?string $_includesPath = null;
	/** Path to the controllers directory. */
	protected ?string $_controllersPath = null;
	/** Path to the views directory. */
	protected ?string $_viewsPath = null;
	/** Path to the templates directory. */
	protected ?string $_templatesPath = null;
	/** List of paths that must be added to PHP include paths. */
	protected ?array $_pathsToInclude = null;
	/** Path to the "var" directory. */
	protected ?string $_varPath = null;
	/** Path to the web root directory. */
	protected ?string $_webPath = null;
	/** Name of the loader object. */
	protected ?string $_loader = null;
	/** Array of loader preloaded data. */
	protected ?array $_loaderPreload = null;
	/** Array of loader aliases. */
	protected ?array $_loaderAliases = null;
	/** Array of loader prefixes. */
	protected ?array $_loaderPrefixes = null;
	/** Log manager(s). */
	protected null|string|array $_logManager = null;
	/** Definition of log levels. */
	protected null|string|array $_logLevels = null;
	/** Definition of buffering log levels. */
	protected null|string|array $_bufferingLogLevels = null;
	/** Controller names' suffix. */
	protected string $_controllersSuffix = '';
	/** Name of the root controller. */
	protected ?string $_rootController = null;
	/** Name of the default controller. */
	protected ?string $_defaultController = null;
	/** Name of the proxy controller. */
	protected ?string $_proxyController = null;
	/** Default controllers namespace. */
	protected ?string $_defaultNamespace = null;
	/** Default view. */
	protected ?string $_defaultView = null;
	/** Automatically imported configuration variables. */
	protected ?array $_autoimport = null;
	/** Extended configurations. */
	protected array $_extraConfig = [];

	/**
	 * Constructor.
	 * @param	string	$appPath	Path to the application root directory.
	 * @param	?array	$attributes	(optional) Initialization attributes. SHOULD BE USED BY THE __set_state() METHOD ONLY.
	 */
	public function __construct(string $appPath, ?array $attributes=null) {
		$this->_appPath = $appPath;
		$this->_etcPath = $this->_appPath . DIRECTORY_SEPARATOR . self::ETC_DIR;
		// management of initialization attributes
		if ($attributes) {
			foreach ($attributes as $attrName => $attrValue) {
				if (property_exists($this, $attrName)) {
					$this->$attrName = $attrValue;
				}
			}
		}
	}
	/**
	 * Method used to generate a configuration object dynamically.
	 * @see		The 'bin/configObjectGenerator.php' script.
	 * @param	array	$attributes	Associative array which contains the object attributes.
	 * @return	\Temma\Web\Config	The instanciated object.
	 */
	static public function __set_state(array $attributes) : \Temma\Web\Config {
		$conf = new self($attributes['_appPath'], $attributes);
		return ($conf);
	}
	/**
	 * Reads the "temma.json" configuration file.
	 * @param	?string	$forcedConfigPath	(optional) Path to the 'temma.*' file to be used to get the configuration. Defaults to null, to get the default file 'etc/temma.*'.
	 * @param	?array	$overConf		(optional) Associative array that contains data used to override the configuration file.
	 * @throws	\Temma\Exceptions\Framework	If the configuration file is not correct.
	 */
	public function readConfigurationFile(?string $forcedConfigPath=null, ?array $overConf=null) : void {
		// read the configuration file
		if ($forcedConfigPath) {
			try {
				$ini = \Temma\Utils\Serializer::read($forcedConfigPath);
			} catch (\Exception $e) {
				throw new TµFrameworkException("Unable to read configuration file '$forcedConfigPath'.", TµFrameworkException::CONFIG);
			}
		} else {
			// read the base configuration file ('etc/temma.php', 'etc/temma.json', 'etc/temma.yaml' or 'etc/temma.neon')
			$configPath = $this->_etcPath . '/' . self::CONFIG_FILE_PREFIX;
			$ini = null;
			try {
				$ini = \Temma\Utils\Serializer::readFromPrefix($configPath);
			} catch (\Exception $e) { }
			// read the platform-specific configuration file (e.g. 'etc/temma.prod.php', 'etc/temma.staging.json', 'etc/temma.dev.yaml', etc.)
			$envType = getenv(self::ENV_PLATFORM);
			if ($envType) {
				$envConfigPath = "$configPath.$envType";
				try {
					$envIni = \Temma\Utils\Serializer::readFromPrefix($envConfigPath);
					$ini = \Temma\Utils\ExtendedArray::fusion(($ini ?? []), $envIni);
				} catch (\Exception $e) { }
			}
			// verify if the configuration was found
			if (!$ini)
				throw new TµFrameworkException("Unable to read configuration file with prefix '$configPath'.", TµFrameworkException::CONFIG);
		}
		// overload if needed
		if ($overConf)
			$ini = array_replace_recursive($ini, $overConf);

		// path to log file
		$logPath = null;
		if (!array_key_exists('application', $ini) || !array_key_exists('logFile', $ini['application']))
			$logPath = self::LOG_DIR . '/' . self::LOG_FILE;
		else if (is_string($ini['application']['logFile']) && !empty($ini['application']['logFile']))
			$logPath = $ini['application']['logFile'];
		if ($logPath && $logPath[0] != '/')
			$logPath = $this->_appPath . '/' . $logPath;
		$this->_logPath = $logPath;

		// define the error pages
		if (isset($ini['errorPages'])) {
			if (is_string($ini['errorPages']))
				$this->_errorPages['default'] = $ini['errorPages'];
			else if (is_array($ini['errorPages']))
				$this->_errorPages = $ini['errorPages'];
		}

		// define the data sources
		if (isset($ini['application']['dataSources']) && is_array($ini['application']['dataSources']))
			$this->_dataSources = $ini['application']['dataSources'];
		else
			$this->_dataSources = [];

		// add additional include paths
		$pathsToInclude = [];
		// path to the project libraries
		$includesPath = $this->_appPath . '/' . self::INCLUDES_DIR;
		if (is_dir($includesPath)) {
			$pathsToInclude[] = $includesPath;
			$this->_includesPath = $includesPath;
		}
		// path to the controllers
		$controllersPath = $this->_appPath . '/' . self::CONTROLLERS_DIR;
		if (is_dir($controllersPath)) {
			$pathsToInclude[] = $controllersPath;
			$this->_controllersPath = $controllersPath;
		}
		// path to the views
		$viewsPath = $this->_appPath . '/' . self::VIEWS_DIR;
		if (is_dir($viewsPath)) {
			$pathsToInclude[] = $viewsPath;
			$this->_viewsPath = $viewsPath;
		}
		// paths defined in the configuration
		if (isset($ini['includePaths']) && is_array($ini['includePaths']))
			$pathsToInclude = array_merge($pathsToInclude, $ini['includePaths']);
		// namespace paths defined in the configuration
		if (isset($ini['namespacePaths']) && is_array($ini['namespacePaths']))
			$pathsToInclude = array_merge($pathsToInclude, $ini['namespacePaths']);
		$this->_pathsToInclude = $pathsToInclude;

		// default loader
		if (empty($ini['application']['loader']))
			$ini['application']['loader'] = self::DEFAULT_LOADER;
		$this->_loader = $ini['application']['loader'] ?? null;

		// default controller
		$this->_defaultController = ($ini['application']['defaultController'] ?? null) ?: self::DEFAULT_CONTROLLER;
		// default view
		$this->_defaultView = ($ini['application']['defaultView'] ?? null) ?: self::DEFAULT_VIEW;

		// set routes
		if (isset($ini['routes']) && is_array($ini['routes']))
			$this->_routes = $ini['routes'];

		// check plugins
		$this->_plugins = (isset($ini['plugins']) && is_array($ini['plugins'])) ? $ini['plugins'] : [];

		// fetch the extended configuration
		foreach ($ini as $key => $value) {
			if (substr($key, 0, strlen(self::XTRA_CONFIG_PREFIX)) === self::XTRA_CONFIG_PREFIX)
				$this->_extraConfig[$key] = $value;
		}

		// check for the need of loading the sessions
		if (isset($ini['application']['enableSessions']) && $ini['application']['enableSessions'] === false)
			$this->_enableSessions = false;
		// define the session name
		$this->_sessionName = ($ini['application']['sessionName'] ?? null) ?: self::SESSION_NAME;
		// define the data source that stores the sessions
		$this->_sessionSource = ($ini['application']['sessionSource'] ?? null) ?: null;
		// define if session cookies are secured
		$this->_sessionSecure = (isset($ini['application']['sessionSecure']) && is_bool($ini['application']['sessionSecure'])) ? $ini['application']['sessionSecure'] : self::SESSION_SECURE;
		// define session duration
		$this->_sessionDuration = ($ini['application']['sessionDuration'] ?? null) ?: self::SESSION_DURATION;
		// define session's cookie domain name
		// if the domain is not defined in the configuration, the variable is set to null
		// if the domain is set in the configuration, but empty (empty string, false or null), the variable is set to false
		// if the domain is set in the configuration and not empty, its value is set in the variable
		if (array_key_exists('cookieDomain', $ini['application'])) {
			if (is_string($ini['application']['cookieDomain']))
				$ini['application']['cookieDomain'] = trim($ini['application']['cookieDomain']);
			$this->_cookieDomain = $ini['application']['cookieDomain'] ?: false;
		}

		// definitions
		$this->_loaderPreload = $ini['x-loader']['preload'] ?? null;
		$this->_loaderAliases = $ini['x-loader']['aliases'] ?? null;
		$this->_loaderPrefixes = $ini['x-loader']['prefixes'] ?? null;
		$this->_logManager = $ini['application']['logManager'] ?? null;
		$this->_logLevels = $ini['loglevels'] ?? null;
		$this->_bufferingLogLevels = $ini['bufferingLoglevels'] ?? null;
		$this->_tmpPath = $this->_appPath . '/' . self::TEMP_DIR;
		$this->_varPath = $this->_appPath . '/' . self::VAR_DIR;
		$this->_templatesPath = $this->_appPath . '/' . self::TEMPLATES_DIR;
		$this->_webPath = $this->_appPath . '/' . self::WEB_DIR;
		$this->_controllersSuffix = $ini['application']['controllersSuffix'] ?? '';
		$this->_rootController = $ini['application']['rootController'] ?? null;
		$this->_defaultNamespace = $ini['application']['defaultNamespace'] ?? null;
		$this->_proxyController = $ini['application']['proxyController'] ?? null;
		$this->_autoimport = $ini['autoimport'] ?? null;

		// add include paths
		$this->_initIncludePaths();
	}
	/**
	 * Getter. Returns any requested configuration value.
	 * @param	string	$name	Name of the configuration property.
	 * @return	mixed	The associated value.
	 */
	public function __get(string $name) : mixed {
		if ($this->_executorConfig)
			return ($this->_executorConfig->$name);
		$name = '_' . $name;
		return ($this->$name);
	}
	/**
	 * Setter. Defines a configuration value.
	 * @param	string	$name	Name of the property to set.
	 * @param	mixed	$value	Value of the property.
	 */
	public function __set(string $name, mixed $value) : void {
		if ($this->_executorConfig) {
			$this->_executorConfig->$name = $value;
			return;
		}
		$name = '_' . $name;
		$this->$name = $value;
	}
	/**
	 * Tell if a configuration variable exists.
	 * @param	string	$name	Property name.
	 * @return	bool	True if the variable exists.
	 */
	public function __isset(string $name) : bool {
		if ($this->_executorConfig)
			return isset($this->_executorConfig->$name);
		$name = '_' . $name;
		return (isset($this->$name));
	}
	/**
	 * Getter for the automatically imported configuration variables.
	 * @param	string	$key		Key of the sub-element.
	 * @param	mixed	$default	(optional) Default value if the element doesn't exists.
	 * @return	mixed	The associated value.
	 */
	public function autoimport(string $key, mixed $default=null) : mixed {
		if ($this->_executorConfig)
			return $this->_executorConfig->autoimport($key, $default);
		if (isset($this->_autoimport[$key]))
			return ($this->_autoimport[$key]);
		return ($default);
	}
	/**
	 * Getter for the extended configuration.
	 * @param	string	$name		Name of the extended configuration (without the "x-" prefix).
	 * @param	string	$key		(optional) Name of the element. If not set, the whole extended configuration will be returned.
	 * @param	mixed	$default	(optionnel) Default value. Not used if the $key parameter is null.
	 * @return	mixed	The requested value, or the whole extended configuration.
	 */
	public function xtra(string $name, ?string $key=null, mixed $default=null) : mixed {
		if ($this->_executorConfig)
			return $this->_executorConfig->xtra($name, $key, $default);
		if (is_null($key)) {
			if (isset($this->_extraConfig["x-$name"]))
				return ($this->_extraConfig["x-$name"]);
			return (null);
		}
		$result = null;
		if (isset($this->_extraConfig["x-$name"][$key]))
			$result = $this->_extraConfig["x-$name"][$key];
		return ((!is_null($default) && is_null($result)) ? $default : $result);
	}
	/**
	 * Setter for extended configuration.
	 * @param	string	$name	Name of the extended configuration (without the "x-" prefix).
	 * @param	string	$key	Name of the element that must be added to the extended configuration.
	 * @param	mixed	$value	Associated value.
	 */
	public function setXtra(string $name, string $key, mixed $value) : void {
		if ($this->_executorConfig) {
			$this->_executorConfig->setXtra($name, $key, $value);
			return;
		}
		if (!isset($this->_extraConfig["x-$name"]))
			$this->_extraConfig["x-$name"] = [];
		$this->_extraConfig["x-$name"][$key] = $value;
	}
	/** Initialize the PHP include paths when the configuration is read. */
	protected function _initIncludePaths() : void {
		$pathsToInclude = $this->_executorConfig ? $this->_executorConfig->_pathsToInclude : $this->_pathsToInclude;
		if ($pathsToInclude)
			\Temma\Base\Autoload::addIncludePath($pathsToInclude);
	}
}

