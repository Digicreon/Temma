#!/usr/bin/php
<?php

/**
 * Programme de génération du code de configuration, à partir d'un fichier temma.json.
 *
 * @author	Amaury Bouchard <amaury.bouchard@finemedia.fr>
 * @copyright	© 2012, Fine Media
 * @package	Temma
 * @subpackage	bin
 * @version	$Id$
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
if (!empty($pathsToInclude))
	$pathsToInclude = implode(PATH_SEPARATOR, $pathsToInclude);

/* Constitution du code de génération. */
$s = '<' . '?php
/* File generated using Temma\'s configObjectGenerator. */
namespace Temma {
	class TemmaController extends \Temma\BaseController {
';

if (isset($ini['application']['dataSources']) && is_array($ini['application']['dataSources'])) {
	foreach ($ini['application']['dataSources'] as $name => $dsn)
		$s .= '		public $' . $name . ' = null;' . "\n";
}
$s .= '
		final public function __construct($dataSources, \FineSession $session=null, \Temma\Config $config, \Temma\Request $request=null, $executor=null) {
';
if (isset($ini['application']['dataSources']) && is_array($ini['application']['dataSources'])) {
	foreach ($ini['application']['dataSources'] as $name => $dsn) 
		$s .= '			$this->' . $name . ' = $dataSources["' . $name . '"];' . "\n";
}
$s .= '
			parent::__construct($dataSources, $session, $config, $request, $executor);
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
	$s .= "\t\t\t" . '$this->_controllersPath = "' . $controllersPath . '";' . "\n";
if ($viewsPath)
	$s .= "\t\t\t" . '$this->_viewsPath = "' . $viewsPath . '";' . "\n";
if ($includesPath)
	$s .= "\t\t\t" . '$this->_includesPath = "' . $includesPath . '";' . "\n";
if (!empty($pathsToInclude))
	$s .= "\t\t\t" . '\FineAutoload::addIncludePath("' . $pathsToInclude . '");' . "\n";
$s .= '			$this->_templatesPath = "' . $templatesPath . '";
			$this->_webPath = "' . $webPath . '";
			$this->_tmpPath = "' . $tmpPath . '";
			$this->_logPath = "' . $logPath . '";
			// log
			\FineLog::setLogFile("' . $logFile . '");
			\FineLog::setThreshold(' . ((isset($ini['loglevels']) && is_array($ini['loglevels'])) ?
						    ('unserialize("' . str_replace('"', '\"', serialize($ini['loglevels'])) . '")') :
						    'self::LOG_LEVEL') . ');

			// error pages
			$this->_errorPages = ' . ((isset($ini['errorPages']) && is_array($ini['errorPages'])) ?
						  ('unserialize("' . str_replace('"', '\"', serialize($ini['errorPages'])) . '")') :
						  'array()') . ';

			// data sources
			$this->_dataSources = array(';
if (isset($ini['application']['dataSources']) && is_array($ini['application']['dataSources'])) {
	foreach ($ini['application']['dataSources'] as $name => $dsn)
		$s .= "\n\t\t\t\t'$name' => FineDatasource::factory('$dsn'),";
}
$s .= '
			);
			// routes
			$this->_routes = ' . ((isset($ini['routes']) && is_array($ini['routes'])) ?
					      ('unserialize("' . str_replace('"', '\"', serialize($ini['routes'])) . '")') :
					      'array()') . ';
			// plugins
			$this->_plugins = ' . ((isset($ini['plugins']) && is_array($ini['plugins'])) ?
					       ('unserialize("' . str_replace('"', '\"', serialize($ini['plugins'])) . '")') :
					       'array()') . ';
			// extended configuration
			$this->_extraConfig = array(';
foreach ($ini as $key => $value) {
	if (substr($key, 0, strlen(\Temma\Config::XTRA_CONFIG_PREFIX)) === \Temma\Config::XTRA_CONFIG_PREFIX)
		$s .= "\n\t\t\t\t'$key' => unserialize(\"" . str_replace('"', '\"', serialize($value)) . "\"),";
}
$s .= '			);
			// autoimport
			$this->_autoimport = ' . (isset($ini['autoimport']) ?
						  ('unserialize("' . str_replace('"', '\"', serialize($ini['autoimport'])) . '")') :
						  'null') . ';
			// session management
			$this->_enableSessions = ' . ((isset($ini['application']['enableSessions']) && $ini['application']['enableSessions'] === false) ?
						      'false' : 'true') . ';
			$this->_sessionName = "' . ((isset($ini['application']['sessionName']) && !empty($ini['application']['sessionName'])) ?
						    $ini['application']['sessionName'] : self::SESSION_NAME) . '";
			$this->_sessionSource = ' . ((isset($ini['application']['sessionSource']) && !empty($ini['application']['sessionSource'])) ?
						     ('"' . $ini['application']['sessionSource'] . '"') : 'null') . ';
			// controllers
			$this->_rootController = ' . (isset($ini['application']['rootController']) ?
						      ('"' . $ini['application']['rootController'] . '"') : 'null') . ';
			$this->_defaultController = ' . (isset($ini['application']['defaultController']) ?
							 ('"' . $ini['application']['defaultController'] . '"') : 'null') . ';
			$this->_proxyController = ' . (isset($ini['application']['proxyController']) ?
						       ('"' . $ini['application']['proxyController'] . '"') : 'null') . ';
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
	print("	" . $_SERVER['argv'][0] . " project_root_dir\n");
	exit(1);
}

?>
