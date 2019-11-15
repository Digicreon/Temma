<?php

namespace Temma\Web;

use \Temma\Base\Log as TµLog;

/**
 * Basic object for controllers management.
 *
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2012-2019, Amaury Bouchard
 * @package	Temma
 * @subpackage	Web
 */
class Controller implements \ArrayAccess {
	/** Execution flow constant: go to the next step (next plugin, for example). */
	const EXEC_FORWARD = null;
	/** Execution flow constant: stop the current flow (pre-plugins, controller, post-plugins). */
	const EXEC_STOP = 0;
	/** Execution flow constant: stop controller/plugins execution and go to the view. */
	const EXEC_HALT = 1;
	/** Execution flow constant: stop everything (even the view). */
	const EXEC_QUIT = 2;
	/**
	 * Execution flow constant: restart the execution.
	 * The behaviour depends on what has returned this value:
	 * - If it's a pre-plugin, the execution restarts from the first pre-plugin.
	 * - If it's a controller method (init, action or finalize), the execution restarts from the init method.
	 * - If it's a post-plugin, the execution restarts from the first post-plugin.
	 */
	const EXEC_RESTART = 3;
	/** Execution flow constant: restart the whole execution, from the first pre-plugin. */
	const EXEC_REBOOT = 4;
	/** Loader object (dependency injection container). */
	protected $_loader = null;
	/** Executor controller object (the one who called this object; could be null). */
	private $_executorController = null;

	/**
	 * Constructor.
	 * @param	\Temma\Base\Loader	$loader		Dependency injection object, with (at least) the keys
	 *							'dataSources', 'session', 'config', 'request', 'response'.
	 * @param	\Temma\Web\Controller	$executor	(optional) Executor controller object (the one who called this controller).
	 */
	public function __construct(\Temma\Base\Loader $loader, ?\Temma\Web\Controller $executor=null) {
		$this->_loader = $loader;
		$this->_executorController = $executor;
		// top-level controller: definition of the template variable which contains the session ID
		if (is_null($executor) && isset($loader->session) && $loader->config->enableSessions) {
			$this['SESSIONID'] = $loader->session->getSessionId();
		}
	}
	/** Destructor. */
	public function __destruct() {
	}
	/**
	 * Initialization function.
	 * Called for each controller before the action.
	 * Could be overloaded in all controllers.
	 * @return	?int	The return code (self::EXEC_QUIT, ...). Could be null (==self::EXEC_FORWARD).
	 */
	public function __wakeup() /* : ?int */ {
	}
	/**
	 * Finalization function.
	 * Called for each controller after the action.
	 * Could be overloaded in all controllers.
	 * @return	?int	The return code (self::EXEC_QUIT, ...). Could be null (==self::EXEC_FORWARD).
	 */
	public function __sleep() /* : ?int */ {
	}

