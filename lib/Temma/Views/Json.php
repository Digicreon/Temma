<?php

/**
 * Json view
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2010-2023, Amaury Bouchard
 */

namespace Temma\Views;

use \Temma\Utils\Validation\DataFilter as TµDataFilter;

/**
 * View for JSON export.
 *
 * The JSON encoded data is fetched from the "json" template variable.
 * To activate the debug mode (user-readable JSON), set the "jsonDebug" template variable to true.
 *
 * @link	http://json.org/
 */
class Json extends \Temma\Web\View {
	/** Constant: list of generic headers. */
	const GENERIC_HEADERS = [
		'Content-Type: text/x-json; charset=UTF-8',
		'Cache-Control: no-cache, no-store, must-revalidate, max-age=0, post-check=0, pre-check=0',
		'Pragma: no-cache',
		'Expires: 0',
	];
	/** Data that must be JSON-encoded. */
	private mixed $_data = null;
	/** Name of the downloadable file. */
	private ?string $_filename = null;
	/** Debug mode. */
	private bool $_debug = false;

	/** Init. */
	public function init() : void {
		// get data
		$this->_data = $this->_response->getData('@output') ??
		               $this->_response->getData('json');
		$this->_filename = $this->_response->getData('filename');
		$this->_debug = $this->_response->getData('jsonDebug', false);
		if (is_null($this->_data)) {
		        $this->_data = $this->_response->getData();
			unset($this->_data['filename']);
			unset($this->_data['jsonDebug']);
		}
		// data validation
		$validationContract = $this->_response->getValidationContract();
		if ($validationContract)
			$this->_data = TµDataFilter::process($this->_data, $validationContract);


		$this->_data = $this->_response->getData('json');
		$this->_filename = $this->_response->getData('filename');
		$this->_debug = $this->_response->getData('jsonDebug', false);
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

