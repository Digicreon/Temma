<?php

/**
 * S3
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 */

namespace Temma\Base\Datasources;

use \Temma\Base\Log as TµLog;

require_once('aws.phar');

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
 * $s3 = \Temma\Base\Datasources\S3::factory('s3://ACCESS_KEY:SECRET_KEY@REGION/BUCKET');
 * $s3 = \Temma\Base\Datasource::factory('s3://ACCESS_KEY:SECRET_KEY@REGION/BUCKET');
 * // alternative init
 * $s3 = \Temma\Base\Datasources\S3::factory('s3://ACCESS_KEY:SECRET_KEY@REGION/BUCKET:private');
 * $s3 = \Temma\Base\Datasource::factory('s3://ACCESS_KEY:SECRET_KEY@REGION/BUCKET:private');
 *
 * // initialization, with public-read access for created files by default
 * $s3 = \Temma\Base\Datasources\S3::factory('s3://ACCESS_KEY:SECRET_KEY@REGION/BUCKET:public');
 * $s3 = \Temma\Base\Datasource::factory('s3://ACCESS_KEY:SECRET_KEY@REGION/BUCKET:public');
 *
 * // add data with private access
 * $s3['path/to/s3/file'] = $data;
 * $s3->set('path/to/s3/file', $data);
 *
 * // add data with private access and a specific MIME type
 * $s3->set('path/to/s3/file', $data, 'application/pdf');
 *
 * // add data with public access
 * $s3->set('path/to/s3/file', $data, true);
 *
 * // add data with public access and a specific MIME type
 * $s3->set('path/to/s3/file', $data, [
 *     'public'   => true,
 *     'mimetype' => 'application/pdf',
 * ]);
 *
 * // add a file with private access
 * $s3->put('path/to/s3/file', '/path/to/local/file');
 *
 * // add a file with private access and a specific MIME type
 * $s3->put('path/to/s3/file', '/path/to/local/file', 'application/pdf');
 *
 * // add a file with public access
 * $s3->put('path/to/s3/file', '/path/to/local/file', true);
 *
 * // add a file with public access and a specific MIME type
 * $s3->put('path/to/s3/file', '/path/to/local/file', $data, [
 *     'public'   => true,
 *     'mimetype' => 'application/pdf',
 * ]);
 *
 * // read a file
 * $data = $s3['path/to/s3/file'];
 * $data = $s3->get('path/to/s3/file');
 *
 * // get a URL to a S3 file (20 minutes validity)
 * $url = $s3->getUrl('path/to/s3/file');
 *
 * // tell if a file exists
 * if (isset($s3['path/to/s3/file')) { }
 * if ($s3->isSet('path/to/s3/file')) { }
 *
 * // search a list of files with a given prefix
 * $list = $s3->search('prefix/folder');
 *
 * // remove file from S3
 * unset($s3['path/to/s3/file']);
 * $s3['path/to/s3/file'] = null;
 * $s3->set('path/to/s3/file', null);
 * </code>
 */
class S3 extends \Temma\Base\Datasource {
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
	 * @return	\Temma\Base\Datasources\S3	The created instance.
	 * @throws	\Temma\Exceptions\Database	If the DNS is invalid.
	 */
	static public function factory(string $dsn) : \Temma\Base\Datasources\S3 {
		TµLog::log('Temma/Base', 'DEBUG', "\Temma\Base\Datasources\S3 object creation with DSN: '$dsn'.");
		if (!preg_match("/^([^:]+):\/\/([^:]+):([^@]+)@([^\/]+)\/([^:]+):?(.*)$/", $dsn, $matches)) {
			TµLog::log('Temma/Base', 'WARN', "Invalid S3 DSN '$dsn'.");
			throw new \Temma\Exceptions\Database("Invalid S3 DSN '$dsn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		$type = $matches[1] ?? null;
		$accessKey = $matches[2] ?? '';
		$privateKey = $matches[3] ?? '';
		$region = $matches[4] ?? '';
		$bucket = $matches[5] ?? '';
		$access = $matches[6] ?? 'private';
		if ($type != 's3' ||
		    ($access && $access != 'private' && $access != 'public'))
			throw new \Temma\Exceptions\Database("Invalid S3 DSN '$dsn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
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
	private function __construct(string $accessKey, string $privateKey, string $region, string $bucket, bool $publicAccess=false) {
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
	private function _connect() {
		if (!$this->_enabled || $this->_s3Client)
			return;
		$this->_s3Client = new \Aws\S3\S3Client([
			'version'     => 'latest',
			'region'      => $this->_region,
			'credentials' => [
				'key'    => $this->_accessKey,
				'secret' => $this->_privateKey,
			],
		]);
	}

	/* ********** SPECIAL REQUESTS ********** */
	/**
	 * Get a download URL of an S3 file.
	 * @param	string	$s3Path	File path on S3.
	 * @return	string	The pre-signed URL.
	 * @throws	\Exception 	If an error occured.
	 */
	public function getUrl(string $s3Path) : string {
		if (!$this->_enabled)
			return ('');
		$this->_connect();
		try {
			$cmd = $this->_s3Client->getCommand('GetObject', [
				'Bucket' => $this->_bucket,
				'Key'    => $s3Path,
			]);
			$request = $this->_s3Client->createPresignedRequest($cmd, '+20 minutes');
			$presignedUrl = (string)$request->getUri();
		} catch (\Exception $e) {
			TµLog::log('Temma/Base', 'INFO', "Unable to create S3 pre-signed URL. Bucket='{$this->_bucket}'. key='$s3Path'.");
			$presignedUrl = null;
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
		$this->_connect();
		return ($this->_s3Client->doesObjectExistV2($this->_bucket, $s3Path));
	}
	/**
	 * Remove a file from S3.
	 * @param	string	$s3Path	Path of the S3 file.
	 * @return	\Temma\Base\Datasources\S3	The current object.
	 */
	public function remove(string $s3Path) : \Temma\Base\Datasources\S3 {
		if (!$this->_enabled)
			return ($this);
		$this->_connect();
		try {
			$this->_s3Client->deleteObject([
				'Bucket' => $this->_bucket,
				'Key'    => $s3Path,
			]);
		} catch (\Exception $e) {
			TµLog::log('Temma/Base', 'INFO', "Unable to remove AWS S3 file. Bucket='{$this->_bucket}'. key='$s3Path'.");
			throw $e;
		}
		return ($this);
	}
	/**
	 * Multiple remove.
	 * @param	array	$s3Paths	List of path to S3 files.
	 * @return	\Temma\Base\Datasources\S3	The current object.
	 */
	public function mRemove(array $s3Paths) : \Temma\Base\Datasources\S3 {
		if (!$this->_enabled)
			return ($this);
		$this->_connect();
		$objects = [];
		foreach ($s3Paths as $path) {
			$objects[] = [
				'Key' => $path,
			];
		}
		try {
			$this->_s3Client->deleteObject([
				'Bucket' => $this->_bucket,
				'Delete' => [
					'Objects' => $objects,
				],
			]);
		} catch (\Exception $e) {
			TµLog::log('Temma/Base', 'INFO', "Unable to remove AWS S3 files. Bucket='{$this->_bucket}'.");
			throw $e;
		}
		return ($this);
	}
	/**
	 * Remove all S3 files matching a given prefix.
	 * @param	string	$prefix	Prefix string. Nothing will be removed if this parameter is empty.
	 * @return	\Temma\Base\Datasources\S3	The current object.
	 */
	public function clear(string $prefix) : \Temma\Base\Datasources\S3 {
		if (!$this->_enabled)
			return ($this);
		$this->_connect();
		try {
			$this->_s3Client->deleteMatchingObjects($this->_bucket, $prefix);
		} catch (\Exception $e) {
			TµLog::log('Temma/Base', 'INFO', "Unable to remove AWS S3 files. Bucket='{$this->_bucket}'. prefix='$prefix'.");
			throw $e;
		}
		return ($this);
	}
	/**
	 * Remove all S3 files from a bucket.
	 * @return	\Temma\Base\Datasources\S3	The current object.
	 */
	public function flush() : \Temma\Base\Datasources\S3 {
		if (!$this->_enabled)
			return ($this);
		$this->_connect();
		try {
			$objects = $this->_s3Client->getIterator('ListObjects', [
				'Bucket' => $this->_bucket,
			]);
			foreach ($objects as $object) {
				$this->remove($object['Key']);
			}
		} catch (\Exception $e) {
			TµLog::log('Temma/Base', 'INFO', "Unable to list files on AWS S3. Bucket='{$this->_bucket}'.");
			throw $e;
		}
		return ($this);
	}

	/* ********** RAW REQUESTS ********** */
	/**
	 * Search all S3 files starting with the given prefix.
	 * @param	string	$prefix		Key prefix.
	 * @param	bool	$getValues	(optional) True to fetch the associated values. False by default.
	 * @return	array	List of keys, or associative array of key-value pairs.
	 * @throws	\Exception	If an error occured.
	 */
	public function find(string $prefix, bool $getValues=false) : array {
		if (!$this->_enabled)
			return ([]);
		$this->_connect();
		$list = [];
		try {
			$objects = $this->_s3Client->getIterator('ListObjects', [
				'Bucket' => $this->_bucket,
				'Prefix' => $prefix,
			]);
			foreach ($objects as $object) {
				if ($getValues)
					$list[$object['Key']] = $this->read($object['Key']);
				else
					$list[] = $object['Key'];
			}
		} catch (\Exception $e) {
			TµLog::log('Temma/Base', 'INFO', "Unable to list files on AWS S3. Bucket='{$this->_bucket}'.");
			throw $e;
		}
		return ($list);
	}
	/**
	 * Get a file from S3.
	 * @param	string	$s3Path			File path on S3.
	 * @param       mixed   $defaultOrCallback      (optional) Default scalar value or function called if the data is not found.
	 *						If scalar: the value is returned.
	 *						If callback: the value returned by the function is stored in the data source, and returned.
	 * @param	mixed	$options		(optional) Options used if the file is created.
	 *						If a string: mime type.
	 *						If a boolean: true for public access, false for private access.
	 *						If an array: 'public' (bool) and/or 'mimetype' (string) keys.
	 * @return	?string	The data fetched from the S3 file, or null.
	 * @throws	\Exception	If an error occured.
	 */
	public function read(string $s3Path, mixed $defaultOrCallback=null, mixed $options=null) : ?string {
		$this->_connect();
		// fetch the file content
		if ($this->_enabled) {
			try {
				$result = $this->_s3Client->getObject([
					'Bucket' => $this->_bucket,
					'Key'    => $s3Path,
				]);
				if (($result['Body'] ?? null))
					return ($result['Body']);
			} catch (\Exception $e) {
			}
		}
		// manage default value
		if (!$defaultOrCallback)
			return (null);
		$value = $defaultOrCallback;
		if (is_callable($defaultOrCallback)) {
			$value = $defaultOrCallback();
			$this->write($s3Path, $value, $options);
		}
		return ($value);
	}
	/**
	 * Get a file from S3 and write it to a local file.
	 * @param	string	$s3Path			File path on S3.
	 * @param	string	$localPath		Local path.
	 * @param       mixed   $defaultOrCallback      (optional) Default scalar value or function called if the data is not found.
	 *						If scalar: the value is written in the local file.
	 *						If callback: the value returned by the function is stored in the data source, and written in the file.
	 * @param	mixed	$options		(optional) Options used if the file is created.
	 *						If a string: mime type.
	 *						If a boolean: true for public access, false for private access.
	 *						If an array: 'public' (bool) and/or 'mimetype' (string) keys.
	 * @return	bool	True if the S3 file exists and has been written locally.
	 * @throws	\Temma\Exceptions\IO	If the destination path is not writable.
	 * @throws	\Exception		If an error occured.
	 */
	public function copyFrom(string $s3Path, string $localPath, mixed $defaultOrCallback=null, mixed $options=null) : bool {
		// check if the local file is writable
		$dirname = dirname($localPath);
		if (($dirname && !file_exists($dirname) && !mkdir($dirname, 0777, true)) ||
		    !is_writeable($localPath)) {
			TµLog::log('Temma/Base', 'INFO', "Unable to write file '$localPath'.");
			throw new \Temma\Exceptions\IO("Unable to write file '$localPath'.", \Temma\Exceptions\IO::UNWRITABLE);
		}
		$this->_connect();
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
				unlink($localPath);
			}
		}
		// manage default value
		if (!$defaultOrCallback)
			return (false);
		$value = $defaultOrCallback;
		if (is_callable($defaultOrCallback)) {
			$value = $defaultOrCallback();
			$this->write($s3Path, $value, $options);
		}
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
	 * @return	\Temma\Base\Datasources\S3	The current object.
	 * @throws	\Exception	If an error occured.
	 */
	public function write(string $s3Path, string $data, mixed $options=null) : \Temma\Base\Datasources\S3 {
		if (!$this->_enabled)
			return ($this);
		$this->_connect();
		// create or update file
		$public = $this->_publicAccess;
		$mimetype = 'application/octet-stream';
		if (is_bool($options))
			$public = $options;
		else if (is_string($options))
			$mime = $options;
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
		return ($this);
	}
	/**
	 * Create or update a file in S3 from a file.
	 * @param	string	$s3Path		File path on S3.
	 * @param	string	$localPath	Path to the local file.
	 * @param	mixed	$options	(optional) If a string: mime type.
	 *					If a boolean: true for public access, false for private access.
	 *					If an array: 'public' (bool) and/or 'mimetype' (string) keys.
	 * @return	\Temma\Base\Datasources\S3	The current object.
	 * @throws	\Temma\Exceptions\IO		If the destination file is not writeable.
	 * @throws	\Exception	If an error occured.
	 */
	public function copyTo(string $s3Path, string $localPath, mixed $options=null) : \Temma\Base\Datasources\S3 {
		if (!$this->_enabled)
			return ($this);
		$this->_connect();
		// check destination file
		if (!is_writeable($localPath)) {
			TµLog::log('Temma/Base', 'INFO', "Unable to write in file '$localPath'.");
			throw new \Temma\Exceptions\IO("Unable to write in file '$localPath'.", \Temma\Exceptions\IO::UNWRITABLE);
		}
		// check parameters
		$public = $this->_publicAccess;
		$mimetype = 'application/octet-stream';
		if (is_bool($options))
			$public = $options;
		else if (is_string($options))
			$mime = $options;
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
		return ($this);
	}

	/* ********** KEY-VALUE REQUESTS ********** */
	/**
	 * Read serialized data in a S3 file.
	 * @param	string	$s3Path			File path on S3.
	 * @param	mixed	$defaultOrCallback	(optional) Default scalar value or function called if the data is not found.
	 *						If scalar: the value is returned.
	 *						If callback: the value returned by the function is stored in the data source, and returned.
	 * @param	mixed	$options	(optional) If a string: mime type.
	 *					If a boolean: true for public access, false for private access.
	 *					If an array: 'public' (bool).
	 * @return	mixed	The fetched data.
	 */
	public function get(string $key, mixed $defaultOrCallback=null, mixed $options=null) : mixed {
		if (!$this->_enabled)
			return (null);
		// manage options
		$public = false;
		if ($options === true || ($options['public'] ?? null) === true)
			$public = true;
		$options = [
			'public'   => $public,
			'mimetype' => 'application/json',
		];
		// read the data
		$data = $this->read($key, $defaultOrCallback, $options);
		if (!$data)
			return ($data);
		return (json_decode($data, true));
	}
	/**
	 * Store serialized data in a S3 file.
	 * @param	string	$s3Path		File path on S3.
	 * @param	mixed	$value		Value of the data. Remove the file if null.
	 * @param	mixed	$options	(optional) If a string: mime type.
	 *					If a boolean: true for public access, false for private access.
	 *					If an array: 'public' (bool).
	 * @return	\Temma\Base\Datasources\S3	The current object.
	 * @throws	\Exception	If an error occured.
	 */
	public function set(string $s3Path, mixed $value, mixed $options) : \Temma\Base\Datasources\S3 {
		if (!$this->_enabled)
			return ($this);
		if (is_null($value)) {
			$this->remove($s3Path);
			return ($this);
		}
		$public = false;
		if ($options === true || ($options['public'] ?? null) === true)
			$public = true;
		$options = [
			'public'   => $public,
			'mimetype' => 'application/json',
		];
		$this->write($s3Path, json_encode($value), $options);
		return ($this);
	}
	/**
	 * Multiple set.
	 * @param	array	$data		Associative array with file names and their associated contents.
	 * @param	mixed	$options	(optional) Options (see set() method).
	 * @return	int	The number of written files.
	 */
	public function mSet(array $data, mixed $options=null) : int {
		if (!$this->_enabled)
			return (0);
		$public = false;
		if ($options === true || ($options['public'] ?? null) === true)
			$public = true;
		$options = [
			'public'   => $public,
			'mimetype' => 'application/json',
		];
		array_walk($data, function(&$value, $key) {
			$value = json_encode($value);
		});
		return ($this->mWrite($data, $options));
	}
}

