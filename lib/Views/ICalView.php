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
			$uid = $event['uid'] ?? md5(uniqid(mt_rand(), true)) . '@temma.net';
			print("BEGIN:VEVENT\r\n");
			print("UID:$uid\r\n");
			if (isset($event['date'])) {
				print('DTSTART;VALUE=DATE:' . gmdate('Ymd', strtotime($event['date'])) . "\r\n");
				print('DTEND;VALUE=DATE:' . gmdate('Ymd', strtotime($event['date'] . ' +1 day')) . "\r\n");
			} else {
				print('DTSTART:' . gmdate('Ymd', strtotime($event['dateStart'])) . 'T' . gmdate('His', strtotime($event['dateStart'])) . "Z\r\n");
				print('DTEND:' . gmdate('Ymd' . strtotime($event['dateEnd'])) . 'T' . gmdate('His', strtotime($event['dateEnd'])) . "Z\r\n");
			}
			print("DTSTAMP:" . gmdate('Ymd').'T'. gmdate('His') . "Z\r\n");
			if (isset($event['dateCreation']))
				print('CREATED:' . gmdate('Ymd', strtotime($event['dateCreation'])) . 'T' . gmdate('His', strtotime($event['dateCreation'])) . "Z\r\n");
			print('SUMMARY:' . $event['name'] . "\r\n");
			$description = $event['description'] ?? '';
			$description = str_replace(' ', chr(7), $description);
			$description = str_replace("\n", "\\n", $description);
			$description = wordwrap($description, 70, "\n", true);
			$description = str_replace("\n", "\r\n ", $description);
			$description = str_replace("\r\r", "\r", $description);
			$description = str_replace(chr(7), ' ', $description);
			print("DESCRIPTION:$description\r\n");
			if (isset($event['html'])) {
				$html = str_replace(' ', chr(7), $description);
				$html = wordwrap($event['html'], 70, "\n", true);
				$html = str_replace(" \n", "\n ", $html);
				$html = str_replace("\n", "\r\n ", $html);
				$html = str_replace("\r\r", "\r", $html);
				$html = str_replace(chr(7), ' ', $description);
				print("X-ALT-DESC;FMTTYPE=text/html:$html\r\n");
			}
			print("TRANSP:TRANSPARENT\r\n");
			print("STATUS:CONFIRMED\r\n");
			print("END:VEVENT\r\n");
		}
		print("END:VCALENDAR\r\n");
	}
}

?>
