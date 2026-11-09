<?php

/**
 * File
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023-2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/datasource-file
 */

namespace Temma\Datasources;

use \Temma\Base\Log as TµLog;

/**
 * Simple File management object.
 *
 * This object is used to read and write data from files.
 *
 * <b>Usage</b>
 * <code>
 * // initialization, with default umask
 * $file = \Temma\Datasources\File::factory('file:///opt/temma_files');
 * $file = \Temma\Base\Datasource::factory('file:///opt/temma_files');
 * // init with a custom umask
 * $file = \Temma\Datasources\File::factory('file://0007/opt/temma_files');
 * $file = \Temma\Base\Datasource::factory('file://0007/opt/temma_files');
 *
 * // add or update a file (missing directories are created)
 * // the data is JSON-encoded (and JSON-decoded when read with get())
 * $file['path/to/file'] = $data;
 * $file->set('path/to/file', $data);
 *
 * // add or update a file, giving its permissions
 * $file->set('path/to/file', $data, 0600);
 *
 * // add or update a file with raw data (written as is)
 * $file->write('path/to/file', $rawData);
 * $file->write('path/to/file', $rawData, 0600);
 *
 * // copy a local file
 * $file->copyTo('path/to/file', '/path/to/source/file');
 *
 * // copy a local file, giving special permissions
 * $file->copyTo('path/to/file', '/path/to/source/file', 0600);
 *
 * // read a file (JSON-decoded data)
 * $data = $file['path/to/file'];
 * $data = $file->get('path/to/file');
 *
 * // read a file (raw content)
 * $rawData = $file->read('path/to/file');
 *
 * // copy a file to a local file
 * $file->copyFrom('path/to/file', '/path/to/local/file');
 *
 * // tell if a file exists
 * if (isset($file['path/to/file'])) { }
 * if ($file->isSet('path/to/file')) { }
 *
 * // search files matching a glob pattern, in the whole sub-tree
 * $list = $file->search('folder/*');
 *
 * // remove file
 * unset($file['path/to/file']);
 * $file['path/to/file'] = null;
 * $file->set('path/to/file', null);
 * </code>
 */
class File extends \Temma\Base\Datasource {
	/** Root path. */
	protected string $_rootPath;
	/** Default umask. */
	protected int $_umask;

