<?php

/**
 * Smarty view
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	2007-2023, Amaury Bouchard
 */

namespace Temma\Views;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Framework as TµFrameworkException;
use \Temma\Exceptions\IO as TµIOException;

if (!class_exists('\Smarty')) {
	include_once('smarty4/Autoloader.php');
	include_once('smarty4/bootstrap.php');
	require_once('smarty4/Smarty.class.php');
}

/**
 * View used for Smarty templates.
 *
 * @link	http://smarty.net/
 */
class Smarty extends \Temma\Web\View {
	/** Name of the temporary directory where Smarty compiled files must be written. */
	const COMPILED_DIR = 'templates_compile';
	/** Name of the temporary directory where Smarty cache files must be written. */
	const CACHE_DIR = 'templates_cache';
	/** Path to the smarty plugins directory. */
	const PLUGINS_DIR = 'lib/smarty/plugins';
	/** Flag telling if the page could be stored in cache. */
	private bool $_isCacheable = false;
	/** Smarty object. */
	private \Smarty $_smarty;
	/** Name of the template. */
	private ?string $_template = null;

	/**
	 * Constructor.
	 * @param	array			$dataSources	Liste de connexions à des sources de données.
	 * @param	\Temma\Web\Config	$config		Objet de configuration.
	 * @param	\Temma\Web\Response	$response	Objet de réponse.
	 * @throws	\Temma\Exceptions\Framework	If something went wrong.
	 */
	public function __construct(array $dataSources, \Temma\Web\Config $config, ?\Temma\Web\Response $response) {
		global $smarty;

		parent::__construct($dataSources, $config, $response);
		// check temporary directories
		$compiledDir = $config->tmpPath . '/' . self::COMPILED_DIR;
		if (!is_dir($compiledDir) && !mkdir($compiledDir, 0755))
			throw new TµFrameworkException("Unable to create directory '$compiledDir'.", TµFrameworkException::CONFIG);
		$cacheDir = $config->tmpPath . '/' . self::CACHE_DIR;
		if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755))
			throw new TµFrameworkException("Unable to create directory '$cacheDir'.", TµFrameworkException::CONFIG);
		// create the Smarty object
		$this->_smarty = new \Smarty();
		$smarty = $this->_smarty;
		$this->_smarty->setCompileDir($compiledDir);
		$this->_smarty->setCacheDir($cacheDir);
		$this->_smarty->setErrorReporting(E_ALL & ~E_NOTICE);
		$this->_smarty->muteUndefinedOrNullWarnings();
		// add plugins include path
		$pluginPathList = [];
		$pluginPathList[] = $config->appPath . '/' . self::PLUGINS_DIR;
		$pluginsDir = $config->xtra('smarty-view', 'pluginsDir');
		if (is_string($pluginsDir))
			$pluginPathList[] = $pluginsDir;
		else if (is_array($pluginsDir))
			$pluginPathList = array_merge($pluginPathList, $pluginsDir);
		$pluginPathList = array_merge($this->_smarty->getPluginsDir(), $pluginPathList);
		$this->_smarty->setPluginsDir($pluginPathList);
	}
	/**
	 * Tell that this view use template files.
	 * @return	bool	True.
	 */
	public function useTemplates() : bool {
		return (true);
	}
	/**
	 * Define template file.
	 * @param	string	$path		Templates include path.
	 * @param	string	$template	Name of the template.
	 * @throws	\Temma\Exceptions\IO	If the template file doesn't exist.
	 */
	public function setTemplate(string $path, string $template) : void {
		TµLog::log('Temma/Web', 'DEBUG', "Searching template '$template'.");
		$this->_smarty->setTemplateDir($path);
		if ($this->_smarty->templateExists($template)) {
			$this->_template = $template;
			return;
		}
		TµLog::log('Temma/Web', 'WARN', "No one template found with name '$template'.");
		throw new TµIOException("Can't find template '$template'.", TµIOException::NOT_FOUND);
	}
	/** Init. */
	public function init() : void {
		foreach ($this->_response->getData() as $key => $value) {
			if (isset($key[0]) && $key[0] != '_')
				$this->_smarty->assign($key, $value);
			else if ($key == '_temmaCacheable' && $value === true)
				$this->_isCacheable = true;
		}
	}
	/** Write body. */
	public function sendBody() : void {
		// cache management
		if ($this->_isCacheable && ($dataSource = $this->_config->xtra('temma-cache', 'source')) &&
		    isset($this->_dataSources[$dataSource]) && ($cache = $this->_dataSources[$dataSource])) {
			// Smarty template rendering
			$data = $this->_smarty->fetch($this->_template);
			if (!empty($data)) {
				// store the page in cache
				$cacheVarName = $_SERVER['HTTP_HOST'] . ':' . $_SERVER['REQUEST_URI'];
				$cache->setPrefix('temma-cache')->set($cacheVarName, $data)->setPrefix();
			}
			// write the page to stdout
			print($data);
			return;
		}
		// direct rendering to stdout
		$this->_smarty->display($this->_template);
	}
}

