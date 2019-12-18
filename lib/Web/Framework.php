<?php

/**
 * Framework
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2019, Amaury Bouchard
 */

namespace Temma\Web;

use \Temma\Base\Log as TµLog;

/**
 * Main framework management object.
 *
 * @see		\Temma\Web\Controller
 * @see		\Temma\Web\View
 */
class Framework {
	/** Name of the local file which can contain the application configuration object. */
	const CONFIG_OBJECT_FILE_NAME = '_TemmaConfig.php';
	/** Name of the local configuration file. */
	const CONFIG_FILE_NAME = 'temma.json';
	/** Name of the roott action. */
	const CONTROLLERS_ROOT_ACTION = 'index';
	/** Name of the proxy action. */
	const CONTROLLERS_PROXY_ACTION = '__invoke';
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
	/** Configuration object. */
	private $_config = null;
	/** List of data sources. */
	private $_dataSources = null;
	/** Session object. */
	private $_session = null;
	/** Request object. */
	private $_request = null;
	/** Response object. */
	private $_response = null;
	/** "neutral" controller object. */
	private $_executorController = null;
	/** Name of the controller called in first place. */
	private $_initControllerName = null;
	/** Name of the executed controller. */
	private $_controllerName = null;
	/** Name of the object corresponding to the controller. */
	private $_objectControllerName = null;
	/** Name of the executed action. */
	private $_actionName = null;
	/** Reflexion object over the controller (for checking purposes). */
	private $_controllerReflection = null;

