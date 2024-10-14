<?php

/**
 * Serializer
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 * @link	https://www.temma.net/en/documentation/helper-serializer
 */

namespace Temma\Utils;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Application as TµApplicationException;
use \Temma\Exceptions\IO as TµIOException;

/**
 * Helper object used to serialize and unserialize data.
 *
 * This object is able to manage configurations stored in PHP, JSON, YAML, NEON, INI or XML formats.
 *
 * Usage
 *
 * ```php
 * use \Temma\Utils\Serializer as TµSerializer;
 *
 * // unserialize data from JSON stream
 * $data = TµSerializer::decode($json, TµSerializer::JSON);
 * $data = TµSerializer::decodeJson($json);
 *
 * // unserialize data from stream, trying to infere the format
 * $data = TµSerialize::decode($stream);
 * // unserialize data from stream, giving format priorities list
 * $data = TµSerialize::decode($stream, [TµSerialize::JSON, TµSerialize::INI, TµSerialize::XML]);
 *
 * // serialize a data to JSON format
 * $json = TµSerializer::encode($data, TµSerializer::JSON);
 * $json = TµSerializer::encodeJson($data);
 *
 * // read a file and unserialize its content, infering the format from the file name's extension
 * $data = TµSerializer::read('/path/to/file.json');
 *
 * // unserialize data from JSON file, giving the file type
 * $data = TµSerializer::read('/path/fo/file', TµSerializer::JSON);
 * $data = TµSerializer::readJson('/path/to/file');
 *
 * // unserialize data from a file, searching the file from a prefix
 * $data = TµSerializer::readFromPrefix('/path/to/file');
 * // the same, giving type priorities
 * $data = TµSerializer::readFromPrefix('/path/to/file', [TµSerializer::JSON, TµSerializer::INI, TµSerializer::XML]);
 *
 * // serialize into a file, infering the format from the file name's extension
 * TµSerializer::write('/path/to/file.json', $data);
 *
 * // serialize a data to JSON format, written into a file
 * TµSerializer::write('/path/to/file', $data, TµSerializer::JSON);
 * Tµserializer::writeJson('/path/to/file', $data);
 * ```
 *
 * @see	https://www.php.net/manual/en/function.include.php
 * @see	https://www.php.net/manual/en/function.json-decode.php
 * @see	https://www.php.net/manual/en/book.yaml.php
 * @see	https://doc.nette.org/en/neon
 * @see	https://www.php.net/manual/fr/function.parse-ini-file.php
 * @see	https://www.php.net/manual/en/book.simplexml.php
 */
class Serializer {
	/** Constant: extension for PHP files. */
	const PHP = 'php';
	/** Constant: extension for JSON files. */
	const JSON = 'json';
	/** Constant: extension for INI files. */
	const INI = 'ini';
	/** Constant: extension for YAML files. */
	const YAML = 'yaml';
	/** Constant: extension for NEON files. */
	const NEON = 'neon';
	/** Constant: extension for XML files. */
	const XML = 'xml';

