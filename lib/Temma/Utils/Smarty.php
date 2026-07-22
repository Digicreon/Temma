<?php

/**
 * Smarty
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023-2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-smarty
 */

namespace Temma\Utils;

use \Temma\Exceptions\Framework as TµFrameworkException;
use \Temma\Exceptions\IO as TµIOException;

/**
 * Smarty templates processing object.
 *
 * Compatible with both Smarty 4 and Smarty 5. The library is used from Composer
 * (autoloaded automatically), from a copy in 'lib/Smarty' (Smarty 5) or 'lib/smarty4'
 * (Smarty 4), autoloaded by Temma.
 */
class Smarty implements \Temma\Base\Loadable {
	/** Name of the temporary directory where Smarty compiled files must be written. */
	const COMPILED_DIR = 'templates_compile';
	/** Name of the temporary directory where Smarty cache files must be written. */
	const CACHE_DIR = 'templates_cache';
	/** Path to the smarty plugins directory. */
	const PLUGINS_DIR = 'lib/smarty-plugins';
	/** Default setting for HTML auto-escaping. */
	const DEFAULT_AUTO_ESCAPE = true;
	/** Configuration object. */
	protected \Temma\Web\Config $_config;
	/** Smarty object. */
	private \Smarty|\Smarty\Smarty $_smarty;