	/** Constructor. */
	public function __construct() {
		$this->_controllers = [];
		$this->_views = [];
	}
	/** Destructor. */
	public function __destruct() {
	}
	/** Framework init: read the configuration, connect to data sources, create the session. */
	public function init() : void {
		// load the configuration, log system init
		$this->_loadConfig();
		// connect to data sources
		if (isset($this->_executorController))
			$this->_dataSources = $this->_executorController->getDataSources();
		else {
			$this->_dataSources = [];
			$foundDb = $foundCache = false;
			foreach ($this->_config->dataSources as $name => $dsn)
				$this->_dataSources[$name] = \Temma\Base\Datasource::factory($dsn);
		}
		// get the session if needed
		if ($this->_config->enableSessions) {
			$sessionSource = (isset($this->_config->sessionSource) && isset($this->_dataSources[$this->_config->sessionSource])) ?
					 $this->_dataSources[$this->_config->sessionSource] : null;
			$this->_session = \Temma\Base\Session::factory($sessionSource, $this->_config->sessionName);
		}
	}
	/**
	 * In case of automatic configuration (using a generated configuration object), this method store the configuration object.
	 * @param	\Temma\Web\Config	$config	The configuration object to use.
	 * @see		Temma/bin/configObjectGenerator.php
	 */
	public function setConfig(\Temma\Web\Config $config)  : void {
		$this->_config = $config;
	}
	/**
	 * Starts the execution flow: extract request parameters, initialize variables, pre-plugins/controller/post-plugins execution.
	 */
	public function process() : void {
		/* ********** INIT ********** */
		// extraction of request parameters
		$this->_request = new \Temma\Web\Request();
		// create the response object
		$this->_response = new \Temma\Web\Response();
		// create the loader object (dependency injection container)
		$loaderName = $this->_config->loader;
		$this->_loader = new $loaderName([
			'dataSources'	=> $this->_dataSources,
			'session'	=> $this->_session,
			'config'	=> $this->_config,
			'request'	=> $this->_request,
			'response'	=> $this->_response,
		]);
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
		$prePlugins = $this->_generatePrePluginsList();
		// processing of pre-plugins
		while (($pluginName = current($prePlugins)) !== false) {
			next($prePlugins);
			if (empty($pluginName))
				continue;
			// execution of the pre-plugin
			$execStatus = $this->_execPlugin($pluginName, 'pre');
			// if asked for, stops all processing and quit immediately
			if ($execStatus === \Temma\Web\Controller::EXEC_QUIT) {
				TµLog::log('Temma/Web', 'DEBUG', "Premature but wanted end of processing.");
				return;
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
				$prePlugins = $this->_generatePrePluginsList();
				reset($prePlugins);
			} else if ($execStatus === \Temma\Web\Controller::EXEC_REBOOT) {
				// restarts the execution from the very beginning
				$this->process();
				return;
			}
		}

		/* ********** CONTROLLER ********** */
		if ($this->_controllerReflection->getName() == 'Temma\Web\Controller')
			throw new \Temma\Exceptions\HttpException("The requested page doesn't exists.", 404);
		if ($execStatus === \Temma\Web\Controller::EXEC_FORWARD) {
			do {
				// process the controller
				TµLog::log('Temma/Web', 'DEBUG', "Controller processing.");
				$execStatus = $this->_executorController->subProcess($this->_objectControllerName, $this->_actionName);
			} while ($execStatus === \Temma\Web\Controller::EXEC_RESTART);
			// if asked, restarts the execution from the very beginning
			if ($execStatus === \Temma\Web\Controller::EXEC_REBOOT) {
				$this->process();
				return;
			}
			// if asked for, stops all processing and quit immediately
			if ($execStatus === \Temma\Web\Controller::EXEC_QUIT) {
				TµLog::log('Temma/Web', 'DEBUG', "Premature but wanted end of processing.");
				return;
			}
		}

		/* ********** POST-PLUGINS ********** */
		if ($execStatus === \Temma\Web\Controller::EXEC_FORWARD) {
			TµLog::log('Temma/Web', 'DEBUG', "Processing of post-process plugins.");
			// generate the list of post-plugins
			$postPlugins = $this->_generatePostPluginsList();
			// processing of post-plugins
			while (($pluginName = current($postPlugins)) !== false) {
				next($postPlugins);
				if (empty($pluginName))
					continue;
				// execution of the post-plugin
				$execStatus = $this->_execPlugin($pluginName, 'post');
				// if asked for, stops all processing and quit immediately
				if ($execStatus === \Temma\Web\Controller::EXEC_QUIT) {
					TµLog::log('Temma/Web', 'DEBUG', "Premature but wanted end of processing.");
					return;
				}
				// if asked, restarts the execution from the very beginning
				if ($execStatus === \Temma\Web\Controller::EXEC_REBOOT) {
					$this->process();
					return;
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
					$prePlugins = $this->_generatePrePluginsList();
					reset($prePlugins);
				}
			}
		}

		/* ********** RESPONSE ********** */
		// management of HTTP errors
		$httpError = $this->_response->getHttpError();
		if (isset($httpError)) {
			TµLog::log('Temma/Web', 'WARN', "HTTP error '$httpError': " . $this->_request->getController()  . "/" . $this->_request->getAction());
			throw new \Temma\Exceptions\HttpException("HTTP error.", $httpError);
		}
		// management of redirection if needed
		$url = $this->_response->getRedirection();
		if (!empty($url)) {
			TµLog::log('Temma/Web', 'DEBUG', "Redirecting to '$url'.");
			if ($this->_response->getRedirectionCode() == 301)
				header('HTTP/1.1 301 Moved Permanently');
			header("Location: $url");
			exit();
		}

		/* ********** VIEW ********** */
		// load the view object
		$view = $this->_loadView();
		// init the view
		$this->_initView($view);
		// send HTTP headers
		TµLog::log('Temma/Web', 'DEBUG', "Writing of response headers.");
		$view->sendHeaders();
		// send data body
		TµLog::log('Temma/Web', 'DEBUG', "Writing of response body.");
		$view->sendBody();
	}
	/**
	 * Returns the path to the HTML page corresponding to an HTTP error code.
	 * @param	int	$code	The HTTP error code.
	 * @return	string|null	The path to the file, or null if it's not defined.
	 */
	public function getErrorPage(int $code)  : ?string {
		$errorPages = $this->_config->errorPages;
		if (isset($errorPages[$code]) && !empty($errorPages[$code]))
			return ($this->_config->webPath . '/' . $errorPages[$code]);
		if (isset($errorPages['default']) && !empty($errorPages['default']))
			return ($this->_config->webPath . '/' . $errorPages['default']);
		return (null);
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Load the configuration file.
	 * @throws	\Temma\Exceptions\FrameworkException	If the file is not readable and well-formed.
	 */
	private function _loadConfig() : void {
		// fetch the path to the application root path
		$appPath = realpath(dirname($_SERVER['SCRIPT_FILENAME']) . '/..');
		if (empty($appPath) || !is_dir($appPath))
			throw new \Temma\Exceptions\FrameworkException("Unable to find application's root path.", \Temma\Exceptions\FrameworkException::CONFIG);
		$etcPath = $appPath . '/' . \Temma\Web\Config::ETC_DIR;
		// search for a PHP file which contains a configuration object
		$configObject = $etcPath . '/' . self::CONFIG_OBJECT_FILE_NAME;
		if (is_readable($configObject)) {
			// load the object - call $temma->setConfig() automatically
			include($configObject);
			$this->_config = new \_TemmaAutoConfig($this, $appPath, $etcPath);
			$this->_executorController = $this->_config->executorController;
			return;
		}
		// read the configuration file
		$configFile = $etcPath . '/' . self::CONFIG_FILE_NAME;
		if (!is_readable($configFile))
			throw new \Temma\Exceptions\FrameworkException("Unable to read configuration file '$configFile'.", \Temma\Exceptions\FrameworkException::CONFIG);
		$this->_config = new \Temma\Web\Config($appPath, $etcPath);
		$this->_config->readConfigurationFile($configFile);
	}

	/* ********** CONTROLLERS/PLUGINS LOADING ********** */
	/**
	 * Define the name of the loaded controller
	 * @throws	\Temma\Exceptions\HttpException	If the controller doesn't exist.
	 */
	private function _setControllerName() : void {
		$this->_initControllerName = $this->_controllerName = $this->_request->getController();
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
				$this->_objectName = $routeName;
			}
			// check controller name
			$lastBackslashPos = strrpos($this->_controllerName, '\\');
			if ($lastBackslashPos === false) {
				// there is no namespace
				// checks that the controller name's first letter is in lower case
				$firstLetter = substr($this->_controllerName, 0, 1);
				if ($firstLetter != lcfirst($firstLetter)) {
					TµLog::log('Temma/Web', 'ERROR', "Bad name for controller '" . $this->_controllerName . "' (must start by a lower-case character).");
					throw new \Temma\Exceptions\HttpException("Bad name for controller '" . $this->_controllerName . "' (must start by a lower-case character).", 404);
				}
				// ensure the controller object's name starts with an upper-case letter
				$this->_objectControllerName = ucfirst($this->_controllerName);
			} else {
				// there is a namespace
				// ensure the controller object's name starts with an upper-case letter
				$this->_objectControllerName = substr($this->_controllerName, 0, $lastBackslashPos + 1) .
				                               ucfirst(substr($this->_controllerName, $lastBackslashPos + 1));
			}
			// management of the suffix
			$controllersSuffix = $this->_config->controllersSuffix;
			if (!empty($controllerSuffix) && substr($this->_objectControllerName, -strlen($controllerSuffix)) != $controllerSuffix)
				$this->_objectControllerName .= $controllerSuffix;
		}
		if (empty($this->_objectControllerName)) {
			// no requested controller, use the default controller
			TµLog::log('Temma/Web', 'INFO', "No controller found, use the default controller.");
			$this->_objectControllerName = $this->_config->defaultController;
			if (empty($this->_objectControllerName)) {
				TµLog::log('Temma/Wen', 'ERROR', "No defined controller.");
				throw new \Temma\Exceptions\HttpException("No defifned controller.", 404);
			}
		}
		// if the controller object's name doesn't start with a backslash, prepend the default namespace
		if ($this->_objectControllerName[0] != '\\') {
			$defaultNamespace = rtrim($this->_config->defaultNamespace, '\\');
			$this->_objectControllerName = "$defaultNamespace\\" . $this->_objectControllerName;
		}
		// check that the controller object exists
		try {
			$this->_controllerReflection = new \ReflectionClass($this->_objectControllerName);
		} catch (\ReflectionException $e) {
			TµLog::log('Temma/Web', 'ERROR', "No controller object '" . $this->_objectControllerName . "'.");
			throw new \Temma\Exceptions\HttpException("No controller object '" . $this->_objectControllerName . "'.", 404);
		}
		// check how the controller name is spelled
		if ($this->_controllerReflection->getName() !== trim($this->_objectControllerName, '\ '))
			throw new \Temma\Exceptions\HttpException("Bad name for controller '" . $this->_controllerName . "'.", 404);
	}
	/**
	 * Generate the list of pre-plugins.
	 * @return	array	List of pre-plugin names.
	 */
	private function _generatePrePluginsList() : array {
		$plugins = $this->_config->plugins;
		$prePlugins = $plugins['_pre'] ?? [];
		if (isset($plugins[$this->_objectControllerName]['_pre']))
			$prePlugins = array_merge($prePlugins, $plugins[$this->_objectControllerName]['_pre']);
		if (isset($plugins[$this->_objectControllerName][$this->_actionName]['_pre']))
			$prePlugins = array_merge($prePlugins, $plugins[$this->_objectControllerName][$this->_actionName]['_pre']);
		if (isset($plugins[$this->_controllerName]['_pre']))
			$prePlugins = array_merge($prePlugins, $plugins[$this->_controllerName]['_pre']);
		if (isset($plugins[$this->_controllerName][$this->_actionName]['_pre']))
			$prePlugins = array_merge($prePlugins, $plugins[$this->_controllerName][$this->_actionName]['_pre']);
		TµLog::log('Temma/Web', 'DEBUG', "Pre plugins: " . print_r($prePlugins, true));
		return ($prePlugins);
	}
	/**
	 * Generate the list of post-plugins.
	 * @return	array	List of post-plugin names.
	 */
	private function _generatePostPluginsList() : array {
		$plugins = $this->_config->plugins;
		$postPlugins = $plugins['_post'] ?? [];
		if (isset($plugins[$this->_objectControllerName]['_post']))
			$postPlugins = array_merge($postPlugins, $plugins[$this->_objectControllerName]['_post']);
		if (isset($plugins[$this->_objectControllerName][$this->_actionName]['_post']))
			$postPlugins = array_merge($postPlugins, $plugins[$this->_objectControllerName][$this->_actionName]['_post']);
		if (isset($plugins[$this->_controllerName]['_post']))
			$postPlugins = array_merge($postPlugins, $plugins[$this->_controllerName]['_post']);
		if (isset($plugins[$this->_controllerName][$this->_actionName]['_post']))
			$postPlugins = array_merge($postPlugins, $plugins[$this->_controllerName][$this->_actionName]['_post']);
		TµLog::log('Temma/Web', 'DEBUG', "Post plugins: " . print_r($postPlugins, true));
		return ($postPlugins);
	}
	/**
	 * Execute a plugin.
	 * @param	string	$pluginName	Name of the plugin object.
	 * @param	string	$pluginType	Type of plugin ('pre' or 'post').
	 * @return	int	Plugin execution status.
	 * @throws	\Temma\Exceptions\HttpException	If the plugin doesn't exist.
	 */
	private function _execPlugin(string $pluginName, string $pluginType) : ?int {
		TµLog::log('Temma/Web', 'INFO', "Executing plugin '$pluginName'.");
		try {
			// check that the plugin exists
			if (!class_exists($pluginName)) {
				// can't find the object, try with the default namespace
				$defaultNamespace = $this->_config->defaultNamespace;
				$fullPluginName = "$defaultNamespace\\" . $pluginName;
				if (empty($defaultNamespace) || !class_exists($fullPluginName)) {
					TµLog::log('Temma/Web', 'ERROR', "Plugin '$pluginName' doesn't exist.");
					throw new \Temma\Exceptions\HttpException("Plugin '$pluginName' doesn't exist.", 500);
				}
				$pluginName = $fullPluginName;
			}
			// check object's type
			if (!is_subclass_of($pluginName, '\Temma\Web\Plugin')) {
				TµLog::log('Temma/Web', 'ERROR', "Plugin '$pluginName' is not a subclass of \\Temma\\Web\\Plugin.");
				throw new \Temma\Exceptions\HttpException("Plugin '$pluginName' is not a subclass of \\Temma\\Web\\Plugin.", 500);
			}
			// define the plugin method that must be called
			$methodName = ($pluginType === 'pre') ? self::PLUGINS_PREPLUGIN_METHOD : self::PLUGINS_POSTPLUGIN_METHOD;
			$reflector = new \ReflectionMethod($pluginName, $methodName);
			if ($reflector->getDeclaringClass()->getName() !== ltrim($pluginName, '\\')) {
				$methodName = self::PLUGINS_PLUGIN_METHOD;
				$reflector = new \ReflectionMethod($pluginName, $methodName);
				if ($reflector->getDeclaringClass()->getName() !== ltrim($pluginName, '\\')) {
					TµLog::log('Temma/Web', 'ERROR', "Plugin '$pluginName' has no executable '$pluginType' method.");
					throw new \Temma\Exceptions\HtppException("Plugin '$pluginName' has no executable '$pluginType' method.", 500);
				}
			}
			// execute the plugin
			$plugin = new $pluginName($this->_loader, $this->_executorController);
			$pluginReturn = $plugin->$methodName();
			return ($pluginReturn);
		} catch (Exception $e) { }
		TµLog::log('Temma/Web', 'DEBUG', "Unable to execute plugin '$pluginName'::'$methodName'.");
		throw new \Temma\Exceptions\HttpException("Unable to execute plugin '$pluginName'::'$methodName'.", 500);
	}

	/* ********** ACTION ********** */
	/**
	 * Define the name of the action to execute.
	 * @throws	\Temma\Exceptions\HttpException	If the requested action doesn't exist.
	 */
	private function _setActionName() : void {
		if (empty($this->_actionName = $this->_request->getAction()))
			$this->_actionName = self::CONTROLLERS_ROOT_ACTION;
		else if (($this->_actionName === self::PLUGINS_PREPLUGIN_METHOD ||
		          $this->_actionName === self::PLUGINS_POSTPLUGIN_METHOD ||
		          $this->_actionName === self::PLUGINS_PLUGIN_METHOD) &&
		         is_a($this->_objectControllerName, '\Temma\Web\Plugin')) {
			TµLog::l('Temma/Web', 'ERROR', "Try to execute a plugin method as an action on the controller '" . $this->_objectControllerName . "'.");
			throw new \Temma\Exceptions\HttpException("Try to execute a plugin method as an action on the controller '" . $this->_objectControllerName . "'.", 500);
		}
	}

	/* ********** VIEW ********** */
	/**
	 * Load the view.
	 * @return	\Temma\Web\View	Instance of the requested view.
	 * @throws	\Temma\Exceptions\FrameworkException	If no view can be loaded.
	 */
	private function _loadView() : \Temma\Web\View {
		$name = $this->_response->getView();
		TµLog::log('Temma/Web', 'INFO', "Loading view '$name'.");
		// no defined view, use the default view
		if (empty($name)) {
			TµLog::log('Temma/Web', 'DEBUG', "Using default view.");
			$name = $this->_config->defaultView;
		}
		// load the view
		if (class_exists($name) && is_subclass_of($name, '\Temma\Web\View'))
			return (new $name($this->_dataSources, $this->_config, $this->_response));
		// the view doesn't exist
		TµLog::log('Temma/Web', 'ERROR', "Unable to instantiate view '$name'.");
		throw new \Temma\Exceptions\FrameworkException("Unable to load any view.", \Temma\Exceptions\FrameworkException::NO_VIEW);
	}
	/**
	 * View init.
	 * @param	\Temma\Web\View		$view		The view object.
	 * @throws	\Temma\Exceptions\FrameworkException	If no template could be used.
	 */
	private function _initView(\Temma\Web\View $view) : void {
		if ($view->useTemplates()) {
			$template = $this->_response->getTemplate();
			if (empty($template)) {
				$controller = $this->_controllerName ? $this->_controllerName : $this->_objectControllerName;
				$action = $this->_actionName ? $this->_actionName : self::CONTROLLERS_PROXY_ACTION;
				$template = $controller . '/' . $action . self::TEMPLATE_EXTENSION;
			}
			$templatePrefix = trim($this->_response->getTemplatePrefix(), '/');
			if (!empty($templatePrefix))
				$template = $templatePrefix . '/' . $template;
			TµLog::log('Temma/Web', 'DEBUG', "Initializing view '" . get_class($view) . "' with template '$template'.");
			try {
				$view->setTemplate($this->_config->templatesPath, $template);
			} catch (\Temma\Exceptions\IOException $ie) {
				TµLog::log('Temma/Web', 'ERROR', "No usable template.");
				throw new \Temma\Exceptions\FrameworkException("No usable template.", \Temma\Exceptions\FrameworkException::NO_TEMPLATE);
			}
		}
		$view->init();
	}
}

