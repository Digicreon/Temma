<?php

namespace Temma;

/**
 * Objet de gestion des vues au sein d'applications MVC.
 *
 * @auhor	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2011, Fine Media
 * @package	Temma
 * @version	$Id: View.php 232 2011-06-23 10:27:59Z abouchard $
 */
abstract class View {
	/**
	 * Constructeur.
	 * @param	\Temma\Config	$config	Objet contenant la configuration du projet.
	 */
	public function __construct(\Temma\Config $config) {
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
