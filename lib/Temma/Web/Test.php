<?php

/**
 * Test
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 */

namespace Temma\Web;

use \Temma\Base\Log as TµLog;
use \Temma\Base\Loader as TµLoader;

/**
 * Integration test object.
 */
class Test {
	/** Path to the application's root directory. */
	protected ?string $_appPath = null;
	/** Path to the configuration file. */
	protected ?string $_jsonConfigPath = null;
	/** Original loader, given at creation. */
	protected ?TµLoader $_originalLoader = null;
	/** Loader used for the last request. */
	protected ?TµLoader $_loader = null;

	/**
	 * Constructor.
	 * @param	?string		$appPath	(optional) Path to the root directory of the application.
	 *						Give it only if the $jsonConfigPath is given.
	 * @param	?string		$jsonConfigPath	(optional) Path to the 'temma.json' configuration file.
	 * @param	?TµLoader	$loader		(optional) Loader object. Defaults to null.
	 */
	public function __construct(?string $appPath=null, ?string $jsonConfigPath=null, ?TµLoader $loader=null) {
		$this->_appPath = $appPath;
		$this->_jsonConfigPath = $jsonConfigPath;
		$this->_originalLoader = $loader;
	}
	/**
	 * Returns the loader object.
	 * @return	?TµLoader	The created loader object. Null if no request has been done yet.
	 */
	public function getLoader() : ?TµLoader {
		return ($this->_loader);
	}
	/**
	 * Executes a request and returns the loader object.
	 * @param	string	$url		URL to execute (starting with a slash character).
	 * @param	string	$httpMethod	(optional) HTTP method ("GET", "POST"...). Defaults to "GET".
	 * @param	?array	$data		(optional) Associative array of parameters (GET or POST parameters, depending on the $httpMethod parameter). Defaults to null.
	 * @param	?array	$cookies	(optional) Associative array of cookies. Defaults to null.
	 * @return	\Temma\Base\Loader	The dependency injection component.
	 */
	public function execLoader(string $url, string $httpMethod='GET', ?array $data=null, ?array $cookies=null) : \Temma\Base\Loader {
		$temma = $this->_createEnvironment($url, $httpMethod, $data);
		ob_start();
		$temma->process(processView: null, sendHeaders: false);
		ob_end_clean();
		return ($this->_loader);
	}
	/**
	 * Executes a request and returns the response object.
	 * @param	string	$url		URL to execute (starting with a slash character).
	 * @param	string	$httpMethod	(optional) HTTP method ("GET", "POST"...). Defaults to "GET".
	 * @param	?array	$data		(optional) Associative array of parameters (GET or POST parameters, depending on the $httpMethod parameter). Defaults to null.
	 * @param	?array	$cookies	(optional) Associative array of cookies. Defaults to null.
	 * @return	\Temma\Web\Response	The response object.
	 */
	public function execResponse(string $url, string $httpMethod='GET', ?array $data=null, ?array $cookies=null) : \Temma\Web\Response {
		$temma = $this->_createEnvironment($url, $httpMethod, $data);
		ob_start();
		$temma->process(processView: null, sendHeaders: false);
		ob_end_clean();
		return ($this->_loader->response);
	}
	/**
	 * Executes a request and returns the defined variables.
	 * @param	string	$url		URL to execute (starting with a slash character).
	 * @param	string	$httpMethod	(optional) HTTP method ("GET", "POST"...). Defaults to "GET".
	 * @param	?array	$data		(optional) Associative array of parameters (GET or POST parameters, depending on the $httpMethod parameter). Defaults to null.
	 * @param	?array	$cookies	(optional) Associative array of cookies. Defaults to null.
	 * @return	null|string|array	Redirection URL or associative array of template variables.
	 */
	public function execData(string $url, string $httpMethod='GET', ?array $data=null, ?array $cookies=null) : null|string|array {
		$temma = $this->_createEnvironment($url, $httpMethod, $data);
		ob_start();
		$result = $temma->process(processView: false, sendHeaders: false);
		ob_end_clean();
		return ($result);
	}
	/**
	 * Executes a request and returns its output stream.
	 * @param	string	$url		URL to execute (starting with a slash character).
	 * @param	string	$httpMethod	(optional) HTTP method ("GET", "POST"...). Defaults to "GET".
	 * @param	?array	$data		(optional) Associative array of parameters (GET or POST parameters, depending on the $httpMethod parameter). Defaults to null.
	 * @param	?array	$cookies	(optional) Associative array of cookies. Defaults to null.
	 * @return	string	The output (depending on the view, HTML by default).
	 */
	public function execOutput(string $url, string $httpMethod='GET', ?array $data=null, ?array $cookies=null) : string {
		$temma = $this->_createEnvironment($url, $httpMethod, $data);
		ob_start();
		$temma->process(sendHeaders: false);
		$result = ob_get_clean();
		return ($result ?: '');
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Creates the execution environment.
	 * @param	string	$url		URL to execute (starting with a slash character).
	 * @param	string	$httpMethod	(optional) HTTP method ("GET", "POST"...). Defaults to "GET".
	 * @param	?array	$data		(optional) Associative array of parameters (GET or POST parameters, depending on the $httpMethod parameter). Defaults to null.
	 * @param	?array	$cookies	(optional) Associative array of cookies. Defaults to null.
	 * @return	\Temma\Web\Framework	An instance of the framework main object.
	 */
	protected function _createEnvironment(string $url, string $httpMethod='GET', ?array $data=null, ?array $cookies=null) : \Temma\Web\Framework {
		// reset
		$_GET = $_POST = [];
		$_COOKIE = $cookies ?? [];
		$_SERVER['REQUEST_METHOD'] = $httpMethod;
		$_SERVER['REQUEST_URI'] = $url;
		// loader creation
		if ($this->_originalLoader)
			$this->_loader = clone $this->_originalLoader;
		else
			$this->_loader = new TµLoader();
		// configuration loading
		if ($this->_appPath) {
			$config = new \Temma\Web\Config($this->_appPath);
			$config->readConfigurationFile($this->_jsonConfigPath);
			$this->_loader->config = $config;
		}
		// data
		if ($data) {
			if ($httpMethod == 'GET')
				$_GET = $data;
			else
				$_POST = $data;
			$_REQUEST = array_merge($_POST, $_GET, $_COOKIE);
		}
		// request
		$this->_loader->request = new \Temma\Web\Request($url);
		// framework
		$temma = new \Temma\Web\Framework($this->_loader);
		return ($temma);
	}
}

