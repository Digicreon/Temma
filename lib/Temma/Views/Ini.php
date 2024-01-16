<?php

/**
 * Ini view
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2012-2023, Amaury Bouchard
 */

namespace Temma\Views;

/**
 * View for INI export.
 *
 * The INI encoded data is fetched from the "data" template variable.
 * The name of the downloaded file is fetched from the "filename" template variable.
 */
class Ini extends \Temma\Web\View {
	/** Constant: list of generic headers. */
	const GENERIC_HEADERS = [
		'Content-Encoding: UTF-8',
		'Content-Type: text/ini; charset=UTF-8',
		'Cache-Control: no-cache, no-store, must-revalidate, max-age=0, post-check=0, pre-check=0',
		'Pragma: no-cache',
		'Expires: 0',
	];
	/** Data that must be INI-encoded. */
	private mixed $_data = null;
	/** Name of the downloadable file. */
	private ?string $_filename = null;

	/** Init. */
	public function init() : void {
		$this->_data = $this->_response->getData('data');
		$this->_filename = $this->_response->getData('filename');
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
		print(\Temma\Utils\Serializer::encodeIni($this->_data));
	}
}

