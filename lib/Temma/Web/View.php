<?php

/**
 * View
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2023, Amaury Bouchard
 */

namespace Temma\Web;

/**
 * Object used to manage views.
 */
abstract class View {
	/** Constant: list of generic headers. */
	const GENERIC_HEADERS = [
		'Content-Type: text/html; charset=UTF-8',
		'Cache-Control: no-cache, no-store, must-revalidate, max-age=0, post-check=0, pre-check=0',
		'Expires: Mon, 26 Jul 1997 05:00:00 GMT',
		'Pragma: no-cache',
	];
	/** List of data sources. */
	protected array|\ArrayAccess $_dataSources;
	/** Configuration object. */
	protected \Temma\Web\Config $_config;
	/** Response object. */
	protected ?\Temma\Web\Response $_response = null;

	/**
	 * Constructor.
	 * @param	array|\ArrayAccess	$dataSources	List of data sources.
	 * @param	\Temma\Web\Config	$config		Configuration object.
	 * @param	\Temma\Web\Response	$response	Response object.
	 */
	public function __construct(array|\ArrayAccess $dataSources, \Temma\Web\Config $config, ?\Temma\Web\Response $response=null) {
		$this->_dataSources = $dataSources;
		$this->_config = $config;
		$this->_response = $response;
	}
	/** Destructor. */
	public function __destruct() {
	}
	/**
	 * Tell if this view uses templates or not.
	 * Views that doesn't use templates don't need to overload this method.
	 * @return	bool	True if this view uses templates.
	 */
	public function useTemplates() : bool {
		return (false);
	}
	/**
	 * Define template.
	 * Views that doesn't use templates don't need to overload this method.
	 * @param	string	$path		Path of where to search templates.
	 * @param	string	$template	Name of the template to use.
	 * @throws	\Temma\Exceptions\IO	If the template file doesn't exists.
	 */
	public function setTemplate(string $path, string $template) : void {
	}
	/** Initialization method. */
	public function init() : void {
	}
	/**
	 * Write HTTP headers on stdout.
	 * This default function sends an HTML content-type, with cache deactivation header.
	 * @param	array	$headers	(optional) Default array of headers that must be sent.
	 */
	public function sendHeaders(?array $headers=null) : void {
		$httpCode = $this->_response->getHttpCode();
		if ($httpCode != 200)
			http_response_code($httpCode);
		// send generic headers
		foreach (static::GENERIC_HEADERS as $_header) {
			header($_header);
		}
		// send default headers
		$headersDefault = $this->_config->xtra('headers', 'default');
		if (is_array($headersDefault)) {
			foreach ($headersDefault as $key => $val) {
				$val = trim($val);
				if (!is_int($key)) {
					$key = trim($key);
					$val = "$key: $val";
				}
				header($val);
			}
		}
		// send specific headers
		if ($headers) {
			foreach ($headers as $key => $val) {
				$val = trim($val);
				if (!is_int($key)) {
					$key = trim($key);
					$val = "$key: $val";
				}
				header($val);
			}
		}
	}
	/** Write the document body on stdout. */
	abstract public function sendBody() : void;
}

