<?php

namespace Temma\Views;

/**
 * Vue traitant les flux de calendrier iCal.
 *
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @package	Temma
 * @subpackage	Views
 */
class ICalView extends \Temma\View {
	/** Données de l'agenda. */
	private $_ical = null;

	/** Fonction d'initialisation. */
	public function init() {
		$this->_ical = $this->_response->getData('ical');
	}
	/** Ecrit les headers HTTP sur la sortie standard si nécessaire. */
	public function sendHeaders($headers=null) {
		parent::sendHeaders(array('Content-type: text/calendar; charset=utf-8'));
	}
	/** Ecrit le corps du document sur la sortie standard. */
	public function sendBody() {
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
			$uid = $event['uid'] ?? md5(uniqid(mt_rand(), true)) . '@temma.net';
			print("BEGIN:VEVENT\r\n");
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
	 * Écrit sur la sortie standard un texte après avoir échappé ses caractères spéciaux.
	 * Les lignes sont découpées et un retour chariot ("\r\n") est ajouté à la fin.
	 * @param	string	$attr	Nom de l'attribut (incluant le caractère ':' à la fin).
	 * @param	string	$text	Texte à échapper.
	 */
	private function _print($attr, $text) {
		print($this->_escape($attr, $text) . "\r\n");
	}
	/**
	 * Transforme un texte en échappant les caractères et en le mettant à la bonne longueur.
	 * @param	string	$attr	Nom de l'attribut (incluant le caractère ':' à la fin).
	 * @param	string	$text	Texte à échapper.
	 * @param	bool	$wrap	(optionnel) Indique s'il faut couper les textes. True par défaut.
	 * @return	string	Le texte résultant.
	 */
	private function _escape($attr, $text, $wrap=true) {
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

