<?php

/**
 * Controller
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2012-2026, Amaury Bouchard
 */

namespace Temma\Web;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Http as TµHttpException;
use \Temma\Exceptions\Flow as TµFlowException;

/**
 * Basic object for controllers management.
 */
class Controller implements \ArrayAccess {
	/** Execution flow constant: go to the next step (next plugin, for example). */
	const EXEC_FORWARD = null;
	/** Execution flow constant: same as EXEC_FORWARD, but can be used as an exception code. */
	const EXEC_FORWARD_THROWABLE = 0;
	/** Execution flow constant: stop the current flow (pre-plugins, controller, post-plugins). */
	const EXEC_STOP = 1;
	/** Execution flow constant: stop controller/plugins execution and go to the view. */
	const EXEC_HALT = 2;
	/** Execution flow constant: stop everything (even the view). */
	const EXEC_QUIT = 3;
	/**
	 * Execution flow constant: restart the execution.
	 * The behaviour depends on what has returned this value:
	 * - If it's a pre-plugin, the execution restarts from the first pre-plugin.
	 * - If it's a controller method (__wakeup, action or __sleep), the execution restarts from the init method.
	 * - If it's a post-plugin, the execution restarts from the first post-plugin.
	 */
	const EXEC_RESTART = 4;
	/** Execution flow constant: restart the whole execution, from the first pre-plugin. */
	const EXEC_REBOOT = 5;
	/** Loader object (dependency injection container). */
	protected \Temma\Base\Loader $_loader;
	/** Session object. */
	protected ?\Temma\Base\Session $_session = null;
	/** Config object. */
	protected ?\Temma\Web\Config $_config = null;
	/** Request object. */
	protected ?\Temma\Web\Request $_request = null;
	/** Response object. */
	protected ?\Temma\Web\Response $_response = null;
	/** DAO object. */
	protected ?\Temma\Dao\Dao $_dao = null;
	/** Configuration of auto DAO. */
	protected $_temmaAutoDao = null;

