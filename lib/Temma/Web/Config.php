<?php

/**
 * Config
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2019, Amaury Bouchard
 */

namespace Temma\Web;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Framework as TµFrameworkException;

/**
 * Object used to store the configuration of a Temma application.
 */
class Config {
	/** Name of the local configuration file (JSON format). */
	const JSON_CONFIG_FILE_NAME = 'temma.json';
	/** Name of the local configuration file (PHP format). */
	const PHP_CONFIG_FILE_NAME = 'temma.php';
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
	/** The controller object generated by the configuration, used by the framework as the "executor controller". */
	protected $_executorController = null;
	/** List of error pages. */
	protected $_errorPages = null;
	/** List of data sources. */
	protected $_dataSources = null;
	/** List of routes. */
	protected $_routes = null;
	/** List of plugins. */
	protected $_plugins = null;
	/** Tell if sessions are enabled. */
	protected $_enableSessions = null;
	/** Name of the cookie which stores the session ID. */
	protected $_sessionName = null;
	/** Name of the data source used to store session data. */
	protected $_sessionSource = null;
	/** Tell if session cookies must be sent only on HTTPS connections. */
	protected $_sessionSecure = null;
	/** Session duration. */
	protected $_sessionDuration = null;
	/** Path to the application root directory. */
	protected $_appPath = null;
	/** Path to the configuration directory. */
	protected $_etcPath = null;
	/** Path to the log directory. */
	protected $_logPath = null;
	/** Path to the temporary directory. */
	protected $_tmpPath = null;
	/** Path to the includes directory. */
	protected $_includesPath = null;
	/** Path to the controllers directory. */
	protected $_controllersPath = null;
	/** Path to the views directory. */
	protected $_viewsPath = null;
	/** Path to the templates directory. */
	protected $_templatesPath = null;
	/** Path to the "var" directory. */
	protected $_varPath = null;
	/** Path to the web root directory. */
	protected $_webPath = null;
	/** Name of the loader object. */
	protected $_loader = null;
	/** Path to the log file. */
	protected $_logFile = null;
	/** Log manager(s). */
	protected $_logManager = null;
	/** Definition of log levels. */
	protected $_logLevels = null;
	/** Controller names' suffix. */
	protected $_controllersSuffix = null;
	/** Name of the root controller. */
	protected $_rootController = null;
	/** Name of the default controller. */
	protected $_defaultController = null;
	/** Name of the proxy controller. */
	protected $_proxyController = null;
	/** Default controllers namespace. */
	protected $_defaultNamespace = null;
	/** Default view. */
	protected $_defaultView = null;
	/** Automatically imported configuration variables. */
	protected $_autoimport = null;
	/** Extended configurations. */
	protected $_extraConfig = null;

