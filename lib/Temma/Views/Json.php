<?php

/**
 * Json view
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2010-2026, Amaury Bouchard
 */

namespace Temma\Views;

/**
 * View for JSON export.
 *
 * The JSON encoded data is fetched from the "@output" template variable or, if it is not defined,
 * from the "json" template variable. If none of them is defined, all template variables are sent,
 * except "URL", "CONTROLLER", "ACTION", "filename", "jsonDebug" and the variables whose name starts
 * with an underscore.
 * The name of the downloaded file is fetched from the "filename" template variable.
 * To activate the debug mode (user-readable JSON), set the "jsonDebug" template variable to true.
 *
 * @link	http://json.org/
 */
class Json extends \Temma\Web\View {
	/** Constant: default content type. */
	const CONTENT_TYPE = 'application/json';
	/** Data that must be JSON-encoded. */
	private mixed $_data = null;
	/** Name of the downloadable file. */
	private ?string $_filename = null;
	/** Debug mode. */
	private bool $_debug = false;

	/** Init. */
	public function init() : void {
		$this->_filename = $this->_response->getData('filename');
		$this->_debug = $this->_response->getData('jsonDebug', false);
		$this->_data = $this->_getSerializationData('json', ['filename', 'jsonDebug']);
	}
	/** Write HTTP headers. */
	public function sendHeaders(?array $headers=null) : void {
		if ($this->_filename) {
			$headers ??= [];
			$headers[] = 'Content-Disposition: attachment; filename="' . \Temma\Utils\Text::filenamize($this->_filename) . '"';
		}
		parent::sendHeaders($headers);
	}
	/** Write body. */
	public function sendBody() : void {
		$option = $this->_debug ? JSON_PRETTY_PRINT : 0;
		print(json_encode($this->_data, $option));
	}
}

