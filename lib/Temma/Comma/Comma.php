<?php

/**
 * Comma
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2024-2026, Amaury Bouchard
 */

namespace Temma\Comma;

use \Temma\Base\Log as TµLog;
use \Temma\Utils\Ansi as TµAnsi;

/**
 * Comma execution object.
 *
 * @see		\Temma\Web\Framework
 */
class Comma {
	/** Path to the project root. */
	private string $_rootPath;
	/** Name of the executed object. */
	private ?string $_objectName = null;
	/** Name of the executed method. */
	private ?string $_methodName = null;
	/** List of parameters given to the method. */
	private array $_params = [];
	/** Path to the configuration file, if forced on the command line. */
	private ?string $_forcedConfigPath = null;
	/** Configured log levels. */
	private null|string|array $_usedLogLevels = null;
	/** Configuration object. */
	private ?\Temma\Web\Config $_config = null;
	/** Request object. */
	private ?\Temma\Web\Request $_request = null;
	/** Response object. */
	private ?\Temma\Web\Response $_response = null;
	/** Loader object. */
	private ?\Temma\Base\Loader $_loader = null;

	/**
	 * Constructor.
	 * @param	string	$rootPath	Path to the project root.
	 */
	public function __construct(string $rootPath) {
		$this->_rootPath = realpath($rootPath);
	}
	/**
	 * Comma execution.
	 */
	public function exec() : void {
		global $temma;

		$temma = null;
		// log configuration
		TµLog::logToStdErr();

		/* *** Options management. *** */
		$this->_getParameters();

		/* *** Read configuration file. *** */
		$this->_manageConfiguration();

		/* *** Create Loader object (dependency injection container). *** */
		$this->_initLoader();

		/* *** Connect to data sources. *** */
		$this->_loadDatasources();

		/* *** Check controller object. *** */
		$this->_checkController();

		/* *** Command execution. *** */
		// check root action
		$this->_methodName = $this->_methodName ?: \Temma\Web\Framework::CONTROLLERS_ROOT_ACTION;
		// execution
		$executorController = new \Temma\Web\Controller($this->_loader);
		try {
			$status = $executorController->_subProcess($this->_objectName, $this->_methodName);
			exit((int)$status);
		} catch (\Exception $e) {
			// display errors
			$message = $e->getMessage() ?: 'Error';
			print("\n");
			print(TµAnsi::block('alert', $message));
			// if the loglevel is DEBUG, shows the stacktrace
			if (($this->_usedLogLevels['Temma/Cli'] ?? null) == TµLog::DEBUG)
				throw $e;
			exit(1);
		}
	}

