#!/usr/bin/php
<?php

/**
 * Programme de génération du code de configuration, à partir d'un fichier temma.json.
 *
 * @author	Amaury Bouchard <amaury.bouchard@finemedia.fr>
 * @copyright	© 2012-2018
 * @package	Temma
 * @subpackage	bin
 */

require_once('finebase/FineAutoload.php');
FineAutoload::autoload();

// paramètre
if ($_SERVER['argc'] != 2 || $_SERVER['argv'][1] === '--help' || $_SERVER['argv'][1] === '-h' ||
    !($appPath = realpath($_SERVER['argv'][1])) || !is_readable("$appPath/etc/temma.json"))
	usage();
// lecture du fichier de configuration
$ini = json_decode(file_get_contents("$appPath/etc/temma.json"), true);
if (is_null($ini))
	usage();
// chemins
$etcPath = "$appPath/etc";
$tmpPath = $appPath . '/' . \Temma\Config::TEMP_DIR;
$logPath = $appPath . '/' . \Temma\Config::LOG_DIR;
$logFile = $logPath . '/' . \Temma\Config::LOG_FILE;
$includesPath = $appPath . '/' . \Temma\Config::INCLUDES_DIR;
$controllersPath = $appPath . '/' . \Temma\Config::CONTROLLERS_DIR;
$viewsPath = $appPath . '/' . \Temma\Config::VIEWS_DIR;
$templatesPath = $appPath . '/' . \Temma\Config::TEMPLATES_DIR;
$webPath = $appPath . '/' . \Temma\Config::WEB_DIR;
// vérification des chemins
$pathsToInclude = array();
if (is_dir($includesPath))
	$pathsToInclude[] = $includesPath;
else
	$includesPath = null;
if (is_dir($controllersPath))
	$pathsToInclude[] = $controllersPath;
else
	$controllersPath = null;
if (is_dir($viewsPath))
	$pathsToInclude[] = $viewsPath;
else
	$viewsPath = null;
if (isset($ini['includePaths']) && is_array($ini['includePaths']))
	$pathsToInclude = array_merge($pathsToInclude, $ini['includePaths']);
$pathsToInclude = empty($pathsToInclude) ? null : implode(PATH_SEPARATOR, $pathsToInclude);
// extra configuration
$xtra = array();
foreach ($ini as $key => $value) {
	if (substr($key, 0, strlen(\Temma\Config::XTRA_CONFIG_PREFIX)) === \Temma\Config::XTRA_CONFIG_PREFIX)
		$xtra[$key] = $value;
}

/* Constitution du code de génération. */
$s = '<' . '?php
/* File generated using Temma\'s configObjectGenerator. */
namespace Temma {
	class TemmaController extends \Temma\BaseController {
';

if (isset($ini['application']['dataSources']) && is_array($ini['application']['dataSources'])) {
	// write data sources private variables
	foreach ($ini['application']['dataSources'] as $name => $dsn) {
		$s .= '		public $' . $name . ' = null;' . "\n";
	}
	$s .= "\n";
}
$s .= '		final public function __construct($dataSources, \FineSession $session=null, \Temma\Config $config, \Temma\Request $request=null, $executor=null) {
';
if (isset($ini['application']['dataSources']) && is_array($ini['application']['dataSources'])) {
	foreach ($ini['application']['dataSources'] as $name => $dsn) {
		$s .= '			$this->' . $name . ' = $dataSources[\'' . $name . '\'];' . "\n";
	}
	$s .= "\n";
}
$s .= '			parent::__construct($dataSources, $session, $config, $request, $executor);
		}
	}
}

