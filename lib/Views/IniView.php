<?php

namespace Temma\Views;

require_once('finebase/IniExport.php');

/**
 * Vue traitant les flux au format INI.
 *
 * La donnée qui sera encodée au format INI doit avoir été stockée sous la clé "data".
 *
 * @author	Amaury Bouchard <amaury.bouchard@finemedia.fr>
 * @copyright	© 2019, Amaury Bouchard
 */
class IniView extends \Temma\View {
	/** Donnée à envoyer encodée au format INI. */
	private $_data = null;

	/** Fonction d'initialisation. */
	public function init() {
		$this->_data = $this->_response->getData('data');
	}
	/** Ecrit les headers HTTP sur la sortie standard si nécessaire. */
	public function sendHeaders($headers=null) {
		parent::sendHeaders(array('Content-Type' => 'text/ini; charset=UTF-8'));
	}
	/** Ecrit le corps du document sur la sortie standard. */
	public function sendBody() {
		print(\IniExport::generate($this->_data));
	}
}

?>
