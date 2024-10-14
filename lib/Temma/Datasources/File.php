<?php

/**
 * File
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 * @link	https://www.temma.net/en/documentation/datasource-file
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
 * // add or update a file
 * // if the data is a string, it is written as is
 * // otherwise it is JSON-encoded
 * $file['path/to/file'] = $data;
 * $file->set('path/to/file', $data);
 *
 * // add or update a file, giving its permissions
 * $file->set('path/to/file', $data, 0600);
 *
 * // copy a file
 * $file->put('path/to/file', '/path/to/source/file');
 *
 * // copy a file, giving special permissions
 * $file->put('path/to/file', '/path/to/source/file', 0600);
 *
 * // read a file
 * $data = $file['path/to/file'];
 * $data = $file->get('path/to/file');
 *
 * // tell if a file exists
 * if (isset($file['path/to/file')) { }
 * if ($file->isSet('path/to/file')) { }
 *
 * // search a list of files with a given prefix
 * $list = $file->search('prefix/folder');
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
		if (!preg_match("/^([^:]+):\/\/([^\/]+)\/(.*)$/", $dsn, $matches)) {
			TµLog::log('Temma/Base', 'WARN', "Invalid File DSN '$dsn'.");
			throw new \Temma\Exceptions\Database("Invalid File DSN '$dsn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		$type = $matches[1] ?? null;
		$umask = $matches[2] ?? '';
		$rootPath = $matches[3] ?? '';
		if ($type != 'file' || !$rootPath || ($umask && mb_strlen($umask) != 4))
			throw new \Temma\Exceptions\Database("Invalid File DSN '$dsn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		$rootPath = rtrim($rootPath, '/');
		return (new self($rootPath, $umask));
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
	 * Return the number of files.
	 * @return 	int	The number of files.
	 */
	public function count() : int {
		if (!$this->_enabled)
			return (0);
		$fi = new \FilesystemIterator($this->_rootPath);
		return (iterator_count($fi));
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
		return (file_exists($fullPath));
	}
	/**
	 * Remove a file.
	 * @param	string	$path	Path of the file.
	 */
	public function remove(string $path) : void {
		if (!$this->_enabled)
			return;
		$path = $this->_cleanPath($path);
		@unlink($this->_rootPath . '/' . $path);
	}
	/**
	 * Remove all files matching a given pattern.
	 * @param	string	$pattern	Pattern string.
	 */
	public function clear(string $pattern) : void {
		$files = $this->find($pattern);
		if ($files)
			array_map('unlink', $files);
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
	 * Search all files matching a pattern.
	 * @param	string	$pattern	The pattern.
	 * @param	bool	$getValues	(optional) True to fetch the associated values. False by default.
	 * @return	array	List of keys, or associative array of key-value pairs.
	 * @throws	\Exception	If an error occured.
	 */
	public function find(string $pattern, bool $getValues=false) : array {
		if (!$this->_enabled)
			return ([]);
		$pattern = $this->_cleanPath($pattern);
		$files = glob($this->_rootPath . '/' . $pattern);
		if (!$getValues)
			return ($files);
		$files = $this->mRead($files);
		return ($files);
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
		if ($this->_enabled && file_exists($fullPath)) {
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
		$dirname = dirname($localPath);
		if (($dirname && !file_exists($dirname) && !mkdir($dirname, 0777, true)) ||
		    !is_writeable($localPath)) {
			TµLog::log('Temma/Base', 'INFO', "Unable to write file '$localPath'.");
			throw new \Temma\Exceptions\IO("Unable to write file '$localPath'.", \Temma\Exceptions\IO::UNWRITABLE);
		}
		// copy the file
		$path = $this->_cleanPath($path);
		$fullPath = $this->_rootPath . '/' . $path;
		if ($this->_enabled && is_readable($fullPath) && copy($fullPath, $localPath))
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
		// check if the destination file is writeable
		$path = $this->_cleanPath($path);
		$fullPath = $this->_rootPath . '/' . $path;
		if (!is_writeable($fullPath)) {
			TµLog::log('Temma/Base', 'INFO', "Unable to write file '$fullPath'.");
			throw new \Temma\Exceptions\IO("Unable to write file '$fullPath'.", \Temma\Exceptions\IO::UNWRITABLE);
		}
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
		// create or update file
		if (file_put_contents($fullPath, $data) === false) {
			TµLog::log('Temma/Base', 'INFO', "Unable to write file '$fullPath'.");
			throw new \Temma\Exceptions\IO("Unable to write file '$fullPath'.", \Temma\Exceptions\IO::UNWRITABLE);
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
		if (!copy($localPath, $fullPath)) {
			TµLog::log('Temma/Base', 'INFO', "Unable to copy file '$localPath' to '$fullPath'.");
			throw new \Temma\Exceptions\IO("Unable to copy file '$localPath' to '$fullPath'.", \Temma\Exceptions\IO::UNWRITABLE);
		}
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

