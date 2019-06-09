<?php

namespace Temma\Views;

/**
 * Vue traitant les flux JSON.
 *
 * La donnée qui sera encodée en JSON doit avoir été stockée sous la clé "json".
 *
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @package	Temma
 * @subpackage	Views
 * @link	http://json.org/
 */
class JsonView extends \Temma\View {
	/** Nom de la clé de configuration pour les headers. */
	protected $_cacheKey = 'json';
	/** Donnée à envoyer encodée en JSON. */
	private $_data = null;
	/** Mode debug. */
	private $_debug = false;

	/** Fonction d'initialisation. */
	public function init() {
		$this->_data = $this->_response->getData('json');
		$this->_debug = $this->_response->getData('jsonDebug', false);
	}
	/** Ecrit les headers HTTP sur la sortie standard si nécessaire. */
	public function sendHeaders($headers=null) {
		parent::sendHeaders(array('Content-Type' => 'text/x-json; charset=UTF-8'));
	}
	/** Ecrit le corps du document sur la sortie standard. */
	public function sendBody() {
		$option = $this->_debug ? JSON_PRETTY_PRINT : 0;
		print(json_encode($this->_data, $option));
	}
}

