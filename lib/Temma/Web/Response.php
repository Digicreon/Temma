<?php

/**
 * Response
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2023, Amaury Bouchard
 */

namespace Temma\Web;

use \Temma\Base\Log as TµLog;

/**
 * Object use to manage the response of a controller execution.
 */
class Response implements \ArrayAccess {
	/** Redirection URL. */
	private ?string $_redirect = null;
	/** Redirection code (301, 302). */
	private int $_redirectCode = 302;
	/** HTTP error code. */
	private ?int $_httpError = null;
	/** HTTP return code. */
	private int $_httpCode = 200;
	/** Name of the view. */
	private null|false|string $_view = null;
	/** Prefix to add at the beginning of the template path. */
	private ?string $_templatePrefix = null;
	/** Name of the template. */
	private ?string $_template = null;
	/** Array of header strings. */
	private array $_headers = [];
	/** Template variables. */
	private array $_data = [];
	/** Prepended response stream. */
	private string $_prependStream = '';
	/** Appended response stream. */
	private string $_appendStream = '';

	/**
	 * Constructor.
	 * @param	string	$view		(optional) Name of the view.
	 * @param	string	$template	(optional) Name of the template.
	 */
	public function __construct(?string $view=null, ?string $template=null) {
		TµLog::log('Temma/Web', 'DEBUG', "Response creation.");
		$this->_view = $view;
		$this->_template = $template;
	}
	/**
	 * Define a redirection.
	 * @param	?string	$url		Redirection URL, or null to remove the redirection.
	 * @param	bool	$code301	True for a 301 redirection. False by default (302 redirection).
	 */
	public function setRedirection(?string $url, bool $code301=false) : void {
		$this->_redirect = $url;
		$this->_redirectCode = $code301 ? 301 : 302;
	}
	/**
	 * Define an HTTP error code.
	 * @param	int	$code	The error code (403, 404, 500, ...).
	 */
	public function setHttpError(int $code) : void {
		$this->_httpError = $code;
	}
	/**
	 * Define an HTTP return code.
	 * @param	int	$code	The return code (403, 404, 500, ...).
	 */
	public function setHttpCode(int $code) : void {
		$this->_httpCode = $code;
	}
	/**
	 * Define the view name.
	 * @param	null|false|string	$view	(optional) The view name.
	 *					Set to null of leave empty to use the default view defined in the 'temma.json' configuration file.
	 *					Set to false to disable the processing of the view.
	 */
	public function setView(null|false|string $view=null) : void {
		$this->_view = $view;
	}
	/**
	 * Define the template name prefix.
	 * @param	?string	$prefix	The prefix.
	 */
	public function setTemplatePrefix(?string $prefix) : void {
		$this->_templatePrefix = $prefix;
	}
	/**
	 * Define the template path.
	 * @param	?string	$template	The template path. Set to null to use the default path.
	 */
	public function setTemplate(?string $template) : void {
		$this->_template = $template;
	}
	/**
	 * Clear all template variable data.
	 */
	public function clearData() : void {
		$this->_data = [];
	}
	/**
	 * Set all template variable data.
	 * @param	array	$data	Associative array containing the data.
	 */
	public function setData(array $data) : void {
		$this->_data = $data;
	}
	/**
	 * Add template variables.
	 * @param	array	$data	Associative array containing the added data.
	 */
	public function addData(array $data) : void {
		$this->_data = array_merge($this->_data, $data);
	}
	/**
	 * Add a view header.
	 * @param	string	$header	The header string.
	 */
	public function header(string $header) : void {
		$this->_headers[] = $header;
	}
	/**
	 * Set the prepend stream.
	 * @param	string	$prependStream	Stream to prepend to the response.
	 */
	public function setPrependStream(string $prependStream) : void {
		$this->_prependStream = $prependStream;
	}
	/**
	 * Add content to the prepend stream.
	 * @param	string	$stream	Stream to prepend to the response.
	 */
	public function addPrependStream(string $stream) : void {
		$this->_prependStream .= $stream;
	}
	/**
	 * Set the append stream.
	 * @param	string	$appendStream	Stream to append to the response.
	 */
	public function setAppendStream(string $appendStream) : void {
		$this->_appendStream = $appendStream;
	}
	/**
	 * Add content to the append stream.
	 * @param	string	$stream	Stream to append to the response.
	 */
	public function addAppendStream(string $stream) : void {
		$this->_appendStream .= $stream;
	}
	/**
	 * Add a template variable, array-like syntax.
	 * @param	mixed	$name	Data name.
	 * @param	mixed	$value	Data value.
	 */
	public function offsetSet(mixed $name, mixed $value) : void {
		$this->_data[$name] = $value;
	}
	/**
	 * Remove a template variable.
	 * @param	mixed	$name	Name of the variable.
	 */
	public function offsetUnset(mixed $name) : void {
		$this->_data[$name] = null;
		unset($this->_data[$name]);
	}

