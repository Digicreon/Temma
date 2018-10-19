<?php

namespace Temma\Views;

/**
 * Vue traitant les flux de calendrier iCal.
 *
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2018, Amaury Bouchard
 * @package	Temma
 * @subpackage	Views
 * @version	$Id$
 */
class ICalView extends \Temma\View {
	/** Données de l'agenda. */
	private $_ical = null;

	/**
	 * Fonction d'initialisation.
	 * @param	\Temma\Response	$response	Réponse de l'exécution du contrôleur.
	 * @param	string		$templatePath	Chemin vers le template à traiter.
	 */
	public function init(\Temma\Response $response) {
		$this->_ical = $response->getData('ical');
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
			print("X-WR-CALNAME:" . $this->_ical['name'] . "\r\n");
		if (isset($this->_ical['description']))
			print("X-WR-CALDESC:" . $this->_ical['description'] . "\r\n");
		foreach ($this->_ical['events'] as $event) {
			if (!isset($event['name']) || (!isset($event['date']) && (!isset($event['dateStart']) || !isset($event['dateEnd']))))
				continue;
			$uid = $event['uid'] ?? md5(uniqid(mt_rand(), true)) . '@temma.net';
			print("BEGIN:VEVENT\r\n");
			print("UID:$uid\r\n");
			if (isset($event['organizerName'])) {
				$organizer = 'ORGANIZER;CN=' . $event['organizerName'];
				if (isset($event['organizerEmail']))
					$organizer .= ':MAILTO:' . $event['organizerEmail'];
				print(wordwrap($organizer, 70, "\n", true) . "\r\n");
			}
			if (isset($event['date'])) {
				$start = $this->_escapeText('DTSTART;VALUE=DATE:', gmdate('Ymd', strtotime($event['date'])));
				$end = $this->_escapeText('DTEND;VALUE=DATE:', gmdate('Ymd', strtotime($event['date'] . ' +1 day')));
			} else {
				$start = $this->_escapeText('DTSTART:', gmdate('Ymd', strtotime($event['dateStart'])) . 'T' . gmdate('His', strtotime($event['dateStart'])) . 'Z');
				$end = $this->_escapeText('DTEND:', gmdate('Ymd' . strtotime($event['dateEnd'])) . 'T' . gmdate('His', strtotime($event['dateEnd'])) . 'Z');
			}
			print("$start\r\n$end\r\n");
			$dtstamp = $this->_escapeText('DTSTAMP:', gmdate('Ymd') . 'T' . gmdate('His') . 'Z');
			print("$dtstamp\r\n");
			if (isset($event['dateCreation'])) {
				$created = gmdate('Ymd', strtotime($event['dateCreation'])) . 'T' . gmdate('His', strtotime($event['dateCreation'])) . 'Z';
				$created = $this->_escapeText('CREATED:', $created);
				print("$created\r\n");
			}
			$summary = $this->_escapeText('SUMMARY:', $event['name'] ?? '');
			print("$summary\r\n");
			$description = $this->_escapeText('DESCRIPTION:', $event['description'] ?? '');
			print("$description\r\n");
			if (isset($event['html'])) {
				$html = $this->_escapeText('X-ALT-DESC;FMTTYPE=text/html:', $event['html']);
				print("$html\r\n");
			}
			print("TRANSP:TRANSPARENT\r\n");
			print("STATUS:CONFIRMED\r\n");
			print("END:VEVENT\r\n");
		}
		print("END:VCALENDAR\r\n");
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Transforme un texte en échappant les caractères et en le mettant à la bonne longueur.
	 * @param	string	$attr	Nom de l'attribut (incluant le caractère ':' à la fin).
	 * @param	string	$text	Texte à échapper.
	 * @return	string	Le texte résultant.
	 */
	private function _escapeText($attr, $text) {
		$text = str_replace(' ', chr(7), $text);
		$text = str_replace("\\", "\\\\", $text);
		$text = str_replace(',', '\,', $text);
		$text = str_replace(';', '\;', $text);
		$text = str_replace("\n", "\\n", $text);
		$text = "$attr$text";
		$text = wordwrap($text, 70, "\n", true);
		$text = str_replace("\n", "\r\n ", $text);
		$text = str_replace("\r\r", "\r", $text);
		$text = str_replace(chr(7), ' ', $text);
		return ($text);
	}
}

?>
