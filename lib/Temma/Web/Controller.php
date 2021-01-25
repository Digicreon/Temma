<?php

/**
 * Controller
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2012-2019, Amaury Bouchard
 */

namespace Temma\Web;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Http as TµHttpException;

/**
 * Basic object for controllers management.
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
	 * - If it's a controller method (__wakeup, action or __sleep), the execution restarts from the init method.
	 * - If it's a post-plugin, the execution restarts from the first post-plugin.
	 */
	const EXEC_RESTART = 3;
	/** Execution flow constant: restart the whole execution, from the first pre-plugin. */
	const EXEC_REBOOT = 4;
	/** Loader object (dependency injection container). */
	protected $_loader = null;
	/** Executor controller object (the one who called this object; could be null). */
	private $_executorController = null;
	/** Session object. */
	protected $_session = null;
	/** DAO object. */
	protected $_dao = null;

	/**
	 * Constructor.
	 * @param	\Temma\Base\Loader	$loader		Dependency injection object, with (at least) the keys
	 *							'dataSources', 'session', 'config', 'request', 'response'.
	 * @param	\Temma\Web\Controller	$executor	(optional) Executor controller object (the one who called this controller).
	 */
	public function __construct(\Temma\Base\Loader $loader, ?\Temma\Web\Controller $executor=null) {
		$this->_loader = $loader;
		$this->_executorController = $executor;
		$this->_session = $loader->session;
		// top-level controller: definition of the template variable which contains the session ID
		if (is_null($executor) && isset($loader->session) && $loader->config->enableSessions) {
			$this['SESSIONID'] = $loader->session->getSessionId();
		}
		// creation of the DAO if needed
		if (isset($executor) && isset($this->_temmaAutoDao) && $this->_temmaAutoDao !== false) {
			$this->_dao = $this->_loadDao($this->_temmaAutoDao);
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

	/* ********** DAO MANAGEMENT ********** */
	/**
	 * Load a DAO.
	 * @param	string|array	$param	Name of the DAO object, or an associative array with parameters.
	 * @return	\Temma\Dao\Dao	The loaded DAO.
	 */
	public function _loadDao($param) : \Temma\Dao\Dao {
		$daoConf = [
			'object'   => '\Temma\Dao\Dao',
			'criteria' => null,
			'source'   => null,
			'cache'    => true,
			'base'     => null,
			'table'    => $this['CONTROLLER'],
			'id'       => 'id',
			'fields'   => null,
		];
		// Get the DAO configuration
		if (is_string($param))
			$daoConf['object'] = $param;
		else if (is_array($param)) {
			$daoConf['object'] = $param['object'] ?? $daoConf['object'];
			$daoConf['criteria'] = $param['criteria'] ?? $daoConf['criteria'];
			$daoConf['source'] = $param['source'] ?? $daoConf['source'];
			$daoConf['cache'] = $param['cache'] ?? $daoConf['cache'];
			$daoConf['base'] = $param['base'] ?? $daoConf['base'];
			$daoConf['table'] = $param['table'] ?? $daoConf['table'];
			$daoConf['id'] = $param['id'] ?? $daoConf['id'];
			$daoConf['fields'] = (isset($param['fields']) && is_array($param['fields'])) ? $param['fields'] : $daoConf['fields'];
		}
		// object creation
		if (isset($daoConf['source']) && isset($this->_dataSources[$daoConf['source']]))
			$dataSource = $this->_loader->dataSources[$daoConf['source']];
		else
			$dataSource = reset($this->_loader->dataSources);
		$dao = new $daoConf['object']($dataSource, ($daoConf['cache'] ? $this->_loader->cache : null), $daoConf['table'], $daoConf['id'],
		                              $daoConf['base'], $daoConf['fields'], $daoConf['criteria']);
		return ($dao);
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
	 * @return	int	self::EXEC_HALT (useful value to return from the controller).
	 */
	final protected function _httpError(int $code) : int {
		$this->_loader->response->setHttpError($code);
		return (self::EXEC_HALT);
	}
	/**
	 * Method used to tell the HTTP return code (like the httpError() method,
	 * but without raising an error).
	 * @param	int	$code	The HTTP return code.
	 * @return	int	self::EXEC_HALT (useful value to return from the controller).
	 */
	final protected function _httpCode(int $code) : int {
		$this->_loader->response->setHttpCode($code);
		return (self::EXEC_HALT);
	}
	/**
	 * Returns the configured HTTP error.
	 * @return	int	The configured error code (403, 404, 500, ...) or null
	 *			if no error was configured.
	 */
	final protected function _getHttpError() : ?int {
		return ($this->_loader->response->getHttpError());
	}
	/**
	 * Returns the configured HTTP return code.
	 * @return	int	The configured return code, or null if no code was configured.
	 */
	final protected function _getHttpCode() : ?int {
		return ($this->_loader->response->getHttpCode());
	}
	/**
	 * Define an HTTP redirection (302).
	 * @param	?string	$url	Redirection URL, or null to remove the redirection.
	 * @return	int	self::EXEC_HALT (useful value to return from the controller).
	 */
	final protected function _redirect(?string $url) : int {
		$this->_loader->response->setRedirection($url);
		return (self::EXEC_HALT);
	}
	/**
	 * Define an HTTP redirection (301).
	 * @param	string	$url	Redirection URL.
	 * @return	int	self::EXEC_HALT (useful value to return from the controller).
	 */
	final protected function _redirect301(string $url) : int {
		$this->_loader->response->setRedirection($url, true);
		return (self::EXEC_HALT);
	}
	/**
	 * Define the view to use.
	 * @param	string	$view	Name of the view.
	 * @return	\Temma\Web\Controller	The current object.
	 */
	final protected function _view(string $view) : \Temma\Web\Controller {
		$this->_loader->response->setView($view);
		return ($this);
	}
	/**
	 * Define the template to use.
	 * @param	string	$template	Template name.
	 * @return	\Temma\Web\Controller	The current object.
	 */
	final protected function _template(string $template) : \Temma\Web\Controller {
		$this->_loader->response->setTemplate($template);
		return ($this);
	}
	/**
	 * Define the prefix to the template path.
	 * @param	string	$prefix	The template prefix path.
	 * @return	\Temma\Web\Controller	The current object.
	 */
	final protected function _templatePrefix(string $prefix) : \Temma\Web\Controller {
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
	 * @throws	\Temma\Exceptions\Http	If the requested controller or action doesn't exist.
	 */
	final public function _subProcess(string $controller, ?string $action=null, ?array $parameters=null) : ?int {
		TµLog::log('Temma/Web', 'DEBUG', "Subprocess of '$controller'::'$action'.");
		// checks
		if (!class_exists($controller) || !is_subclass_of($controller, '\Temma\Web\Controller')) {
			TµLog::log('Temma/Web', 'ERROR', "Sub-controller '$controller' doesn't exists.");
			throw new TµHttpException("Unable to find controller '$controller'.", 404);
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
			throw new TµHttpException("Unable to initialize the controller '$controller'.", 500);
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
				throw new TµHttpException("Actions must start with a lower-case letter (here: '$action').", 404);
			}
			// check that the action could be executed
			if (!is_callable([$obj, $action])) {
				// no proxy action, and no callable action method
				throw new TµHttpException("Unable to find action '$action' on controller '$controller'.", 404);
			}
			// the requested action is set, or there is a default action that could handle it
			$method = $action;
		}

		/* ********** execution ********** */
		$parameters = $parameters ?? $this->_loader->request->getParams();
		try {
			$status = $obj->$method(...$parameters);
		} catch (\ArgumentCountError $ace) {
			TµLog::log('Temma/Web', 'ERROR', "$controller::$method: " . $ace->getMessage());
			throw new TµHttpException("$controller::$method: " . $ace->getMessage(), 404);
		} catch (\Error $e) {
			TµLog::log('Temma/Web', 'ERROR', "$controller::$method: " . $e->getMessage());
			throw new TµHttpException("Unable to execute method '$method' on controller '$controller'.", 404);
		}
		if ($status !== self::EXEC_FORWARD)
			return ($status);

		/* ********** finalization ********** */
		$method = \Temma\Web\Framework::CONTROLLERS_FINALIZE_METHOD;
		try {
			$status = $obj->$method();
		} catch (\Error $e) {
			TµLog::log('Temma/Web', 'ERROR', "Unable to finalize the controller '$controller'.");
			throw new TµHttpException("Unable to finalize the controller '$controller'.", 500);
		}
		return ($status);
	}
}

