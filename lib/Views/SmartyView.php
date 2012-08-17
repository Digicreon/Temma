<?php

namespace Temma\Views;

require_once('smarty/libs/Smarty.class.php');

/**
 * Vue traitant les templates Smarty.
 *
 * @author	Amaury Bouchard <amaury.bouchard@finemedia.fr>
 * @copyright	© 2007-2012, Fine Media
 * @package	Temma
 * @subpackage	Views
 * @version	$Id: SmartyView.php 277 2012-06-26 15:55:46Z abouchard $
 * @link	http://smarty.php.net/
 */
class SmartyView extends \Temma\View {
	/** Nom du répertoire temporaire des templates compilés. */
	const COMPILED_DIR = 'templates_compile';
	/** Nom du répertoire temporaire de cache des templates. */
	const CACHE_DIR = 'templates_cache';
	/** Chemin vers le répertoire de plugins Smarty. */
	const PLUGINS_DIR = 'lib/smarty/plugins';
	/** Indique si on peut mettre la page en cache. */
	private $_isCacheable = false;
	/** Objet Smarty. */
	private $_smarty = null;
	/** Nom du template à utiliser. */
	private $_template = null;

	/**
	 * Constructeur.
	 * @param	array		$dataSources	Liste de connexions à des sources de données.
	 * @param	\Temma\Config	$config		Objet de configuration.
	 * @param	\FineSession	$session	(optionnel) Objet de connexion à la session.
	 */
	public function __construct($dataSources, \Temma\Config $config, \FineSession $session=null) {
		parent::__construct($dataSources, $config, $session);
		// vérification de la présence des répertoires temporaires
		$compiledDir = $config->tmpPath . '/' . self::COMPILED_DIR;
		if (!is_dir($compiledDir) && !mkdir($compiledDir, 0755))
			throw new \Temma\Exceptions\FrameworkException("Unable to create directory '$compiledDir'.", \Temma\Exceptions\FrameworkException::CONFIG);
		$cacheDir = $config->tmpPath . '/' . self::CACHE_DIR;
		if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755))
			throw new \Temma\Exceptions\FrameworkException("Unable to create directory '$cacheDir'.", \Temma\Exceptions\FrameworkException::CONFIG);
		// création de l'objet Smarty
		$this->_smarty = new \Smarty();
		$this->_smarty->compile_dir = $compiledDir;
		$this->_smarty->cache_dir = $cacheDir;
		// ajout des répertoires d'inclusion de plugins
		$this->_smarty->plugins_dir[] = $config->appPath . '/' . self::PLUGINS_DIR;
		$pluginsDir = $config->xtra('smarty-view', 'pluginsDir');
		if (is_string($pluginsDir))
			$this->_smarty->plugins_dir[] = $pluginsDir;
		else if (is_array($pluginsDir)) {
			foreach ($pluginsDir as $dir)
				$this->_smarty->plugins_dir[] = $dir;
		}
	}
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
		\FineLog::log('temma', \FineLog::DEBUG, "Searching template '$template'.");
		$this->_smarty->template_dir = $path;
		if ($this->_smarty->template_exists($template)) {
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
				$this->_smarty->assign($key, $value);
			else if ($key == '_temmaCacheable' && $value === true)
				$this->_isCacheable = true;
		}
	}
	/** Ecrit le corps du document sur la sortie standard. */
	public function sendBody() {
		if ($this->_isCacheable && ($dataSource = $this->_config->xtra('temma-cache', 'source')) &&
		    isset($this->_dataSources[$dataSource]) && ($cache = $this->_dataSources[$dataSource])) {
			// rendu du template par Smarty
			$data = $this->_smarty->fetch($this->_template);
			if (!empty($data)) {
				// ajout du contenu de la page en cache
				$cacheVarName = $_SERVER['HTTP_HOST'] . ':' . $_SERVER['REQUEST_URI'];
				$cache->setPrefix('temma-cache')->set($cacheVarName, $data)->setPrefix();
			}
			// écriture du contenu de la page
			print($data);
			return;
		}
		$this->_smarty->display($this->_template);
	}
}

?>
