<?php

/**
 * Smarty view
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2026, Amaury Bouchard
 */

namespace Temma\Views;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\IO as TµIOException;
use \Temma\Utils\Validation\DataFilter as TµDataFilter;
use \Temma\Utils\Smarty as TµSmarty;

/**
 * View used for Smarty templates.
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
	/** Init. */
	public function init() : void {
		// get data (from "@output" template variable, or from all template variables)
		$data = $this->_response->getData('@output') ??
		        $this->_response->getData();
		// data validation contract
		$validationContract = $this->_response->getValidationContract();
		// clean data
		foreach ($data as $key => &$value) {
			if (str_starts_with($key, '_') && !str_starts_with($key, '__')) {
				if ($key == '_temmaCacheable' && $value === true)
					$this->_isCacheable = true;
				unset($data[$key]);
			} else if (!$validationContract) {
				// transfer data to Smarty
				$this->_smarty->assign($key, $value);
			}
		}
		if (!$validationContract)
			return;
		// data filtering
		$data = TµDataFilter::process($data, $validationContract);
		// transfer data to Smarty
		foreach ($data as $key => $value) {
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

