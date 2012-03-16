<?php

namespace Temma\Views;

/**
 * Vue traitant les flux JSON.
 *
 * La donnée qui sera encodée en JSON doit avoir été stockée sous la clé "json".
 *
 * @author	Amaury Bouchard <amaury.bouchard@finemedia.fr>
 * @copyright	© 2007-2011, Fine Media
 * @package	Temma
 * @subpackage	Views
 * @version	$Id: JsonView.php 212 2011-05-17 09:57:39Z abouchard $
 * @link	http://json.org/
 */
class JsonView extends \Temma\View {
	/** Donnée à envoyer encodée en JSON. */
	private $_data = null;

	/**
	 * Fonction d'initialisation.
	 * @param	\Temma\Response	$response	Réponse de l'exécution du contrôleur.
	 * @param	string		$templatePath	Chemin vers le template à traiter.
	 */
	public function init(\Temma\Response $response) {
		$this->_data = $response->getData('json');
	}
	/** Ecrit les headers HTTP sur la sortie standard si nécessaire. */
	public function sendHeaders() {
		header('Content-Type: text/x-json; charset=UTF-8');
	}
	/** Ecrit le corps du document sur la sortie standard. */
	public function sendBody() {
		print(json_encode($this->_data));
	}
}

?>
