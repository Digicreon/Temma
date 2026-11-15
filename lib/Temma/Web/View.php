<?php

/**
 * View
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2026, Amaury Bouchard
 */

namespace Temma\Web;

use \Temma\Utils\DataFilter as TµDataFilter;
use \Temma\Exceptions\Framework as TµFrameworkException;

/**
 * Object used to manage views.
 */
abstract class View {
	/** Constant: default content type of the view (used if no content type is defined on the response). */
	const CONTENT_TYPE = 'text/html';
	/** Constant: MIME type aliases, usable everywhere a content type is expected. */
	const MIME_ALIASES = [
		'html'     => 'text/html',
		'xhtml'    => 'application/xhtml+xml',
		'json'     => 'application/json',
		'rss'      => 'application/rss+xml',
		'csv'      => 'text/csv',
		'calendar' => 'text/calendar',
	];
	/** Constant: list of generic headers, sent after the Content-Type header. */
	const GENERIC_HEADERS = [
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
	 * Sends the HTTP return code and the headers built by the buildHeaders() method.
	 * @param	array	$headers	(optional) Default array of headers that must be sent.
	 */
	public function sendHeaders(?array $headers=null) : void {
		$httpCode = $this->_response?->getHttpCode() ?? 200;
		if ($httpCode != 200)
			http_response_code($httpCode);
		foreach ($this->buildHeaders($headers) as $header)
			header($header);
	}
	/**
	 * Build the list of HTTP headers to send: the Content-Type header, the generic headers (cache deactivation),
	 * the default headers defined in the configuration, and the given specific headers.
	 * The Content-Type header uses the content type defined on the response object if any, or the view's default
	 * content type otherwise. A "text/*" content type without charset information is completed with a
	 * "charset=UTF-8" parameter.
	 * @param	array	$headers	(optional) Array of specific headers that must be sent.
	 * @return	array	List of header strings.
	 */
	public function buildHeaders(?array $headers=null) : array {
		// content type
		$contentType = $this->_response?->getContentType() ?? static::CONTENT_TYPE;
		if (str_starts_with(mb_strtolower($contentType), 'text/') && mb_stripos($contentType, 'charset') === false)
			$contentType .= '; charset=UTF-8';
		// generic headers
		$result = array_merge(["Content-Type: $contentType"], static::GENERIC_HEADERS);
		// default headers
		$headersDefault = $this->_config->xtra('headers', 'default');
		if (is_array($headersDefault)) {
			foreach ($headersDefault as $key => $val) {
				$val = trim($val);
				if (!is_int($key)) {
					$key = trim($key);
					$val = "$key: $val";
				}
				$result[] = $val;
			}
		}
		// specific headers
		if ($headers) {
			foreach ($headers as $key => $val) {
				$val = trim($val);
				if (!is_int($key)) {
					$key = trim($key);
					$val = "$key: $val";
				}
				$result[] = $val;
			}
		}
		return ($result);
	}
	/** Write the document body on stdout. */
	abstract public function sendBody() : void;

	/* ********** PROTECTED METHODS ********** */
	/**
	 * Returns the data used by template-based views (Smarty, PHP...).
	 * Data come from the "@output" template variable or, if it is not defined, from all template variables.
	 * Variables whose name starts with an underscore are removed (except those starting with two underscores).
	 * If an output validation contract is defined, data are validated with it.
	 * @return	array	Associative array of template variables.
	 * @throws	\Temma\Exceptions\Framework	If data are not an associative array.
	 * @throws	\Temma\Exceptions\Application	If data don't respect the validation contract.
	 */
	protected function _getTemplateData() : array {
		// get data
		$data = $this->_response->getData('@output');
		if (is_null($data)) {
			$data = $this->_response->getData();
			unset($data['@output']);
		} else if (!is_array($data)) {
			throw new TµFrameworkException("The '@output' template variable must be an associative array.", TµFrameworkException::BAD_OUTPUT);
		}
		// remove private variables
		$data = array_filter($data, fn($key) => (!str_starts_with($key, '_') || str_starts_with($key, '__')), ARRAY_FILTER_USE_KEY);
		// data validation
		$validationContract = $this->_response->getValidationContract();
		if (!$validationContract)
			return ($data);
		$data = TµDataFilter::process($data, $validationContract);
		if (!is_array($data))
			throw new TµFrameworkException("Validated template data must be an associative array.", TµFrameworkException::BAD_OUTPUT);
		return ($data);
	}
	/**
	 * Returns the data used by serialization views (JSON, INI...).
	 * Data come from the "@output" template variable or, if it is not defined, from the view-specific template variable.
	 * If none of them is defined, all template variables are used, except "URL", "CONTROLLER", "ACTION",
	 * the variables whose name starts with an underscore, the view-specific variable and the given excluded variables.
	 * If an output validation contract is defined, data are validated with it.
	 * @param	string	$name		Name of the view-specific template variable.
	 * @param	array	$excluded	(optional) List of variables that must not be used when all template variables are used.
	 * @return	mixed	The data.
	 * @throws	\Temma\Exceptions\Application	If data don't respect the validation contract.
	 */
	protected function _getSerializationData(string $name, array $excluded=[]) : mixed {
		// get data
		$data = $this->_response->getData('@output') ??
		        $this->_response->getData($name);
		if (is_null($data)) {
			$excluded = array_merge(['@output', 'URL', 'CONTROLLER', 'ACTION', $name], $excluded);
			$data = array_filter($this->_response->getData(), fn($key) => (!str_starts_with($key, '_') && !in_array($key, $excluded)), ARRAY_FILTER_USE_KEY);
		}
		// data validation
		$validationContract = $this->_response->getValidationContract();
		if ($validationContract)
			$data = TµDataFilter::process($data, $validationContract);
		return ($data);
	}
}