	/* ********** METHODS CALLED BY THE CHILDREN OBJECTS ********** */
	/**
	 * Magical method which returns the requested data source.
	 * @param	string	$dataSource	Name of the data source.
	 * @return	\Temma\Base\Datasource	Data source object, or null if the source is not set.
	 */
	final public function __get(string $dataSource) : ?\Temma\Base\Datasource {
		return ($this->_loader->dataSources[$dataSource] ?? null);
	}
	/**
	 * Magical method used to know if a data source exists.
	 * @param	string	$dataSource	Name of the data source.
	 * @return	bool	True if the data source exists.
	 */
	final public function __isset(string $dataSource) : bool {
		return (isset($this->_loader->dataSources[$dataSource]));
	}
	/**
	 * Method used to raise en HTTP error (403, 404, 500, ...).
	 * @param	int	$code	The HTTP error code.
	 * @return	\Temma\Web\Controller	The current object.
	 */
	final protected function httpError(int $code) : \Temma\Web\Controller {
		$this->_loader->response->setHttpError($code);
		return ($this);
	}
	/**
	 * Method used to tell the HTTP return code (like the httpError() method,
	 * but without raising an error).
	 * @param	int	$code	The HTTP return code.
	 * @return	\Temma\Web\Controller	The current object.
	 */
	final protected function httpCode(int $code) : \Temma\Web\Controller {
		$this->_loader->response->setHttpCode($code);
		return ($this);
	}
	/**
	 * Returns the configured HTTP error.
	 * @return	int	The configured error code (403, 404, 500, ...) or null
	 *			if no error was configured.
	 */
	final protected function getHttpError() : ?int {
		return ($this->_loader->response->getHttpError());
	}
	/**
	 * Returns the configured HTTP return code.
	 * @return	int	The configured return code, or null if no code was configured.
	 */
	final protected function getHttpCode() : ?int {
		return ($this->_loader->response->getHttpCode());
	}
	/**
	 * Define an HTTP redirection (302).
	 * @param	string	$url	Redirection URL.
	 * @return	\Temma\Web\Controller	The current object.
	 */
	final protected function redirect(string $url) : \Temma\Web\Controller {
		$this->_loader->response->setRedirection($url);
		return ($this);
	}
	/**
	 * Define an HTTP redirection (301).
	 * @param	string	$url	Redirection URL.
	 * @return	\Temma\Web\Controller	The current object.
	 */
	final protected function redirect301(string $url) : \Temma\Web\Controller {
		$this->_loader->response->setRedirection($url, true);
		return ($this);
	}
	/**
	 * Define the view to use.
	 * @param	string	$view	Name of the view.
	 * @return	\Temma\Web\Controller	The current object.
	 */
	final protected function view(string $view) : \Temma\Web\Controller {
		$this->_loader->response->setView($view);
		return ($this);
	}
	/**
	 * Define the template to use.
	 * @param	string	$template	Template name.
	 * @return	\Temma\Web\Controller	The current object.
	 */
	final protected function template(string $template) : \Temma\Web\Controller {
		$this->_loader->response->setTemplate($template);
		return ($this);
	}
	/**
	 * Define the prefix to the template path.
	 * @param	string	$prefix	The template prefix path.
	 * @return	\Temma\Web\Controller	The current object.
	 */
	final protected function templatePrefix(string $prefix) : \Temma\Web\Controller {
		$this->_loader->response->setTemplatePrefix($prefix);
		return ($this);
	}

	/* ********** MANAGEMENT OF "TEMPLATE VARIABLES" ********** */
	/**
	 * Set a template variable, array-like syntax.
	 * @param	string	$name	Name of the variable.
	 * @param	mixed	$value	Associated value.
	 */
	final public function offsetSet(/* mixed */ $name, /* mixed */ $value) : void {
		$this->_loader->response[$name] = $value;
	}
	/**
	 * Return a template variable, array-like syntax.
	 * @param	string	$name	Variable name.
	 * @return	mixed	The template variable's data or null if it doesn't exist.
	 */
	public function offsetGet(/* mixed */ $name) /* : mixed */ {
		return ($this->_loader->response[$name] ?? null);
	}
	/**
	 * Returns a template variable.
	 * @param	string		$name		Name of the variable
	 * @param	mixed|Closure	$default	(optional) Default value if the data doesn't exist.
	 *						If this parameter is a regular value, it will be stored as a value associated
	 *						to the requested variable, and returned by this method.
	 *						If this parameter is an anonymous function, and the requested data doesn't exist,
	 *						the function will be executed, it's returned value will be stored as a value
	 *						associated to the requested variable, and returned by this method.
	 * @return	mixed	The template variable's data.
	 */
	final public function get(string $name, /* mixed */ $default=null) /* : mixed */ {
		return ($this->_loader->response->getData($name, $default, $this->_loader));
	}
	/**
	 * Remove a template variable.
	 * @param	string	$name	Name of the variable.
	 */
	public function offsetUnset(/* mixed */ $name) : void {
		unset($this->_loader->response[$name]);
	}
	/**
	 * Tell if a template variable exists.
	 * @param	string	$name	Name of the variable.
	 * @return	bool	True if the variable was defined, false otherwise.
	 */
	public function offsetExists(/* mixed */ $name) : bool {
		return (isset($this->_loader->response[$name]));
	}

