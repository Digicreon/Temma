<?php

/**
 * IniView
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2012-2019, Amaury Bouchard
 */

namespace Temma\Views;

/**
 * View for INI export.
 *
 * The INI encoded data is fetched from the "data" template variable.
 */
class IniView extends \Temma\Web\View {
	/** Data that must be INI-encoded. */
	private $_data = null;

	/** Init. */
	public function init() : void {
		$this->_data = $this->_response->getData('data');
	}
	/** Write HTTP headers. */
	public function sendHeaders(?array $headers=null) : void {
		parent::sendHeaders([
			'Content-Type'  => 'text/ini; charset=UTF-8',
			'Cache-Control'	=> 'no-cache, no-store, must-revalidate, max-age=0, post-check=0, pre-check=0',
			'Pragma'        => 'no-cache',
			'Expires'       => '0',
		]);
	}
	/** Write body. */
	public function sendBody() : void {
		print(\Temma\Utils\IniExport::generate($this->_data));
	}
}

