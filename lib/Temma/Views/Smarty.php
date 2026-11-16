<?php

/**
 * Smarty view
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2026, Amaury Bouchard
 */

namespace Temma\Views;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\IO as TµIOException;
use \Temma\Utils\Smarty as TµSmarty;

/**
 * View used for Smarty templates.
 *
 * Template data are fetched from the "@output" template variable (which must be an associative array)
 * or, if it is not defined, from all template variables. Variables whose name starts with an underscore
 * are not transmitted to the template (except those starting with two underscores).
 *
 * @link	http://smarty.net/
 */
class Smarty extends \Temma\Web\View {
	/** Name of the temporary directory where Smarty compiled files must be written. */
	const COMPILED_DIR = TµSmarty::COMPILED_DIR;
	/** Name of the temporary directory where Smarty cache files must be written. */
	const CACHE_DIR = TµSmarty::CACHE_DIR;
	/** Path to the smarty plugins directory. */
	const PLUGINS_DIR = TµSmarty::PLUGINS_DIR;
	/** Default setting for HTML auto-escaping. */
	const DEFAULT_AUTO_ESCAPE = TµSmarty::DEFAULT_AUTO_ESCAPE;
	/** Flag telling if the page could be stored in cache. */
	private bool $_isCacheable = false;
	/** Smarty object. */
	private \Smarty|\Smarty\Smarty $_smarty;
	/** Name of the template. */
	private ?string $_template = null;

	/**
	 * Constructor.
	 * @param	array|\ArrayAccess	$dataSources	Liste de connexions à des sources de données.
	 * @param	\Temma\Web\Config	$config		Objet de configuration.
	 * @param	\Temma\Web\Response	$response	Objet de réponse.
	 * @throws	\Temma\Exceptions\Framework	If something went wrong.
	 */
	public function __construct(array|\ArrayAccess $dataSources, \Temma\Web\Config $config, ?\Temma\Web\Response $response) {
		parent::__construct($dataSources, $config, $response);
		// create and configure the Smarty engine (temporary directories, plugins and auto-escaping)
		$this->_smarty = TµSmarty::createEngine($config);
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
	/**
	 * Init.
	 * @throws	\Temma\Exceptions\Framework	If the "@output" template variable is not an associative array.
	 * @throws	\Temma\Exceptions\Application	If data don't respect the validation contract.
	 */
	public function init() : void {
		// check if the page could be stored in cache
		$this->_isCacheable = ($this->_response->getData('_temmaCacheable') === true);
		// transfer data to Smarty
		foreach ($this->_getTemplateData() as $key => $value) {
			$this->_smarty->assign($key, $value);
		}
	}
	/** Write body. */
	public function sendBody() : void {
		print($this->_response->getPrependStream());
		// cache management
		$cache = null;
		if ($this->_isCacheable &&
		    ((($dataSource = $this->_config->xtra('temma-cache', 'source')) &&
		      ($cache = $this->_dataSources[$dataSource] ?? null)) ||
		     ($cache = $this->_dataSources['cache'] ?? null)
		    )) {
			// Smarty template rendering
			$data = $this->_smarty->fetch($this->_template);
			if (!empty($data)) {
				// store the page in cache
				$cacheVarName = $_SERVER['HTTP_HOST'] . ':' . $_SERVER['REQUEST_URI'];
				$cache->setPrefix('temma-cache')->set($cacheVarName, $data);
				$cache->setPrefix();
			}
			// write the page to stdout
			print($data);
		} else {
			// direct rendering to stdout
			$this->_smarty->display($this->_template);
		}
		print($this->_response->getAppendStream());
	}
}