	/**
	 * Constructor.
	 * @param	\Temma\Base\Loader	$loader	Dependency injection container.
	 * @throws	\Temma\Exceptions\Framework	If the Smarty library is missing or a directory can't be created.
	 */
	public function __construct(\Temma\Base\Loader $loader) {
		$this->_config = $loader->config;
		$this->_smarty = self::createEngine($this->_config);
		// add templates root directory
		$this->_smarty->setTemplateDir($this->_config->templatesPath);
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
	 * @param	?array	$data		(optional) Associative array.
	 * @param	?bool	$autoEscape	(optional) True or false to force HTML auto-escaping for this call only,
	 *					null (default) to use the engine's configured setting.
	 * @return	string	The generated stream.
	 * @throws	\Temma\Exceptions\IO	If the template doesnt exist.
	 */
	public function render(string $template, ?array $data=null, ?bool $autoEscape=null) : string {
		return ($this->_process($template, $data, $autoEscape));
	}
	/**
	 * Process a Smarty template which content is given in a string.
	 * @param	string	$templateContent	Content of the template.
	 * @param	?array	$data			(optional) Associative array.
	 * @param	?bool	$autoEscape		(optional) True or false to force HTML auto-escaping for this call only,
	 *						null (default) to use the engine's configured setting.
	 * @return	string	The generated stream.
	 * @throws	\Temma\Exceptions\IO	If the template doesnt exist.
	 */
	public function eval(string $templateContent, ?array $data=null, ?bool $autoEscape=null) : string {
		return ($this->_process('eval:' . $templateContent, $data, $autoEscape));
	}

	/* ********** STATIC METHODS ********** */
	/**
	 * Create and configure a Smarty engine (Smarty 4 or Smarty 5) from a Temma configuration object.
	 * Used by this object and by the Smarty view (\Temma\Views\Smarty).
	 * @param	\Temma\Web\Config	$config	Configuration object.
	 * @return	\Smarty|\Smarty\Smarty	The configured Smarty object.
	 * @throws	\Temma\Exceptions\Framework	If the Smarty library is missing or a directory can't be created.
	 */
	static public function createEngine(\Temma\Web\Config $config) : \Smarty|\Smarty\Smarty {
		global $smarty;

		// load the Smarty library if needed (Smarty 5 is preferred, the bundled Smarty 4 is a fallback)
		if (!class_exists('\Smarty\Smarty') && !class_exists('\Smarty')) {
			include_once('smarty4/Autoloader.php');
			include_once('smarty4/bootstrap.php');
			require_once('smarty4/Smarty.class.php');
		} else if (class_exists('\Smarty\Smarty') && !function_exists('smarty_ucfirst_ascii'))
			require_once('Smarty/functions.php');
		if (!class_exists('\Smarty\Smarty') && !class_exists('\Smarty'))
			throw new TµFrameworkException("Smarty library not found.", TµFrameworkException::CONFIG);
		$isSmarty5 = class_exists('\Smarty\Smarty');
		// check temporary directories
		$compiledDir = $config->tmpPath . '/' . self::COMPILED_DIR;
		if (!is_dir($compiledDir) && !mkdir($compiledDir, 0755))
			throw new TµFrameworkException("Unable to create directory '$compiledDir'.", TµFrameworkException::CONFIG);
		$cacheDir = $config->tmpPath . '/' . self::CACHE_DIR;
		if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755))
			throw new TµFrameworkException("Unable to create directory '$cacheDir'.", TµFrameworkException::CONFIG);
		// create the Smarty object
		$smarty = $isSmarty5 ? new \Smarty\Smarty() : new \Smarty();
		$smarty->setCompileDir($compiledDir);
		$smarty->setCacheDir($cacheDir);
		$smarty->setErrorReporting(E_ALL & ~E_NOTICE);
		$smarty->muteUndefinedOrNullWarnings();
		// HTML auto-escaping ('x-smarty' section, with a fallback to the legacy 'x-smarty-view' section)
		$autoEscape = $config->xtra('smarty', 'autoEscape') ??
		              $config->xtra('smarty-view', 'autoEscape') ??
		              self::DEFAULT_AUTO_ESCAPE;
		$smarty->setEscapeHtml((bool)$autoEscape);
		// build the plugins directories list
		$pluginPathList = [];
		$pluginPathList[] = $config->appPath . '/' . self::PLUGINS_DIR;
		$pluginsDir = $config->xtra('smarty', 'pluginsDir') ?? $config->xtra('smarty-view', 'pluginsDir');
		if (is_string($pluginsDir))
			$pluginPathList[] = $pluginsDir;
		else if (is_array($pluginsDir))
			$pluginPathList = array_merge($pluginPathList, $pluginsDir);
		// auto-registration of temma-ui plugins
		$pluginPathList[] = $config->appPath . '/vendor/digicreon/temma-ui/lib/smarty-plugins';
		if (!$isSmarty5) {
			// Smarty 4: register the plugins directories; native PHP functions are usable as modifiers by default
			$pluginPathList = array_merge($smarty->getPluginsDir(), $pluginPathList);
			$pluginPathList = array_unique($pluginPathList);
			$smarty->addPluginsDir($pluginPathList);
			return ($smarty);
		}
		// Smarty 5: register each plugin found in the directories
		$pluginPathList = array_unique($pluginPathList);
		foreach ($pluginPathList as $path) {
			$path = rtrim($path, '/');
			foreach (['function', 'modifier', 'block', 'compiler', 'prefilter', 'postfilter', 'outputfilter'] as $type) {
				foreach (glob("$path/$type.?*.php") as $filename) {
					if (!preg_match('/.*\.([a-z_A-Z0-9]+)\.php$/', $filename, $matches))
						continue;
					$pluginName = $matches[1];
					require_once($filename);
					$functionOrClassName = 'smarty_' . $type . '_' . $pluginName;
					if (function_exists($functionOrClassName) || class_exists($functionOrClassName))
						$smarty->registerPlugin($type, $pluginName, $functionOrClassName, true);
				}
			}
		}
		// Smarty 5: registration of native PHP functions as modifiers (skip names already registered as plugins)
		$functions = ['array_key_exists', 'ceil', 'date', 'explode', 'floatval', 'htmlentities',
		              'md5', 'ip2long', 'is_array', 'is_numeric', 'intval', 'str_starts_with',
		              'str_ends_with', 'str_contains', 'stripslashes', 'strstr', 'strtotime', 'trim'];
		foreach ($functions as $f) {
			if (!$smarty->getRegisteredPlugin('modifier', $f))
				$smarty->registerPlugin('modifier', $f, $f);
		}
		return ($smarty);
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Process a Smarty template with the given set of data.
	 * @param	string	$template	Template path.
	 * @param	?array	$data		Associative array.
	 * @param	?bool	$autoEscape	True or false to force HTML auto-escaping for this call only,
	 *					null to use the engine's configured setting.
	 * @return	string	The generated stream.
	 * @throws	\Temma\Exceptions\IO	If the template doesnt exist.
	 */
	protected function _process(string $template, ?array $data, ?bool $autoEscape=null) : string {
		// check template
		if (!$this->_smarty->templateExists($template))
			throw new TµIOException("Can't find template '$template'.", TµIOException::NOT_FOUND);
		// set data
		if ($data) {
			foreach ($data as $key => $value) {
				$this->_smarty->assign($key, $value);
			}
		}
		// no auto-escaping override: render with the engine's configured setting
		if (is_null($autoEscape))
			return ($this->_smarty->fetch($template));
		// per-call auto-escaping override: force the setting, then restore it (the engine could be shared).
		// A distinct compile_id is used to avoid reusing a template variant that was compiled with the
		// other escaping setting: Smarty caches template objects by resource/cache_id/compile_id, not by
		// the escape_html flag.
		$previousAutoEscape = $this->_smarty->escape_html;
		$this->_smarty->setEscapeHtml($autoEscape);
		try {
			$result = $this->_smarty->fetch($template, null, ($autoEscape ? 'escape' : 'noescape'));
		} finally {
			$this->_smarty->setEscapeHtml($previousAutoEscape);
		}
		return ($result);
	}
}
