<?php

/**
 * Ini view
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2012-2026, Amaury Bouchard
 */

namespace Temma\Views;

use \Temma\Utils\DataFilter as TµDataFilter;

/**
 * View for INI export.
 *
 * The INI encoded data is fetched from the "data" template variable.
 * The name of the downloaded file is fetched from the "filename" template variable.
 */
class Ini extends \Temma\Web\View {
	/** Constant: default content type. */
	const CONTENT_TYPE = 'text/ini';
	/** Data that must be INI-encoded. */
	private mixed $_data = null;
	/** Name of the downloadable file. */
	private ?string $_filename = null;

	/** Init. */
	public function init() : void {
		$this->_data = $this->_response->getData('@output') ??
		               $this->_response->getData('data');
		$this->_filename = $this->_response->getData('filename');
		// data validation
		$validationContract = $this->_response->getValidationContract();
		if ($validationContract)
			$this->_data = TµDataFilter::process($this->_data, $validationContract);
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

