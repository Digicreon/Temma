<?php

/**
 * Autoload
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2012-2023, Amaury Bouchard
 */

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
 * // optional: Add include path at init (single path or list of paths)
 * \Temma\Base\Autoload::autoload('/path/to/lib');
 * \Temma\Base\Autoload::autoload(['/path/to/lib1', '/path/to/lib2', '/path/to/lib3']);
 * // optional: Add namespaced include paths at init
 * \Temma\Base\Autoload::autoload([
 *     '\Temma\Base'      => '/path/to/temma/base/directory',
 *     '\Acme\Log\Writer' => './acme-log-writer/lib/',
 * ]);
 *
 * // optional: Add include path after init (single path or list of paths)
 * \Temma\Base\Autoload::addIncludePath('/path/to/lib');
 * \Temma\Base\Autoload::addIncludePath(['/path/to/lib1', '/path/to/lib2', '/path/to/lib3']);
 * // optional: Add namespaced include paths
 * \Temma\Base\Autoload::addIncludePath([
 *     '\Temma\Base'      => '/path/to/temma/base/directory',
 *     '\Acme\Log\Writer' => './acme-log-writer/lib/',
 * ]);
 * </code>
 *
 * @link	https://www.php-fig.org/psr/psr-0/
 * @link	https://www.php-fig.org/psr/psr-4/
 */
class Autoload {
	/** List of known namespaces. */
	static protected array $_namespaces = [];

	/**
	 * Starts the autoloader.
	 * @param	null|string|array	$path	(optional) Include path, or list of include paths, or associative array of namespaces associated to paths.
	 */
	static public function autoload(null|string|array $path=null) : void {
		// autoloader init
		spl_autoload_register([get_called_class(), 'load'], true, true);
		if ($path)
			self::addIncludePath($path);
	}
	/**
	 * Loads a class, an interface or a trait.
	 * @param	string	$name	The namespaced name of the class.
	 */
	static public function load(string $name) : void {
		$name = '\\' . ltrim($name, '\\');
		// check if there is some configured namespaces
		if (self::$_namespaces) {
			$prefix = $name;
			while (($pos = mb_strrpos($prefix, '\\')) !== false) {
				// cut the prefix down
				$prefix = substr($name, 0, $pos);
				// check if this prefix was declared
				$prefixPath = self::$_namespaces[$prefix] ?? null;
				if (!$prefixPath) {
					// trim the last backslash from the prefix and loop again
					$prefix = rtrim($prefix, '\\');
					continue;
				}
				// extract the subname from this part
				$subname = substr($name, $pos + 1);
				// transform the subname into path
				$subname = ltrim($subname, '\\');
				$subname = str_replace('\\', DIRECTORY_SEPARATOR, $subname);
				// try to load the file
				$fullPath = "$prefixPath/$subname.php";
				$realPath = stream_resolve_include_path($fullPath);
				if ($realPath === false)
					continue;
				$included = include($realPath);
				if ($included !== false) {
					// the file is loaded
					return;
				}
			}
			// not found in namespaces
		}
		$path = str_replace('\\', DIRECTORY_SEPARATOR, ltrim($name, '\\')) . '.php';
		$realPath = stream_resolve_include_path($path);
		if (!$realPath)
			return;
		require($realPath);
	}
	/**
	 * Add include path(s).
	 * @param	string|array	$path	Include path, or list of include paths, or associative array of namespaces associated to paths.
	 * @throws	\Exception	If the parameter is not valid.
	 */
	static public function addIncludePath(string|array $path) : void {
		if (is_string($path))
			$path = [$path];
		foreach ($path as $ns => $pathChunk) {
			if (empty(trim($pathChunk)))
				continue;
			if (is_string($ns))
				self::$_namespaces['\\' . trim($ns, '\\')] = $pathChunk;
			else
				set_include_path($pathChunk . PATH_SEPARATOR . get_include_path());
		}
	}
}

