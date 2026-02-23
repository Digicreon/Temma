<?php

/**
 * Smarty
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-smarty
 */

namespace Temma\Utils;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Framework as TµFrameworkException;
use \Temma\Exceptions\IO as TµIOException;

if (!class_exists('\Smarty')) {
	include_once('smarty4/Autoloader.php');
	include_once('smarty4/bootstrap.php');
	require_once('smarty4/Smarty.class.php');
}

/**
 * Smarty templates processing object.
 */
class Smarty implements \Temma\Base\Loadable {
	/** Configuration object. */
	protected \Temma\Web\Config $_config;
	/** Smarty object. */
	private \Smarty $_smarty;

	/**
	 * Constructor.
	 * @param	\Temma\Base\Loader	$loader	Dependency injection container.
	 */
	public function __construct(\Temma\Base\Loader $loader) {
		global $smarty;

		$this->_config = $loader->config;
		// check temporary directories
		$compiledDir = $this->_config->tmpPath . '/' . \Temma\Views\Smarty::COMPILED_DIR;
		if (!is_dir($compiledDir) && !mkdir($compiledDir, 0755))
			throw new TµFrameworkException("Unable to create directory '$compiledDir'.", TµFrameworkException::CONFIG);
		$cacheDir = $this->_config->tmpPath . '/' . \Temma\Views\Smarty::CACHE_DIR;
		if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755))
			throw new TµFrameworkException("Unable to create directory '$cacheDir'.", TµFrameworkException::CONFIG);
		// create the Smarty object
		$this->_smarty = new \Smarty();
		$smarty = $this->_smarty;
		$this->_smarty->setCompileDir($compiledDir);
		$this->_smarty->setCacheDir($cacheDir);
		$this->_smarty->setErrorReporting(E_ALL & ~E_NOTICE);
		$this->_smarty->muteUndefinedOrNullWarnings();
		// add templates root directory
		$this->_smarty->setTemplateDir($this->_config->templatesPath);
		// add plugins include path
		$pluginPathList = [];
		$pluginPathList[] = $this->_config->appPath . '/' . \Temma\Views\Smarty::PLUGINS_DIR;
		$pluginsDir = $this->_config->xtra('smarty-view', 'pluginsDir');
		if (is_string($pluginsDir))
			$pluginPathList[] = $pluginsDir;
		else if (is_array($pluginsDir))
			$pluginPathList = array_merge($pluginPathList, $pluginsDir);
		// auto-registration of temma-ui plugins
		$pluginPathList[] = $this->_config->appPath . '/vendor/digicreon/temma-ui/lib/smarty-plugins';
		$pluginPathList = array_merge($this->_smarty->getPluginsDir(), $pluginPathList);
		$this->_smarty->setPluginsDir($pluginPathList);
	}
	/**
	 * Tell if a template file exists.
	 * @param	string	$path	Path to the template file.
	 * @return	bool	True if the template exists.
	 */
	public function templateExists(string $path) : bool {
		return ($this->_smarty->templateExists($path));
	}
	/**
	 * Process a Smarty template with the given set of data.
	 * @param	string	$template	Template path.
	 * @param	?array	$data		Associative array.
	 * @return	string	The generated stream.
	 * @throws	\Temma\Exceptions\IO	If the template doesnt exist.
	 */
	public function render(string $template, ?array $data) : string {
		return ($this->_process($template, $data));
	}
	/**
	 * Process a Smarty template which content is given in a string.
	 * @param	string	$templateContent	Content of the template.
	 * @param	?array	$data			Associative array.
	 * @return	string	The generated stream.
	 * @throws	\Temma\Exceptions\IO	If the template doesnt exist.
	 */
	public function eval(string $templateContent, ?array $data) : string {
		return ($this->_process('eval:' . $templateContent, $data));
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Process a Smarty template with the given set of data.
	 * @param	string	$template	Template path.
	 * @param	?array	$data		Associative array.
	 * @return	string	The generated stream.
	 * @throws	\Temma\Exceptions\IO	If the template doesnt exist.
	 */
	protected function _process(string $template, ?array $data) : string {
		// check template
		if (!$this->_smarty->templateExists($template))
			throw new TµIOException("Can't find template '$template'.", TµIOException::NOT_FOUND);
		// set data
		if ($data) {
			foreach ($data as $key => $value) {
				$this->_smarty->assign($key, $value);
			}
		}
		// Smarty template rendering
		$result = $this->_smarty->fetch($template);
		return ($result);
	}
}

