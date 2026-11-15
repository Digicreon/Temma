<?php

/**
 * Php view
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2010-2026, Amaury Bouchard
 */

namespace Temma\Views;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\IO as TµIOException;

/**
 * View for templates written in plain PHP.
 *
 * Template data are fetched from the "@output" template variable (which must be an associative array)
 * or, if it is not defined, from all template variables. Variables whose name starts with an underscore
 * are not transmitted to the template (except those starting with two underscores).
 */
class Php extends \Temma\Web\View {
	/** Tell if the generated page could be stored in cache. */
	private bool $_isCacheable = false;
	/** Name of the template. */
	private ?string $_template = null;

	/**
	 * Tell that this view uses templates.
	 * @return	bool	True.
	*/
	public function useTemplates() : bool {
		return (true);
	}
	/**
	 * Define the used templates file.
	 * @param	string	$path		Templates include path.
	 * @param	string	$template	Name of the template.
	 * @throws	\Temma\Exceptions\IO	If the template file doesn't exists.
	 */
	public function setTemplate(string $path, string $template) : void {
		// add the templates include path to the PHP include paths
		set_include_path($path . PATH_SEPARATOR . get_include_path());
		// check if the file exists
		TµLog::log('Temma/Web', 'DEBUG', "Searching template '$template'.");
		if (is_file("$path/$template")) {
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
		// set global variables
		foreach ($this->_getTemplateData() as $key => $value) {
			$GLOBALS[$key] = $value;
		}
	}
	/** Write the body. */
	public function sendBody() : void {
		// processing
		ini_set('implicit_flush', false);
		ob_start();
		include($this->_template);
		$out = ob_get_contents();
		ob_end_clean();
		ini_set('implicit_flush', true);
		// cache management
		if ($this->_isCacheable && !empty($out) && ($dataSource = $this->_config->xtra('temma-cache', 'source')) &&
		    isset($this->_dataSources[$dataSource]) && ($cache = $this->_dataSources[$dataSource])) {
			$cacheVarName = $_SERVER['HTTP_HOST'] . ':' . $_SERVER['REQUEST_URI'];
			$cache->setPrefix('temma-cache')->set($cacheVarName, $out)->setPrefix();
		}
		// write the page
		print($out);
	}
}

