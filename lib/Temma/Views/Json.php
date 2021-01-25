<?php

/**
 * Json view
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2010-2019, Amaury Bouchard
 */

namespace Temma\Views;

/**
 * View for JSON export.
 *
 * The JSON encoded data is fetched from the "json" template variable.
 * To activate the debug mode (user-readable JSON), set the "jsonDebug" template variable to true.
 *
 * @link	http://json.org/
 */
class Json extends \Temma\Web\View {
	/** Data that must be JSON-encoded. */
	private $_data = null;
	/** Debug mode. */
	private $_debug = false;

	/** Init. */
	public function init() : void {
		$this->_data = $this->_response->getData('json');
		$this->_debug = $this->_response->getData('jsonDebug', false);
		// output filtering
		if ($parameters)
			$this->_data = \Temma\Utils\DataFilter::process($this->_data, $parameters[0]);
	}
	/** Write HTTP headers. */
	public function sendHeaders(?array $headers=null) : void {
		parent::sendHeaders([
			'Content-Type'  => 'text/x-json; charset=UTF-8',
			'Cache-Control'	=> 'no-cache, no-store, must-revalidate, max-age=0, post-check=0, pre-check=0',
			'Pragma'        => 'no-cache',
			'Expires'       => '0',
		]);
	}
	/** Write body. */
	public function sendBody() : void {
		$option = $this->_debug ? JSON_PRETTY_PRINT : 0;
		print(json_encode($this->_data, $option));
	}
}

