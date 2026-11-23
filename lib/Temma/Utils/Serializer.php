<?php

/**
 * Serializer
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023-2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-serializer
 */

namespace Temma\Utils;

use \Temma\Exceptions\Application as TµApplicationException;
use \Temma\Exceptions\IO as TµIOException;

/**
 * Helper object used to serialize and unserialize data.
 *
 * This object is able to manage data stored in PHP, JSON, INI, YAML, NEON or XML formats.
 *
 * Scalar values written without explicit type in INI streams (unquoted values) and in XML streams
 * (elements without "__type" attribute) are converted like JSON literals: "true" and "false" become
 * booleans, "null" becomes null, numbers written with the JSON syntax become integers or floats; any
 * other value is a string (no conversion of "yes", "no", "on", "off" or "none").
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
 * // unserialize data from stream, detecting its format
 * $data = TµSerializer::decode($stream);
 * // unserialize data from stream, giving the list of accepted formats (by priority)
 * $data = TµSerializer::decode($stream, [TµSerializer::JSON, TµSerializer::INI, TµSerializer::XML]);
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
 * TµSerializer::writeJson('/path/to/file', $data);
 * ```
 *
 * @see	https://www.php.net/manual/en/function.include.php
 * @see	https://www.php.net/manual/en/function.json-decode.php
 * @see	https://www.php.net/manual/en/book.yaml.php
 * @see	https://doc.nette.org/en/neon
 * @see	https://www.php.net/manual/en/book.dom.php
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
	/** Constant: name of the XML elements used for list entries and for keys which are not valid element names. */
	const XML_ITEM = '__item';
	/** Constant: name of the XML attribute which contains the type of a value. */
	const XML_TYPE = '__type';
	/** Constant: name of the XML attribute which contains a key. */
	const XML_KEY = '__key';
	/** Constant: name of the XML attribute which contains the encoding of a key. */
	const XML_KEY_TYPE = '__keyType';

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
		if ($type == self::PHP)
			$stream = "<?php\nreturn $stream;";
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
		$stream = self::encodeYaml($data);
		if (file_put_contents($path, $stream) === false)
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
			throw new TµIOException("Unable to write XML file '$path'.", TµIOException::UNWRITABLE);
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
		throw new TµIOException("Unable to encode data, unknown format '$type'.", TµIOException::BAD_FORMAT);
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
	 * First-level scalar values are written before the sections. First-level arrays become sections, whose
	 * scalar values are written as "key=value" lines, and whose array values are written as "key[]=value"
	 * (or "key[subkey]=value") lines.
	 * Strings are written between double quotes; booleans, null values and numbers are written without quotes.
	 * @param	mixed	$data	The data to encode (associative array).
	 * @return	string	The INI stream.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 */
	static public function encodeIni(mixed $data) : string {
		if (!is_array($data))
			throw new TµIOException("Unable to encode INI stream.", TµIOException::BAD_FORMAT);
		$stream = '';
		$sections = '';
		// first level
		foreach ($data as $key1 => $value1) {
			if (is_int($key1))
				throw new TµIOException("Unable to encode INI stream.", TµIOException::BAD_FORMAT);
			// scalar value (written before the sections)
			if (!is_array($value1)) {
				$stream .= "$key1=" . self::_encodeIniValue($value1) . "\n";
				continue;
			}
			// section
			$sections .= "[$key1]\n";
			// second level
			foreach ($value1 as $key2 => $value2) {
				if (!is_array($value2)) {
					$sections .= "$key2=" . self::_encodeIniValue($value2) . "\n";
					continue;
				}
				// third level
				$index3 = 0;
				foreach ($value2 as $key3 => $value3) {
					if (is_int($key3) && $key3 == $index3) {
						$sections .= $key2 . '[]=';
						$index3++;
					} else {
						$sections .= $key2 . "[$key3]=";
					}
					$sections .= self::_encodeIniValue($value3) . "\n";
				}
			}
		}
		return ($stream . $sections);
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
	 * The root element is named "data" and contains the root value. Each array entry is a child element, named
	 * after its key when the key is a simple XML name; otherwise it is an "__item" element, with a "__key"
	 * attribute if the key is not the next automatic index of the array.
	 * Scalar values are written as text, and read back like JSON literals ("true", "false", "null", numbers).
	 * A "__type" attribute is added when needed: "string" for strings which would be read as another type
	 * (like "28" or "true"), "array" for empty arrays, "base64" for strings which are not valid UTF-8 or which
	 * contain characters forbidden in XML (such keys are base64-encoded too, with a "__keyType" attribute).
	 * @param	mixed	$data		The data to encode.
	 * @param	bool	$prettyPrint	(optional) Set to false for inline output. Defaults to true.
	 * @return	string	The XML stream.
	 * @throws	\Temma\Exceptions\IO	If the data can't be encoded (objects which are not Stringable,
	 *					resources, infinite or NaN floats).
	 */
	static public function encodeXml(mixed $data, bool $prettyPrint=true) : string {
		$xml = new \XMLWriter();
		$xml->openMemory();
		$xml->setIndent($prettyPrint);
		$xml->setIndentString("\t");
		$xml->startDocument('1.0', 'UTF-8');
		$xml->startElement('data');
		self::_encodeXmlValue($xml, $data);
		$xml->endElement();
		$xml->endDocument();
		return ($xml->outputMemory());
	}

	/* ********** UNSERIALIZATION FROM FILE ********** */
	/**
	 * Search for a file from a prefix path, and unserialize it.
	 * @param	string	$prefixPath	Prefix path of the file.
	 * @param	array	$types		(optional) List of searched types.
	 *					Defaults to [TµSerializer::PHP, TµSerializer::JSON, TµSerializer::INI, TµSerializer::YAML, TµSerializer::NEON, TµSerializer::XML]
	 * @return	mixed	The unserialized file's data.
	 * @throws	\Temma\Exceptions\IO		If there is no suitable file.
	 * @throws	\Temma\Exceptions\Application	If a required dependency is not satisfied.
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
	 * @throws	\Temma\Exceptions\IO	If the file can't be read or if its format is not correct.
	 * @throws	\Temma\Exceptions\Application	If a required dependency is not satisfied.
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
		set_error_handler(function($errno, $errstr, $errfile, $errline) {
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
	 * @throws	\Temma\Exceptions\IO	If the file can't be read or if its format is not correct.
	 * @see		\Temma\Utils\Serializer::decodeJson()
	 */
	static public function readJson(string $path) : mixed {
		$stream = self::_readFile($path, 'JSON');
		$data = self::decodeJson($stream);
		return ($data);
	}
	/**
	 * Unserialize INI file.
	 * @param	string	$path	Path to the INI file.
	 * @return	mixed	The decoded data.
	 * @throws	\Temma\Exceptions\IO	If the file can't be read or if its format is not correct.
	 * @see		\Temma\Utils\Serializer::decodeIni()
	 */
	static public function readIni(string $path) : mixed {
		$stream = self::_readFile($path, 'INI');
		$data = self::decodeIni($stream);
		return ($data);
	}
	/**
	 * Unserialize YAML file.
	 * @param	string	$path	Path to the YAML file.
	 * @return	mixed	The decoded data.
	 * @throws	\Temma\Exceptions\IO	If the file can't be read or if its format is not correct.
	 * @throws	\Temma\Exceptions\Application	If a required dependency cannot be satisfied.
	 */
	static public function readYaml(string $path) : mixed {
		$stream = self::_readFile($path, 'YAML');
		$data = self::decodeYaml($stream);
		return ($data);
	}
	/**
	 * Unserialize NEON file.
	 * @param	string	$path	Path to the NEON file.
	 * @return	mixed	The decoded data.
	 * @throws	\Temma\Exceptions\IO	If the file can't be read or if its format is not correct.
	 * @throws	\Temma\Exceptions\Application	If a required dependency cannot be satisfied.
	 */
	static public function readNeon(string $path) : mixed {
		$stream = self::_readFile($path, 'NEON');
		$data = self::decodeNeon($stream);
		return ($data);
	}
	/**
	 * Unserialize XML file.
	 * @param	string	$path	Path to the XML file.
	 * @return	mixed	The decoded data.
	 * @throws	\Temma\Exceptions\IO	If the file can't be read or if its format is not correct.
	 * @see		\Temma\Utils\Serializer::decodeXml()
	 */
	static public function readXml(string $path) : mixed {
		$stream = self::_readFile($path, 'XML');
		$data = self::decodeXml($stream);
		return ($data);
	}

	/* ********** UNSERIALIZATION FROM STREAM ********** */
	/**
	 * Unserialize the given stream.
	 * If only one type is given, the stream is decoded with this format. If several types are given, the format
	 * is detected:
	 * - A stream starting with "<?php" is a PHP stream (if the PHP type is accepted).
	 * - A stream starting with "<" is an XML stream (if the XML type is accepted).
	 * - Otherwise, the other types are tried in the given order: a JSON stream must be valid; an INI stream must
	 *   be valid and contain at least one "key = value" line; a YAML or NEON stream must be decoded as an array
	 *   (these formats accept any text as a scalar string).
	 * Warning: a PHP stream is executed. Never accept the PHP type when the stream comes from an untrusted source.
	 * @param	string		$stream	The stream to decode.
	 * @param	string|array	$types	(optional) The type of the stream, or a list of accepted types (by priority).
	 * @return	mixed		The decoded data.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 * @throws	\Temma\Exceptions\Application	If a required dependency is not satisfied.
	 */
	static public function decode(string $stream, string|array $types=[self::PHP, self::JSON, self::INI, self::YAML, self::NEON, self::XML]) : mixed {
		$types = is_array($types) ? array_values($types) : [$types];
		// only one type: no detection
		if (count($types) == 1) {
			if ($types[0] == self::PHP)
				return (self::decodePhp($stream));
			if ($types[0] == self::JSON)
				return (self::decodeJson($stream));
			if ($types[0] == self::INI)
				return (self::decodeIni($stream));
			if ($types[0] == self::YAML)
				return (self::decodeYaml($stream));
			if ($types[0] == self::NEON)
				return (self::decodeNeon($stream));
			if ($types[0] == self::XML)
				return (self::decodeXml($stream));
			throw new TµIOException("Unable to decode stream, unknown format '{$types[0]}'.", TµIOException::BAD_FORMAT);
		}
		// beginning of the stream, without UTF-8 BOM and leading spaces
		$start = ltrim(str_starts_with($stream, "\xEF\xBB\xBF") ? substr($stream, 3) : $stream);
		if ($start === '')
			throw new TµIOException("Unable to decode empty stream.", TµIOException::BAD_FORMAT);
		// PHP stream
		if (str_starts_with($start, '<?php')) {
			if (!in_array(self::PHP, $types))
				throw new TµIOException("Unable to decode PHP stream, this format is not accepted.", TµIOException::BAD_FORMAT);
			return (self::decodePhp($stream));
		}
		// XML stream
		if (str_starts_with($start, '<')) {
			if (!in_array(self::XML, $types))
				throw new TµIOException("Unable to decode XML stream, this format is not accepted.", TµIOException::BAD_FORMAT);
			return (self::decodeXml($stream));
		}
		// other formats, tried by priority
		foreach ($types as $type) {
			try {
				if ($type == self::JSON)
					return (self::decodeJson($stream));
				if ($type == self::INI) {
					$data = self::_decodeIni($stream, $nbValues);
					if ($nbValues)
						return ($data);
				} else if ($type == self::YAML || $type == self::NEON) {
					$data = ($type == self::YAML) ? self::decodeYaml($stream) : self::decodeNeon($stream);
					if (is_array($data))
						return ($data);
				}
			} catch (\Exception $e) {
				// not this format (or missing dependency), try the next one
			}
		}
		throw new TµIOException("Unable to decode stream, no matching format.", TµIOException::BAD_FORMAT);
	}
	/**
	 * Unserialize PHP stream.
	 * The stream may start with a "<?php" tag, and must contain a "return" statement.
	 * Warning: the stream is executed. Never use this method on a stream coming from an untrusted source.
	 * @param	string	$stream	The PHP stream to decode.
	 * @return	mixed	The decoded data.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 */
	static public function decodePhp(string $stream) : mixed {
		$stream = preg_replace('/^(?:\xEF\xBB\xBF)?\s*<\?php\b/', '', $stream, 1);
		try {
			$data = eval($stream);
		} catch (\Throwable $t) {
			throw new TµIOException("Unable to decode PHP stream.", TµIOException::BAD_FORMAT);
		}
		return ($data);
	}
	/**
	 * Unserialize JSON stream.
	 * The stream may contain single-line comments ("// ...") and multi-line comments ("/* ... * /"), and trailing
	 * commas at the end of lists and objects ("[1, 2, 3,]").
	 * @param	string	$stream	The JSON stream to decode.
	 * @return	mixed	The decoded data.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 * @link	https://stackoverflow.com/questions/8148797/a-json-parser-for-php-that-supports-comments/43439966#43439966
	 */
	static public function decodeJson(string $stream) : mixed {
		// remove comments (strings are matched first, to be kept as is)
		$stream = preg_replace('~("(?:[^"\\\\]|\\\\.)*+")|//[^\r\n]*+|/\*.*?\*/~s', '$1', $stream);
		// remove trailing commas (a comma alone in a list or an object is an error)
		$emptyComma = false;
		if (!is_null($stream)) {
			$stream = preg_replace_callback('~("(?:[^"\\\\]|\\\\.)*+")|([\[{]\s*)?,(?=\s*[\]}])~s', function($matches) use (&$emptyComma) {
				if (($matches[2] ?? '') !== '')
					$emptyComma = true;
				return ($matches[1] ?? '');
			}, $stream);
		}
		if (is_null($stream) || $emptyComma)
			throw new TµIOException("Unable to decode JSON stream.", TµIOException::BAD_FORMAT);
		// decoding
		try {
			$data = json_decode($stream, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $je) {
			throw new TµIOException("Unable to decode JSON stream.", TµIOException::BAD_FORMAT);
		}
		return ($data);
	}
	/**
	 * Unserialize INI stream.
	 * Syntax:
	 * - Empty lines and lines starting with ";" or "#" are ignored.
	 * - "[name]" lines start sections.
	 * - "key = value" lines define values; "key[] = value" and "key[subkey] = value" lines define arrays.
	 * - Values between double quotes are strings (on one or several lines); the \", \\ and \$ sequences are unescaped.
	 * - Values between single quotes are strings, written as is.
	 * - Unquoted values (which end at the first ";") are converted like JSON literals: "true", "false", "null"
	 *   and numbers are converted, any other value is a string.
	 * There is no interpolation of constants or variables.
	 * @param	string	$stream	The INI stream to decode.
	 * @return	array	The decoded data.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 */
	static public function decodeIni(string $stream) : array {
		$data = self::_decodeIni($stream);
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
		// parsing, without PHP warnings
		set_error_handler(function() {
			return (true);
		});
		try {
			$data = yaml_parse($stream);
		} finally {
			restore_error_handler();
		}
		if ($data === false)
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
	 * Reads the streams generated by encodeXml(), and hand-written XML streams of data:
	 * - The name of the root element doesn't matter. Comments are ignored, CDATA sections are read as text.
	 * - An element without child element is a scalar value, converted like a JSON literal ("true", "false",
	 *   "null" and numbers are converted, any other text is a string), unless it has a "__type" attribute
	 *   ("string", "int", "float", "bool", "null", "array" or "base64").
	 * - An element with child elements is an array. A child element is an entry whose key is the element name;
	 *   "__item" elements are entries with the next automatic index, or with the key given in their "__key"
	 *   attribute. When all the child elements have the same name, repeated several times, the element is a list;
	 *   otherwise, the values of elements whose name is repeated are grouped in a list, under this name.
	 * - Other attributes are ignored.
	 * Elements mixing text and child elements, and documents with a DOCTYPE declaration (protection against XXE
	 * and entity expansion attacks), are refused.
	 * This method is not a generic XML reader (attributes are ignored, mixed content is refused); use SimpleXML
	 * or DOM to read any XML document.
	 * @param	string	$stream	The XML stream to decode.
	 * @return	mixed	The decoded data.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 * @see		\Temma\Utils\Serializer::encodeXml()
	 */
	static public function decodeXml(string $stream) : mixed {
		if (trim($stream) === '')
			throw new TµIOException("Unable to decode empty XML stream.", TµIOException::BAD_FORMAT);
		// parsing, without PHP warnings
		$dom = new \DOMDocument();
		$useErrors = libxml_use_internal_errors(true);
		try {
			$loaded = $dom->loadXML($stream, LIBXML_NONET);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($useErrors);
		}
		if (!$loaded || !$dom->documentElement)
			throw new TµIOException("Unable to decode XML stream.", TµIOException::BAD_FORMAT);
		if ($dom->doctype)
			throw new TµIOException("Unable to decode XML stream with a DOCTYPE declaration.", TµIOException::BAD_FORMAT);
		// decoding
		$data = self::_decodeXmlElement($dom->documentElement);
		return ($data);
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Read the content of a file.
	 * @param	string	$path	Path to the file.
	 * @param	string	$format	Name of the format, used in error messages.
	 * @return	string	The content of the file.
	 * @throws	\Temma\Exceptions\IO	If the file doesn't exist or can't be read.
	 */
	static private function _readFile(string $path, string $format) : string {
		if (!is_file($path))
			throw new TµIOException("Unable to find $format file '$path'.", TµIOException::NOT_FOUND);
		if (!is_readable($path) || ($stream = file_get_contents($path)) === false)
			throw new TµIOException("Unable to read $format file '$path'.", TµIOException::UNREADABLE);
		return ($stream);
	}
	/**
	 * Convert a text like a JSON literal: "true" and "false" become booleans, "null" becomes null, numbers written
	 * with the JSON syntax become integers (or floats if they have a decimal part, an exponent, or if they are too
	 * big); any other text is returned as is.
	 * @param	string	$text	The text to convert.
	 * @return	mixed	The converted value.
	 */
	static private function _decodeScalar(string $text) : mixed {
		if ($text === 'true')
			return (true);
		if ($text === 'false')
			return (false);
		if ($text === 'null')
			return (null);
		if (!preg_match('/^-?(?:0|[1-9]\d*)(\.\d+)?([eE][+-]?\d+)?$/', $text, $matches))
			return ($text);
		if (!isset($matches[1]) && ($int = filter_var($text, FILTER_VALIDATE_INT)) !== false)
			return ($int);
		return ((float)$text);
	}
	/**
	 * Encode a scalar value for an INI stream.
	 * Strings are written between double quotes, with escaped double quotes and backslashes; numbers are
	 * written as is (floats always with a decimal part or an exponent); booleans and null values are written
	 * as "true", "false" and "null".
	 * @param	mixed	$value	The value to encode.
	 * @return	string	The encoded value.
	 * @throws	\Temma\Exceptions\IO	If the value can't be encoded.
	 */
	static private function _encodeIniValue(mixed $value) : string {
		if (is_null($value))
			return ('null');
		if (is_bool($value))
			return ($value ? 'true' : 'false');
		if (is_int($value))
			return ((string)$value);
		if (is_float($value) && is_finite($value))
			return (var_export($value, true));
		if (is_string($value) || $value instanceof \Stringable)
			return ('"' . addcslashes((string)$value, '"\\') . '"');
		throw new TµIOException("Unable to encode INI stream.", TµIOException::BAD_FORMAT);
	}
	/**
	 * Decode an INI stream.
	 * @param	string	$stream		The INI stream.
	 * @param	?int	$nbValues	(optional) Reference to a variable which will contain the number of "key = value" lines.
	 * @return	array	The decoded data.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 * @see		\Temma\Utils\Serializer::decodeIni()
	 */
	static private function _decodeIni(string $stream, ?int &$nbValues=null) : array {
		$data = [];
		$section = null;
		$nbValues = 0;
		$pos = str_starts_with($stream, "\xEF\xBB\xBF") ? 3 : 0;
		$length = strlen($stream);
		$endOfLine = '[ \t]*(?:;[^\r\n]*)?(?:\r\n|\r|\n|$)';
		while ($pos < $length) {
			// empty line or comment
			if (preg_match('/\G[ \t]*(?:[;#][^\r\n]*)?(?:\r\n|\r|\n|$)/', $stream, $matches, 0, $pos) && $matches[0] !== '') {
				$pos += strlen($matches[0]);
				continue;
			}
			// section
			if (preg_match('/\G[ \t]*\[([^\]\r\n]*)\]' . $endOfLine . '/', $stream, $matches, 0, $pos)) {
				$section = trim($matches[1]);
				if ($section === '')
					break;
				if (!is_array($data[$section] ?? null))
					$data[$section] = [];
				$pos += strlen($matches[0]);
				continue;
			}
			// "key = value" line
			if (!preg_match('/\G[ \t]*([^=\[\]"\';\r\n]+?)[ \t]*(?:\[([^\]\r\n]*)\])?[ \t]*=[ \t]*' .
			                '(?:"((?:[^"\\\\]|\\\\.)*+)"|\'([^\']*+)\'|((?:[^;"\' \t\r\n][^;\r\n]*?)?))' . $endOfLine . '/s',
			                $stream, $matches, PREG_UNMATCHED_AS_NULL, $pos))
				break;
			$pos += strlen($matches[0]);
			$nbValues++;
			$key = trim($matches[1]);
			$index = isset($matches[2]) ? trim($matches[2]) : null;
			if (isset($matches[3]))
				$value = preg_replace('/\\\\(["\\\\$])/', '$1', $matches[3]);
			else if (isset($matches[4]))
				$value = $matches[4];
			else
				$value = self::_decodeScalar(trim($matches[5] ?? ''));
			// store the value
			$target = is_null($section) ? $data : $data[$section];
			if (is_null($index)) {
				$target[$key] = $value;
			} else {
				if (!is_array($target[$key] ?? null))
					$target[$key] = [];
				if ($index === '')
					$target[$key][] = $value;
				else
					$target[$key][$index] = $value;
			}
			if (is_null($section))
				$data = $target;
			else
				$data[$section] = $target;
		}
		if ($pos < $length) {
			$line = substr_count($stream, "\n", 0, $pos) + 1;
			throw new TµIOException("Unable to decode INI stream (syntax error on line $line).", TµIOException::BAD_FORMAT);
		}
		return ($data);
	}
	/**
	 * Write the content of the current XML element (attributes, text and child elements) from a value.
	 * @param	\XMLWriter	$xml	XML writer, whose current element will contain the value.
	 * @param	mixed		$data	The value to encode.
	 * @throws	\Temma\Exceptions\IO	If the value can't be encoded.
	 */
	static private function _encodeXmlValue(\XMLWriter $xml, mixed $data) : void {
		// scalar values (written like JSON literals)
		if (is_null($data)) {
			$xml->text('null');
			return;
		}
		if (is_bool($data)) {
			$xml->text($data ? 'true' : 'false');
			return;
		}
		if (is_int($data)) {
			$xml->text((string)$data);
			return;
		}
		if (is_float($data)) {
			if (!is_finite($data))
				throw new TµIOException("Unable to encode infinite or NaN float to XML.", TµIOException::BAD_FORMAT);
			$xml->text(var_export($data, true));
			return;
		}
		// strings
		if (is_string($data) || $data instanceof \Stringable) {
			$data = (string)$data;
			if (!self::_isXmlText($data)) {
				$xml->writeAttribute(self::XML_TYPE, 'base64');
				$xml->text(base64_encode($data));
				return;
			}
			if (self::_decodeScalar($data) !== $data)
				$xml->writeAttribute(self::XML_TYPE, 'string');
			$xml->text($data);
			return;
		}
		if (!is_array($data))
			throw new TµIOException("Unable to encode data of type '" . get_debug_type($data) . "' to XML.", TµIOException::BAD_FORMAT);
		// empty array
		if (!$data) {
			$xml->writeAttribute(self::XML_TYPE, 'array');
			return;
		}
		// array entries
		$nextIndex = null;
		foreach ($data as $key => $value) {
			if (is_int($key) && $key === ($nextIndex ?? 0)) {
				// next automatic index
				$xml->startElement(self::XML_ITEM);
			} else if (is_string($key) && self::_isXmlName($key)) {
				// key used as element name
				$xml->startElement($key);
			} else {
				// key written as an attribute
				$xml->startElement(self::XML_ITEM);
				$keyString = (string)$key;
				if (self::_isXmlText($keyString)) {
					$xml->writeAttribute(self::XML_KEY, $keyString);
				} else {
					$xml->writeAttribute(self::XML_KEY, base64_encode($keyString));
					$xml->writeAttribute(self::XML_KEY_TYPE, 'base64');
				}
			}
			// computation of the next automatic index (same behaviour as PHP)
			if (is_int($key) && (is_null($nextIndex) || $key >= $nextIndex))
				$nextIndex = $key + 1;
			self::_encodeXmlValue($xml, $value);
			$xml->endElement();
		}
	}
	/**
	 * Tell if an array key can be used as an XML element name.
	 * Only simple ASCII names are used, which don't start with "xml" (reserved by the XML specification),
	 * and which are not equal to "__item" (used for list entries and complex keys).
	 * @param	string	$key	The key.
	 * @return	bool	True if the key can be used as an element name.
	 */
	static private function _isXmlName(string $key) : bool {
		return (preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $key) &&
		        strncasecmp($key, 'xml', 3) &&
		        $key !== self::XML_ITEM);
	}
	/**
	 * Tell if a string can be written as is in an XML stream (valid UTF-8, without characters forbidden by XML 1.0).
	 * @param	string	$text	The string.
	 * @return	bool	True if the string can be written as is.
	 */
	static private function _isXmlText(string $text) : bool {
		return (preg_match('/^[\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]*$/u', $text) === 1);
	}
	/**
	 * Decode an XML element.
	 * @param	\DOMElement	$element	The element.
	 * @return	mixed	The decoded value.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 */
	static private function _decodeXmlElement(\DOMElement $element) : mixed {
		$type = $element->hasAttribute(self::XML_TYPE) ? $element->getAttribute(self::XML_TYPE) : null;
		// fetch the text and the child elements
		$text = '';
		$children = [];
		foreach ($element->childNodes as $node) {
			if ($node instanceof \DOMElement)
				$children[] = $node;
			else if ($node instanceof \DOMText)
				$text .= $node->data;
			else if (!($node instanceof \DOMComment) && !($node instanceof \DOMProcessingInstruction))
				throw new TµIOException("Unable to decode XML stream (unexpected node in element '{$element->nodeName}').", TµIOException::BAD_FORMAT);
		}
		// array
		if ($children) {
			if (!is_null($type) || trim($text) !== '')
				throw new TµIOException("Unable to decode XML stream (element '{$element->nodeName}' mixes text, type or child elements).", TµIOException::BAD_FORMAT);
			return (self::_decodeXmlArray($element, $children));
		}
		// value without type
		if (is_null($type))
			return (self::_decodeScalar($text));
		// typed value
		if ($type == 'string')
			return ($text);
		$value = trim($text);
		if ($type == 'int' && preg_match('/^[+-]?\d+$/', $value) && ($int = filter_var($value, FILTER_VALIDATE_INT)) !== false)
			return ($int);
		if ($type == 'float' && is_numeric($value))
			return ((float)$value);
		if ($type == 'bool' && ($value == 'true' || $value == 'false'))
			return ($value == 'true');
		if ($type == 'null' && ($value === '' || $value == 'null'))
			return (null);
		if ($type == 'array' && $value === '')
			return ([]);
		if ($type == 'base64' && ($decoded = base64_decode($value, true)) !== false)
			return ($decoded);
		throw new TµIOException("Unable to decode XML stream (bad value or type in element '{$element->nodeName}').", TµIOException::BAD_FORMAT);
	}
	/**
	 * Decode the child elements of an XML element as an array.
	 * @param	\DOMElement	$element	The parent element.
	 * @param	\DOMElement[]	$children	The child elements.
	 * @return	array	The decoded array.
	 * @throws	\Temma\Exceptions\IO	If the format is not correct.
	 */
	static private function _decodeXmlArray(\DOMElement $element, array $children) : array {
		$result = [];
		$grouped = [];
		// count the names of the child elements (except "__item")
		$names = [];
		foreach ($children as $child) {
			if ($child->nodeName != self::XML_ITEM)
				$names[$child->nodeName] = ($names[$child->nodeName] ?? 0) + 1;
		}
		// all the child elements have the same name, repeated: the element is a list
		if (count($names) == 1 && count($children) > 1 && reset($names) == count($children)) {
			foreach ($children as $child)
				$result[] = self::_decodeXmlElement($child);
			return ($result);
		}
		// array entries
		foreach ($children as $child) {
			$value = self::_decodeXmlElement($child);
			// "__item" element without key: next automatic index
			if ($child->nodeName == self::XML_ITEM && !$child->hasAttribute(self::XML_KEY)) {
				$result[] = $value;
				continue;
			}
			// repeated element name: the values are grouped in a list
			if ($child->nodeName != self::XML_ITEM && $names[$child->nodeName] > 1) {
				$key = $child->nodeName;
				if (array_key_exists($key, $result) && !isset($grouped[$key]))
					throw new TµIOException("Unable to decode XML stream (duplicate key '$key' in element '{$element->nodeName}').", TµIOException::BAD_FORMAT);
				$grouped[$key] = true;
				$result[$key][] = $value;
				continue;
			}
			// key
			if ($child->nodeName != self::XML_ITEM) {
				$key = $child->nodeName;
			} else {
				$key = $child->getAttribute(self::XML_KEY);
				$keyType = $child->hasAttribute(self::XML_KEY_TYPE) ? $child->getAttribute(self::XML_KEY_TYPE) : null;
				if ($keyType == 'base64')
					$key = base64_decode($key, true);
				if ((!is_null($keyType) && $keyType != 'base64') || $key === false)
					throw new TµIOException("Unable to decode XML stream (bad key in element '{$element->nodeName}').", TµIOException::BAD_FORMAT);
			}
			if (array_key_exists($key, $result))
				throw new TµIOException("Unable to decode XML stream (duplicate key '$key' in element '{$element->nodeName}').", TµIOException::BAD_FORMAT);
			$result[$key] = $value;
		}
		return ($result);
	}
}
