<?php

/**
 * Autoload
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2012-2023, Amaury Bouchard
 */

namespace Temma\Base;

use \Temma\Utils\File as TµFile;

/**
 * Autoloading management object.
 *
 * Usage:
 * ```php
 * // autoloader init
 * require_once('Temma/Base/Autoload.php');
 * \Temma\Base\Autoload::autoload();
 *
 * // use objects without explicitly loading files
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
 * ```
 *
 * @link	https://www.php-fig.org/psr/psr-0/
 * @link	https://www.php-fig.org/psr/psr-4/
 */
class Autoload {
	/** List of known namespaces. */
	static protected array $_namespaces = [];
	/** List of known included paths. */
	static protected array $_includePaths = [];

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
		// normalize the name with a leading backslash
		$name = '\\' . trim($name, '\\');
		// check if there are configured namespaces
		if (self::$_namespaces) {
			$prefix = $name;
			// look for the namespace prefix
			while (($pos = mb_strrpos($prefix, '\\')) !== false) {
				// shorten the prefix
				$prefix = mb_substr($prefix, 0, $pos);
				// check if this prefix was declared
				$prefixPath = self::$_namespaces[$prefix] ?? null;
				if (!$prefixPath)
					continue;
				// extract the relative class name
				$relativeClass = mb_substr($name, $pos + 1);
				$relativeClass = str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass);
				// convert the subname into a path
				$file = $prefixPath . DIRECTORY_SEPARATOR . $relativeClass . '.php';
				// check if the prefix is absolute or relative
				if (TµFile::isAbsolutePath($prefixPath)) {
					// check if the file exists directly
					if (file_exists($file)) {
						require($file);
						return;
					}
				} else {
					// relative prefix, iterate through include paths
					foreach (self::$_includePaths as $includePath) {
						// convert the relative subname into an absolute path
						$path = $includePath . DIRECTORY_SEPARATOR . $file;
						// check if the file exists directly
						if (file_exists($path)) {
							require($path);
							return;
						}
					}
				}
			}
		}
		// fallback: look in the global include path (PSR-0 style or simple include)
		$path = str_replace('\\', DIRECTORY_SEPARATOR, ltrim($name, '\\')) . '.php';
		// check if the file exists in a defined include path
		foreach (self::$_includePaths as $includePath) {
			$file = $includePath . DIRECTORY_SEPARATOR . $path;
			if (file_exists($file)) {
				require($file);
				return;
			}
		}
		// fallback: this handles system-wide libraries (e.g. /usr/share/php)
		$realPath = stream_resolve_include_path($path);
		if ($realPath)
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
			$pathChunk = trim($pathChunk);
			if (empty($pathChunk))
				continue;
			// check if it is a namespace mapping
			if (is_string($ns)) {
				// ensure the path has no trailing slash (standardization)
				self::$_namespaces['\\' . trim($ns, '\\')] = rtrim($pathChunk, '/\\');
			} else {
				// it's a global include path
				self::$_includePaths[] = rtrim($pathChunk, '/\\');
			}
		}
	}
}