	/* ********** PRIVATE METHODS ********** */
	/** Check controller object. */
	private function _checkController() : void {
		if (!class_exists($this->_objectName)) {
			fprintf(STDERR, "Object '{$this->_objectName}' doesn't exists.\n");
			exit(3);
		}
		if (!is_subclass_of($this->_objectName, '\Temma\Web\Controller')) {
			fprintf(STDERR, "Object '{$this->_objectName}' doesn't extend the \Temma\Web\Controller object.\n");
			exit(3);
		}
	}
	/** Load datasources. */
	private function _loadDatasources() : void {
		$dataSources = new \Temma\Utils\Registry();
		foreach ($this->_config->dataSources as $name => $dsParam) {
			$dataSource = \Temma\Base\Datasource::metaFactory($dsParam);
			$dataSources[$name] = $dataSource;
			if (!in_array($name, ['config', 'request', 'response', 'temma', 'session', 'dataSources', 'controller']))
				$this->_loader[$name] = $dataSource;
		}
		$this->_loader['dataSources'] = $dataSources;
	}
	/** Loader init. */
	private function _initLoader() : void {
		// create request
		$this->_request = new \Temma\Web\Request(false);
		$this->_request->setParams($this->_params);
		// create response
		$this->_response = new \Temma\Web\Response();
		$this->_response['CONTROLLER'] = $this->_objectName;
		$this->_response['ACTION'] = $this->_methodName;
		$this->_response['conf'] = $this->_config->autoimport;
		// create the loader
		$loaderName = $this->_config->loader;
		$this->_loader = new $loaderName([
			'config'   => $this->_config,
			'request'  => $this->_request,
			'response' => $this->_response,
		]);
		// configure the loader with the defined aliases and prefixes
		if (isset($this->_config->loaderPreload))
			$this->_loader->set($this->_config->loaderPreload);
		if (isset($this->_config->loaderAliases))
			$this->_loader->alias($this->_config->loaderAliases);
		if (isset($this->_config->loaderPrefixes))
			$this->_loader->prefix($this->_config->loaderPrefixes);
	}
	/** Creates the configuration. */
	private function _manageConfiguration() : void {
		$this->_config = new \Temma\Web\Config($this->_rootPath);
		$this->_config->readConfigurationFile($this->_forcedConfigPath);

		/* *** Manage log thresholds. *** */
		$logLevels = $this->_config->logLevels;
		$this->_usedLogLevels = TµLog::checkLogLevel($logLevels);
		if (!$this->_usedLogLevels && is_array($logLevels)) {
			$this->_usedLogLevels = []; 
			foreach ($logLevels as $class => $level) {
				if (($level = TµLog::checkLogLevel($level)))
					$this->_usedLogLevels[$class] = $level;
			}
		}
		if (!$this->_usedLogLevels)
			$this->_usedLogLevels = \Temma\Web\Config::LOG_LEVEL;
		TµLog::setThreshold($this->_usedLogLevels);
	}
	/** Extracts parameters from command-line options. */
	private function _getParameters() : void {
		if ($_SERVER['argc'] < 2) {
			self::_showHelp(true);
			exit(1);
		}
		array_shift($_SERVER['argv']);
		// help management
		if ($_SERVER['argv'][0] == 'help') {
			self::_showHelp();
			exit(0);
		}
		while (true) {
			// stderr management
			if ($_SERVER['argv'][0] == 'nostderr') {
				TµLog::logToStdErr(false);
				array_shift($_SERVER['argv']);
				continue;
			}
			// Temma configuration file management
			if (str_starts_with($_SERVER['argv'][0], 'conf=')) {
				$this->_forcedConfigPath = mb_substr($_SERVER['argv'][0], mb_strlen('conf='));
				array_shift($_SERVER['argv']);
				continue;
			}
			// inclusion path management
			if (str_starts_with($_SERVER['argv'][0], 'inc=')) {
				$incPath = mb_substr($_SERVER['argv'][0], mb_strlen('inc='));
				set_include_path($incPath . PATH_SEPARATOR . get_include_path());
				array_shift($_SERVER['argv']);
				continue;
			}
			break;
		}
		// extract object name, method name and positional parameters, possibly aggregated
		// in the first element ("Obj:method", "Obj::method", "Obj/method/param1"...)
		[$this->_objectName, $this->_methodName, $positional] = self::_parseCommand(array_shift($_SERVER['argv']));
		$this->_objectName = self::_resolveObjectName($this->_objectName);
		if ($this->_methodName === null) {
			// no separator used: the method name may be given as the next element
			$this->_methodName = array_shift($_SERVER['argv']);
			if ($this->_methodName !== null && str_starts_with($this->_methodName, '--')) {
				fprintf(STDERR, "No parameter allowed for the root action.\n");
				exit(1);
			}
		}
		// extract named and positional parameters
		$named = [];
		$rawMode = false;
		foreach ($_SERVER['argv'] as $param) {
			// after a '--' marker, everything is a positional parameter
			if ($rawMode) {
				$positional[] = $param;
				continue;
			}
			if ($param === '--') {
				$rawMode = true;
				continue;
			}
			// named parameter
			if (str_starts_with($param, '--')) {
				// remove '--' prefix
				$param = mb_substr($param, 2);
				// extract parameter without value
				if (!str_contains($param, '=')) {
					$named[$param] = true;
					continue;
				}
				// extract parameter with value
				if (!preg_match('/^([^=]+)=(.*)$/', $param, $matches)) {
					fprintf(STDERR, "Bad parameter '$param'\n");
					exit(2);
				}
				$param = $matches[1];
				$val = $matches[2];
				if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
				    (str_starts_with($val, "'") && str_ends_with($val, "'")))
					$val = mb_substr($val, 1, -1);
				$named[$param] = $val;
				continue;
			}
			// parameter starting with a single dash: probably a mistyped named parameter
			// (use the '--' marker to pass positional values starting with a dash)
			if (str_starts_with($param, '-')) {
				fprintf(STDERR, "Invalid parameter '$param'.\n");
				exit(2);
			}
			// positional parameter
			$positional[] = $param;
		}
		// positional parameters first, named parameters after (mandatory order for PHP named arguments)
		$this->_params = array_merge($positional, $named);
	}
	/**
	 * Parse a command designation, which may aggregate the object name, the method name and
	 * positional parameters ("Obj", "Obj:method", "Obj::method", "Obj/method/param1/param2",
	 * "Obj:method/param1"...).
	 * @param	string	$token	The command designation.
	 * @return	array	Object name, method name (null if no separator was used) and
	 *			list of positional parameters.
	 */
	static private function _parseCommand(string $token) : array {
		// search for the leftmost separator ('::' takes precedence over ':' at the same position)
		$positions = [];
		if (($pos = mb_strpos($token, '::')) !== false)
			$positions['::'] = $pos;
		if (($pos = mb_strpos($token, ':')) !== false && $pos !== ($positions['::'] ?? -1))
			$positions[':'] = $pos;
		if (($pos = mb_strpos($token, '/')) !== false)
			$positions['/'] = $pos;
		if (!$positions)
			return ([$token, null, []]);
		asort($positions);
		$separator = array_key_first($positions);
		$objectName = mb_substr($token, 0, $positions[$separator]);
		$rest = mb_substr($token, $positions[$separator] + mb_strlen($separator));
		// the rest is split on '/' to get the method name and the positional parameters
		$chunks = explode('/', $rest);
		$methodName = array_shift($chunks);
		return ([$objectName, $methodName, $chunks]);
	}
	/**
	 * Resolve an object name: if the given name doesn't start with a backslash and doesn't match
	 * an existing class, search the class in the '\Temma\Cli' namespace (framework's built-in commands).
	 * @param	string	$objectName	The object name given on the command line.
	 * @return	string	The resolved object name.
	 */
	static private function _resolveObjectName(string $objectName) : string {
		if (!class_exists($objectName) && !str_starts_with($objectName, '\\') && class_exists("\\Temma\\Cli\\$objectName"))
			return ("\\Temma\\Cli\\$objectName");
		return ($objectName);
	}
	/**
	 * Function called when the first parameter on the command line is 'help'.
	 * It extracts the name of the controller (and the action if given) and shows
	 * the related information.
	 * @param	bool	$showUsage	(optional) True to force usage display.
	 */
	static private function _showHelp(bool $showUsage=false) : void {
		array_shift($_SERVER['argv']);
		if (!$_SERVER['argv'] || $showUsage) {
			$s = "<h1 padding='2'>COMMA USAGE</h1>
<h2 line=''>Common usage</h2>
    <b>bin/comma</b> <color t='blue'>controller</color><faint>/</faint><color t='green'>action</color> <faint>[</faint>--<color t='yellow'>param1</color>=<color t='cyan'>value1</color><faint>] [</faint>--<color t='yellow'>flag</color><faint>]</faint>...<br />
    <b>bin/comma</b> <color t='blue'>controller</color><faint>/</faint><color t='green'>action</color><faint>/</faint><color t='cyan'>value1</color><faint>/</faint><color t='cyan'>value2</color>...<br />
        <faint>Parameters can be named (mapped to the parameters of the action method) or positional, like in a URL.</faint><br />
        <faint>Positional values can also be given as separate arguments: <b>bin/comma</b> <span textColor='blue'>controller</span>/<span textColor='green'>action</span> <span textColor='cyan'>value1</span> <span textColor='cyan'>value2</span></faint><br />
        <faint>Values starting with a dash must be preceded by a '--' marker: <b>bin/comma</b> <span textColor='blue'>Math</span>/<span textColor='green'>add</span> -- <span textColor='cyan'>-5</span></faint><br />

    <b>bin/comma</b> <color t='blue'>controller</color> <color t='green'>action</color>, <b>bin/comma</b> <color t='blue'>controller</color>:<color t='green'>action</color>, <b>bin/comma</b> <color t='blue'>controller</color>::<color t='green'>action</color><br />
        <faint>Alternative equivalent forms.</faint><br />

    <b>bin/comma</b> <color t='blue'>controller</color><br />
        <faint>If no action is given, the <span textColor='green'>__invoke()</span> method is executed.</faint><br />
        <faint>If the controller has a <span textColor='green'>__proxy()</span> method, it is executed systematically.</faint><br />
        <faint>If the requested action doesn't exist, but the controller has a <span textColor='green'>__call()</span> method, this method is executed.</faint><br />

    <b>bin/comma</b> <color t='blue'>Cache</color><faint>/</faint><color t='green'>clear</color><br />
        <faint>If the controller is not found and doesn't start with a backslash, it is searched in the <span textColor='blue'>\\Temma\\Cli</span> namespace (framework's built-in commands).</faint><br />


<h2 line='' marginTop='2'>Help</h2>
    <b>bin/comma</b><br />
    <b>bin/comma</b> <u>help</u><br />
        <faint>Shows this help.</faint><br /><br />

    <b>bin/comma</b> <u>help</u> <color t='blue'>controller</color><br />
        <faint>Shows the list of actions offered by the requested controller.</faint><br /><br />

    <b>bin.comma</b> <u>help</u> <color t='blue'>controller</color> <color t='red'>action</color><br />
        <faint>Shows the documentation of the requested action.</faint><br />";
			fprintf(STDERR, TµAnsi::style($s));
			exit(0);
		}
		$objectName = array_shift($_SERVER['argv']);
		[$objectName, $methodName, ] = self::_parseCommand($objectName);
		$objectName = self::_resolveObjectName($objectName);
		if ($methodName)
			array_unshift($_SERVER['argv'], $methodName);
		if (!class_exists($objectName)) {
			print(TµAnsi::color('red', "There is no avaiable controller named '$objectName'.\n"));
			exit(1);
		}
		// get controller info
		$reflect = new \ReflectionClass($objectName);
		print(TµAnsi::title1('COMMA HELP'));
		print(TµAnsi::title3("Controller: $objectName"));
		$notMethods = get_class_methods('\Temma\Web\Controller');
		$methods = $reflect->getMethods(\ReflectionMethod::IS_PUBLIC);
		$realMethods = [];
		foreach ($methods as $method) {
			if (in_array($method->name, $notMethods))
				continue;
			$realMethod = [
				'name'    => $method->getName(),
				'params'  => [],
				'comment' => $method->getDocComment(),
			];
			foreach ($method->getParameters() as $param) {
				$realMethod['params'][] = TµAnsi::color('green', $param->getName()) . TµAnsi::faint('=') . TµAnsi::color('blue', (string)$param->getType());
			}
			$realMethods[] = $realMethod;
		}
		// shows object info
		TµAnsi::setStyle('h4', bold: true, textColor: 'black');
		foreach ($realMethods as $method) {
			if ($_SERVER['argv'] && $method['name'] != $_SERVER['argv'][0])
				continue;
			print(TµAnsi::title4('Action: ' . $method['name']));
			print(' ' . TµAnsi::bold($method['name']) . ' ' . implode(' ', $method['params']) . "\n");
			if ($method['comment']) {
				$comment = preg_replace('/^\/[\*\s]*/', '', $method['comment']);
				$comment = preg_replace('/\n[\*\s]*/', "\n  ", $comment);
				$comment = preg_replace('/[\s\*\/]*$/', '', $comment);
				print(TµAnsi::faint(preg_replace('/\n\s*/', "\n  ", ' ' . $comment)) . "\n");
			}
			print("\n");
		}
	}
}

