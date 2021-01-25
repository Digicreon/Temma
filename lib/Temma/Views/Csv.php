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
 * The CSV exported data is fetched from the "csv" template variable.
 * The name of the downloaded file is fetched from the "filename" template variable.
 */
class Csv extends \Temma\Web\View {
	/** Data that must be exported. */
	private $_csv = null;
	/** Name of the downloadable file. */
	private $_filename = null;

	/** Init. */
	public function init() : void {
		$this->_csv = $this->_response->getData('csv');
		$this->_filename = $this->_response->getData('filename');
	}
	/** Write HTTP headers. */
	public function sendHeaders(?array $headers=null) : void {
		$headers = [
			'Content-Encoding' => 'UTF-8',
			'Content-Type'     => 'text/csv; charset=UTF-8',
			'Cache-Control'	   => 'no-cache, no-store, must-revalidate, max-age=0, post-check=0, pre-check=0',
			'Pragma'           => 'no-cache',
			'Expires'          => '0',
		];
		if ($this->_filename)
			$headers['Content-Disposition'] = 'attachment; filename="' . \Temma\Utils\Text::filenamize($this->_filename) . '"';
		parent::sendHeaders($headers);
	}
	/** Write body. */
	public function sendBody() : void {
		$stdout = fopen('php://output', 'w');
		foreach ($this->_csv as $line) {
			fputcsv($stdout, $line);
		}
		fclose($stdout);
	}
}

