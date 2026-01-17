<?php

/**
 * Request
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2026, Amaury Bouchard
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
	/* ********** REQUEST PROPERTIES ********** */
	/** Accepted formats ("Accept" HTTP header). */
	private ?array $_acceptedFormats = null;
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
		// extraction of the path from the site root
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
	 * Returns the list of accepted formats.
	 * @return	array	The list of accepted formats.
	 */
	public function getAcceptedFormats() : array {
		if (is_null($this->_acceptedFormats)) {
			// extraction of the Accept HTTP header value
			$this->_acceptedFormats = array_map(function($format) {
				return (trim(explode(';', $format)[0]));
			}, explode(',', ($_SERVER['HTTP_ACCEPT'] ?? '')));
			if (!strcasecmp(($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest')) {
				array_unshift($this->_acceptedFormats, 'application/json');
				$this->_acceptedFormats = array_unique($this->_acceptedFormats);
			}
		}
		return ($this->_acceptedFormats);
	}
	/**
	 * Tell if a content-type is accepted by the client browser.
	 * @param	string	$requestedFormat	The requested format (in the form "image/png" or "image").
	 * @return	bool	True if the requested format is supported.
	 */
	public function isAcceptedFormat(string $requestedFormat) : bool {
		$requestedFormat = explode('/', $requestedFormat);
		$requestedFormat[1] ??= '*';
		$acceptedFormats = $this->getAcceptedFormats();
		foreach ($acceptedFormats as $acceptedFormat) {
			if (\Temma\Utils\Text::mimeTypesMatch($requestedFormat, $acceptedFormat))
				return (true);
		}
		return (false);
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
	public function getParamCount() : int {
		return (is_array($this->_params) ? count($this->_params) : 0);
	}
	/** Alias of getParamCount() for backward compatibility. */
	public function getNbrParams() : int {
		return ($this->getParamCount());
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
	 * Validate parameters (GET and POST).
	 * @param	array	$params	Contract to validate the parameters.
	 * @param	?string	$source	(optional) Source of the parameters ('GET', 'POST'). If null, checks both.
	 * @param	bool	$strict	(optional) True to use strict matching. False by default.
	 * @throws	\Temma\Exceptions\Application	If the parameters are not valid.
	 */
	public function validateParams(array $params, ?string $source=null, bool $strict=false) : void {
		$source = strtoupper($source ?? '');
		$checkGet = ($source === 'GET' || ($source === '' && !empty($_GET)));
		$checkPost = ($source === 'POST' || ($source === '' && !empty($_POST)));
		// optimize contract
		$contract = [
			'type' => 'assoc',
			'keys' => $params,
		];
		// validate
		if ($checkGet)
			$_GET = TµDataFilter::process($_GET, $contract, $strict);
		if ($checkPost)
			$_POST = TµDataFilter::process($_POST, $contract, $strict);
	}
	/**
	 * Validate the request payload.
	 * @param	mixed	$contract	Contract to validate the payload.
	 * @param	bool	$strict		(optional) True to use strict matching. False by default.
	 * @return	mixed	The validated data.
	 * @throws	\Temma\Exceptions\Application	If the payload is not valid.
	 */
	public function validatePayload(mixed $contract, bool $strict=false) : mixed {
		$inputSource = (php_sapi_name() === 'cli') ? 'php://stdin' : 'php://input';
		$input = file_get_contents($inputSource);
		return (TµDataFilter::process($input, $contract, $strict));
	}
	/**
	 * Validate uploaded files.
	 * @param	array	$contract	Contract to validate the files.
	 * @param	bool	$strict		(optional) True to use strict matching. False by default.
	 * @throws	\Temma\Exceptions\Application	If the files are not valid.
	 */
	public function validateFiles(array $contract, bool $strict=false) : void {
		// process each contract key
		$hasWildcard = false;
		$wildcardContract = null;
		$contractKeys = [];
		foreach ($contract as $key => $subcontract) {
			// check for wildcard (as key or as value in indexed array)
			if ($key === '...' || $key === '…') {
				$hasWildcard = true;
				$wildcardContract = ($subcontract !== '...' && $subcontract !== '…') ? $subcontract : null;
				continue;
			}
			if ($subcontract === '...' || $subcontract === '…') {
				$hasWildcard = true;
				continue;
			}
			// check for optional suffix
			$optional = false;
			if (str_ends_with($key, '?')) {
				$optional = true;
				$key = mb_substr($key, 0, -1);
			}
			// store the key (for later use)
			$contractKeys[$key] = true;
			// check if file exists
			if (!isset($_FILES[$key])) {
				if (!$optional)
					throw new TµApplicationException("Mandatory file '$key' is missing.", TµApplicationException::API);
				continue;
			}
			// check for single file or multiple files
			if (!is_array($_FILES[$key]['name'])) {
				// handle single file
				$content = file_get_contents($_FILES[$key]['tmp_name']);
				$result = TµDataFilter::process($content, $subcontract, $strict);
				// update MIME type in $_FILES
				if (isset($result['mime']))
					$_FILES[$key]['type'] = $result['mime'];
			} else {
				// handle multiple files (array)
				foreach ($_FILES[$key]['name'] as $i => $name) {
					$content = file_get_contents($_FILES[$key]['tmp_name'][$i]);
					$result = TµDataFilter::process($content, $subcontract, $strict);
					// update MIME type in $_FILES
					if (isset($result['mime']))
						$_FILES[$key]['type'][$i] = $result['mime'];
				}
			}
		}
		// handle extra files
		if ($hasWildcard && $wildcardContract) {
			// validate extra files with wildcard contract
			foreach (array_keys($_FILES) as $fileKey) {
				if (isset($contractKeys[$fileKey]))
					continue;
				// validate extra file
				if (!is_array($_FILES[$fileKey]['name'])) {
					$content = file_get_contents($_FILES[$fileKey]['tmp_name']);
					$result = TµDataFilter::process($content, $wildcardContract, $strict);
					if (isset($result['mime']))
						$_FILES[$fileKey]['type'] = $result['mime'];
				} else {
					foreach ($_FILES[$fileKey]['name'] as $i => $name) {
						$content = file_get_contents($_FILES[$fileKey]['tmp_name'][$i]);
						$result = TµDataFilter::process($content, $wildcardContract, $strict);
						if (isset($result['mime']))
							$_FILES[$fileKey]['type'][$i] = $result['mime'];
					}
				}
			}
		} else if (!$hasWildcard) {
			if ($strict) {
				// strict mode: throw exception on extra files
				$extraFiles = [];
				$fileKeys = array_keys($_FILES);
				foreach ($fileKeys as $fileKey) {
					if (!isset($contractKeys[$fileKey]))
						$extraFiles[] = $fileKey;
				}
				if ($extraFiles)
					throw new TµApplicationException("Extra file(s) '" . implode("', '", $extraFiles) . "' are not allowed.", TµApplicationException::API);
			} else {
				// non-strict mode: remove extra files
				foreach (array_keys($_FILES) as $fileKey) {
					if (!isset($contractKeys[$fileKey]))
						unset($_FILES[$fileKey]);
				}
			}
		}
	}
}