	/**
	 * Constructor.
	 * @param	string	$appPath	Path to the application root directory.
	 */
	public function __construct(string $appPath) {
		$this->_appPath = $appPath;
		$this->_etcPath = $this->_appPath . '/' . self::ETC_DIR;
	}
	/**
	 * Reads the "temma.json" configuration file.
	 * @param	?array	$overConf	(optional) Associative array that contains data used to override the configuration file.
	 * @throws	\Temma\Exceptions\Framework	If the configuration file is not correct.
	 */
	public function readConfigurationFile(?array $overConf=null) : void {
		// load the configuration file
		$phpConfigPath = $this->_etcPath . '/' . self::PHP_CONFIG_FILE_NAME;
		$jsonConfigPath = $this->_etcPath . '/' . self::JSON_CONFIG_FILE_NAME;
		// try to include the PHP configuration file
		if (!@include($phpConfigPath) || !isset($_globalTemmaConfig) || !is_array($_globalTemmaConfig)) {
			// try to read the JSON configuration file
			$_globalTemmaConfig = json_decode(file_get_contents($jsonConfigPath), true);
		}
		if (is_null($_globalTemmaConfig))
			throw new TµFrameworkException("Unable to read configuration file '$jsonConfigPath'.", TµFrameworkException::CONFIG);
		$ini = $_globalTemmaConfig;
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

		// define the error pages
		$this->_errorPages = [];
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
		if (is_dir($includesPath))
			$pathsToInclude[] = $includesPath;
		else
			$includesPath = null;
		// path to the controllers
		$controllersPath = $this->_appPath . '/' . self::CONTROLLERS_DIR;
		if (is_dir($controllersPath))
			$pathsToInclude[] = $controllersPath;
		else
			$controllersPath = null;
		// path to the views
		$viewsPath = $this->_appPath . '/' . self::VIEWS_DIR;
		if (is_dir($viewsPath))
			$pathsToInclude[] = $viewsPath;
		else
			$viewsPath = null;
		// paths defined in the configuration
		if (isset($ini['includePaths']) && is_array($ini['includePaths']))
			$pathsToInclude = array_merge($pathsToInclude, $ini['includePaths']);
		// namespace paths defined in the configuration
		if (isset($ini['namespacePaths']) && is_array($ini['namespacePaths']))
			$pathsToInclude = array_merge($pathsToInclude, $ini['namespacePaths']);
		// add additional include paths
		if (!empty($pathsToInclude))
			\Temma\Base\Autoload::addIncludePath($pathsToInclude);

		// check the default loader
		if (empty($ini['application']['loader']))
			$ini['application']['loader'] = self::DEFAULT_LOADER;

		// check the default controller
		if (empty($ini['application']['defaultController']))
			$ini['application']['defaultController'] = self::DEFAULT_CONTROLLER;

		// check the default view
		if (empty($ini['application']['defaultView']))
			$ini['application']['defaultView'] = self::DEFAULT_VIEW;

		// check routes
		if (isset($ini['routes']) && is_array($ini['routes']))
			$this->_routes = $ini['routes'];

		// check plugins
		$this->_plugins = (isset($ini['plugins']) && is_array($ini['plugins'])) ? $ini['plugins'] : [];

		// fetch the extended configuration
		$this->_extraConfig = [];
		foreach ($ini as $key => $value) {
			if (substr($key, 0, strlen(self::XTRA_CONFIG_PREFIX)) === self::XTRA_CONFIG_PREFIX)
				$this->_extraConfig[$key] = $value;
		}

		// check for the need of loading the sessions
		$this->_enableSessions = true;
		if (isset($ini['application']['enableSessions']) && $ini['application']['enableSessions'] === false)
			$this->_enableSessions = false;
		// define the session name
		$this->_sessionName = (isset($ini['application']['sessionName']) && !empty($ini['application']['sessionName'])) ? $ini['application']['sessionName'] : self::SESSION_NAME;
		// define the data source that stores the sessions
		$this->_sessionSource = (isset($ini['application']['sessionSource']) && !empty($ini['application']['sessionSource'])) ? $ini['application']['sessionSource'] : null;
		// define if session cookies are secured
		$this->_sessionSecure = (isset($ini['application']['sessionSecure']) && is_bool($ini['application']['sessionSecure'])) ? $ini['application']['sessionSecure'] : self::SESSION_SECURE;
		// define session duration
		$this->_sessionDuration = (isset($ini['application']['sessionDuration']) && !empty($ini['application']['sessionDuration'])) ?
		                          $ini['application']['sessionDuration'] : self::SESSION_DURATION;

		// definitions
		$this->_logPath = $logPath;
		$this->_logManager = $ini['application']['logManager'] ?? null;
		$this->_logLevels = $ini['loglevels'] ?? null;
		$this->_tmpPath = $this->_appPath . '/' . self::TEMP_DIR;
		$this->_varPath = $this->_appPath . '/' . self::VAR_DIR;
		$this->_includesPath = $includesPath;
		$this->_controllersPath = $controllersPath;
		$this->_viewsPath = $viewsPath;
		$this->_templatesPath = $this->_appPath . '/' . self::TEMPLATES_DIR;
		$this->_webPath = $this->_appPath . '/' . self::WEB_DIR;
		$this->_loader = $ini['application']['loader'] ?? null;
		$this->_logFile = $logPath;
		$this->_controllersSuffix = $ini['application']['controllersSuffix'] ?? '';
		$this->_rootController = $ini['application']['rootController'] ?? null;
		$this->_defaultController = $ini['application']['defaultController'] ?? null;
		$this->_defaultNamespace = $ini['application']['defaultNamespace'] ?? null;
		$this->_proxyController = $ini['application']['proxyController'] ?? null;
		$this->_defaultView = $ini['application']['defaultView'] ?? null;
		$this->_autoimport = $ini['autoimport'] ?? null;
	}
	/**
	 * Getter. Returns any requested configuration value.
	 * @param	string	$name	Name of the configuration property.
	 * @return	mixed	The associated value.
	 */
	public function __get(string $name) /* : mixed */ {
		$name = '_' . $name;
		return ($this->$name);
	}
	/**
	 * Setter. Defines a configuration value.
	 * @param	string	$name	Name of the property to set.
	 * @param	mixed	$value	The associated value.
	 */
	public function __set(string $name, /* mixed */ $value) /* : mixed */ {
		$name = '_' . $name;
		$this->$name = $value;
	}
	/**
	 * Tell if a configuration variable exists.
	 * @param	string	$name	Property name.
	 * @return	bool	True if the variable exists.
	 */
	public function __isset(string $name) : bool {
		$name = '_' . $name;
		return (isset($this->$name));
	}
	/**
	 * Getter for the automatically imported configuration variables.
	 * @param	string	$key		Key of the sub-element.
	 * @param	mixed	$default	(optional) Default value if the element doesn't exists.
	 * @return	mixed	The associated value.
	 */
	public function autoimport(string $key, /* mixed */ $default=null) /* : mixed */ {
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
	public function xtra(string $name, ?string $key=null, /* mixed */ $default=null) /* : mixed */ {
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
	public function setXtra(string $name, string $key, /* mixed */ $value) : void {
		if (!isset($this->_extraConfig["x-$name"]))
			$this->_extraConfig["x-$name"] = [];
		$this->_extraConfig["x-$name"][$key] = $value;
	}
}

