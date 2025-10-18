<?php

/**
 * Framework
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2023, Amaury Bouchard
 */

namespace Temma\Web;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Flow as TµFlowException;
use \Temma\Exceptions\Http as TµHttpException;
use \Temma\Exceptions\Framework as TµFrameworkException;
use \Temma\Exceptions\IO as TµIOException;

/**
 * Main framework management object.
 *
 * @see		\Temma\Web\Controller
 * @see		\Temma\Web\View
 */
class Framework {
	/** Version number of Temma's last tagged release. */
	const TEMMA_VERSION = '2.12.1';
	/** Name of the root action. */
	const CONTROLLERS_ROOT_ACTION = '__invoke';
	/** Name of the proxy action. */
	const CONTROLLERS_PROXY_ACTION = '__proxy';
	/** Old name of the proxy action. */
	const CONTROLLERS_OLD_PROXY_ACTION = '__clone';
	/** Name of the default action. */
	const CONTROLLERS_DEFAULT_ACTION = '__call';
	/** Name of controllers' init method. */
	const CONTROLLERS_INIT_METHOD = '__wakeup';
	/** Name of controllers' finalize method. */
	const CONTROLLERS_FINALIZE_METHOD = '__sleep';
	/** Name of plugins' preplugin method. */
	const PLUGINS_PREPLUGIN_METHOD = 'preplugin';
	/** Name of plugins' postplugin method. */
	const PLUGINS_POSTPLUGIN_METHOD = 'postplugin';
	/** Name of plugins' default plugin method. */
	const PLUGINS_PLUGIN_METHOD = 'plugin';
	/** Default extension of template files. */
	const TEMPLATE_EXTENSION = '.tpl';
	/** Maximum recursion depth when searching for routes. */
	const ROUTE_MAX_DEPTH = 4;
	/** Name of the template variable that will contain data automatically imported from configuration. */
	const AUTOIMPORT_VARIABLE = 'conf';
	/** Name of the default data source. */
	const DEFAULT_DATASOURCE = 'db';
	/** Loader object. */
	private ?\Temma\Base\Loader $_loader = null;
	/** Configuration object. */
	private ?\Temma\Web\Config $_config = null;
	/** List of data sources. */
	private ?\Temma\Utils\Registry $_dataSources = null;
	/** Session object. */
	private ?\Temma\Base\Session $_session = null;
	/** Request object. */
	private ?\Temma\Web\Request $_request = null;
	/** Response object. */
	private ?\Temma\Web\Response $_response = null;
	/** "neutral" controller object. */
	private ?\Temma\Web\Controller $_executorController = null;
	/** Name of the executed controller. */
	private ?string $_controllerName = null;
	/** Name of the object corresponding to the controller. */
	private ?string $_objectControllerName = null;
	/** Name of the executed action. */
	private ?string $_actionName = null;
	/** Reflexion object over the controller (for checking purposes). */
	private ?\ReflectionClass $_controllerReflection = null;

