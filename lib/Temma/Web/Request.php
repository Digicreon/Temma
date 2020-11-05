<?php

/**
 * Request
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2019, Amaury Bouchard
 */

namespace Temma\Web;

use \Temma\Base\Log as TµLog;

/**
 * Object use to manage HTTP requests.
 */
class Request {
	/** PathInfo data. */
	private $_pathInfo = null;
	/** Name of the requested controller. */
	private $_controller = null;
	/** Name of the requested action. */
	private $_action = null;
	/** Parameters for the action. */
	private $_params = null;
	/** Path from the site root. */
	private $_sitePath = null;
	/** Received JSON data. */
	private $_json = null;
	/** Received XML data. */
	private $_xml = null;

	/**
	 * Constructor.
	 * @param	string	$setUri	(optional) Path to use to extract the execution elements (controller, action, parameters),
	 *				without going through the analysis of the current URL.
	 * @throws	\Temma\Exceptions\FrameworkException	If no PATH_INFO nor REQUEST_URI data found in the environment.
	 */
	public function __construct(?string $setUri=null) {
		TµLog::log('Temma/Web', 'DEBUG', "Request creation.");
		if (isset($setUri)) {
			// use the path given as parameter
			$requestUri = '/' . trim($setUri, '/') . '/';
		} else {
			// extraction of the path from the site root
			if (isset($_SERVER['REQUEST_URI']) && !empty($_SERVER['REQUEST_URI'])) {
				$requestUri = $_SERVER['REQUEST_URI'];
				if (($offset = strpos($requestUri, '?')) !== false)
					$requestUri = substr($requestUri, 0, $offset);
			} else if (isset($_SERVER['PATH_INFO']) && !empty($_SERVER['PATH_INFO'])) {
				// get the script's execution path
				$rootPath = $_SERVER['SCRIPT_FILENAME'];
				if (substr($rootPath, -10) == '/index.php')
					$rootPath = substr($rootPath, 0, -10);
				// get the path (pruning the scrip's execution path and the GET parameters, if needed)
				$requestUri = $_SERVER['PATH_INFO'];
				$rootPathLen = strlen($rootPath);
				if (substr($requestUri, 0, $rootPathLen) === $rootPath)
					$requestUri = substr($requestUri, $rootPathLen);
			} else
				throw new \Temma\Exceptions\FrameworkException('No PATH_INFO nor REQUEST_URI environment variable.', \Temma\Exceptions\FrameworkExceptions::CONFIG);
			// for SEO purpose: if the URL ends with a slash, do a redirection without it
			// hint: PATH_INFO is not filled when we access to the Temma project's root (being at the site root or in a sub-directory)
			if (isset($_SERVER['PATH_INFO']) && !empty($PATH_INFO) && substr($requestUri, -1) == '/') {
				$url = substr($requestUri, 0, -1) . ((isset($_SERVER['QUERY_STRING']) && !empty($_SERVER['QUERY_STRING'])) ? ('?' . $_SERVER['QUERY_STRING']) : '');
				TµLog::log('Temma/Web', 'DEBUG', "Redirecting to '$url'.");
				header('HTTP/1.1 301 Moved Permanently');
				header("Location: $url");
				exit();
			}
		}
		TµLog::log('Temma/Web', 'INFO', "URL : '$requestUri'.");
		$this->_pathInfo = $requestUri;
		// extraction of URL components
		$chunkedUri = explode('/', $requestUri);
		array_shift($chunkedUri);
		$this->_controller = array_shift($chunkedUri);
		$this->_action = array_shift($chunkedUri);
		$this->_params = [];
		foreach ($chunkedUri as $chunk)
			if (strlen(trim($chunk)) > 0)
				$this->_params[] = $chunk;
		// extraction of the path from the site root
		$this->_sitePath = dirname($_SERVER['SCRIPT_NAME']);
	}

	/* ***************** GETTERS *************** */
	/**
	 * Returns the pathInfo.
	 * @return 	string	The pathInfo.
	 */
	public function getPathInfo() : string {
		return ($this->_pathInfo);
	}
	/**
	 * Returns the requested controller's name.
	 * @return	string|null	The controller name or null if it was not set.
	 */
	public function getController() : ?string {
		return ($this->_controller);
	}
	/**
	 * Returns the requested action's name.
	 * @return	string|null	The action name, or null if it was not set.
	 */
	public function getAction() : ?string {
		return ($this->_action);
	}
	/**
	 * Returns parameters count.
	 * @return	int	The count.
	 */
	public function getNbrParams() : int {
		return (is_array($this->_params) ? count($this->_params) : 0);
	}
	/**
	 * Returns action parameters.
	 * @return	array	The list of parameters.
	 */
	public function getParams() : array {
		return ($this->_params);
	}
	/**
	 * Returns an action parameter.
	 * @param	int	$index		Parameter's index.
	 * @param	mixed	$default	(optional) Default value if the parameter doesn't exist.
	 * @return	string	The associated value.
	 */
	public function getParam(int $index, /* mixed */ $default=null) : string {
		if (isset($this->_params[$index]))
			return ($this->_params[$index]);
		return ($default);
	}
	/**
	 * Returns the path from the site root.
	 * @return	string	The path.
	 */
	public function getSitePath() : string {
		return ($this->_sitePath);
	}

	/* ***************** SETTERS *************** */
	/**
	 * Define the controller's name.
	 * @param	string	$name	The name.
	 */
	public function setController(string $name) : void {
		$this->_controller = $name;
	}
	/**
	 * Define the the action's name.
	 * @param	string	$name	The name.
	 */
	public function setAction(string $name) : void {
		$this->_action = $name;
	}
	/**
	 * Define the action parameters as they would have been received on the URL.
	 * @param	array	$data	Array of strings.
	 */
	public function setParams(array $data) : void {
		if (is_array($data))
			$this->_params = $data;
	}
	/**
	 * Define an action parameter.
	 * @param	int	$index	Offset of the parameter.
	 * @param	string	$value	Associated value.
	 */
	public function setParam(int $index, string $value) : void {
		$this->_params[$index] = $value;
	}
}

