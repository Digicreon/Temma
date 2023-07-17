<?php

/**
 * Attribute
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
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
	/* ********** METHODS CALLABLE BY THE CHILDREN OBJECTS ********** */
	/**
	 * Magical method which returns the requested data source.
	 * @param	string	$dataSource	Name of the data source.
	 * @return	\Temma\Base\Datasource	Data source object, or null if the source is not set.
	 */
	final public function __get(string $dataSource) : ?\Temma\Base\Datasource {
		global $temma;
		return ($temma->getLoader()->dataSources[$dataSource] ?? null);
	}
	/**
	 * Magical method used to know if a data source exists.
	 * @param	string	$dataSource	Name of the data source.
	 * @return	bool	True if the data source exists.
	 */
	final public function __isset(string $dataSource) : bool {
		global $temma;
		return (isset($temma->getLoader()->dataSources[$dataSource]));
	}
	/**
	 * Returns the loader object.
	 * @return	\Temma\Base\Loader	The loader object.
	 */
	final protected function _getLoader() : \Temma\Base\Loader {
		global $temma;
		return ($temma->getLoader());
	}
	/**
	 * Returns the session object.
	 * @return	\Temma\Base\Session	The session object.
	 */
	final protected function _getSession() : \Temma\Base\Session {
		global $temma;
		return ($temma->getLoader()->session);
	}
	/**
	 * Returns the configuration object.
	 * @return	\Temma\Web\Config	The configuration object.
	 */
	final protected function _getConfig() : \Temma\Web\Config {
		global $temma;
		return ($temma->getLoader()->config);
	}
	/**
	 * Returns the request object.
	 * @return	\Temma\Web\Request	The request object.
	 */
	final protected function _getRequest() : \Temma\Web\Request {
		global $temma;
		return ($temma->getLoader()->request);
	}
	/**
	 * Returns the response object.
	 * @return	\Temma\Web\Response	The response object.
	 */
	final protected function _getResponse() : \Temma\Web\Response {
		global $temma;
		return ($temma->getLoader()->response);
	}
	/**
	 * Method used to raise en HTTP error (403, 404, 500, ...).
	 * @param	int	$code	The HTTP error code.
	 */
	final protected function _httpError(int $code) : void {
		global $temma;
		$temma->getLoader()->response->setHttpError($code);
	}
	/**
	 * Method used to tell the HTTP return code (like the httpError() method,
	 * but without raising an error).
	 * @param	int	$code	The HTTP return code.
	 */
	final protected function _httpCode(int $code) :void {
		global $temma;
		$temma->getLoader()->response->setHttpCode($code);
	}
	/**
	 * Returns the configured HTTP error.
	 * @return	int	The configured error code (403, 404, 500, ...) or null
	 *			if no error was configured.
	 */
	final protected function _getHttpError() : ?int {
		global $temma;
		return ($temma->getLoader()->response->getHttpError());
	}
	/**
	 * Returns the configured HTTP return code.
	 * @return	int	The configured return code, or null if no code was configured.
	 */
	final protected function _getHttpCode() : ?int {
		global $temma;
		return ($temma->getLoader()->response->getHttpCode());
	}
	/**
	 * Define an HTTP redirection (302).
	 * @param	?string	$url	Redirection URL, or null to remove the redirection.
	 */
	final protected function _redirect(?string $url) : void {
		global $temma;
		$temma->getLoader()->response->setRedirection($url);
	}
	/**
	 * Define an HTTP redirection (301).
	 * @param	string	$url	Redirection URL.
	 */
	final protected function _redirect301(string $url) : void {
		global $temma;
		$temma->getLoader()->response->setRedirection($url, true);
	}
	/**
	 * Define the view to use.
	 * @param	string	$view	Name of the view.
	 * @return	\Temma\Web\Attribute	The current object.
	 */
	final protected function _view(string $view) : \Temma\Web\Attribute {
		global $temma;
		$temma->getLoader()->response->setView($view);
		return ($this);
	}
	/**
	 * Define the template to use.
	 * @param	string	$template	Template name.
	 * @return	\Temma\Web\Attribute	The current object.
	 */
	final protected function _template(string $template) : \Temma\Web\Attribute {
		global $temma;
		$temma->getLoader()->response->setTemplate($template);
		return ($this);
	}
	/**
	 * Define the prefix to the template path.
	 * @param	string	$prefix	The template prefix path.
	 * @return	\Temma\Web\Attribute	The current object.
	 */
	final protected function _templatePrefix(string $prefix) : \Temma\Web\Attribute {
		global $temma;
		$temma->getLoader()->response->setTemplatePrefix($prefix);
		return ($this);
	}

	/* ********** MANAGEMENT OF "TEMPLATE VARIABLES" ********** */
	/**
	 * Set a template variable, array-like syntax.
	 * @param       string  $name   Name of the variable.
	 * @param       mixed   $value  Associated value.
	 */
	final public function offsetSet(mixed $name, mixed $value) : void {
		global $temma;
		$temma->getLoader()->response[$name] = $value;
	}
	/**
	 * Return a template variable, array-like syntax.
	 * @param       string  $name   Variable name.
	 * @return      mixed   The template variable's data or null if it doesn't exist.
	 */
	public function offsetGet(mixed $name) : mixed {
		global $temma;
		return ($temma->getLoader()->response[$name] ?? null);
	}
	/**
	 * Remove a template variable.
	 * @param       string  $name   Name of the variable.
	 */
	public function offsetUnset(mixed $name) : void {
		global $temma;
		unset($temma->getLoader()->response[$name]);
	}
	/**
	 * Tell if a template variable exists.
	 * @param       string  $name   Name of the variable.
	 * @return      bool    True if the variable was defined, false otherwise.
	 */
	public function offsetExists(mixed $name) : bool {
		global $temma;
		return (isset($temma->getLoader()->response[$name]));
	}
}