	/**
	 * Constructor. Framework init: read the configuration, connect to data sources, create the session.
	 * @param	?\Temma\Base\Loader	$loader	(optional) Loader object. (defaults to null)
	 */
	public function __construct(?\Temma\Base\Loader $loader=null) {
		// extraction of request parameters
		$this->_request = $loader['request'] ?? new \Temma\Web\Request();
		// create the response object
		$this->_response = $loader['response'] ?? new \Temma\Web\Response();
		// load the configuration, log system init
		if (isset($loader['config']))
			$this->_config = $loader['config'];
		else
			$this->_loadConfig();
		// create the loader object (dependency injection container)
		if ($loader) {
			$this->_loader = $loader;
			$this->_loader['config'] ??= $this->_config;
			$this->_loader['request'] ??= $this->_request;
			$this->_loader['response'] ??= $this->_response;
			$this->_loader['temma'] ??= $this;
		} else {
			$loaderName = $this->_config->loader;
			$this->_loader = new $loaderName([
				'config'   => $this->_config,
				'request'  => $this->_request,
				'response' => $this->_response,
				'temma'    => $this,
			]);
		}
		// configure the loader with the defined configuration (preload, lazy, aliases and prefixes)
		if (isset($this->_config->loaderPreload))
			$this->_loader->set($this->_config->loaderPreload);
		if (isset($this->_config->loaderLazy))
			$this->_loader->setLazy($this->_config->loaderLazy);
		if (isset($this->_config->loaderAliases))
			$this->_loader->setAliases($this->_config->loaderAliases);
		if (isset($this->_config->loaderPrefixes))
			$this->_loader->setPrefixes($this->_config->loaderPrefixes);
		// initialization of the log system
		$this->_configureLog();
		// check the requested URL and log it
		if ($_SERVER['REQUEST_URI'] == '/index.php') {
			TµLog::log('Temma/Web', 'DEBUG', "Requested URL '/index.php', redirecting to '/'.");
			header("Location: /");
			exit();
		} else if (($_SERVER['REQUEST_URI'] ?? null))
			TµLog::log('Temma/Web', 'DEBUG', "Processing URL '" . $_SERVER['REQUEST_URI'] . "'.");
		// connect to data sources
		$this->_dataSources = new \Temma\Utils\Registry();
		foreach ($this->_config->dataSources as $name => $dsParam) {
			$this->_dataSources[$name] = \Temma\Base\Datasource::metaFactory($dsParam);
		}
		$this->_loader['dataSources'] = $this->_dataSources;
		// get the session if needed
		if ($this->_config->enableSessions && !isset($this->_loader['session'])) {
			$sessionSource = (isset($this->_config->sessionSource) && isset($this->_dataSources[$this->_config->sessionSource])) ?
					 $this->_dataSources[$this->_config->sessionSource] : null;
			$this->_session = \Temma\Base\Session::factory($sessionSource, $this->_config->sessionName, $this->_config->sessionDuration, $this->_config->cookieDomain);
			$this->_loader['session'] = $this->_session;
			// manage flash variables
			$flash = $this->_session->extractPrefix('__');
			$this->_response->addData($flash);
		}
	}
	/**
	 * In case of automatic configuration (using a generated configuration object), this method store the configuration object.
	 * @param	\Temma\Web\Config	$config	The configuration object to use.
	 * @see		Temma/bin/configObjectGenerator.php
	 */
	public function setConfig(\Temma\Web\Config $config)  : void {
		$this->_config = $config;
		$this->_loader->set('config', $config);
	}
	/**
	 * Starts the execution flow: extract request parameters, initialize variables, pre-plugins/controller/post-plugins execution.
	 * @param	?bool	$processView	(optional) Set to false or null to avoid processing of the view (or redirection). Defaults to true.
	 *					- false: returns an associative array of data (the template variables) or a string if a redirection was defined.
	 *					- null: returns null.
	 *					- true: the view or the redirection is processed.
	 * @param	bool	$sendHeaders	(optional) Set to false to avoid sending headers. Defaults to true.
	 * @return	null|string|array	Null by default, or a string (for a redirection) or an associative array of data if the
	 *					$returnData parameter is set to true.
	 */
	public function process(?bool $processView=true, bool $sendHeaders=true) : null|string|array {
		/* ********** INIT ********** */
		// create the executor controller if needed
		$this->_executorController = $this->_executorController ?? new \Temma\Web\Controller($this->_loader);
		// variables init
		$this->_executorController['URL'] = $this->_request->getPathInfo();
		$this->_executorController['CONTROLLER'] = $this->_request->getController();
		$this->_executorController['ACTION'] = $this->_request->getAction();
		// import of "autoimport" variables defined in the configuration file
		$this->_executorController[self::AUTOIMPORT_VARIABLE] = $this->_config->autoimport;

		/* ********** NAME OF CONTROLLER/ACTION ********** */
		$this->_setControllerName();
		$this->_setActionName();

		/* ********** PRE-PLUGINS ********** */
		TµLog::log('Temma/Web', 'DEBUG', "Processing of pre-process plugins.");
		$execStatus = \Temma\Web\Controller::EXEC_FORWARD;
		// generate the list of pre-plugins
		$prePlugins = $this->_generatePluginsList('pre');
		// processing of pre-plugins
		while (($pluginName = current($prePlugins)) !== false) {
			next($prePlugins);
			if (empty($pluginName))
				continue;
			// execution of the pre-plugin
			try {
				$execStatus = $this->_execPlugin($pluginName, 'pre');
			} catch (TµFlowException $fe) {
				$execStatus = $fe->getCode();
			}
			// if asked for, stops all processing and quit immediately
			if ($execStatus === \Temma\Web\Controller::EXEC_QUIT) {
				TµLog::log('Temma/Web', 'DEBUG', "Premature but wanted end of processing.");
				return (($processView === false) ? $this->_response->getData() : null);
			}
			// re-compute controller/action names (is case of the plugin modified the controller, the action,
			// the default namespace, or the include paths)
			$this->_setControllerName();
			$this->_setActionName();
			// check the execution status returned by the plugin
			if ($execStatus === \Temma\Web\Controller::EXEC_STOP ||
			    $execStatus === \Temma\Web\Controller::EXEC_HALT) {
				// stops pre-plugins processing
				break;
			} else if ($execStatus === \Temma\Web\Controller::EXEC_RESTART) {
				// restarts all pre-plugins execution
				$prePlugins = $this->_generatePluginsList('pre');
				reset($prePlugins);
			} else if ($execStatus === \Temma\Web\Controller::EXEC_REBOOT) {
				// restarts the execution from the very beginning
				$this->process($processView, $sendHeaders);
				return (($processView === false) ? $this->_response->getData() : null);
			}
		}

		/* ********** CONTROLLER ********** */
		if ($this->_controllerReflection->getName() == 'Temma\Web\Controller')
			throw new TµHttpException("The requested page doesn't exists.", 404);
		if (!$execStatus) { // $execStatus === \Temma\Web\Controller::EXEC_FORWARD || $execStatus === \Temma\Web\Controller::EXEC_FORWARD_THROWABLE
			do {
				// process the controller
				TµLog::log('Temma/Web', 'DEBUG', "Controller processing.");
				try {
					$execStatus = $this->_executorController->_subProcess($this->_objectControllerName, $this->_actionName);
				} catch (TµFlowException $fe) {
					$execStatus = $fe->getCode();
				}
			} while ($execStatus === \Temma\Web\Controller::EXEC_RESTART);
			// if asked, restarts the execution from the very beginning
			if ($execStatus === \Temma\Web\Controller::EXEC_REBOOT) {
				$this->process($processView, $sendHeaders);
				return (($processView === false) ? $this->_response->getData() : null);
			}
			// if asked for, stops all processing and quit immediately
			if ($execStatus === \Temma\Web\Controller::EXEC_QUIT) {
				TµLog::log('Temma/Web', 'DEBUG', "Premature but wanted end of processing.");
				return (($processView === false) ? $this->_response->getData() : null);
			}
		}

		/* ********** POST-PLUGINS ********** */
		if (!$execStatus) { // $execStatus === \Temma\Web\Controller::EXEC_FORWARD || $execStatus === \Temma\Web\Controller::EXEC_FORWARD_THROWABLE
			TµLog::log('Temma/Web', 'DEBUG', "Processing of post-process plugins.");
			// generate the list of post-plugins
			$postPlugins = $this->_generatePluginsList('post');
			// processing of post-plugins
			while (($pluginName = current($postPlugins)) !== false) {
				next($postPlugins);
				if (empty($pluginName))
					continue;
				// execution of the post-plugin
				try {
					$execStatus = $this->_execPlugin($pluginName, 'post');
				} catch (TµFlowException $fe) {
					$execStatus = $fe->getCode();
				}
				// if asked for, stops all processing and quit immediately
				if ($execStatus === \Temma\Web\Controller::EXEC_QUIT) {
					TµLog::log('Temma/Web', 'DEBUG', "Premature but wanted end of processing.");
					return (($processView === false) ? $this->_response->getData() : null);
				}
				// if asked, restarts the execution from the very beginning
				if ($execStatus === \Temma\Web\Controller::EXEC_REBOOT) {
					$this->process($processView, $sendHeaders);
					return (($processView === false) ? $this->_response->getData() : null);
				}
				// if asked, stops pre-plugins processing
				if ($execStatus === \Temma\Web\Controller::EXEC_STOP ||
				    $execStatus === \Temma\Web\Controller::EXEC_HALT) {
					break;
				}
				// if asked, restarts all post-plugins execution
				// si demandé, reprise des traitements de tous les post-plugins
				if ($execStatus === \Temma\Web\Controller::EXEC_RESTART) {
					$this->_setControllerName();
					$this->_setActionName();
					$postPlugins = $this->_generatePluginsList('post');
					reset($postPlugins);
				}
			}
		}

		/* ********** RESPONSE ********** */
		// management of HTTP errors
		$httpError = $this->_response->getHttpError();
		if (isset($httpError)) {
			TµLog::log('Temma/Web', 'WARN', "HTTP error '$httpError': " . $this->_request->getController()  . "/" . $this->_request->getAction());
			throw new TµHttpException("HTTP error.", $httpError);
		}
		// management of redirection if needed
		$url = $this->_response->getRedirection();
		if (!empty($url)) {
			TµLog::log('Temma/Web', 'DEBUG', "Redirecting to '$url'.");
			if ($processView === false)
				return ($url);
			if ($this->_response->getRedirectionCode() == 301)
				header('HTTP/1.1 301 Moved Permanently');
			header("Location: $url");
			exit();
		}
		// return data if asked
		if ($processView === false)
			return ($this->_response->getData());
		if ($processView === null)
			return (null);

		/* ********** VIEW ********** */
		// load the view object
		$view = $this->_loadView();
		if ($view === false)
			return (null);
		// init the view
		$this->_initView($view);
		// send HTTP headers
		if ($sendHeaders) {
			TµLog::log('Temma/Web', 'DEBUG', "Writing of response headers.");
			$view->sendHeaders($this->_response->getHeaders());
		}
		// send data body
		TµLog::log('Temma/Web', 'DEBUG', "Writing of response body.");
		$view->sendBody();
		return (null);
	}
	/**
	 * Returns the path to the HTML page corresponding to an HTTP error code.
	 * @param	int	$code	The HTTP error code.
	 * @return	?string	The path to the file, or null if it's not defined.
	 */
	public function getErrorPage(int $code)  : ?string {
		$errorPages = $this->_config->errorPages;
		if (isset($errorPages[$code]) && !empty($errorPages[$code]))
			return ($this->_config->webPath . '/' . $errorPages[$code]);
		if (isset($errorPages['default']) && !empty($errorPages['default']))
			return ($this->_config->webPath . '/' . $errorPages['default']);
		return (null);
	}