namespace {
	class _TemmaAutoConfig extends \Temma\Config {
		protected $_executorController = null;
		public function __construct($temma, $appPath, $etcPath) {
			parent::__construct($appPath, $etcPath);
			// paths
';
if ($controllersPath)
	$s .= "\t\t\t" . '$this->_controllersPath = \'' . $controllersPath . "';\n";
if ($viewsPath)
	$s .= "\t\t\t" . '$this->_viewsPath = \'' . $viewsPath . "';\n";
if ($includesPath)
	$s .= "\t\t\t" . '$this->_includesPath = \'' . $includesPath . "';\n";
if (!empty($pathsToInclude))
	$s .= "\t\t\t" . '\FineAutoload::addIncludePath(\'' . $pathsToInclude . "');\n";
$s .= '			$this->_templatesPath = \'' . $templatesPath . '\';
			$this->_webPath = \'' . $webPath . '\';
			$this->_tmpPath = \'' . $tmpPath . '\';
			$this->_logPath = \'' . $logPath . '\';
			// log
			\FineLog::setLogFile(\'' . $logFile . '\');
			\FineLog::setThreshold(' . ((isset($ini['loglevels']) && is_array($ini['loglevels'])) ? var_export($ini['loglevels'], true) : 'self::LOG_LEVEL') . ');
			// error pages
			$this->_errorPages = ' . ((isset($ini['errorPages']) && is_array($ini['errorPages'])) ? var_export($ini['errorPages'], true) : 'null') . ';
';
if (isset($ini['application']['dataSources']) && is_array($ini['application']['dataSources'])) {
	$s .= '			// data sources
			$this->_dataSources = array(';
	foreach ($ini['application']['dataSources'] as $name => $dsn) {
		$s .= "\n\t\t\t\t'$name' => FineDatasource::factory('$dsn'),";
	}
	$s .= "\n			);\n";
}
$s .= '			// routes
			$this->_routes = ' . ((isset($ini['routes']) && is_array($ini['routes'])) ? var_export($ini['routes'], true) : 'null') . ';
			// plugins
			$this->_plugins = ' . ((isset($ini['plugins']) && is_array($ini['plugins'])) ? var_export($init['plugins'], true) : 'null') . ';
';
if (!empty($xtra)) {
	$s .= '			// extended configuration
			$this->_extraConfig = array(';
	foreach ($xtra as $key => $value) {
		$s .= "\n\t\t\t\t'$key' => " . var_export($value, true) . ",";
	}
	$s .= "\n			);\n";
}
$s .= '			// autoimport
			$this->_autoimport = ' . (isset($ini['autoimport']) ? var_export($ini['autoimport'], true) : 'null') . ';
			// session management
			$this->_enableSessions = ' . ((isset($ini['application']['enableSessions']) && $ini['application']['enableSessions'] === false) ?
						      'false' : 'true') . ';
			$this->_sessionName = ' . ((isset($ini['application']['sessionName']) && !empty($ini['application']['sessionName'])) ?
						    ("'" . $ini['application']['sessionName'] . "'") : 'self::SESSION_NAME') . ';
			$this->_sessionSource = ' . ((isset($ini['application']['sessionSource']) && !empty($ini['application']['sessionSource'])) ?
						     ("'" . $ini['application']['sessionSource'] . "'") : 'null') . ';
			// controllers
			$this->_rootController = ' . (isset($ini['application']['rootController']) ?
						      ("'" . $ini['application']['rootController'] . "'") : 'null') . ';
			$this->_defaultController = ' . (isset($ini['application']['defaultController']) ?
							 ("'" . $ini['application']['defaultController'] . "'") : 'null') . ';
			$this->_proxyController = ' . (isset($ini['application']['proxyController']) ?
						       ("'" . $ini['application']['proxyController'] . "'") : 'null') . ';
			$this->_defaultNamespace = ' . (isset($ini['application']['defaultNamespace']) ?
							("'" . $ini['application']['defaultNamespace'] . "'") : 'null') . ';
			// create a new executorController
			$this->_executorController = new \Temma\Controller($this->_dataSources, null, $this, null, null);
			// add this configuration object to Temma framework
			$temma->setConfig($this);
		}
	}
}
?' . '>';

$destination = $etcPath . '/' . \Temma\Framework::CONFIG_OBJECT_FILE_NAME;
file_put_contents($destination, $s);

/** Affiche la documentation du programme et quitte. */
function usage() {
	print("Usage:\n");
	print('	' . $_SERVER['argv'][0] . " project_root_dir\n");
	exit(1);
}

?>