	/* ********** SERIALIZATION TO FILE ********** */
	/**
	 * Serialize data and write into a file.
	 * @param	string	$path	Path to the file.
	 * @param	mixed	$data	The data to encode.
	 * @param	?string	$type	(optional) Serialization type. By default, try to infere from the file name's extension.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 * @throws	\Temma\Exceptions\Application	If a required dependency is not satisfied.
	 */
	static public function write(string $path, mixed $data, ?string $type=null) : void {
		if (!$type) {
			if (($pos = mb_strrpos($path, '.')) === false)
				throw new TµIOException("Unable to find extension of file '$path'.", TµIOException::BAD_FORMAT);
			$type = mb_strtolower(mb_substr($path, $pos + 1));
		}
		$stream = self::encode($data, $type);
		if (file_put_contents($path, $stream) === false)
			throw new TµIOException("Unable to write file '$path'.", TµIOException::UNWRITABLE);
	}
	/**
	 * Serialize data in a PHP file.
	 * @param	string	$path	Path to the file.
	 * @param	mixed	$data	The data to encode.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 */
	static public function writePhp(string $path, mixed $data) : void {
		$stream = self::encodePhp($data);
		$stream = "<?php\nreturn $stream;";
		if (file_put_contents($path, $stream) === false)
			throw new TµIOException("Unable to write PHP file '$path'.", TµIOException::UNWRITABLE);
	}
	/**
	 * Serialize data in an JSON file.
	 * @param	string	$path	Path to the file.
	 * @param	mixed	$data	The data to encode.
	 * @param	bool	$prettyPrint	(optional) Set to false for inline output. Defaults to true.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 */
	static public function writeJson(string $path, mixed $data, bool $prettyPrint=true) : void {
		$stream = self::encodeJson($data, $prettyPrint);
		if (file_put_contents($path, $stream) === false)
			throw new TµIOException("Unable to write JSON file '$path'.", TµIOException::UNWRITABLE);
	}
	/**
	 * Serialize data in an INI file.
	 * @param	string	$path	Path to the file.
	 * @param	mixed	$data	The data to encode.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 */
	static public function writeIni(string $path, mixed $data) : void {
		$stream = self::encodeIni($data);
		if (file_put_contents($path, $stream) === false)
			throw new TµIOException("Unable to write INI file '$path'.", TµIOException::UNWRITABLE);
	}
	/**
	 * Serialize data in a YAML file.
	 * @param	string	$path	Path to the file.
	 * @param	mixed	$data	The data to encode.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 * @throws	\Temma\Exceptions\Application	If a required dependency is not satisfied.
	 */
	static public function writeYaml(string $path, mixed $data) : void {
		if (!extension_loaded('yaml'))
			throw new TµApplicationException("Unable to encode YAML stream. The YAML extension is not loaded.", TµApplicationException::DEPENDENCY);
		if (yaml_emit_file($path, $data, YAML_UTF8_ENCODING, YAML_CR_BREAK) !== true)
			throw new TµIOException("Unable to write YAML file '$path'.", TµIOException::UNWRITABLE);
	}
	/**
	 * Serialize data in a NEON file.
	 * @param	string	$path	Path to the file.
	 * @param	mixed	$data	The data to encode.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 * @throws	\Temma\Exceptions\Application	If a required dependency is not satisfied.
	 */
	static public function writeNeon(string $path, mixed $data) : void {
		$stream = self::encodeNeon($data);
		if (file_put_contents($path, $stream) === false)
			throw new TµIOException("Unable to write NEON file '$path'.", TµIOException::UNWRITABLE);
	}
	/**
	 * Serialize data in an XML file.
	 * @param	string	$path	Path to the file.
	 * @param	mixed	$data	The data to encode.
	 * @param	bool	$prettyPrint	(optional) Set to false for inline output. Defaults to true.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 */
	static public function writeXml(string $path, mixed $data, bool $prettyPrint=true) : void {
		$stream = self::encodeXml($data, $prettyPrint);
		if (file_put_contents($path, $stream) === false)
			throw new TµIOException("Unable to write NEON file '$path'.", TµIOException::UNWRITABLE);
	}

