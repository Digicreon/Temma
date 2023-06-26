<?php

/**
 * Php view
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2010-2023, Amaury Bouchard
 */

namespace Temma\Views;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\IO as TµIOException;

/**
 * View for templates written in plain PHP.
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
	/** Init. */
	public function init() : void {
		foreach ($this->_response->getData() as $key => $value) {
			if (isset($key[0]) && $key[0] != '_')
				$GLOBALS[$key] = $value;
			else if ($key == '_temmaCacheable' && $value === true)
				$this->_isCacheable = true;
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

