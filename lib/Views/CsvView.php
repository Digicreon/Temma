<?php

/**
 * CsvView
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2020, Amaury Bouchard
 */

namespace Temma\Views;

/**
 * View for CSV export.
 *
 * The CSV exported data is fetched from the "data" template variable.
 */
class CsvView extends \Temma\Web\View {
	/** Data that must be INI-encoded. */
	private $_data = null;

	/** Init. */
	public function init() : void {
		$this->_data = $this->_response->getData('data');
	}
	/** Write HTTP headers. */
	public function sendHeaders(?array $headers=null) : void {
		parent::sendHeaders([
			'Content-Type'	=> 'text/csv; charset=UTF-8',
		]);
	}
	/** Write body. */
	public function sendBody() : void {
		foreach ($this->_data as $line)
			fputscsv(STDOUT, $line);
	}
}

