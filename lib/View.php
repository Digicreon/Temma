<?php

namespace Temma;

/**
 * Objet de gestion des vues au sein d'applications MVC.
 *
 * @auhor	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2011, Fine Media
 * @package	Temma
 * @version	$Id: View.php 277 2012-06-26 15:55:46Z abouchard $
 */
abstract class View {
	/** Liste de connexion à des sources de données. */
	protected $_dataSources = null;
	/** Configuration de l'application. */
	protected $_config = null;
	/** Connexion à la session. */
	protected $_session = null;

	/**
	 * Constructeur.
	 * @param	array		$dataSources	Liste de connexions à des sources de données.
	 * @param	\Temma\Config	$config		Objet contenant la configuration du projet.
	 * @param	\FineSession	$session	(optionnel) Objet de connexion à la session.
	 */
	public function __construct($dataSources, \Temma\Config $config, \FineSession $session=null) {
		$this->_dataSources = $dataSources;
		$this->_config = $config;
		$this->_session = $session;
	}
	/** Destructeur. */
	public function __destruct() {
	}
	/**
	 * Indique si cette vue utilise des templates ou non.
	 * Les vues qui n'ont pas besoin de template n'ont pas besoin de redéfinir cette méthode.
	 * @return	bool	True si cette vue utilise des templates.
	 */
	public function useTemplates() {
		return (false);
	}
	/**
	 * Fonction d'affectation de template.
	 * Les vues qui n'ont pas besoin de template n'ont pas besoin de redéfinir cette méthode.
	 * @param	string|array	$path		Chemin(s) de recherche des templates.
	 * @param	string		$template	Nom du template à utiliser.
	 * @return	bool		True si tout s'est bien passé.
	 */
	public function setTemplate($path, $template) {
		return (true);
	}
	/**
	 * Fonction d'initialisation.
	 * @param	\Temma\Response	$response		Réponse de l'exécution du contrôleur.
	 * @param	string		$templatePath		Chemin vers le template à traiter.
	 */
	abstract public function init(\Temma\Response $response);
	/**
	 * Ecrit les headers HTTP sur la sortie standard si nécessaire.
	 * Par défaut, envoie un header HTML avec désactivation du cache.
	 */
	public function sendHeaders() {
		header('Content-Type: text/html; charset=UTF-8');
		header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0, post-check=0, pre-check=0');
		header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
		header('Pragma: no-cache');
	}
	/** Ecrit le corps du document sur la sortie standard. */
	abstract public function sendBody();
}

?>