	/* ********** SERIALIZATION TO STREAM ********** */
	/**
	 * Serialize data to the given format.
	 * @param	mixed	$data	The data to encode.
	 * @param	string	$type	The serialization type.
	 * @return	string	The serialized stream.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 * @throws	\Temma\Exceptions\Application	If a required dependency is not satisfied.
	 */
	static public function encode(mixed $data, string $type) : string {
		if ($type == self::PHP)
			return (self::encodePhp($data));
		if ($type == self::JSON)
			return (self::encodeJson($data));
		if ($type == self::INI)
			return (self::encodeIni($data));
		if ($type == self::YAML)
			return (self::encodeYaml($data));
		if ($type == self::NEON)
			return (self::encodeNeon($data));
		if ($type == self::XML)
			return (self::encodeXml($data));
		throw new TµIOException("Unable to decode stream, no matching format.", TµIOException::BAD_FORMAT);
	}
	/**
	 * Serialize data to PHP stream.
	 * @param	mixed	$data	The data to encode.
	 * @return	string	The PHP stream.
	 */
	static public function encodePhp(mixed $data) : string {
		$data = var_export($data, true);
		return ($data);
	}
	/**
	 * Serialize data to JSON stream.
	 * @param	mixed	$data		The data to encode.
	 * @param	bool	$prettyPrint	(optional) Set to false for inline output. Defaults to true.
	 * @return	string	The JSON stream.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 */
	static public function encodeJson(mixed $data, bool $prettyPrint=true) : string {
		$flags = JSON_THROW_ON_ERROR;
		if ($prettyPrint)
			$flags = $flags | JSON_PRETTY_PRINT;
		try {
			$json = json_encode($data, $flags);
		} catch (\JsonException $je) {
			throw new TµIOException("Bad JSON stream.", TµIOException::BAD_FORMAT);
		}
		return ($json);
	}
	/**
	 * Serialize data to INI stream.
	 * @param	mixed	$data	The data to encode.
	 * @return	string	The INI stream.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 */
	static public function encodeIni(mixed $data) : string {
		if (!is_array($data))
			throw new TµIOException("Unable to encode INI stream.", TµIOException::BAD_FORMAT);
		$stream = '';
		// first level
		foreach ($data as $key1 => $value1) {
			if (is_int($key1))
				throw new TµIOException("Unable to encode INI stream.", TµIOException::BAD_FORMAT);
			if (is_null($value1))
				$stream .= "$key1=null\n";
			else if (is_bool($value1))
				$stream .= "$key1=" . ($value1 ? 'true' : 'false') . "\n";
			else if (is_string($value1) || is_numeric($value1) || (is_object($value1) && $value1 instanceof \Stringable))
				$stream .= "$key1=\"" . addcslashes($value1, '\"') . "\"\n";
			else if (!is_array($value1))
				throw new TµIOException("Unable to encode INI stream.", TµIOException::BAD_FORMAT);
			$stream .= "[$key1]\n";
			// second level
			foreach ($value1 as $key2 => $value2) {
				if (is_null($value2))
					$stream .= "$key2=null\n";
				else if (is_bool($value2))
					$stream .= "$key2=" . ($value2 ? 'true' : 'false') . "\n";
				else if (is_string($value2) || is_numeric($value2) || (is_object($value1) && $value1 instanceof \Stringable))
					$stream .= "$key2=\"$value2\"\n";
				else if (!is_array($value2))
					throw new TµIOException("Unable to encode INI stream.", TµIOException::BAD_FORMAT);
				// third level
				$index3 = 0;
				foreach ($value2 as $key3 => $value3) {
					if (is_int($key3) && $key3 == $index3) {
						$stream .= $key2 . '[]=';
						$index3++;
					} else
						$stream .= $key2 . "[$key3]=";
					if (is_null($value3))
						$stream .= "null\n";
					else if (is_bool($value3))
						$stream .= $value3 ? 'true' : 'false';
					else if (is_string($value3) || is_numeric($value3) || (is_object($value1) && $value1 instanceof \Stringable))
						$stream .= "$value3\n";
					else
						throw new TµIOException("Unable to encode INI stream.", TµIOException::BAD_FORMAT);
				}
			}
		}
		return ($stream);
	}
	/**
	 * Serialize data to YAML stream.
	 * @param	mixed	$data	The data to encode.
	 * @return	string	The YAML stream.
	 * @throws	\Temma\Exceptions\Application	If a required dependency is not satisfied.
	 */
	static public function encodeYaml(mixed $data) : string {
		if (!extension_loaded('yaml'))
			throw new TµApplicationException("Unable to encode YAML stream. The YAML extension is not loaded.", TµApplicationException::DEPENDENCY);
		$stream = yaml_emit($data, YAML_UTF8_ENCODING, YAML_CR_BREAK);
		return ($stream);
	}
	/**
	 * Serialize data to NEON stream.
	 * @param	mixed	$data	The data to encode.
	 * @return	string	The NEON stream.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 * @throws	\Temma\Exceptions\Application	If a required dependency is not satisfied.
	 */
	static public function encodeNeon(mixed $data) : string {
		if (!class_exists('\Nette\Neon\Neon'))
			throw new TµApplicationException("Unable to encode NEON stream. The Neon library is not loaded.", TµApplicationException::DEPENDENCY);
		try {
			$stream = \Nette\Neon\Neon::encode($data);
		} catch (\Exception $e) {
			throw new TµIOException("Unable to encode NEON stream.", TµIOException::BAD_FORMAT);
		}
		return ($stream);
	}
	/**
	 * Serialize data to XML stream.
	 * @param	mixed	$data		The data to encode.
	 * @param	bool	$prettyPrint	(optional) Set to false for inline output. Defaults to true.
	 * @return	string	The XML stream.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 * @throws	\Temma\Exceptions\Application	If a required dependency is not satisfied.
	 */
	static public function encodeXml(mixed $data, bool $prettyPrint=true) : string {
		$xml = new \XMLWriter();
		$xml->setIndentString("\t");
		$xml->setIndent($prettyPrint);
		$xml->openMemory();
		$xml->startDocument('1.0');
		$xml->startElement('root');
		self::_encodeXmlNode($xml, 'root', $data);
		$xml->endElement();
		$xml->endDocument();
		$stream = $xml->outputMemory();
		return ($stream);
	}
	/**
	 * Private method used to serialize data to XML.
	 * @param	\XMLWriter	$xml		XML object.
	 * @param	?string		$nodeName	Name of the last XML node, used for list elements.
	 * @param	mixed		$data		The data to serialize.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 */
	static private function _encodeXmlNode(\XMLWriter $xml, ?string $nodeName, mixed $data) {
		$stream = '';
		if (is_null($data))
			$xml->writeAttribute('null', 'true');
		else if (is_bool($data))
			$xml->writeAttribute('bool', ($data ? 'true' : 'false'));
		else if (is_string($data) || is_numeric($data) || (is_object($data) && $data instanceof \Stringable))
			$xml->text((string)$data);
		else if (!is_array($data))
			throw new TµIOException("Unable to encode XML stream.", TµIOException::BAD_FORMAT);
		else {
			$index = 0;
			foreach ($data as $key => $value) {
				if (is_int($key)) {
					$xml->startElement($nodeName);
					if ($key != $index)
						$xml->writeAttribute('index', $index);
					else
						$index++;
					self::_encodeXmlNode($xml, $nodeName, $value);
				} else {
					$name = \Temma\Utils\Text::urlize($key);
					$xml->startElement($name);
					if ($name != $key)
						$xml->writeAttribute('name', $key);
					self::_encodeXmlNode($xml, $name, $value);
				}
				$xml->endElement();
			}
		}
	}

