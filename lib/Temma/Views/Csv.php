<?php

/**
 * CsvView
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2020-2023, Amaury Bouchard
 */

namespace Temma\Views;

/**
 * View for CSV export.
 *
 * The CSV exported data is fetched from the "csv" template variable.
 * The name of the downloaded file is fetched from the "filename" template variable.
 */
class Csv extends \Temma\Web\View {
	/** Constant: list of generic headers. */
	const GENERIC_HEADERS = [
		'Content-Encoding: UTF-8',
		'Content-Type: text/csv; charset=UTF-8',
		'Cache-Control: no-cache, no-store, must-revalidate, max-age=0, post-check=0, pre-check=0',
		'Pragma: no-cache',
		'Expires: 0',
	];
	/** Constant: default separator character. */
	const DEFAULT_SEPARATOR = ',';
	/** Data that must be exported. */
	private ?array $_csv = null;
	/** Name of the downloadable file. */
	private ?string $_filename = null;
	/** Separator character. */
	private string $_separator = ',';

	/** Init. */
	public function init() : void {
		$this->_csv = $this->_response->getData('@output') ??
		              $this->_response->getData('csv');
		$this->_filename = $this->_response->getData('filename');
		$this->_separator = $this->_response->getData('separator') ?? self::DEFAULT_SEPARATOR;
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
		$stdout = fopen('php://output', 'w');
		foreach ($this->_csv as $line) {
			fputcsv($stdout, $line, $this->_separator);
		}
		fclose($stdout);
	}
}

