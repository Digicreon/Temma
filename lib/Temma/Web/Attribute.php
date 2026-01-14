<?php

/**
 * Attribute
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023-2026, Amaury Bouchard
 */

namespace Temma\Web;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Base object for Temma attributes, used to affect the behaviour of the framework when an action is accessed.
 *
 * Can't be instanciated directly. Real attributes must inherit from this class.
 *
 * @see	\Temma\Web\Controller
 */
abstract class Attribute implements \ArrayAccess {
	/** Loader. */
	protected ?\Temma\Base\Loader $_loader = null;
	/** Config. */
	protected ?\Temma\Web\Config $_config = null;
	/** Request. */
	protected ?\Temma\Web\Request $_request = null;
	/** Response. */
	protected ?\Temma\Web\Response $_response = null;
	/** Session. */
	protected ?\Temma\Base\Session $_session = null;

	/* ********** INITIALIZATION AND PROCESSING ********** */
	/**
	 * Initialization of the attribute.
	 * This methode should be called by the framework only.
	 * @param	?\Temma\Base\Loader	$loader	Dependency injection component.
	 */
	public function init(?\Temma\Base\Loader $loader) : void {
		if (!$loader)
			return;
		$this->_loader = $loader;
		$this->_config = $loader->config;
		$this->_request = $loader->request;
		$this->_response = $loader->response;
		$this->_session = $loader->session;
	}
	/**
	 * Processing of the attribute.
	 * @param	\Reflector	$context	Context of the element on which the attribute is applied
	 *						(ReflectionClass, ReflectionMethod or ReflectionFunction).
	 */
	public function apply(\Reflector $context) : void {
	}

	/* ********** METHODS CALLABLE BY THE CHILDREN OBJECTS ********** */
	/**
	 * Magical method which returns the requested data source.
	 * @param	string	$dataSource	Name of the data source.
	 * @return	\Temma\Base\Datasource	Data source object, or null if the source is not set.
	 */
	final public function __get(string $dataSource) : ?\Temma\Base\Datasource {
		return ($this->_loader?->dataSources[$dataSource] ?? null);
	}
	/**
	 * Magical method used to know if a data source exists.
	 * @param	string	$dataSource	Name of the data source.
	 * @return	bool	True if the data source exists.
	 */
	final public function __isset(string $dataSource) : bool {
		return (isset($this->_loader?->dataSources[$dataSource]));
	}
	/**
	 * Method used to raise en HTTP error (403, 404, 500, ...).
	 * @param	int	$code	The HTTP error code.
	 */
	final protected function _httpError(int $code) : void {
		$this->_response?->setHttpError($code);
	}
	/**
	 * Method used to tell the HTTP return code (like the httpError() method,
	 * but without raising an error).
	 * @param	int	$code	The HTTP return code.
	 */
	final protected function _httpCode(int $code) : void {
		$this->_response?->setHttpCode($code);
	}
	/**
	 * Returns the configured HTTP error.
	 * @return	int	The configured error code (403, 404, 500, ...) or null
	 *			if no error was configured.
	 */
	final protected function _getHttpError() : ?int {
		return ($this->_response?->getHttpError());
	}
	/**
	 * Returns the configured HTTP return code.
	 * @return	int	The configured return code, or null if no code was configured.
	 */
	final protected function _getHttpCode() : ?int {
		return ($this->_response?->getHttpCode());
	}
	/**
	 * Define an HTTP redirection (302).
	 * @param	?string	$url	Redirection URL, or null to remove the redirection.
	 */
	final protected function _redirect(?string $url) : void {
		$this->_response?->setRedirection($url);
	}
	/**
	 * Define an HTTP redirection (301).
	 * @param	string	$url	Redirection URL.
	 */
	final protected function _redirect301(string $url) : void {
		$this->_response?->setRedirection($url, true);
	}
	/**
	 * Define the view to use.
	 * @param	string	$view	Name of the view.
	 * @return	\Temma\Web\Attribute	The current object.
	 */
	final protected function _view(string $view) : \Temma\Web\Attribute {
		$this->_response?->setView($view);
		return ($this);
	}
	/**
	 * Define the template to use.
	 * @param	string	$template	Template name.
	 * @return	\Temma\Web\Attribute	The current object.
	 */
	final protected function _template(string $template) : \Temma\Web\Attribute {
		$this->_response?->setTemplate($template);
		return ($this);
	}
	/**
	 * Define the prefix to the template path.
	 * @param	string	$prefix	The template prefix path.
	 * @return	\Temma\Web\Attribute	The current object.
	 */
	final protected function _templatePrefix(string $prefix) : \Temma\Web\Attribute {
		$this->_response?->setTemplatePrefix($prefix);
		return ($this);
	}

	/* ********** MANAGEMENT OF "TEMPLATE VARIABLES" ********** */
	/**
	 * Set a template variable, array-like syntax.
	 * @param       string  $name   Name of the variable.
	 * @param       mixed   $value  Associated value.
	 */
	final public function offsetSet(mixed $name, mixed $value) : void {
		if ($this->_response)
			$this->_response[$name] = $value;
	}
	/**
	 * Return a template variable, array-like syntax.
	 * @param       string  $name   Variable name.
	 * @return      mixed   The template variable's data or null if it doesn't exist.
	 */
	public function offsetGet(mixed $name) : mixed {
		return ($this->_response[$name] ?? null);
	}
	/**
	 * Remove a template variable.
	 * @param       string  $name   Name of the variable.
	 */
	public function offsetUnset(mixed $name) : void {
		if ($this->_response)
			unset($this->_response[$name]);
	}
	/**
	 * Tell if a template variable exists.
	 * @param       string  $name   Name of the variable.
	 * @return      bool    True if the variable was defined, false otherwise.
	 */
	public function offsetExists(mixed $name) : bool {
		if (!$this->_response)
			return (false);
		return (isset($this->_response[$name]));
	}
}