	/* ********** CONSTRUCTION ********** */
	/**
	 * Create a new instance of this class.
	 * @param	string	$dsn	Connection string.
	 * @return	\Temma\Datasources\File	The created instance.
	 * @throws	\Temma\Exceptions\Database	If the DSN is invalid.
	 */
	static public function factory(string $dsn) : \Temma\Datasources\File {
		TµLog::log('Temma/Base', 'DEBUG', "\\Temma\\Datasources\\File object creation with DSN: '$dsn'.");
		// optional umask (4 octal digits, starting with a zero), followed by an absolute path
		if (!preg_match('/^file:\/\/(0[0-7]{3})?(\/.+)$/', $dsn, $matches) ||
		    !($rootPath = rtrim($matches[2], '/'))) {
			TµLog::log('Temma/Base', 'WARN', "Invalid File DSN '$dsn'.");
			throw new \Temma\Exceptions\Database("Invalid File DSN '$dsn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		return (new self($rootPath, $matches[1]));
	}
	/**
	 * Constructor.
	 * @param	string	$rootPath	File root path.
	 * @param	string	$umask		(optional) Default umask.
	 */
	public function __construct(string $rootPath, ?string $umask=null) {
		$this->_rootPath = trim($rootPath);
		if (!$umask)
			$umask = umask(null);
		else
			$umask = base_convert($umask, 8, 10);
		$this->_umask = $umask;
	}

	/* ********** ARRAY-LIKE REQUESTS ********** */
	/**
	 * Return the number of files (sub-directories are searched recursively, see find()).
	 * @param	?string	$pattern	(optional) Glob pattern to match. Null to count all files.
	 * @return 	int	The number of files.
	 */
	public function count(?string $pattern=null) : int {
		return (count($this->find($pattern ?? '*')));
	}

	/* ********** STANDARD REQUESTS ********** */
	/**
	 * Tell if a file exists.
	 * @param	string	$path	Path of the file.
	 * @return	bool	True if the file exists, false otherwise.
	 */
	public function isSet(string $path) : bool {
		if (!$this->_enabled)
			return (false);
		$path = $this->_cleanPath($path);
		$fullPath = $this->_rootPath . '/' . $path;
		return (is_file($fullPath));
	}
	/**
	 * Remove a file. Its parent directories are removed if they become empty.
	 * @param	string	$path	Path of the file.
	 */
	public function remove(string $path) : void {
		if (!$this->_enabled)
			return;
		$path = $this->_cleanPath($path);
		$fullPath = $this->_rootPath . '/' . $path;
		if (!is_file($fullPath) || !unlink($fullPath))
			return;
		// remove the parent directories which became empty (never the root directory)
		$dir = dirname($fullPath);
		while (str_starts_with($dir, $this->_rootPath . '/') && @rmdir($dir))
			$dir = dirname($dir);
	}
	/**
	 * Remove all files matching a given pattern (see find()).
	 * @param	string	$pattern	Glob pattern.
	 */
	public function clear(string $pattern) : void {
		foreach ($this->find($pattern) as $path)
			$this->remove($path);
	}
	/**
	 * Remove all files.
	 */
	public function flush() : void {
		if (!$this->_enabled)
			return;
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->_rootPath, \RecursiveDirectoryIterator::SKIP_DOTS),
		                                        \RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($files as $fileinfo) {
			if ($fileinfo->isDir())
				@rmdir($fileinfo->getRealPath());
			else
				@unlink($fileinfo->getRealPath());
		}
	}

	/* ********** RAW REQUESTS ********** */
	/**
	 * Search all files matching a glob pattern, like a prefix search on S3: sub-directories are searched
	 * recursively, the '*' wildcard also matches the '/' character, and only files are returned
	 * (not directories). Hidden files and directories (whose name starts with a dot) are skipped.
	 * @param	string			$pattern	The glob pattern, relative to the root path (e.g. 'users/*').
	 * @param	bool			$getValues	(optional) True to fetch the associated values. False by default.
	 * @param	null|bool|string|array	$sort		(optional) Sort criteria:
	 *							- null: alphabetical order (default).
	 *							- true: reverse alphabetical order.
	 *							- false: random order.
	 *							- string/array: not supported, triggers an exception.
	 * @param	int			$offset		(optional) Number of items to skip. 0 by default.
	 * @param	int			$limit		(optional) Maximum number of items to return. 0 means no limit.
	 * @return	array	List of file paths relative to the root path, or associative array of path-content pairs.
	 * @throws	\Exception	If an error occured.
	 * @throws	\Temma\Exceptions\Database	If $sort is a string or an array.
	 */
	public function find(string $pattern, bool $getValues=false, null|bool|string|array $sort=null, int $offset=0, int $limit=0) : array {
		if (!$this->_enabled)
			return ([]);
		if (is_string($sort) || is_array($sort))
			throw new \Temma\Exceptions\Database("File datasource does not support field-based sort.", \Temma\Exceptions\Database::FUNDAMENTAL);
		$pattern = $this->_cleanPath($pattern);
		// directory to walk: the fixed part of the pattern, before the first wildcard
		preg_match('/^[^*?\[]*/', $pattern, $matches);
		$slashPos = mb_strrpos($matches[0], '/');
		$dir = $this->_rootPath . (($slashPos === false) ? '' : ('/' . mb_substr($matches[0], 0, $slashPos)));
		if (!is_dir($dir))
			return ([]);
		// files of the sub-tree matching the pattern (hidden files and directories are skipped)
		$iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			fn($file) => !str_starts_with($file->getFilename(), '.')
		));
		$prefixLength = mb_strlen($this->_rootPath) + 1;
		$files = [];
		foreach ($iterator as $file) {
			$path = mb_substr($file->getPathname(), $prefixLength);
			if (fnmatch($pattern, $path))
				$files[] = $path;
		}
		sort($files);
		if ($sort === false)
			shuffle($files);
		else if ($sort === true)
			$files = array_reverse($files);
		if ($offset > 0 || $limit > 0)
			$files = array_slice($files, $offset, $limit ?: null);
		if (!$getValues)
			return ($files);
		return ($this->mRead($files));
	}
	/**
	 * Get a file. The raw content of the file is returned (not JSON-decoded).
	 * @param	string	$path			File path.
	 * @param       mixed   $defaultOrCallback      (optional) Default scalar value or function called if the data is not found.
	 *						If scalar: the value is returned.
	 *						If callback: the value returned by the function is stored in the data source, and returned.
	 * @param	mixed	$options		(optional) Umask used if the file is created.
	 * @return	?string	The data fetched from the file, or null.
	 * @throws	\Exception	If an error occured.
	 */
	public function read(string $path, mixed $defaultOrCallback=null, mixed $options=null) : ?string {
		$path = $this->_cleanPath($path);
		$fullPath = $this->_rootPath . '/' . $path;
		// fetch the file content
		if ($this->_enabled && is_file($fullPath)) {
			$data = file_get_contents($fullPath);
			return ($data);
		}
		// manage default value
		if (!$defaultOrCallback)
			return (null);
		$value = $defaultOrCallback;
		if (is_callable($defaultOrCallback)) {
			$value = $defaultOrCallback();
			$this->write($path, $value, $options);
		}
		return ($value);
	}
	/**
	 * Get a file and write it to a local file.
	 * @param	string	$path			File path.
	 * @param	string	$localPath		Local path.
	 * @param       mixed   $defaultOrCallback      (optional) Default scalar value or function called if the data is not found.
	 *						If scalar: the value is written in the local file.
	 *						If callback: the value returned by the function is stored in the data source, and written in the file.
	 * @param	mixed	$options		(optional) Permission used if the file is created.
	 * @return	bool	True if the file exists and has been written locally.
	 * @throws	\Temma\Exceptions\IO	If the destination path is not writable.
	 * @throws	\Exception		If an error occured.
	 */
	public function copyFrom(string $path, string $localPath, mixed $defaultOrCallback=null, mixed $options=0) : bool {
		// check if the local file is writable
		$this->_prepareFileWrite($localPath);
		// copy the file
		$path = $this->_cleanPath($path);
		$fullPath = $this->_rootPath . '/' . $path;
		if ($this->_enabled && is_file($fullPath) && is_readable($fullPath) && copy($fullPath, $localPath))
			return (true);
		// manage default value
		if (!$defaultOrCallback)
			return (false);
		$value = $defaultOrCallback;
		if (is_callable($defaultOrCallback)) {
			$value = $defaultOrCallback();
			$this->write($path, $value, $options);
		}
		if ($value === null)
			return (false);
		file_put_contents($localPath, $value);
		return (true);
	}
	/**
	 * Create or update a file from a stream of data.
	 * @param	string	$path		File path.
	 * @param	string	$data		Data value.
	 * @param	mixed	$options	(optional) File permissions.
	 * @return	bool	Always true.
	 * @throws	\Temma\Exceptions\IO	If the destination path is not writable.
	 * @throws	\Exception		If an error occurs.
	 */
	public function write(string $path, string $data, mixed $options=0) : bool {
		if (!$this->_enabled)
			return (false);
		$path = $this->_cleanPath($path);
		$fullPath = $this->_rootPath . '/' . $path;
		// manage permissions
		$permissions = null;
		if ($options) {
			if (is_int($options))
				$permissions = $options;
			else if (is_string($options))
				$permissions = base_convert($options, 8, 10);
			if ($permissions === null)
				throw new \Exception("Bad permissions.");
		}
		// create or update file (and its directory if needed), with the data source's umask
		$oldUmask = umask($this->_umask);
		try {
			$this->_prepareFileWrite($fullPath);
			if (file_put_contents($fullPath, $data) === false) {
				TµLog::log('Temma/Base', 'INFO', "Unable to write file '$fullPath'.");
				throw new \Temma\Exceptions\IO("Unable to write file '$fullPath'.", \Temma\Exceptions\IO::UNWRITABLE);
			}
		} finally {
			umask($oldUmask);
		}
		if ($permissions)
			chmod($fullPath, $permissions);
		return (true);
	}
	/**
	 * Create or update a file from a local file.
	 * @param	string	$path		File path.
	 * @param	string	$localPath	Path to the local file.
	 * @param	mixed	$options	(optional) File permissions.
	 * @return	bool	Always true.
	 * @throws	\Temma\Exceptions\IO	If the destination path is not writable.
	 * @throws	\Exception		If an error occured.
	 */
	public function copyTo(string $path, string $localPath, mixed $options=0) : bool {
		if (!$this->_enabled)
			return (false);
		$path = $this->_cleanPath($path);
		$fullPath = $this->_rootPath . '/' . $path;
		$permissions = null;
		if ($options) {
			if (is_int($options))
				$permissions = $options;
			else if (is_string($options))
				$permissions = base_convert($options, 8, 10);
			if ($permissions === null)
				throw new \Exception("Bad permissions.");
		}
		// copy the file (and create its directory if needed), with the data source's umask
		$oldUmask = umask($this->_umask);
		try {
			$this->_prepareFileWrite($fullPath);
			if (!copy($localPath, $fullPath)) {
				TµLog::log('Temma/Base', 'INFO', "Unable to copy file '$localPath' to '$fullPath'.");
				throw new \Temma\Exceptions\IO("Unable to copy file '$localPath' to '$fullPath'.", \Temma\Exceptions\IO::UNWRITABLE);
			}
		} finally {
			umask($oldUmask);
		}
		if ($permissions)
			chmod($fullPath, $permissions);
		return (true);
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Clean a file path.
	 * @param	string	$path	The path to clean.
	 * @return	string	The cleaned path.
	 */
	protected function _cleanPath(string $path) : string {
		$path = str_replace('../', '', $path);
		if (str_ends_with($path, '..'))
			$path = mb_substr($path, 0, -2);
		$path = trim($path, '/');
		return ($path);
	}
}