	/* ********** SUB-PROCESS ********** */
	/**
	 * Process a sub-controller.
	 * @param	string	$controller	Controller name.
	 * @param	string	$action		(optional) Action name. Call the default action if not defined.
	 * @param	array	$parameters	(optional) List of parameters given to the sub-controller.
	 *					If not given, use the parameters received by the main controller.
	 * @return	int|null	The sub-controller's execution status (self::EXE_FORWARD, etc.). Could be null (==self::EXEC_FORWARD).
	 * @throws	\Temma\Exceptions\FrameworkException	If the requested controller or action doesn't exist.
	 */
	final public function subProcess(string $controller, ?string $action=null, ?array $parameters=null) : ?int {
		TµLog::log('Temma/Web', 'DEBUG', "Subprocess of '$controller'::'$action'.");
		// checks
		if (!class_exists($controller) || !is_subclass_of($controller, '\Temma\Web\Controller')) {
			TµLog::log('Temma/Web', 'ERROR', "Sub-controller '$controller' doesn't exists.");
			throw new \Temma\Exceptions\HttpException("Unable to find controller '$controller'.", 404);
		}

		/* ********** init ********** */
		// creation of the sub-controller
		$obj = new $controller($this->_loader, $this);
		// init of the sub-controller
		$method = \Temma\Web\Framework::CONTROLLERS_INIT_METHOD;
		try {
			$status = $obj->$method();
		} catch (\Error $e) {
			TµLog::log('Temma/Web', 'ERROR', "Unable to initialize the controller '$controller': " . $e->getMessage());
			throw new \Temma\Exceptions\HttpException("Unable to initialize the controller '$controller'.", 500);
		}
		if ($status !== self::EXEC_FORWARD)
			return ($status);

		/* ********** find the right method to execute ********** */
		// check if this sub-controller has a proxy action
		$method = \Temma\Web\Framework::CONTROLLERS_PROXY_ACTION;
		if (!method_exists($controller, $method)) {
			// no proxy action defined on this controller
			if (empty($action)) {
				// no action was requested, use the root action
				$method = \Temma\Web\Framework::CONTROLLERS_ROOT_ACTION;
			} else if (!($firstLetter = $action[0]) || $firstLetter !== strtolower($firstLetter)) {
				// the action must start with a lower-case letter
				TµLog::log('Temma/Web', 'ERROR', "Actions must start with a lower-case letter (here: '$action').");
				throw new \Temma\Exceptions\HttpException("Actions must start with a lower-case letter (here: '$action').", 404);
			}
			// check that the action could be executed
			if (!is_callable([$obj, $action])) {
				// no proxy action, and no callable action method
				throw new \Temma\Exceptions\HttpException("Unable to find action '$action' on controller '$controller'.", 404);
			}
			// the requested action is set, or there is a default action that could handle it
			$method = $action;
		}

		/* ********** execution ********** */
		$parameters = $parameters ?? $this->_loader->request->getParams();
		try {
			$status = $obj->$method(...$parameters);
		} catch (\ArgumentCountError $ace) {
			TµLog::log('Temma/Web', 'ERROR', "Bad number of parameters given to the method '$method' on controller '$controller'.");
			throw new \Temma\Exceptions\HttpException("Bad number of parameters given to the method '$method' on controller '$controller'.", 404);
		} catch (\Error $e) {
			TµLog::log('Temma/Web', 'ERROR', "$controller::$method: " . $e->getMessage());
			throw new \Temma\Exceptions\HttpException("Unable to execute method '$method' on controller '$controller'.", 404);
		}
		if ($status !== self::EXEC_FORWARD)
			return ($status);

		/* ********** finalization ********** */
		$method = \Temma\Web\Framework::CONTROLLERS_FINALIZE_METHOD;
		try {
			$status = $obj->$method();
		} catch (\Error $e) {
			TµLog::log('Temma/Web', 'ERROR', "Unable to finalize the controller '$controller'.");
			throw new \Temma\Exceptions\HttpException("Unable to finalize the controller '$controller'.", 500);
		}
		return ($status);
	}
}

