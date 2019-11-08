<?php

namespace Temma\Base;

/**
 * Autoloading management object.
 *
 * Usage:
 * <code>
 * // autoloader init
 * require_once('Temma/Base/Autoload.php');
 * \Temma\Base\Autoload::autoload();
 * 
 * // use objects without explicitly loading file
 * // this object must be defined in the 'NS1/NS2/Object1.php' file, inside a directory of the included paths.
 * $instance1 = new \NS1\NS2\Object1();
 * // this object must be defined in the 'NS1/NS3/Object2.php' file, inside a directory of the included paths.
 * $instance2 = new \NS1\NS3\Object2();
 *
 * // optional: Add include path
 * \Temma\Base\Autoload::addIncludePath('/path/to/lib');
 * </code>
 *
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2012-1029, Amaury Bouchard
 * @link	https://www.php-fig.org/psr/psr-0/
 * @link	https://www.php-fig.org/psr/psr-4/
 * @package	Temma
 * @subpackage	Base
 */
class Autoload {
	/**
	 * Start the autoloader.
	 * @param	string|array	$path	(optional) Include path, or list of include paths.
	 */
	static public function autoload(/* mixed */ $path=null) : void {
		// autoloader init
		spl_autoload_register(function($name) {
			// transform namespace into path
			$name = trim($name, '\\');
			$name = str_replace('\\', DIRECTORY_SEPARATOR, $name);
			//$name = str_replace('_', DIRECTORY_SEPARATOR, $name);
			// deactivate warnings, to manage unfindable objects
			$errorReporting = error_reporting();
			error_reporting($errorReporting & ~E_WARNING);
			$included = include("$name.php");
			if ($included === false) {
				trigger_error("Temma Autoload: Unable to load file '$name.php'.", E_USER_WARNING);
			}
			// reset to the previous error log level
			error_reporting($errorReporting);
		}, true, true);
		if ($path)
			self::addIncludePath($path);
	}
	/**
	 * Add include path(s).
	 * @param	string|array	$path	Include path, or liste of include paths.
	 * @throws	\Exception	If the parameter is not valid.
	 */
	static public function addIncludePath(/* mixed */ $path) : void {
		if (is_string($path))
			$path = [$path];
		else if (!is_array($path))
			throw new \Exception("Invalid include path parameter.");
		$libPath = implode(PATH_SEPARATOR, $path);
		if (!empty($libPath))
			set_include_path($libPath . PATH_SEPARATOR . get_include_path());
	}
}

