<?php

/**
 * S3
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023-2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/datasource-s3
 */

namespace Temma\Datasources;

use \Temma\Base\Log as TµLog;

if (!class_exists('\Aws\S3\S3Client')) {
	require_once('aws.phar');
}

/**
 * Amazon S3 management object.
 *
 * This object is used to read and write data from AWS S3.
 *
 * To use it, you must copy the "aws.phar" file in the "lib" directory:
 * <tt>wget -O lib/aws.phar https://docs.aws.amazon.com/aws-sdk-php/v3/download/aws.phar</tt>
 *
 * <b>Usage</b>
 * <code>
 * // initialization, with private access (default behaviour)
 * $s3 = \Temma\Datasources\S3::factory('s3://ACCESS_KEY:SECRET_KEY@REGION/BUCKET');
 * $s3 = \Temma\Base\Datasource::factory('s3://ACCESS_KEY:SECRET_KEY@REGION/BUCKET');
 * // alternative init
 * $s3 = \Temma\Datasources\S3::factory('s3://ACCESS_KEY:SECRET_KEY@REGION/BUCKET:private');
 * $s3 = \Temma\Base\Datasource::factory('s3://ACCESS_KEY:SECRET_KEY@REGION/BUCKET:private');
 *
 * // initialization, with public-read access for created files by default
 * $s3 = \Temma\Datasources\S3::factory('s3://ACCESS_KEY:SECRET_KEY@REGION/BUCKET:public');
 * $s3 = \Temma\Base\Datasource::factory('s3://ACCESS_KEY:SECRET_KEY@REGION/BUCKET:public');
 *
 * // add data with private access (JSON-encoded, the MIME type is always 'application/json')
 * $s3['path/to/s3/file'] = $data;
 * $s3->set('path/to/s3/file', $data);
 *
 * // add data with public access (JSON-encoded)
 * $s3->set('path/to/s3/file', $data, true);
 * $s3->set('path/to/s3/file', $data, ['public' => true]);
 *
 * // add raw data (written as is), with the access defined in the DSN
 * // and the default MIME type ('application/octet-stream')
 * $s3->write('path/to/s3/file', $rawData);
 *
 * // add raw data with a specific MIME type
 * $s3->write('path/to/s3/file', $rawData, 'application/pdf');
 *
 * // add raw data with public access and a specific MIME type
 * $s3->write('path/to/s3/file', $rawData, [
 *     'public'   => true,
 *     'mimetype' => 'application/pdf',
 * ]);
 *
 * // add a local file, with the access defined in the DSN
 * $s3->copyTo('path/to/s3/file', '/path/to/local/file');
 *
 * // add a local file with a specific MIME type
 * $s3->copyTo('path/to/s3/file', '/path/to/local/file', 'application/pdf');
 *
 * // add a local file with public access
 * $s3->copyTo('path/to/s3/file', '/path/to/local/file', true);
 *
 * // add a local file with public access and a specific MIME type
 * $s3->copyTo('path/to/s3/file', '/path/to/local/file', [
 *     'public'   => true,
 *     'mimetype' => 'application/pdf',
 * ]);
 *
 * // read a file (JSON-decoded data)
 * $data = $s3['path/to/s3/file'];
 * $data = $s3->get('path/to/s3/file');
 *
 * // read a file (raw content)
 * $rawData = $s3->read('path/to/s3/file');
 *
 * // copy a S3 file to a local file
 * $s3->copyFrom('path/to/s3/file', '/path/to/local/file');
 *
 * // get a URL to a S3 file (20 minutes validity)
 * $url = $s3->getUrl('path/to/s3/file');
 *
 * // tell if a file exists
 * if (isset($s3['path/to/s3/file'])) { }
 * if ($s3->isSet('path/to/s3/file')) { }
 *
 * // search a list of files with a given prefix
 * $list = $s3->find('prefix/folder');
 * // same, with the JSON-decoded content of the files
 * $list = $s3->search('prefix/folder', true);
 *
 * // remove file from S3
 * unset($s3['path/to/s3/file']);
 * $s3['path/to/s3/file'] = null;
 * $s3->set('path/to/s3/file', null);
 * $s3->remove('path/to/s3/file');
 *
 * // remove several files
 * $s3->mRemove(['path/to/file1', 'path/to/file2']);
 *
 * // remove all files whose path starts with a prefix
 * $s3->clear('prefix/folder/');
 * </code>
 */
