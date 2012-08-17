<?php

namespace Temma\Views;

/**
 * Vue traitant les templates écrits en PHP.
 *
 * @author	Amaury Bouchard <amaury.bouchard@finemedia.fr>
 * @copyright	© 2012, Fine Media
 * @package	Temma
 * @subpackage	Views
 * @version	$Id: SmartyView.php -1   $
 */
class PhpView extends \Temma\View {
	/** Indique si on peut mettre la page en cache. */
	private $_isCacheable = false;
	/** Nom du template à utiliser. */
	private $_template = null;

	/**
	 * Indique si cette vue utilise des templates ou non.
	 * Les vues qui n'ont pas besoin de template n'ont pas besoin de redéfinir cette méthode.
	 * @return	bool	True si cette vue utilise des templates.
	*/
	public function useTemplates() {
		return (true);
	}
	/**
	 * Fonction d'affectation de template.
	 * @param	string	$path		Chemins de recherche des templates.
	 * @param	string	$template	Nom du template à utiliser.
	 * @return	bool	True si tout s'est bien passé.
	 */
	public function setTemplate($path, $template) {
		// ajout du répertoire des templates aux chemins d'inclusion
		set_include_path($path . PATH_SEPARATOR . get_include_path());
		\FineLog::log('temma', \FineLog::DEBUG, "Searching template '$template'.");
		if (is_file("$path/$template")) {
			$this->_template = $template;
			return (true);
		}
		\FineLog::log('temma', \FineLog::WARN, "No one template found with name '$template'.");
		return (false);
	}
	/**
	 * Fonction d'initialisation.
	 * @param	\Temma\Response	$response	Réponse de l'exécution du contrôleur.
	 * @param	string		$templatePath	Chemin vers le template à traiter.
	 */
	public function init(\Temma\Response $response) {
		foreach ($response->getData() as $key => $value) {
			if (isset($key[0]) && $key[0] != '_')
				$GLOBALS[$key] = $value;
			else if ($key == '_temmaCacheable' && $value === true)
				$this->_isCacheable = true;
		}
	}
	/** Ecrit le corps du document sur la sortie standard. */
	public function sendBody() {
		// traitement du plugin
		ini_set('implicit_flush', false);
		ob_start();
		include($this->_template);
		$out = ob_get_contents();
		ob_end_clean();
		ini_set('implicit_flush', true);
		// gestion du cache
		if ($this->_isCacheable && !empty($out) && ($dataSource = $this->_config->xtra('temma-cache', 'source')) &&
		    isset($this->_dataSources[$dataSource]) && ($cache = $this->_dataSources[$dataSource])) {
			// ajout du contenu de la page en cache
			$cacheVarName = $_SERVER['HTTP_HOST'] . ':' . $_SERVER['REQUEST_URI'];
			$cache->setPrefix('temma-cache')->set($cacheVarName, $out)->setPrefix();
		}
		print($out);
	}
}

?>
