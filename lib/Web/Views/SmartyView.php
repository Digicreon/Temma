<?php

namespace Temma\Views;

require_once('smarty3/Smarty.class.php');

/**
 * Vue traitant les templates Smarty.
 *
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @package	Temma
 * @subpackage	Views
 * @link	http://smarty.php.net/
 */
class SmartyView extends \Temma\View {
	/** Nom du répertoire temporaire des templates compilés. */
	const COMPILED_DIR = 'templates_compile';
	/** Nom du répertoire temporaire de cache des templates. */
	const CACHE_DIR = 'templates_cache';
	/** Chemin vers le répertoire de plugins Smarty. */
	const PLUGINS_DIR = 'lib/smarty/plugins';
	/** Nom de la clé de configuration pour les headers. */
	protected $_cacheKey = 'smarty';
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
	 * @param	\Temma\Response	$response	Objet de réponse.
	 */
	public function __construct($dataSources, \Temma\Config $config, \Temma\Response $response) {
		global $smarty;

		parent::__construct($dataSources, $config, $response);
		// vérification de la présence des répertoires temporaires
		$compiledDir = $config->tmpPath . '/' . self::COMPILED_DIR;
		if (!is_dir($compiledDir) && !mkdir($compiledDir, 0755))
			throw new \Temma\Exceptions\FrameworkException("Unable to create directory '$compiledDir'.", \Temma\Exceptions\FrameworkException::CONFIG);
		$cacheDir = $config->tmpPath . '/' . self::CACHE_DIR;
		if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755))
			throw new \Temma\Exceptions\FrameworkException("Unable to create directory '$cacheDir'.", \Temma\Exceptions\FrameworkException::CONFIG);
		// création de l'objet Smarty
		$this->_smarty = new \Smarty();
		$smarty = $this->_smarty;
		$this->_smarty->compile_dir = $compiledDir;
		$this->_smarty->cache_dir = $cacheDir;
		$this->_smarty->error_reporting = E_ALL & ~E_NOTICE;
		// ajout des répertoires d'inclusion de plugins
		$pluginPathList = array();
		$pluginPathList[] = $config->appPath . '/' . self::PLUGINS_DIR;
		$pluginsDir = $config->xtra('smarty-view', 'pluginsDir');
		if (is_string($pluginsDir))
			$pluginPathList[] = $pluginsDir;
		else if (is_array($pluginsDir))
			$pluginPathList = array_merge($pluginsPathList, $pluginsDir);
		if (method_exists($this->_smarty, 'setPluginsDir')) {
			$pluginPathList = array_merge($this->_smarty->getPluginsDir(), $pluginPathList);
			$this->_smarty->setPluginsDir($pluginPathList);
		} else {
			foreach ($pluginPathList as $_path) {
				$this->_smarty->plugins_dir[] = $_path;
			}
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
		if (method_exists($this->_smarty, 'templateExists')) {
			if ($this->_smarty->templateExists($template)) {
				$this->_template = $template;
				return (true);
			}
		} else if ($this->_smarty->template_exists($template)) {
			$this->_template = $template;
			return (true);
		}
		\FineLog::log('temma', \FineLog::WARN, "No one template found with name '$template'.");
		return (false);
	}
	/** Fonction d'initialisation. */
	public function init() {
		foreach ($this->_response->getData() as $key => $value) {
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

