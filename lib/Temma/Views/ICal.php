<?php

/**
 * ICal view
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2010-2023, Amaury Bouchard
 */

namespace Temma\Views;

/**
 * View for iCal calendars.
 * Get its data from an "ical" template variable.
 * The name of the downloaded file is fetched from the "filename" template variable.
 */
class ICal extends \Temma\Web\View {
	/** Constant: list of generic headers. */
	const GENERIC_HEADERS = [
		'Content-Encoding: UTF-8',
		'Content-Type: text/calendar; charset=UTF-8',
		'Cache-Control: no-cache, no-store, must-revalidate, max-age=0, post-check=0, pre-check=0',
		'Pragma: no-cache',
		'Expires: 0',
	];
	/** Calendar data. */
	private ?array $_ical = null;
	/** Name of the downloadable file. */
	private ?string $_filename = null;

	/** Init. */
	public function init() : void {
		$this->_ical = $this->_response->getData('ical');
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
		if (!isset($this->_ical['events']))
			return;
		print("BEGIN:VCALENDAR\r\n");
		print("VERSION:2.0\r\n");
		print("PRODID:-//Temma//ICalView 1.0//EN\r\n");
		print("CALSCALE:GREGORIAN\r\n");
		print("METHOD:PUBLISH\r\n");
		print("X-WR-TIMEZONE:UTC\r\n");
		if (isset($this->_ical['name']))
			$this->_print('X-WR-CALNAME:', $this->_ical['name']);
		if (isset($this->_ical['description']))
			$this->_print('X-WR-CALDESC:', $this->_ical['description']);
		foreach ($this->_ical['events'] as $event) {
			if (!isset($event['name']) || (!isset($event['date']) && (!isset($event['dateStart']) || !isset($event['dateEnd']))))
				continue;
			print("BEGIN:VEVENT\r\n");
			$uid = $event['uid'] ?? md5(uniqid(mt_rand(), true));
			$this->_print('UID:', $uid);
			if (isset($event['organizerName']) && isset($event['organizerEmail'])) {
				$organizer = $this->_escape('ORGANIZER;CN=', $event['organizerName'], false);
				$organizer .= $this->_escape(':MAILTO:', $event['organizerEmail']);
				print("$organizer\r\n");
			}
			if (isset($event['date'])) {
				$this->_print('DTSTART;VALUE=DATE:', gmdate('Ymd', strtotime($event['date'])));
				$this->_print('DTEND;VALUE=DATE:', gmdate('Ymd', strtotime($event['date'] . ' +1 day')));
			} else {
				$this->_print('DTSTART:', gmdate('Ymd', strtotime($event['dateStart'])) . 'T' . gmdate('His', strtotime($event['dateStart'])) . 'Z');
				$this->_print('DTEND:', gmdate('Ymd' . strtotime($event['dateEnd'])) . 'T' . gmdate('His', strtotime($event['dateEnd'])) . 'Z');
			}
			$this->_print('DTSTAMP:', gmdate('Ymd') . 'T' . gmdate('His') . 'Z');
			if (isset($event['dateCreation'])) {
				$this->_print('CREATED:', gmdate('Ymd', strtotime($event['dateCreation'])) . 'T' . gmdate('His', strtotime($event['dateCreation'])) . 'Z');
			}
			$this->_print('SUMMARY:', $event['name'] ?? '');
			$this->_print('DESCRIPTION:', $event['description'] ?? '');
			if (isset($event['html'])) {
				$this->_print('X-ALT-DESC;FMTTYPE=text/html:', $event['html']);
			}
			print("TRANSP:TRANSPARENT\r\n");
			print("STATUS:CONFIRMED\r\n");
			print("END:VEVENT\r\n");
		}
		print("END:VCALENDAR\r\n");
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Escape special characters of a text and write it to stdout.
	 * @param	string	$attr	Attribute name (ending with ":"). Is not escaped.
	 * @param	string	$text	Text to escape.
	 */
	private function _print(string $attr, string $text) : void {
		print($this->_escape($attr, $text) . "\r\n");
	}
	/**
	 * Transform a text by escaping special characters and cutting it to the right size.
	 * @param	string	$attr	Attribute name (ending with ":"). Is not escaped.
	 * @param	string	$text	Text to escape.
	 * @param	bool	$wrap	(optional) True to cut lines. True by default.
	 * @return	string	The transformed text.
	 */
	private function _escape(string $attr, string $text, bool $wrap=true) : string {
		$text = str_replace(' ', chr(7), $text);
		$text = str_replace("\\", "\\\\", $text);
		$text = str_replace(',', '\,', $text);
		$text = str_replace(';', '\;', $text);
		$text = str_replace("\n", "\\n", $text);
		$text = "$attr$text";
		if ($wrap) {
			$text = wordwrap($text, 70, "\n", true);
			$text = str_replace("\n", "\r\n ", $text);
			$text = str_replace("\r\r", "\r", $text);
		}
		$text = str_replace(chr(7), ' ', $text);
		return ($text);
	}
}