	/* ********** UNSERIALIZATION FROM FILE ********** */
	/**
	 * Search for a file from a prefix path, and unserialize it.
	 * @param	string	$prefixPath	Prefix path of the file.
	 * @param	array	$types		(optional) List of searched types.
	 *					Defaults to [TµConfigManager::PHP, TµConfigManager::JSON, TµConfigManager::INI, TµConfigManager::YAML, TµConfigManager::NEON, TµConfigManager::XML]
	 * @return	mixed	The unserialized file's data.
	 * @throws	\Temma\Exceptions\IO		If there is no suitable file.
	 * @throws	\Temma\Exceptions\Application	If the file can't be read.
	 */
	static public function readFromPrefix(string $prefixPath, array $types=[self::PHP, self::JSON, self::INI, self::YAML, self::NEON, self::XML]) : mixed {
		foreach ($types as $type) {
			$path = "$prefixPath.$type";
			if (file_exists($path)) {
				try {
					return (self::read($path, $type));
				} catch (TµIOException $ioe) {
					// unable to read file, continue to loop
				}
			}
		}
		throw new TµIOException("Unable to find configuration file with prefix '$prefixPath'.", TµIOException::NOT_FOUND);
	}
	/**
	 * Unserialize a file.
	 * @param	string	$path	Path to the file to unserialize.
	 * @param	?string	$type	(optional) Type of the file. If not given, infere the type from the filename's extension.
	 * @return	mixed	The decoded data.
	 */
	static public function read(string $path, ?string $type=null) : mixed {
		// manage file's type
		if (!$type) {
			if (($pos = mb_strrpos($path, '.')) === false)
				throw new TµIOException("Unable to find extension of file '$path'.", TµIOException::BAD_FORMAT);
			$type = mb_strtolower(mb_substr($path, $pos + 1));
		}
		// PHP file
		if ($type == self::PHP)
			return (self::readPhp($path));
		// INI
		if ($type == self::INI)
			return (self::readIni($path));
		// JSON file
		if ($type == self::JSON)
			return (self::readJson($path));
		// YAML file
		if ($type == self::YAML)
			return (self::readYaml($path));
		// NEON file
		if ($type == self::NEON)
			return (self::readNeon($path));
		// XML file
		if ($type == self::XML)
			return (self::readXml($path));
		// unknown file format
		throw new TµIOException("Unknown format for file '$path'.", TµIOException::BAD_FORMAT);
	}
	/**
	 * Unserialize PHP fiie.
	 * @param	string	$path	Path to the PHP file.
	 * @return	mixed	The decoded data.
	 * @throws	\Temma\Exceptions\IO	If the file doesn't exist or its format is not correct.
	 */
	static public function readPhp(string $path) : mixed {
		set_error_handler(function($errno, $errstr, $errfile, $errline ) {
			throw new \ErrorException($errstr, $errno, 0, $errfile, $errline);
		});
		try {
			$data = include($path);
		} catch (\ErrorException $ex) {
			throw new TµIOException("Unable to read PHP file '$path'.", TµIOException::NOT_FOUND);
		} catch (\Error $err) {
			throw new TµIOException("Bad syntax in PHP file '$path'.", TµIOException::BAD_FORMAT);
		} finally {
			restore_error_handler();
		}
		return ($data);
	}
	/**
	 * Unserialize JSON file.
	 * @param	string	$path	Path to the JSON file.
	 * @return	mixed	The decoded data.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 */
	static public function readJson(string $path) : mixed {
		if (($stream = file_get_contents($path)) === false)
			throw new TµIOException("Unable to read JSON file '$path'.", TµIOException::UNREADABLE);
		$data = self::decodeJson($stream);
		return ($data);
	}
	/**
	 * Unserialize INI file.
	 * @param	string	$path	Path to the INI file.
	 * @return	mixed	The decoded data.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 */
	static public function readIni(string $path) : mixed {
		set_error_handler(function($errno, $errstr, $errfile, $errline ) {
			throw new \ErrorException($errstr, $errno, 0, $errfile, $errline);
		});
		try {
			$data = parse_ini_file($path, true);
		} catch (\ErrorException $ex) {
			throw new TµIOException("Unable to read INI file '$path'.", TµIOException::NOT_FOUND);
		} finally {
			restore_error_handler();
		}
		if ($data === false)
			throw new TµIOException("Unable to read INI file '$path'.", TµIOException::UNREADABLE);
		return ($data);
	}
	/**
	 * Unserialize YAML file.
	 * @param	string	$path	Path to the YAML file.
	 * @return	mixed	The decoded data.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 * @throws	\Temma\Exceptions\Application	If a required dependency cannot be satisfied.
	 */
	static public function readYaml(string $path) : mixed {
		if (!extension_loaded('yaml'))
			throw new TµApplicationException("Unable to read the '$path' file. The YAML extension is not loaded.", TµApplicationException::DEPENDENCY);
		if (($data = yaml_parse_file($path)) === false)
			throw new TµIOException("File '$path' is not a valid YAML file.", TµIOException::BAD_FORMAT);
		return ($data);
	}
	/**
	 * Unserialize NEON file.
	 * @param	string	$path	Path to the YAML file.
	 * @return	mixed	The decoded data.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 * @throws	\Temma\Exceptions\Application	If a required dependency cannot be satisfied.
	 */
	static public function readNeon(string $path) : mixed {
		if (!class_exists('\Nette\Neon\Neon'))
			throw new TµApplicationException("Unable to encode NEON stream. The Neon library is not loaded.", TµApplicationException::DEPENDENCY);
		try {
			$data = \Nette\Neon\Neon::decodeFile($path);
		} catch (\Exception $e) {
			throw new TµIOException("Unable to read configuration file '$path'.", TµIOException::UNREADABLE);
		}
		return ($data);
	}
	/**
	 * Unserialize XML file.
	 * @param	string	$path	Path to the XML file.
	 * @return	mixed	The decoded data.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 * @throws	\Temma\Exceptions\Application	If a required dependency cannot be satisfied.
	 * @see		https://stackoverflow.com/a/20431742
	 */
	static public function readXml(string $path) : mixed {
		if (($xml = simplexml_load_file($path, 'SimpleXMLElement', LIBXML_NOCDATA)) === false ||
		    ($json = json_encode($xml)) === false ||
		    ($data = json_decode($json, true)) === null)
			throw new TµIOException("Unable to read XML configuration file '$path'.", TµIOException::BAD_FORMAT);
		return ($data);
	}

