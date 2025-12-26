<?php

/**
 * Request
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2023, Amaury Bouchard
 */

namespace Temma\Web;

use \Temma\Base\Log as TµLog;
use \Temma\Utils\DataFilter as TµDataFilter;
use \Temma\Exceptions\Framework as TµFrameworkException;
use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Object use to manage HTTP requests.
 */
class Request {
	/** PathInfo data. */
	private string $_pathInfo;
	/** HTTP method of the request. */
	private ?string $_method = null;
	/** Name of the requested controller. */
	private ?string $_controller = null;
	/** Name of the requested action. */
	private ?string $_action = null;
	/** Parameters for the action. */
	private ?array $_params = null;
	/** Path from the site root. */
	private string $_sitePath;

	/**
	 * Constructor.
	 * @param	null|bool|string	$setUri	(optional) Path to use to extract the execution elements (controller, action, parameters),
	 *						without going through the analysis of the current URL.
	 *						Set to false to avoid any processing.
	 * @throws	\Temma\Exceptions\Framework	If no PATH_INFO nor REQUEST_URI data found in the environment.
	 */
	public function __construct(null|bool|string $setUri=null) {
		TµLog::log('Temma/Web', 'DEBUG', "Request creation.");
		if ($setUri === false)
			return;
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
				throw new TµFrameworkException('No PATH_INFO nor REQUEST_URI environment variable.', TµFrameworkException::CONFIG);
			// remove multiple slashes at the beginning of the request URI
			$requestUri = '/' . ltrim($requestUri, '/');
			// for SEO purpose: if the URL ends with a slash, do a redirection without it (only for GET requests)
			// hint: PATH_INFO is not filled when we access to the Temma project's root (being at the site root or in a sub-directory)
			if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_SERVER['PATH_INFO']) && !empty($_SERVER['PATH_INFO']) && substr($requestUri, -1) == '/') {
				$url = rtrim($requestUri, '/') . ((isset($_SERVER['QUERY_STRING']) && !empty($_SERVER['QUERY_STRING'])) ? ('?' . $_SERVER['QUERY_STRING']) : '');
				TµLog::log('Temma/Web', 'DEBUG', "Redirecting to '$url'.");
				header('HTTP/1.1 301 Moved Permanently');
				header("Location: $url");
				exit();
			}
		}
		TµLog::log('Temma/Web', 'INFO', "URL : '$requestUri'.");
		$this->_pathInfo = $requestUri;
		$this->_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		/* Extraction of URL components, url-decode them, and remove empty entries. */
		$chunkedUri = explode('/', $requestUri);
		// remove first element (the URL starts with a slash, so the first chunked element is always empty)
		array_shift($chunkedUri);
		// urldecode all URL chunks, and trim them
		array_walk($chunkedUri, function(&$val, $key) {
			$val = trim(\urldecode($val));
		});
		// remove empty elements
		$chunkedUri = array_filter($chunkedUri, function($chunk) {
			return isset($chunk[0]);
		});
		// extraction of the controller, if any
		$this->_controller = array_shift($chunkedUri);
		// extraction of the action, if any
		$this->_action = array_shift($chunkedUri);
		// remaining elements are action's parameters
		$this->_params = $chunkedUri;
		/* Extraction of the path from the site root. */
		$this->_sitePath = dirname($_SERVER['SCRIPT_NAME']);
	}

	/* ***************** GETTERS *************** */
	/**
	 * Tell if the current request is an AJAX request.
	 * @return	bool	True if the "X-Requested-With" header has the "XMLHttpRequest" value.
	 */
	public function isAjax() : bool {
		return (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest');
	}
	/**
	 * Returns the pathInfo.
	 * @return 	string	The pathInfo.
	 */
	public function getPathInfo() : string {
		return ($this->_pathInfo);
	}
	/**
	 * Returns the method.
	 * @return	string	The method.
	 */
	public function getMethod() : string {
		return ($this->_method);
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
	 * @return	?string	The associated value.
	 */
	public function getParam(int $index, mixed $default=null) : ?string {
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
	 * Define the HTTP method.
	 * @param	string	$method	The method.
	 */
	public function setMethod(string $method) : void {
		$this->_method = strtoupper($method);
	}
	/**
	 * Define the controller's name.
	 * @param	string	$name	The name.
	 */
	public function setController(?string $name) : void {
		$this->_controller = $name;
	}
	/**
	 * Define the the action's name.
	 * @param	?string	$name	The name.
	 */
	public function setAction(?string $name) : void {
		$this->_action = $name;
	}
	/**
	 * Define the action parameters as they would have been received on the URL.
	 * @param	array	$data	Array of strings.
	 */
	public function setParams(?array $data) : void {
		if (is_array($data))
			$this->_params = $data;
		else
			$this->_params = [];
	}
	/**
	 * Define an action parameter.
	 * @param	int	$index	Offset of the parameter.
	 * @param	string	$value	Associated value.
	 */
	public function setParam(int $index, string $value) : void {
		$this->_params[$index] = $value;
	}

	/* ***************** VALIDATION *************** */
	/**
	 * Validate parameters.
	 * @param	null|string|array	$parameters	(optional) Associative array of parameters to check, or string for raw payload check.
	 * @param	?string			$type		(optional) Type of check ('GET', 'POST'). If null, checks both if data exists.
	 * @param	bool			$strict		(optional) True to use strict matching. False by default.
	 * @param	null|string|array	$json		(optional) JSON contract to check the payload.
	 * @throws	\Temma\Exceptions\Application	If the parameters are not valid.
	 */
	public function validate(
		null|string|array $parameters=null,
		?string $type=null,
		bool $strict=false,
		null|string|array $json=null,
	) : void {
		$type = strtoupper($type ?? '');
		$checkGet = ($type === 'GET' || ($type === '' && !empty($_GET)));
		$checkPost = ($type === 'POST' || ($type === '' && !empty($_POST)));
		// check GET parameters
		if ($checkGet && is_array($parameters)) {
			$_GET = TµDataFilter::process($_GET, ['type' => 'assoc', 'keys' => $parameters], $strict);
		}
		// check POST parameters
		if ($checkPost) {
			if (!$json && is_array($parameters)) {
				// standard POST parameters check
				$_POST = TµDataFilter::process($_POST, ['type' => 'assoc', 'keys' => $parameters], $strict);
			} else {
				$rawBody = file_get_contents('php://input');
				if (is_string($parameters)) {
					// raw body check
					TµDataFilter::process($rawBody, $parameters, $strict);
				} else if ($json) {
					// JSON check
					$data = json_decode($rawBody, true);
					if (json_last_error() !== JSON_ERROR_NONE) {
						TµLog::log('Temma/Web', 'WARN', "Invalid JSON payload.");
						throw new TµApplicationException("Invalid JSON payload.", TµApplicationException::API);
					}
					TµDataFilter::process($data, $json, $strict);
				} else {
					throw new TµApplicationException("Invalid parameters.", TµApplicationException::API);
				}
			}
		}
	}
}
