<?php

/**
 * Smarty view
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	2007-2019, Amaury Bouchard
 */

namespace Temma\Views;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Framewor as TµFrameworkException;
use \Temma\Exceptions\IO as TµIOException;

include('smarty3/Autoloader.php');
include('smarty3/bootstrap.php');
require_once('smarty3/Smarty.class.php');

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
	private $_isCacheable = false;
	/** Smarty object. */
	private $_smarty = null;
	/** Name of the template. */
	private $_template = null;

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
		$this->_smarty->compile_dir = $compiledDir;
		$this->_smarty->cache_dir = $cacheDir;
		$this->_smarty->error_reporting = E_ALL & ~E_NOTICE;
		// add plugins include path
		$pluginPathList = [];
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
		$this->_smarty->template_dir = $path;
		if (method_exists($this->_smarty, 'templateExists')) {
			if ($this->_smarty->templateExists($template)) {
				$this->_template = $template;
				return;
			}
		} else if ($this->_smarty->template_exists($template)) {
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

