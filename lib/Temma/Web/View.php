<?php

/**
 * View
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2024, Amaury Bouchard
 */

namespace Temma\Web;

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
}