	/* ********** UNSERIALIZATION FROM STREAM ********** */
	/**
	 * Unserialize the given stream.
	 * @param	string		$stream	The stream to decode.
	 * @param	string|array	$types	(optional) The type of the stream, or a list of types.
	 * @return	mixed		The decoded data.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 * @throws	\Temma\Exceptions\Application	If a required dependency is not satisfied.
	 */
	static public function decode(string $stream, string|array $types=[self::PHP, self::JSON, self::INI, self::YAML, self::NEON, self::XML]) : mixed {
		$types = is_array($types) ? $types : [$types];
		$exceptions = [];
		foreach ($types as $type) {
			try {
				if ($type == self::PHP)
					return (self::decodePhp($stream));
				if ($type == self::JSON)
					return (self::decodeJson($stream));
				if ($type == self::INI)
					return (self::decodeIni($stream));
				if ($type == self::YAML)
					return (self::decodeYaml($stream));
				if ($type == self::NEON)
					return (self::decodeNeon($stream));
				if ($type == self::XML)
					return (self::decodeXml($stream));
			} catch (\Exception $e) {
				$exceptions[] = $e;
			}
		}
		if (count($exceptions) == 1)
			throw $exceptions[0];
		throw new TµIOException("Unable to decode stream, no matching format.", TµIOException::BAD_FORMAT);
	}
	/**
	 * Unserialize PHP stream.
	 * @param	string	$stream	The PHP stream to decode.
	 * @return	mixed	The decoded data.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 */
	static public function decodePhp(string $stream) : mixed {
		if (str_starts_with($stream, "<?php\n"))
			$stream = mb_substr($stream, mb_strlen("<?php\n"));
		try {
			$data = eval($stream);
		} catch (\Exception $e) {
			throw new TµIOException("Unable to decode PHP stream.", TµIOException::BAD_FORMAT);
		}
		return ($data);
	}
	/**
	 * Unserialize JSON stream.
	 * @param	string	$stream	The JSON stream to decode.
	 * @return	mixed	The decoded data.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 * @link	https://stackoverflow.com/questions/8148797/a-json-parser-for-php-that-supports-comments/43439966#43439966
	 */
	static public function decodeJson(string $stream) : mixed {
		$stream = preg_replace('~(" (?:[^"\\\\] | \\\\\\\\ | \\\\")*+ ") | // [^\v]*+ | /\* .*? \*/~xs', '$1', $stream);
		if (($data = json_decode($stream, true, 512, JSON_THROW_ON_ERROR)) === null)
			throw new TµIOException("Unable to decode JSON stream.", TµIOException::BAD_FORMAT);
		return ($data);
	}
	/**
	 * Unserialize INI stream.
	 * @param	string	$stream	The INI stream to decode.
	 * @return	mixed	The decoded data.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 */
	static public function decodeIni(string $stream) : mixed {
		if (($data = parse_ini_string($stream, true)) === false)
			throw new TµIOException("Unable to decode INI stream.", TµIOException::UNREADABLE);
		return ($data);
	}
	/**
	 * Unserialize YAML stream.
	 * @param	string	$stream	The YAML stream to decode.
	 * @return	mixed	The decoded data.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 * @throws	\Temma\Exceptions\Application	If a required dependency cannot be satisfied.
	 */
	static public function decodeYaml(string $stream) : mixed {
		if (!extension_loaded('yaml'))
			throw new TµApplicationException("Unable to decode YAML stream. The YAML extension is not loaded.", TµApplicationException::DEPENDENCY);
		if (($data = yaml_parse($stream)) === false)
			throw new TµIOException("Unable to decode YAML stream.", TµIOException::BAD_FORMAT);
		return ($data);
	}
	/**
	 * Unserialize NEON stream.
	 * @param	string	$stream	The NEON stream to decode.
	 * @return	mixed	The decoded data.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 * @throws	\Temma\Exceptions\Application	If a required dependency cannot be satisfied.
	 */
	static public function decodeNeon(string $stream) : mixed {
		if (!class_exists('\Nette\Neon\Neon'))
			throw new TµApplicationException("Unable to decode NEON stream. The Neon library is not loaded.", TµApplicationException::DEPENDENCY);
		try {
			$data = \Nette\Neon\Neon::decode($stream);
		} catch (\Exception $e) {
			throw new TµIOException("Unable to decode NEON stream.", TµIOException::BAD_FORMAT);
		}
		return ($data);
	}
	/**
	 * Unserialize XML stream.
	 * @param	string	$stream	The XML stream to decode.
	 * @return	mixed	The decoded data.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 * @throws	\Temma\Exceptions\Application	If a required dependency cannot be satisfied.
	 * @see		https://stackoverflow.com/a/20431742
	 */
	static public function decodeXml(string $stream) : mixed {
		if (($xml = simplexml_load_string($stream, 'SimpleXMLElement', LIBXML_NOCDATA)) === false ||
		    ($json = json_encode($xml)) === false ||
		    ($data = json_decode($json, true)) === null)
			throw new TµIOException("Unable to code XML stream.", TµIOException::BAD_FORMAT);
		return ($data);
	}
}