	/**
	 * Constructor.
	 * @param	\Temma\Base\Loader	$loader		Dependency injection object, with (at least) the keys
	 *							'dataSources', 'session', 'config', 'request', 'response'.
	 * @param	\Temma\Web\Controller	$executor	(optional) Executor controller object (the one who called this controller).
	 */
	public function __construct(\Temma\Base\Loader $loader, ?\Temma\Web\Controller $executor=null) {
		$this->_loader = $loader;
		if (isset($loader->session))
			$this->_session = $loader->session;
		if (isset($loader->config))
			$this->_config = $loader->config;
		if (isset($loader->request))
			$this->_request = $loader->request;
		if (isset($loader->response))
			$this->_response = $loader->response;
		// top-level controller: definition of the template variable which contains the session ID
		if (is_null($executor) && isset($loader->session) && $loader->config->enableSessions) {
			$this['SESSIONID'] = $loader->session->getSessionId();
		}
		// creation of the DAO if needed
		if (isset($this->_temmaAutoDao) && $this->_temmaAutoDao !== false) {
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
	 * The return could be an constant (e.g. `return self::EXEC_QUIT;`) or null (`return (null);`) or nothing (`return;`).
	 * Null and zero return values are the same than returning `self::EXEC_FORWARD`.
	 * @link	https://www.temma.net/documentation/flow
	 */
	public function __wakeup() {
	}
	/**
	 * Finalization function.
	 * Called for each controller after the action.
	 * Could be overloaded in all controllers.
	 * The return could be an constant (e.g. `return self::EXEC_QUIT;`) or null (`return (null);`) or nothing (`return;`).
	 * Null and zero return values are the same than returning `self::EXEC_FORWARD`.
	 * @link	https://www.temma.net/documentation/flow
	 */
	public function __sleep() {
	}

	/* ********** DAO MANAGEMENT ********** */
	/**
	 * Load a DAO.
	 * @param	string|array	$param	Name of the DAO object, or an associative array with parameters.
	 * @return	\Temma\Dao\Dao	The loaded DAO.
	 */
	public function _loadDao(null|bool|string|array $param) : \Temma\Dao\Dao {
		$daoConf = [
			'object'   => '\Temma\Dao\Dao',
			'criteria' => null,
			'source'   => null,
			'cache'    => true,
			'base'     => null,
			'table'    => $this->_getDaoTableName(),
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
		if (isset($daoConf['source']) && isset($this->_loader->dataSources[$daoConf['source']]))
			$dataSource = $this->_loader->dataSources[$daoConf['source']];
		else
			$dataSource = $this->_loader->dataSources[\Temma\Web\Framework::DEFAULT_DATASOURCE] ?? reset($this->_loader->dataSources);
		if (!is_a($dataSource, '\Temma\Datasources\Sql'))
			throw new \Temma\Exceptions\Database("DAO creation: The used data source is not of type '\\Temma\Datasources\\Sql'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		$dao = new $daoConf['object']($dataSource, ($daoConf['cache'] ? $this->_loader->cache : null), $daoConf['table'], $daoConf['id'],
		                              $daoConf['base'], $daoConf['fields'], $daoConf['criteria']);
		return ($dao);
	}
	/**
	 * Compute the default DAO table name, from the name of the current controller class
	 * (namespace and controllers' suffix removed, first letter in lower case).
	 * @return	string	The table name.
	 */
	protected function _getDaoTableName() : string {
		$name = get_class($this);
		if (($pos = mb_strrpos($name, '\\')) !== false)
			$name = mb_substr($name, $pos + 1);
		$suffix = $this->_config?->controllersSuffix;
		if ($suffix && mb_strlen($name) > mb_strlen($suffix) && str_ends_with($name, $suffix))
			$name = mb_substr($name, 0, -mb_strlen($suffix));
		return (lcfirst($name));
	}

	/* ********** METHODS CALLABLE BY THE CHILDREN OBJECTS ********** */
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
		$this->_response->setHttpError($code);
		return (self::EXEC_HALT);
	}
	/**
	 * Method used to tell the HTTP return code (like the httpError() method,
	 * but without raising an error).
	 * @param	int	$code	The HTTP return code.
	 * @return	int	self::EXEC_HALT (useful value to return from the controller).
	 */
	final protected function _httpCode(int $code) : int {
		$this->_response->setHttpCode($code);
		return (self::EXEC_HALT);
	}
	/**
	 * Returns the configured HTTP error.
	 * @return	int	The configured error code (403, 404, 500, ...) or null
	 *			if no error was configured.
	 */
	final protected function _getHttpError() : ?int {
		return ($this->_response->getHttpError());
	}
	/**
	 * Returns the configured HTTP return code.
	 * @return	int	The configured return code (200 by default).
	 */
	final protected function _getHttpCode() : int {
		return ($this->_response->getHttpCode());
	}
	/**
	 * Define an HTTP redirection (302 by default).
	 * @param	?string	$url		(optional) Redirection URL, or null to remove the redirection.
	 * @param	bool	$referer	(optional) True to use the HTTP REFERER as redirection URL, with $url as fallback.
	 *					False by default.
	 * @param	int	$code		(optional) Redirection code (301, 302, 303, 307, 308...). 302 by default.
	 * @return	int	self::EXEC_HALT (useful value to return from the controller).
	 */
	final protected function _redirect(?string $url=null, bool $referer=false, int $code=302) : int {
		$this->_response->setRedirection($url, $code, $referer);
		return (self::EXEC_HALT);
	}
	/**
	 * Define a permanent HTTP redirection (301). Shortcut for _redirect($url, $referer, 301).
	 * @param	?string	$url		(optional) Redirection URL.
	 * @param	bool	$referer	(optional) True to use the HTTP REFERER as redirection URL, with $url as fallback.
	 *					False by default.
	 * @return	int	self::EXEC_HALT (useful value to return from the controller).
	 */
	final protected function _redirect301(?string $url=null, bool $referer=false) : int {
		$this->_response->setRedirection($url, 301, $referer);
		return (self::EXEC_HALT);
	}
	/**
	 * Define the view to use.
	 * @param	null|false|string	$view	(optional) Name of the view. Null (or left empty) to use the default view
	 *						(as defined in the configuration). False to disable the view processing.
	 * @return	?int	self::EXEC_FORWARD (useful value to return from the controller).
	 */
	final protected function _view(null|false|string $view=null) : ?int {
		$this->_response->setView($view);
		return (self::EXEC_FORWARD);
	}
	/**
	 * Define the template to use.
	 * @param	string	$template	Template name.
	 * @return	?int	self::EXEC_FORWARD (useful value to return from the controller).
	 */
	final protected function _template(string $template) : ?int {
		$this->_response->setTemplate($template);
		return (self::EXEC_FORWARD);
	}
	/**
	 * Define the prefix to the template path.
	 * @param	string	$prefix	The template prefix path.
	 * @return	\Temma\Web\Controller	The current object.
	 */
	final protected function _templatePrefix(string $prefix) : \Temma\Web\Controller {
		$this->_response->setTemplatePrefix($prefix);
		return ($this);
	}
	/**
	 * Define a view header.
	 * @param	string	$header	The header string.
	 * @return	\Temma\Web\Controller	The current object.
	 */
	final protected function _header(string $header) : \Temma\Web\Controller {
		$this->_response->header($header);
		return ($this);
	}
	/**
	 * Define the content type of the response (sent by the view as Content-Type header,
	 * instead of the view's default content type).
	 * @param	?string	$contentType	The MIME type (like "image/png") or an alias (like "json"),
	 *					or null to use the view's default content type.
	 * @return	\Temma\Web\Controller	The current object.
	 * @throws	\Temma\Exceptions\Framework	If the given alias is unknown.
	 */
	final protected function _contentType(?string $contentType) : \Temma\Web\Controller {
		$this->_response->setContentType($contentType);
		return ($this);
	}
	/**
	 * Returns the defined redirection URL.
	 * @return	?string	The URL, or null if no redirection was defined.
	 */
	final protected function _getRedirect() : ?string {
		return ($this->_response->getRedirection());
	}
	/**
	 * Returns the defined view.
	 * @return	null|false|string	The view name, false if the view processing is disabled,
	 *				or null if no view was defined (the default view will be used).
	 */
	final protected function _getView() : null|false|string {
		return ($this->_response->getView());
	}
	/**
	 * Returns the defined template.
	 * @return	?string	The template name, or null if no template was defined.
	 */
	final protected function _getTemplate() : ?string {
		return ($this->_response->getTemplate());
	}
	/**
	 * Returns the defined template prefix.
	 * @return	?string	The prefix, or null if no prefix was defined.
	 */
	final protected function _getTemplatePrefix() : ?string {
		return ($this->_response->getTemplatePrefix());
	}
	/**
	 * Returns the defined view headers.
	 * @return	array	List of header strings.
	 */
	final protected function _getHeaders() : array {
		return ($this->_response->getHeaders());
	}
	/**
	 * Returns the defined content type.
	 * @return	?string	The MIME type, or null if no content type was defined.
	 */
	final protected function _getContentType() : ?string {
		return ($this->_response->getContentType());
	}

	/* ********** MANAGEMENT OF "TEMPLATE VARIABLES" ********** */
	/**
	 * Set a template variable, array-like syntax.
	 * @param	mixed	$name	Name of the variable.
	 * @param	mixed	$value	Associated value.
	 */
	public function offsetSet(mixed $name, mixed $value) : void {
		$this->_response[$name] = $value;
	}
	/**
	 * Return a template variable, array-like syntax.
	 * @param	mixed	$name	Variable name.
	 * @return	mixed	The template variable's data or null if it doesn't exist.
	 */
	public function offsetGet(mixed $name) : mixed {
		return ($this->_response[$name] ?? null);
	}
	/**
	 * Remove a template variable.
	 * @param	mixed	$name	Name of the variable.
	 */
	public function offsetUnset(mixed $name) : void {
		unset($this->_response[$name]);
	}
	/**
	 * Tell if a template variable exists.
	 * @param	mixed	$name	Name of the variable.
	 * @return	bool	True if the variable was defined, false otherwise.
	 */
	public function offsetExists(mixed $name) : bool {
		return (isset($this->_response[$name]));
	}

	/* ********** SUB-PROCESS ********** */
	/**
	 * Process a sub-controller.
	 *
	 * Only public methods declared by the application, whose name starts with a lower-case letter, are callable
	 * as actions. Methods whose name starts with an underscore (framework helpers, magic methods) and methods
	 * declared by the framework's base classes (\Temma\Web\Controller and its descendants in the \Temma\Web namespace)
	 * are never callable as actions. When the controller has a proxy action, the raw action name is given to the
	 * proxy, which is responsible for the dispatch.
	 * @param	string	$controller	Controller name.
	 * @param	string	$action		(optional) Action name. Call the root action if not defined.
	 * @param	array	$parameters	(optional) List of parameters given to the sub-controller.
	 *					If not given, use the parameters received by the main controller.
	 * @return	?int	The sub-controller's execution status (self::EXE_FORWARD, etc.). Could be null (==self::EXEC_FORWARD).
	 * @throws	\Temma\Exceptions\Http	If the requested controller or action doesn't exist, or if the action is not callable.
	 * @throws	\Temma\Exceptions\Flow	If the requested controller or action throws a Flow exception.
	 */
	final public function _subProcess(string $controller, ?string $action=null, ?array $parameters=null) : ?int {
		TµLog::log('Temma/Web', 'DEBUG', "Subprocess of '$controller'::'$action'.");
		// checks
		if (!is_subclass_of($controller, '\Temma\Web\Controller')) {
			TµLog::log('Temma/Web', 'ERROR', "Sub-controller '$controller' doesn't exist.");
			throw new TµHttpException("Unable to find controller '$controller'.", 404);
		}

		/* ********** init ********** */
		// creation of the sub-controller
		$this->_loader->parentController = $this;
		$obj = $this->_loader->$controller;
		$this->_loader->parentController = null;
		// define the controller in the loader
		$this->_loader['controller'] = $obj;
		// init of the sub-controller
		$method = \Temma\Web\Framework::CONTROLLERS_INIT_METHOD;
		try {
			$status = $obj->$method();
		} catch (TµFlowException $fe) {
			// flow control exception: let it bubble up to the framework
			throw $fe;
		} catch (\Throwable $e) {
			TµLog::log('Temma/Web', 'ERROR', "Unable to initialize the controller '$controller' [" . $e->getFile() . ':' . $e->getLine() . ']: ' . $e->getMessage());
			throw new TµHttpException("Unable to initialize the controller '$controller'.", 500);
		}
		if ($status) // $status !== self::EXEC_FORWARD && $status !== self::EXEC_FORWARD_THROWABLE
			return ($status);

		/* ********** find the right method to execute ********** */
		$isProxyAction = false;
		$isDefaultAction = false;
		$actionReflection = null;
		// check if this sub-controller has a proxy action
		if (method_exists($controller, \Temma\Web\Framework::CONTROLLERS_PROXY_ACTION)) {
			// proxy action found
			$method = \Temma\Web\Framework::CONTROLLERS_PROXY_ACTION;
			$isProxyAction = true;
		} else if (method_exists($controller, \Temma\Web\Framework::CONTROLLERS_OLD_PROXY_ACTION)) {
			// old proxy action found
			$method = \Temma\Web\Framework::CONTROLLERS_OLD_PROXY_ACTION;
			$isProxyAction = true;
		} else {
			// no proxy action defined on this controller
			if (empty($action)) {
				// no action was requested, use the root action
				$action = \Temma\Web\Framework::CONTROLLERS_ROOT_ACTION;
			} else if ($action !== \Temma\Web\Framework::CONTROLLERS_ROOT_ACTION) {
				// the action must start with a lower-case letter; underscore-prefixed methods are reserved to the framework
				$firstLetter = $action[0];
				if ($firstLetter === '_' || $firstLetter !== strtolower($firstLetter)) {
					TµLog::log('Temma/Web', 'ERROR', "Actions must start with a lower-case letter (here: '$action').");
					throw new TµHttpException("Actions must start with a lower-case letter (here: '$action').", 404);
				}
			}
			if (method_exists($obj, $action)) {
				// the action exists: it must be a public method declared by the application, not by the framework
				$actionReflection = new \ReflectionMethod($obj, $action);
				if (!$actionReflection->isPublic() ||
				    str_starts_with($actionReflection->getDeclaringClass()->getName(), 'Temma\\Web\\')) {
					TµLog::log('Temma/Web', 'ERROR', "Method '$action' of controller '$controller' is not an action.");
					throw new TµHttpException("Method '$action' of controller '$controller' is not an action.", 404);
				}
				$method = $action;
			} else if (method_exists($obj, \Temma\Web\Framework::CONTROLLERS_DEFAULT_ACTION)) {
				// the action doesn't exist, but there is a default action that could handle it
				$method = $action;
				$isDefaultAction = true;
			} else {
				// no proxy action, no action method, no default action
				throw new TµHttpException("Unable to find action '$action' on controller '$controller'.", 404);
			}
		}

		/* ********** attributes on the action ********** */
		$actionReflection ??= new \ReflectionMethod($obj, ($isDefaultAction ? \Temma\Web\Framework::CONTROLLERS_DEFAULT_ACTION : $method));
		$attributes = $actionReflection->getAttributes(\Temma\Web\Attribute::class, \ReflectionAttribute::IS_INSTANCEOF);
		foreach ($attributes as $attribute) {
			TµLog::log('Temma/Web', 'DEBUG', "Action attribute '{$attribute->getName()}'.");
			// instantiate the attribute
			$instance = $attribute->newInstance();
			// initialize the attribute
			$instance->init($this->_loader);
			// apply the attribute
			$instance->apply($actionReflection);
		}

		/* ********** execution ********** */
		$parameters = $parameters ?? $this->_loader->request->getParams();
		try {
			// call the action (proxy action are called in a special way)
			if ($isProxyAction)
				$status = $obj->$method($action, $parameters);
			else
				$status = $obj->$method(...$parameters);
		} catch (TµFlowException $fe) {
			// flow control exception: let it bubble up to the framework
			throw $fe;
		} catch (\ArgumentCountError $ace) {
			TµLog::log('Temma/Web', 'ERROR', "$controller::$method: " . $ace->getMessage());
			throw new TµHttpException("$controller::$method: " . $ace->getMessage(), 404);
		} catch (\Throwable $e) {
			TµLog::log('Temma/Web', 'ERROR', "$controller::$method" . '[' . $e->getFile() . ':' . $e->getLine() . ']: ' . $e->getMessage());
			throw new TµHttpException("Unable to execute method '$method' on controller '$controller'.", 404);
		}
		if ($status) // $status !== self::EXEC_FORWARD && $status !== self::EXEC_FORWARD_THROWABLE
			return ($status);

		/* ********** finalization ********** */
		$method = \Temma\Web\Framework::CONTROLLERS_FINALIZE_METHOD;
		try {
			$status = $obj->$method();
		} catch (TµFlowException $fe) {
			// flow control exception: let it bubble up to the framework
			throw $fe;
		} catch (\Throwable $e) {
			TµLog::log('Temma/Web', 'ERROR', "Unable to finalize the controller '$controller' [" . $e->getFile() . ':' . $e->getLine() . ']: ' . $e->getMessage());
			throw new TµHttpException("Unable to finalize the controller '$controller'.", 500);
		}
		return ($status);
	}
}