	/* ***************** GETTERS *************** */
	/**
	 * Returns the redirection URL.
	 * @return	string|null	The URL, or null if no redirection was set.
	 */
	public function getRedirection() : ?string {
		return ($this->_redirect);
	}
	/**
	 * Returns the redirection code (301, 302).
	 * @return	int	The code.
	 */
	public function getRedirectionCode() : int {
                return ($this->_redirectCode);
        }
	/**
	 * Returns the HTTP error code if it was defined, or null.
	 * @return	int|null	The HTTP error code (403, 404, 500, ...) or null.
	 */
	public function getHttpError() : ?int {
		return ($this->_httpError);
	}
	/**
	 * Returns the HTTP return code if it was defined, or null.
	 * @return	int	The HTTP return code (403, 404, 500, ...) or 200.
	 */
	public function getHttpCode() : int {
		return ($this->_httpCode);
	}
	/**
	 * Returns the view name.
	 * @return	null|false|string	The view name, or null if it was not set.
	 */
	public function getView() : null|false|string {
		return ($this->_view);
	}
	/**
	 * Returns the template name prefix.
	 * @return	?string	The prefix, or null if it was not set.
	 */
	public function getTemplatePrefix() : ?string {
		return ($this->_templatePrefix);
	}
	/**
	 * Returns the template name.
	 * @return	?string	The template name, or null if it was not set.
	 */
	public function getTemplate() : ?string {
		return ($this->_template);
	}
	/**
	 * Returns the array of header strings.
	 * @return	array	The array of headers.
	 */
	public function getHeaders() : array {
		return ($this->_headers);
	}
	/**
	 * Returns template variable(s), object-oriented syntax.
	 * @param	string	$key		(optional) The name of the data to return.
	 *					If not set, returns the associative array with all template variables.
	 * @param	mixed	$default	(optional) Default value.
	 *					If this parameter is a regular value, it will be stored as a value associated
	 *					to the requested variable, and returned by this method.
	 *					If this parameter is an anonymous function, and the requested data doesn't exist,
	 *					the function will be executed, it's returned value will be stored as a value
	 *					associated to the requested variable, and returned by this method.
	 * @param	mixed	$callbackParam	(optional) Data given as parameter to the function given as the second parameter.
	 *					Not used if the second parameter is not set or is not a callback.
	 * @return	mixed	The requested data, or an associative array with all data.
	 */
	public function getData(?string $key=null, mixed $default=null, mixed $callbackParam=null) : mixed {
		if (is_null($key))
			return ($this->_data);
		if (isset($this->_data[$key]))
			return ($this->_data[$key]);
		if (is_callable($default))
			$default = $default($callbackParam);
		$this[$key] = $default;
		return ($default);
	}
	/**
	 * Returns the prepend stream.
	 * @return	string	The stream to prepend to the response.
	 */
	public function getPrependStream() : string {
		return ($this->_prependStream);
	}
	/**
	 * REturns the append stream.
	 * @return	string	The stream to append to the response.
	 */
	public function getAppendStream() : string {
		return($this->_appendStream);
	}
	/**
	 * Returns a template variable, array-like syntax.
	 * @param	mixed	$name	Name of the variable.
	 * @return	mixed	The associated value, or null.
	 */
	public function offsetGet(mixed $name) : mixed {
		return ($this->_data[$name] ?? null);
	}
	/**
	 * Tell if a template variable exists, array-link syntax.
	 * @param	mixed	$name	Name of the variable.
	 * @return	bool	True if the variable is defined.
	 */
	public function offsetExists(mixed $name) : bool {
		return (isset($this->_data[$name]));
	}
}