	/* ********** GETTERS ********** */
	/**
	 * Returns the loader.
	 * @return	?\Temma\Base\Loader	The current loader object.
	 */
	public function getLoader() : ?\Temma\Base\Loader {
		return ($this->_loader);
	}
	/**
	 * Returns the controller object name.
	 * @return	string	The object controller name.
	 */
	public function getControllerName() : string {
		return ($this->_objectControllerName);
	}
	/**
	 * Returns the action name.
	 * @return	string	The action name.
	 */
	public function getActionName() : string {
		return ($this->_actionName);
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Load the configuration file.
	 * @throws	\Temma\Exceptions\Framework	If the file is not readable and well-formed.
	 */
	private function _loadConfig() : void {
		// fetch the path to the application root path
		$appPath = realpath(dirname($_SERVER['SCRIPT_FILENAME']) . '/..');
		if (empty($appPath) || !is_dir($appPath))
			throw new TµFrameworkException("Unable to find application's root path.", TµFrameworkException::CONFIG);
		// read the configuration
		$this->_config = new \Temma\Web\Config($appPath);
		$this->_config->readConfigurationFile();
	}
	/** Configure the log system. */
	private function _configureLog() : void {
		$logPath = $this->_config->logPath;
		$logManager = $this->_config->logManager;
		// check if the log is disabled
		if (!$logPath && !$logManager) {
			TµLog::disable();
			return;
		}
		// checked if a log file was set
		if ($logPath)
			TµLog::setLogFile($logPath);
		// check is a log manager was set
		if ($logManager) {
			if (is_string($logManager))
				$logManager = [$logManager];
			foreach ($logManager as $managerName) {
				// check if the object exists and implements the right interface
				try {
					$reflect = new \ReflectionClass($managerName);
					if (!$reflect->implementsInterface('\Temma\Web\LogManager'))
						throw new TµFrameworkException("Log manager '$managerName' doesn't implements \Temma\Web\LogManager interface.", TµFrameworkException::CONFIG);
					if ($reflect->implementsInterface('\Temma\Base\Loadable'))
						$manager = new $managerName($this->_loader);
					else
						$manager = new $managerName();
					TµLog::addCallback(function($traceId, $text, $priority, $class) use ($manager) {
						return $manager->log($traceId, $text, $priority, $class);
					});
				} catch (\ReflectionException $re) {
					throw new TµFrameworkException("Log manager '$managerName' doesn't exist.", TµFrameworkException::CONFIG);
				}
			}
		}
		// manage log thresholds
		$logLevels = $this->_config->logLevels;
		$usedLogLevels = TµLog::checkLogLevel($logLevels);
		if (!$usedLogLevels && is_array($logLevels)) {
			$usedLogLevels = [];
			foreach ($logLevels as $class => $level) {
				if (($level = TµLog::checkLogLevel($level)))
					$usedLogLevels[$class] = $level;
			}
		}
		if (!$usedLogLevels)
			$usedLogLevels = \Temma\Web\Config::LOG_LEVEL;
		TµLog::setThreshold($usedLogLevels);
		// manage buffering log thresholds
		$bufferingLogLevels = $this->_config->bufferingLogLevels;
		if ($bufferingLogLevels) {
			$usedBufferingLogLevels = TµLog::checkLogLevel($bufferingLogLevels);
			if (!$usedBufferingLogLevels && is_array($bufferingLogLevels)) {
				$usedBufferingLogLevels = [];
				foreach ($bufferingLogLevels as $class => $level) {
					if (($level = TµLog::checkLogLevel($level)))
						$usedBufferingLogLevels[$class] = $level;
				}
			}
			if ($usedBufferingLogLevels)
				TµLog::setBufferingThreshold($usedBufferingLogLevels);
		}
	}

	/* ********** CONTROLLERS/PLUGINS LOADING ********** */
	/**
	 * Define the name of the loaded controller
	 * @throws	\Temma\Exceptions\Http	If the controller doesn't exist.
	 */
	private function _setControllerName() : void {
		$this->_controllerName = $this->_request->getController();
		if (!empty($proxyName = $this->_config->proxyController)) {
			// a proxy controller was defined
			$this->_objectControllerName = $proxyName;
		} else if (empty($this->_controllerName = $this->_request->getController())) {
			// no requested controller, use the root controller
			TµLog::log('Temma/Web', 'INFO', "No controller defined, use the root controller.");
			$this->_objectControllerName = $this->_config->rootController;
		} else {
			// a usable controller was defined on the URL
			// check if the requested controller is a virtual controller (managed by a route)
			$routeName = $this->_config->routes[$this->_controllerName] ?? null;
			if ($routeName) {
				TµLog::log('Temma/Web', 'INFO', "Routing '" . $this->_controllerName . "' to '$routeName'.");
				$this->_objectControllerName = $routeName;
			} else {
				// check controller name
				$lastBackslashPos = strrpos($this->_controllerName, '\\');
				if ($lastBackslashPos === false) {
					// there is no namespace
					// checks that the controller name's first letter is in lower case
					$firstLetter = substr($this->_controllerName, 0, 1);
					if ($firstLetter != lcfirst($firstLetter)) {
						TµLog::log('Temma/Web', 'ERROR', "Bad name for controller '" . $this->_controllerName . "' (must start by a lower-case character).");
						throw new TµHttpException("Bad name for controller '" . $this->_controllerName . "' (must start by a lower-case character).", 404);
					}
					// ensure the controller object's name starts with an upper-case letter
					$this->_objectControllerName = ucfirst($this->_controllerName);
				} else {
					// there is a namespace
					// ensure the controller object's name starts with an upper-case letter
					$this->_objectControllerName = substr($this->_controllerName, 0, $lastBackslashPos + 1) .
								       ucfirst(substr($this->_controllerName, $lastBackslashPos + 1));
				}
			}
			// management of the suffix
			$controllersSuffix = $this->_config->controllersSuffix;
			if (!empty($controllersSuffix) && substr($this->_objectControllerName, -strlen($controllersSuffix)) != $controllersSuffix)
				$this->_objectControllerName .= $controllersSuffix;
		}
		if (empty($this->_objectControllerName)) {
			// no requested controller, use the default controller
			TµLog::log('Temma/Web', 'INFO', "No controller found, use the default controller.");
			$this->_objectControllerName = $this->_config->defaultController;
			if (empty($this->_objectControllerName)) {
				TµLog::log('Temma/Wen', 'ERROR', "No defined controller.");
				throw new TµHttpException("No defifned controller.", 404);
			}
		}
		// if the controller object's name doesn't start with a backslash, prepend the default namespace
		if ($this->_objectControllerName[0] != '\\') {
			$defaultNamespace = rtrim(($this->_config->defaultNamespace ?? ''), '\\');
			$this->_objectControllerName = "$defaultNamespace\\" . $this->_objectControllerName;
		}
		// check that the controller object exists
		try {
			$this->_controllerReflection = new \ReflectionClass($this->_objectControllerName);
		} catch (\ReflectionException $e) {
			// the requested controller object doesn't exist, use the default controller
			//TµLog::log('Temma/Web', 'ERROR', "No controller object '" . $this->_objectControllerName . "'.");
			//throw new \Temma\Exceptions\Http("No controller object '" . $this->_objectControllerName . "'.", 404);
			$this->_objectControllerName = $this->_config->defaultController;
			$this->_controllerReflection = new \ReflectionClass($this->_objectControllerName);
		}
		// check how the controller name is spelled
		if ($this->_controllerReflection->getName() !== trim($this->_objectControllerName, '\ '))
			throw new TµHttpException("Bad name for controller '" . $this->_controllerName . "'.", 404);
	}
	/**
	 * Generate the list of pre- or post-plugins.
	 * @param	string	$type	'pre' or 'post'.
	 * @return	array	List of plugin names.
	 */
	private function _generatePluginsList(string $type) : array {
		$plugins = $this->_config->plugins ?? [];
		$result = [];
		// loop on plugin configuration entries
		foreach ($plugins as $pluginKey => $pluginData) {
			if (!is_string($pluginKey))
				continue;
			$pluginData = is_array($pluginData) ? $pluginData : [$pluginData];
			// global list of preplugins
			if ($pluginKey == "_$type") {
				$result = array_merge($result, $pluginData);
				continue;
			}
			// controller-specific configuration, or inverse controller-specific configuration
			$inverse = false;
			if (str_starts_with($pluginKey, '-')) {
				$inverse = true;
				$pluginKey = mb_substr($pluginKey, 1);
			}
			if ((!$inverse && ($pluginKey == $this->_objectControllerName || $pluginKey == $this->_controllerName)) ||
			    ($inverse && $pluginKey != $this->_objectControllerName && $pluginKey != $this->_controllerName)) {
				// loop on controller configuration
				foreach ($pluginData as $subKey => $subData) {
					if (!is_string($subKey))
						continue;
					$subData = is_array($subData) ? $subData : [$subData];
					if ($subKey == "_$type") {
						// controller-specific preplugin list
						$result = array_merge($result, $subData);
					} else if (isset($subData["_$type"]) &&
					           ($subKey == $this->_actionName ||
					            (str_starts_with($subKey, '-') && mb_substr($subKey, 1) != $this->_actionName))) {
						// action-specific plugin list, or inverse action-specific plugin list
						$list = is_array($subData["_$type"]) ? $subData["_$type"] : [$subData["_$type"]];
						$result = array_merge($result, $list);
					}
				}
				continue;
			}
		}
		TµLog::log('Temma/Web', 'DEBUG', $result ? ("List of $type plugins: " . print_r($result, true)) : "No $type plugins.");
		return ($result);
	}
	/**
	 * Execute a plugin.
	 * @param	string	$pluginName	Name of the plugin object.
	 * @param	string	$pluginType	Type of plugin ('pre' or 'post').
	 * @return	int	Plugin execution status.
	 * @throws	\Temma\Exceptions\Http	If the plugin doesn't exist.
	 * @throws	\Temma\Exceptions\Flow	If the plugin throws a Flow exception.
	 */
	private function _execPlugin(string $pluginName, string $pluginType) : ?int {
		TµLog::log('Temma/Web', 'INFO', "Executing plugin '$pluginName'.");
		$methodName = ($pluginType === 'pre') ? self::PLUGINS_PREPLUGIN_METHOD : self::PLUGINS_POSTPLUGIN_METHOD;
		// if the plugin object's name doesn't start with a backslash, prepend the default namespace
		if ($pluginName[0] != '\\') {
			$defaultNamespace = rtrim(($this->_config->defaultNamespace ?? ''), '\\');
			$pluginName = "$defaultNamespace\\$pluginName";
		}
		// check that the plugin exists
		if (!class_exists($pluginName)) {
			// can't find the object, try with the default namespace
			$defaultNamespace = $this->_config->defaultNamespace;
			$fullPluginName = "$defaultNamespace\\" . $pluginName;
			if (empty($defaultNamespace) || !class_exists($fullPluginName)) {
				TµLog::log('Temma/Web', 'ERROR', "Plugin '$pluginName' doesn't exist.");
				throw new TµHttpException("Plugin '$pluginName' doesn't exist.", 500);
			}
			$pluginName = $fullPluginName;
		}
		// check object's type
		if (!is_subclass_of($pluginName, '\Temma\Web\Plugin')) {
			TµLog::log('Temma/Web', 'ERROR', "Plugin '$pluginName' is not a subclass of \\Temma\\Web\\Plugin.");
			throw new TµHttpException("Plugin '$pluginName' is not a subclass of \\Temma\\Web\\Plugin.", 500);
		}
		// define the plugin method that must be called
		$reflector = new \ReflectionMethod($pluginName, $methodName);
		if ($reflector->getDeclaringClass()->getName() !== ltrim($pluginName, '\\')) {
			$methodName = self::PLUGINS_PLUGIN_METHOD;
			$reflector = new \ReflectionMethod($pluginName, $methodName);
			if ($reflector->getDeclaringClass()->getName() !== ltrim($pluginName, '\\')) {
				TµLog::log('Temma/Web', 'ERROR', "Plugin '$pluginName' has no executable '$pluginType' method.");
				throw new TµHttpException("Plugin '$pluginName' has no executable '$pluginType' method.", 500);
			}
		}
		// plugin instanciation
		$plugin = new $pluginName($this->_loader, $this->_executorController);
		// define plugin as the controller in the loader
		$this->_loader['controller'] = $plugin;
		// plugin execution
		$pluginReturn = $plugin->$methodName();
		return ($pluginReturn);
	}

	/* ********** ACTION ********** */
	/**
	 * Define the name of the action to execute.
	 * @throws	\Temma\Exceptions\Http	If the requested action doesn't exist.
	 */
	private function _setActionName() : void {
		if (empty($this->_actionName = $this->_request->getAction()))
			$this->_actionName = self::CONTROLLERS_ROOT_ACTION;
		else if (($this->_actionName === self::PLUGINS_PREPLUGIN_METHOD ||
		          $this->_actionName === self::PLUGINS_POSTPLUGIN_METHOD ||
		          $this->_actionName === self::PLUGINS_PLUGIN_METHOD) &&
		         is_a($this->_objectControllerName, '\Temma\Web\Plugin', true)) {
			TµLog::log('Temma/Web', 'ERROR', "Try to execute a plugin method as an action on the controller '" . $this->_objectControllerName . "'.");
			throw new TµHttpException("Try to execute a plugin method as an action on the controller '" . $this->_objectControllerName . "'.", 500);
		}
	}

	/* ********** VIEW ********** */
	/**
	 * Load the view.
	 * @return	false|\Temma\Web\View	Instance of the requested view, or false if the view has been disabled.
	 * @throws	\Temma\Exceptions\Framework	If no view can be loaded.
	 * @throws	\Temma\Exceptions\FlowQuit	If the view was explicitely deactivated in the response.
	 */
	private function _loadView() : false|\Temma\Web\View {
		$name = $this->_response->getView();
		// manage view disabling
		if ($name === false) {
			TµLog::log('Temma/Web', 'DEBUG', "View is disabled.");
			return (false);
		}
		// manage undefined view
		if (!$name) {
			// no defined view, use the default view
			$name = $this->_config->defaultView ?: \Temma\Web\Config::DEFAULT_VIEW;
			TµLog::log('Temma/Web', 'DEBUG', "Using default view '$name'.");
		}
		// manage Temma's standard views
		if (str_starts_with($name, '~')) {
			$name = '\Temma\Views\\' . mb_substr($name, 1);
			TµLog::log('Temma/Web', 'DEBUG', "Using Temma standard view '$name'.");
		}
		// force namespace
		if (!str_starts_with($name, '\\'))
			$name = "\\$name";
		// load the view
		if (class_exists($name) && is_subclass_of($name, '\Temma\Web\View')) {
			TµLog::log('Temma/Web', 'INFO', "Loading view '$name'.");
			return (new $name($this->_dataSources, $this->_config, $this->_response));
		}
		// the view doesn't exist
		TµLog::log('Temma/Web', 'ERROR', "Unable to instantiate view '$name'.");
		throw new TµFrameworkException("Unable to load any view.", TµFrameworkException::NO_VIEW);
	}
	/**
	 * View init.
	 * @param	\Temma\Web\View		$view	The view object.
	 * @throws	\Temma\Exceptions\Framework	If no template could be used.
	 */
	private function _initView(\Temma\Web\View $view) : void {
		if ($view->useTemplates()) {
			$template = $this->_response->getTemplate();
			if (empty($template)) {
				$controller = $this->_controllerName ? $this->_controllerName : $this->_objectControllerName;
				$action = $this->_actionName ? $this->_actionName : self::CONTROLLERS_PROXY_ACTION;
				$template = $controller . '/' . $action . self::TEMPLATE_EXTENSION;
			}
			$templatePrefix = trim(($this->_response->getTemplatePrefix() ?? ''), '/');
			if (!empty($templatePrefix))
				$template = $templatePrefix . '/' . $template;
			TµLog::log('Temma/Web', 'DEBUG', "Initializing view '" . get_class($view) . "' with template '$template'.");
			try {
				$view->setTemplate($this->_config->templatesPath, $template);
			} catch (TµIOException $ie) {
				TµLog::log('Temma/Web', 'ERROR', "No usable template.");
				throw new TµFrameworkException("No usable template.", TµFrameworkException::NO_TEMPLATE);
			}
		}
		$view->init();
	}
}