class S3 extends \Temma\Base\Datasource {
	/** Key-value data source. */
	protected bool $_keyValue = true;
	/** AWS S3 client object. */
	private $_s3Client = null;
	/** Access key. */
	private ?string $_accessKey = null;
	/** Private key. */
	private ?string $_privateKey = null;
	/** Region. */
	private ?string $_region = null;
	/** Bucket. */
	private ?string $_bucket = null;
	/** Public access by default. */
	private bool $_publicAccess = false;

	/* ********** CONSTRUCTION ********** */
	/**
	 * Create a new instance of this class.
	 * @param	string	$dsn	Connection string.
	 * @return	\Temma\Datasources\S3	The created instance.
	 * @throws	\Temma\Exceptions\Database	If the DSN is invalid.
	 */
	static public function factory(string $dsn) : \Temma\Datasources\S3 {
		TµLog::log('Temma/Base', 'DEBUG', "\\Temma\\Datasources\\S3 object creation with DSN: '" . self::_maskDsn($dsn) . "'.");
		if (!preg_match("/^([^:]+):\/\/([^:]+):([^@]+)@([^\/]+)\/([^:]+):?(.*)$/", $dsn, $matches)) {
			TµLog::log('Temma/Base', 'WARN', "Invalid S3 DSN '" . self::_maskDsn($dsn) . "'.");
			throw new \Temma\Exceptions\Database("Invalid S3 DSN '" . self::_maskDsn($dsn) . "'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		$type = $matches[1] ?? null;
		$accessKey = $matches[2] ?? '';
		$privateKey = $matches[3] ?? '';
		$region = $matches[4] ?? '';
		$bucket = $matches[5] ?? '';
		$access = $matches[6] ?? 'private';
		if ($type != 's3' ||
		    ($access && $access != 'private' && $access != 'public'))
			throw new \Temma\Exceptions\Database("Invalid S3 DSN '" . self::_maskDsn($dsn) . "'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		$publicAccess = false;
		if ($access == 'public')
			$publicAccess = true;
		return (new self($accessKey, $privateKey, $region, $bucket, $publicAccess));
	}
	/**
	 * Constructor.
	 * @param	string	$accessKey	S3 access key.
	 * @param	string	$privateKey	S3 private key.
	 * @param	string	$region		S3 region.
	 * @param	string	$bucket		S3 bucket.
	 * @param	bool	$publicAccess	(optional) True to set public access by default (false by default).
	 * @throws	\Temma\Exceptions\Database	If a parameter is invalid.
	 */
	public function __construct(string $accessKey, string $privateKey, string $region, string $bucket, bool $publicAccess=false) {
		$this->_accessKey = trim($accessKey);
		$this->_privateKey = trim($privateKey);
		$this->_region = trim($region);
		$this->_bucket = trim($bucket);
		$this->_publicAccess = $publicAccess;
		if (!$this->_accessKey || !$this->_privateKey || !$this->_region || !$this->_bucket)
			throw new \Temma\Exceptions\Database("Bad S3 connection parameters.", \Temma\Exceptions\Database::FUNDAMENTAL);
	}

	/* ********** CONNECTION ********** */
	/**
	 * Creation of the S3 client object.
	 * @throws      \Exception      If an error occured.
	 */
	public function connect() {
		if (!$this->_enabled || $this->_s3Client)
			return;
		$this->reconnect();
	}
	/**
	 * Reconnection.
	 * @throws	\Temma\Exceptions\Database	If the connection failed.
	 */
	public function reconnect() {
		if (!$this->_enabled)
			return;
		$this->disconnect();
		$this->_s3Client = new \Aws\S3\S3Client([
			'version'     => 'latest',
			'region'      => $this->_region,
			'credentials' => [
				'key'    => $this->_accessKey,
				'secret' => $this->_privateKey,
			],
			// no global timeout, because transfers of big files can be long
			'http'        => [
				'connect_timeout' => 10,
			],
		]);
	}
	/** Disconnection. */
	public function disconnect() {
		unset($this->_s3Client);
		$this->_s3Client = null;
	}

	/* ********** ARRAY-LIKE REQUESTS ********** */
	/**
	 * Get the number of files.
	 * @param	?string	$pattern	(optional) Prefix to match. Null to count all files.
	 * @return	int	The number of files.
	 */
	public function count(?string $pattern=null) : int {
		if (!$this->_enabled)
			return (0);
		$this->connect();
		$count = 0;
		try {
			$params = ['Bucket' => $this->_bucket];
			if ($pattern !== null)
				$params['Prefix'] = $pattern;
			$objects = $this->_s3Client->getIterator('ListObjects', $params);
			foreach ($objects as $object)
				$count++;
		} catch (\Exception $e) {
			TµLog::log('Temma/Base', 'INFO', "Unable to list files on AWS S3. Bucket='{$this->_bucket}'.");
		}
		return ($count);
	}

	/* ********** SPECIAL REQUESTS ********** */
	/**
	 * Get a download URL of an S3 file. The URL is valid for 20 minutes.
	 * @param	string	$s3Path	File path on S3.
	 * @return	string	The pre-signed URL. Empty string if the data source is disabled.
	 * @throws	\Exception 	If an error occured.
	 */
	public function getUrl(string $s3Path) : string {
		if (!$this->_enabled)
			return ('');
		$this->connect();
		try {
			$cmd = $this->_s3Client->getCommand('GetObject', [
				'Bucket' => $this->_bucket,
				'Key'    => $s3Path,
			]);
			$request = $this->_s3Client->createPresignedRequest($cmd, '+20 minutes');
			$presignedUrl = (string)$request->getUri();
		} catch (\Exception $e) {
			TµLog::log('Temma/Base', 'INFO', "Unable to create S3 pre-signed URL. Bucket='{$this->_bucket}'. key='$s3Path'.");
			throw $e;
		}
		return ($presignedUrl);
	}

	/* ********** STANDARD REQUESTS ********** */
	/**
	 * Tell if a file exists in S3.
	 * @param	string	$s3Path	Path of the S3 file.
	 * @return	bool	True if the file exists, false otherwise.
	 */
	public function isSet(string $s3Path) : bool {
		if (!$this->_enabled)
			return (false);
		$this->connect();
		return ($this->_s3Client->doesObjectExistV2($this->_bucket, $s3Path));
	}
	/**
	 * Remove a file from S3.
	 * @param	string	$s3Path	Path of the S3 file.
	 */
	public function remove(string $s3Path) : void {
		if (!$this->_enabled)
			return;
		$this->connect();
		try {
			$this->_s3Client->deleteObject([
				'Bucket' => $this->_bucket,
				'Key'    => $s3Path,
			]);
		} catch (\Exception $e) {
			TµLog::log('Temma/Base', 'INFO', "Unable to remove AWS S3 file. Bucket='{$this->_bucket}'. key='$s3Path'.");
			throw $e;
		}
	}
	/**
	 * Multiple remove. The files are removed by batches of 1000 (one DeleteObjects request per batch).
	 * @param	array	$s3Paths	List of path to S3 files.
	 * @throws	\Temma\Exceptions\IO	If some files couldn't be removed (the other files are removed).
	 * @throws	\Exception	If a request failed.
	 */
	public function mRemove(array $s3Paths) : void {
		if (!$this->_enabled || !$s3Paths)
			return;
		$this->connect();
		$errors = [];
		// a DeleteObjects request accepts up to 1000 keys
		foreach (array_chunk(array_values($s3Paths), 1000) as $chunk) {
			$objects = [];
			foreach ($chunk as $path)
				$objects[] = ['Key' => (string)$path];
			try {
				$result = $this->_s3Client->deleteObjects([
					'Bucket' => $this->_bucket,
					'Delete' => [
						'Objects' => $objects,
						'Quiet'   => true,
					],
				]);
			} catch (\Exception $e) {
				TµLog::log('Temma/Base', 'INFO', "Unable to remove AWS S3 files. Bucket='{$this->_bucket}'.");
				throw $e;
			}
			// in quiet mode, only the errors are returned
			foreach (($result['Errors'] ?? []) as $error)
				$errors[] = ($error['Key'] ?? '') . ' (' . ($error['Code'] ?? '') . ')';
		}
		if ($errors) {
			TµLog::log('Temma/Base', 'INFO', "Unable to remove AWS S3 files. Bucket='{$this->_bucket}'. keys='" . implode("', '", $errors) . "'.");
			throw new \Temma\Exceptions\IO('Unable to remove ' . count($errors) . ' AWS S3 file(s): ' . implode(', ', $errors) . '.', \Temma\Exceptions\IO::UNWRITABLE);
		}
	}
	/**
	 * Remove all S3 files matching a given prefix.
	 * @param	string	$prefix	Prefix string. Nothing will be removed if this parameter is empty (use flush() to remove all files).
	 * @throws	\Exception	If an error occured.
	 */
	public function clear(string $prefix) : void {
		if (!$this->_enabled || $prefix === '')
			return;
		$this->connect();
		try {
			$this->_s3Client->deleteMatchingObjects($this->_bucket, $prefix);
		} catch (\Exception $e) {
			TµLog::log('Temma/Base', 'INFO', "Unable to remove AWS S3 files. Bucket='{$this->_bucket}'. prefix='$prefix'.");
			throw $e;
		}
	}
	/**
	 * Remove all S3 files from a bucket.
	 * @throws	\Exception	If an error occured.
	 */
	public function flush() : void {
		if (!$this->_enabled)
			return;
		$this->connect();
		try {
			$objects = $this->_s3Client->getIterator('ListObjects', [
				'Bucket' => $this->_bucket,
			]);
			// the files are removed by batches of 1000
			$keys = [];
			foreach ($objects as $object) {
				$keys[] = $object['Key'];
				if (count($keys) == 1000) {
					$this->mRemove($keys);
					$keys = [];
				}
			}
			$this->mRemove($keys);
		} catch (\Exception $e) {
			TµLog::log('Temma/Base', 'INFO', "Unable to remove files on AWS S3. Bucket='{$this->_bucket}'.");
			throw $e;
		}
	}

	/* ********** RAW REQUESTS ********** */
	/**
	 * Search all S3 files starting with the given prefix.
	 * @param	string			$prefix		Key prefix.
	 * @param	bool			$getValues	(optional) True to fetch the associated values. False by default.
	 * @param	null|bool|string|array	$sort		(optional) Sort criteria:
	 *							- null: natural lexicographic order (default, from S3 ListObjects).
	 *							- true: reverse lexicographic order (all matching keys are fetched first).
	 *							- false: random order (all matching keys are fetched first).
	 *							- string/array: not supported, triggers an exception.
	 * @param	int			$offset		(optional) Number of items to skip. 0 by default.
	 * @param	int			$limit		(optional) Maximum number of items to return. 0 means no limit.
	 * @return	array	List of keys, or associative array of key-value pairs.
	 * @throws	\Exception	If an error occured.
	 * @throws	\Temma\Exceptions\Database	If $sort is a string or an array.
	 */
	public function find(string $prefix, bool $getValues=false, null|bool|string|array $sort=null, int $offset=0, int $limit=0) : array {
		if (!$this->_enabled)
			return ([]);
		if (is_string($sort) || is_array($sort))
			throw new \Temma\Exceptions\Database("S3 datasource does not support field-based sort.", \Temma\Exceptions\Database::FUNDAMENTAL);
		$this->connect();
		$list = [];
		try {
			$objects = $this->_s3Client->getIterator('ListObjects', [
				'Bucket' => $this->_bucket,
				'Prefix' => $prefix,
			]);
			if ($sort === true || $sort === false) {
				$keys = [];
				foreach ($objects as $object)
					$keys[] = $object['Key'];
				if ($sort === false)
					shuffle($keys);
				else
					$keys = array_reverse($keys);
				$keys = array_slice($keys, $offset, $limit > 0 ? $limit : null);
				if ($getValues) {
					foreach ($keys as $key)
						$list[$key] = $this->read($key);
				} else
					$list = $keys;
			} else {
				$skipped = 0;
				$collected = 0;
				foreach ($objects as $object) {
					if ($skipped < $offset) {
						$skipped++;
						continue;
					}
					if ($getValues)
						$list[$object['Key']] = $this->read($object['Key']);
					else
						$list[] = $object['Key'];
					$collected++;
					if ($limit > 0 && $collected >= $limit)
						break;
				}
			}
		} catch (\Exception $e) {
			TµLog::log('Temma/Base', 'INFO', "Unable to list files on AWS S3. Bucket='{$this->_bucket}'.");
			throw $e;
		}
		return ($list);
	}
	/**
	 * Get a file from S3. The raw content of the file is returned (not JSON-decoded).
	 * @param	string	$s3Path			File path on S3.
	 * @param	mixed	$defaultOrCallback	(optional) Default scalar value or function called if the data is not found.
	 *						If scalar: the value is returned.
	 *						If callback: the value returned by the function is stored in the data source, and returned.
	 * @param	mixed	$options		(optional) Options used if the file is created.
	 *						If a string: mime type.
	 *						If a boolean: true for public access, false for private access.
	 *						If an array: 'public' (bool) and/or 'mimetype' (string) keys.
	 * @return	?string	The data fetched from the S3 file, or null.
	 * @throws	\Temma\Exceptions\Database	If the default value or the value returned by the callback is not a string or a number.
	 * @throws	\Exception	If an error occured.
	 */
	public function read(string $s3Path, mixed $defaultOrCallback=null, mixed $options=null) : ?string {
		$this->connect();
		// fetch the file content
		if ($this->_enabled) {
			try {
				$result = $this->_s3Client->getObject([
					'Bucket' => $this->_bucket,
					'Key'    => $s3Path,
				]);
				if (($result['Body'] ?? null))
					return ((string)$result['Body']);
			} catch (\Exception $e) {
			}
		}
		// manage default value
		if (!self::_isCallback($defaultOrCallback))
			return (self::_toRawValue($defaultOrCallback));
		$value = self::_toRawValue($defaultOrCallback());
		if ($value !== null)
			$this->write($s3Path, $value, $options);
		return ($value);
	}
	/**
	 * Get a file from S3 and write it to a local file.
	 * @param	string	$s3Path			File path on S3.
	 * @param	string	$localPath		Local path.
	 * @param	mixed	$defaultOrCallback	(optional) Default scalar value or function called if the data is not found.
	 *						If scalar: the value is written in the local file.
	 *						If callback: the value returned by the function is stored in the data source, and written in the file.
	 * @param	mixed	$options		(optional) Options used if the file is created.
	 *						If a string: mime type.
	 *						If a boolean: true for public access, false for private access.
	 *						If an array: 'public' (bool) and/or 'mimetype' (string) keys.
	 * @return	bool	True if the S3 file exists (or if a default value was given) and has been written locally.
	 * @throws	\Temma\Exceptions\IO	If the destination path is not writable.
	 * @throws	\Temma\Exceptions\Database	If the default value or the value returned by the callback is not a string or a number.
	 * @throws	\Exception		If an error occured.
	 */
	public function copyFrom(string $s3Path, string $localPath, mixed $defaultOrCallback=null, mixed $options=null) : bool {
		// check if the local file is writable
		$this->_prepareFileWrite($localPath);
		$this->connect();
		// fetch the file content
		if ($this->_enabled) {
			try {
				$result = $this->_s3Client->getObject([
					'Bucket' => $this->_bucket,
					'Key'    => $s3Path,
					'SaveAs' => $localPath,
				]);
				return (true);
			} catch (\Exception $e) {
				if (is_file($localPath))
					unlink($localPath);
			}
		}
		// manage default value
		if (!self::_isCallback($defaultOrCallback))
			$value = self::_toRawValue($defaultOrCallback);
		else {
			$value = self::_toRawValue($defaultOrCallback());
			if ($value !== null)
				$this->write($s3Path, $value, $options);
		}
		if ($value === null)
			return (false);
		file_put_contents($localPath, $value);
		return (true);
	}
	/**
	 * Create or update a file in S3 from a stream of data.
	 * @param	string	$s3Path		File path on S3.
	 * @param	string	$data		Data value.
	 * @param	mixed	$options	(optional) If a string: mime type.
	 *					If a boolean: true for public access, false for private access.
	 *					If an array: 'public' (bool) and/or 'mimetype' (string) keys.
	 *					By default, the access defined in the DSN and the 'application/octet-stream'
	 *					MIME type are used.
	 * @return	bool	True, or false if the data source is disabled.
	 * @throws	\Exception	If an error occured.
	 */
	public function write(string $s3Path, string $data, mixed $options=null) : bool {
		if (!$this->_enabled)
			return (false);
		$this->connect();
		// create or update file
		$public = $this->_publicAccess;
		$mimetype = 'application/octet-stream';
		if (is_bool($options))
			$public = $options;
		else if (is_string($options))
			$mimetype = $options;
		else if (is_array($options)) {
			$public = (bool)($options['public'] ?? $public);
			$mimetype = $options['mimetype'] ?? $mimetype;
		}
		try {
			$result = $this->_s3Client->putObject([
				'Bucket'      => $this->_bucket,
				'Key'         => $s3Path,
				'Body'        => $data,
				'ContentType' => $mimetype,
				'ACL'         => $public ? 'public-read' : 'private',
			]);
		} catch (\Exception $e) {
			TµLog::log('Temma/Base', 'INFO', "Can't send data to AWS S3. Bucket='{$this->_bucket}'. key='$s3Path'.");
			throw $e;
		}
		return (true);
	}
	/**
	 * Create or update a file in S3 from a file.
	 * @param	string	$s3Path		File path on S3.
	 * @param	string	$localPath	Path to the local file.
	 * @param	mixed	$options	(optional) If a string: mime type.
	 *					If a boolean: true for public access, false for private access.
	 *					If an array: 'public' (bool) and/or 'mimetype' (string) keys.
	 *					By default, the access defined in the DSN and the 'application/octet-stream'
	 *					MIME type are used.
	 * @return	bool	True, or false if the data source is disabled.
	 * @throws	\Temma\Exceptions\IO	If the local file is not readable.
	 * @throws	\Exception	If an error occured.
	 */
	public function copyTo(string $s3Path, string $localPath, mixed $options=null) : bool {
		if (!$this->_enabled)
			return (false);
		$this->connect();
		// check source file
		if (!is_readable($localPath)) {
			TµLog::log('Temma/Base', 'INFO', "Unable to read file '$localPath'.");
			throw new \Temma\Exceptions\IO("Unable to read file '$localPath'.", \Temma\Exceptions\IO::UNREADABLE);
		}
		// check parameters
		$public = $this->_publicAccess;
		$mimetype = 'application/octet-stream';
		if (is_bool($options))
			$public = $options;
		else if (is_string($options))
			$mimetype = $options;
		else if (is_array($options)) {
			$public = (bool)($options['public'] ?? $public);
			$mimetype = $options['mimetype'] ?? $mimetype;
		}
		try {
			$result = $this->_s3Client->putObject([
				'Bucket'      => $this->_bucket,
				'Key'         => $s3Path,
				'SourceFile'  => $localPath,
				'ContentType' => $mimetype,
				'ACL'         => $public ? 'public-read' : 'private',
			]);
		} catch (\Exception $e) {
			TµLog::log('Temma/Base', 'INFO', "Unable to send file to AWS S3. Bucket='{$this->_bucket}'. key='$s3Path'.");
			throw $e;
		}
		return (true);
	}

	/* ********** KEY-VALUE REQUESTS ********** */
	/**
	 * Read serialized data in a S3 file.
	 * @param	string	$s3Path			File path on S3.
	 * @param	mixed	$defaultOrCallback	(optional) Default scalar value or function called if the data is not found.
	 *						If scalar: the value is returned.
	 *						If callback: the value returned by the function is stored JSON-encoded in the
	 *						data source, and returned.
	 * @param	mixed	$options	(optional) Options used if the file is created (see set() method).
	 * @return	mixed	The fetched data.
	 */
	public function get(string $s3Path, mixed $defaultOrCallback=null, mixed $options=null) : mixed {
		// read the data (the callback is not given to read(), which would store the raw value)
		// if the data source is disabled, read() returns null and nothing is stored by set()
		$data = $this->read($s3Path);
		if ($data !== null)
			return (json_decode($data, true));
		// manage default value
		if (!self::_isCallback($defaultOrCallback))
			return ($defaultOrCallback);
		$value = $defaultOrCallback();
		$this->set($s3Path, $value, $options);
		return ($value);
	}
	/**
	 * Store serialized data in a S3 file. The file's MIME type is always 'application/json'.
	 * @param	string	$s3Path		File path on S3.
	 * @param	mixed	$value		Value of the data. Remove the file if null.
	 * @param	mixed	$options	(optional) If a boolean: true for public access, false for private access.
	 *					If an array: 'public' (bool); the 'mimetype' key is ignored.
	 *					Other values (like a MIME type string) are ignored.
	 *					By default, the access defined in the DSN is used.
	 * @return	bool	True, or false if the data source is disabled.
	 * @throws	\Exception	If an error occured.
	 */
	public function set(string $s3Path, mixed $value=null, mixed $options=null) : bool {
		if (!$this->_enabled)
			return (false);
		if (is_null($value)) {
			$this->remove($s3Path);
			return (true);
		}
		$this->write($s3Path, json_encode($value), $this->_getJsonOptions($options));
		return (true);
	}
	/**
	 * Multiple set. The data are JSON-encoded (MIME type 'application/json'); the files associated to null are removed.
	 * @param	array	$data		Associative array with file names and their associated contents.
	 * @param	mixed	$options	(optional) Options (see set() method).
	 * @return	int	The number of written files (removed files are not counted).
	 */
	public function mSet(array $data, mixed $options=null) : int {
		if (!$this->_enabled)
			return (0);
		// null values are not encoded, so mWrite() removes them
		array_walk($data, function(&$value, $key) {
			if ($value !== null)
				$value = json_encode($value);
		});
		return ($this->mWrite($data, $this->_getJsonOptions($options)));
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Returns the writing options of the JSON files created by set() and mSet().
	 * @param	mixed	$options	Options given to set() or mSet(): a boolean or an array with a 'public' key.
	 * @return	array	Associative array with the 'public' and 'mimetype' keys.
	 */
	private function _getJsonOptions(mixed $options) : array {
		$public = $this->_publicAccess;
		if (is_bool($options))
			$public = $options;
		else if (is_array($options) && isset($options['public']))
			$public = (bool)$options['public'];
		return ([
			'public'   => $public,
			'mimetype' => 'application/json',
		]);
	}
}

