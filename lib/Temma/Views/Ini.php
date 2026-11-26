<?php

/**
 * Ini view
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2012-2026, Amaury Bouchard
 */

namespace Temma\Views;

/**
 * View for INI export.
 *
 * The INI encoded data is fetched from the "@output" template variable or, if it is not defined,
 * from the "data" template variable. If none of them is defined, all template variables are used,
 * except "URL", "CONTROLLER", "ACTION", "filename" and the variables whose name starts with an underscore.
 * The name of the downloaded file is fetched from the "filename" template variable.
 */
class Ini extends \Temma\Web\View {
	/** Constant: default content type. */
	const CONTENT_TYPE = 'text/ini';
	/** Data that must be INI-encoded. */
	private mixed $_data = null;
	/** Name of the downloadable file. */
	private ?string $_filename = null;

	/**
	 * Init.
	 * @throws	\Temma\Exceptions\Application	If data don't respect the output validation contract.
	 */
	public function init() : void {
		$this->_filename = $this->_response->getData('filename');
		$this->_data = $this->_getSerializationData('data', ['filename']);
	}
	/** Write HTTP headers. */
	public function sendHeaders(?array $headers=null) : void {
		if ($this->_filename) {
			$headers ??= [];
			$headers[] = 'Content-Disposition: attachment; filename="' . \Temma\Utils\Text::filenamize($this->_filename) . '"';
		}
		parent::sendHeaders($headers);
	}
	/**
	 * Write body.
	 * @throws	\Temma\Exceptions\IO	If the data can't be encoded in INI format.
	 */
	public function sendBody() : void {
		print(\Temma\Utils\Serializer::encodeIni($this->_data));
	}
}

