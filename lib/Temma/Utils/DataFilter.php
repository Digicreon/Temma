<?php

/**
 * DataFilter
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2020-2023, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-datafilter
 */

namespace Temma\Utils;

use \Temma\Exceptions\IO as TµIOException;
use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Object used to cleanup data using a contract declaration.
 *
 * Examples of contract:
 * * Pass-through
 *	null
 *
 * * Scalar types
 *	'null'
 *	'false'
 *	'true'
 *	'bool'
 *	'int'
 *	'float'
 *	'string'
 *	'email'
 *	'url'
 *
 * * Nullable types
 *	'?false'
 *	'?true'
 *	'?bool'
 *	'?int'
 *	'?float'
 *	'?string'
 *	'?list'
 *	'?array'
 *
 * * Multiple types
 *	'null|int|string'
 *
 * * Scalar types with a default value
 * ```
 *	[
 *		'type'    => 'string',
 *		'default' => 'abc',
 *	]
 *
 *	[
 *		'type'    => 'bool',
 *		'default' => false,
 *	]
 * ```
 *
 * * Number (int or float) with a min and/or max value
 * ```
 *	[
 *		'type' => 'int',
 *		'min'  => 1,
 *	]
 *
 *	[
 *		'type' => 'float',
 *		'min'  => -8.12,
 *		'max'  => 8.12,
 *	]
 * ```
 *
 * * String with a min length and/or max length, or a regular expression mask
 * ```
 *	[
 *		'type'   => 'string',
 *		'minlen' => 1,
 *		'maxlen' => 12,
 *	]
 *
 *	[
 *		'type' => 'string',
 *		'mask' => '^[Bb][Oo0]..[Oo0].r$',
 *	]
 * ```
 *
 * * Enum type (with an optional default value)
 * ```
 *	[
 *		'type'    => 'enum',
 *		'values'  => ['red', 'green', 'blue'],
 *		'default' => 'red',
 *	]
 * ```
 *
 * * List type with a contract definition used to filter its values as integers
 * ```
 *	[
 *		'type'     => 'list',
 *		'contract' => 'int',
 *	]
 * ```
 *
 * * Associative array with the definition of its keys (some of them with a defined type)
 * ```
 *	[
 *		'type' => 'assoc',
 *		'keys' => [
 *			'id' => 'int',
 *			'name',
 *			'dateCreation',
 *		]
 *	]
 * ```
 *
 * * List type with a contract definition used to defined its values as associative arrays
 * ```
 *	[
 *		'type'     => 'list',
 *		'contract' => [
 *			'type' => 'assoc',
 *			'keys' => ['id', 'name'],
 *		]
 *	]
 * ```
 *
 * * Associative array with keys definition (all of them with a type, one is not mandatory)
 * ```
 *	[
 *		'type' => 'assoc',
 *		'keys' => [
 *			'id'   => int,
 *			'name' => [
 *				'type'      => 'string',
 *				'mandatory' => false,
 *			]
 *		]
 *	]
 * ```
 *
 * * Complex example
 * ```
 *	[
 *		'type' => 'assoc',
 *		'keys' => [
 *			'id'          => 'int',
 *			'isCreated'   => 'bool',
 *			'name'        => [
 *				'type'    => 'string',
 *				'default' => 'abc',
 *			],
 *			'color'       => [
 *				'type'      => 'enum',
 *				'values'    => ['red', 'green', 'blue'],
 *				'default'   => 'red',
 *				'mandatory' => false,
 *			],
 *			'creator'     => [
 *				'type' => 'assoc',
 *				'keys' => [
 *					'id' => 'int',
 *					'name',
 *					'dateCreation',
 *				],
 *			],
 *			'children'    => [
 *				'type'      => 'list',
 *				'mandatory' => false,
 *				'contract'  => [
 *					'type' => 'assoc',
 *					'keys' => [
 *						'id' => 'int',
 *						'name',
 *					]
 *				],
 *			],
 *			'identifiers' => [
 *				'type'     => 'list',
 *				'contract' => 'int',
 *			],
 *		],
 *	 ]
 * ```
 */
class DataFilter {
	/** Constant: list of known types. */
	const SUPPORTED_TYPES = [
		'null', 'false', 'true', 'bool', 'int', 'float', 'string', 'email', 'url', 'enum', 'array', 'list', 'assoc',
		'date', 'time', 'datetime', 'uuid', 'isbn', 'ean',
		'ip', 'ipv4', 'ipv6', 'mac', 'port', 'slug', 'json', 'base64', 'binary', 'color', 'geo', 'phone',
	];

	/**
	 * Cleanup data using a contract.
	 * @param	mixed			$in		Input data.
	 * @param	null|string|array	$contract	The contract. If set to null, the function act as a pass-through.
	 * @param	bool			$strict		(optional) True to force strict type comparison.
	 * @param	bool			$inline		(optional) Set to true if the call was inline (by recursion only).
	 * @return	mixed	The cleaned data.
	 * @throws	\Temma\Exceptions\IO		If the contract is not well formed (BAD_FORMAT).
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static public function process(mixed $in, null|string|array $contract, bool $strict=false, bool $inline=false) : mixed {
		// manage pass-thru
		if ($contract === null || $contract === '')
			return ($in);
		/* *** management of string contract *** */
		if (is_string($contract)) {
			$res = [];
			if (($pos = mb_strpos($contract, ';')) === false) {
				$res['type'] = trim($contract);
			} else {
				$res['type'] = trim(mb_substr($contract, 0, $pos));
				$contract = mb_substr($contract, $pos + 1);
				$len = mb_strlen($contract);
				$labelFound = false;
				$quoted = false;
				$escaped= false;
				$label = $value = '';
				for ($i = 0; $i < $len; $i++) {
					$char = mb_substr($contract, $i, 1);
					if (!$labelFound) {
						if ($char == ':') {
							$labelFound = true;
							$label = trim($label);
							continue;
						}
						$label .= $char;
						continue;
					} else if (!$quoted) {
						if ($char == '"') {
							$quoted = true;
							continue;
						}
						if ($char == ';') {
							$res[$label] = trim($value);
							$label = $value = '';
							$labelFound = false;
							continue;
						}
						$value .= $char;
						continue;
					} else { // quoted
						if ($char == '"') {
							if ($escaped) {
								$value .= '"';
								$escaped = false;
								continue;
							}
							$quoted = false;
							continue;
						}
						if ($char == '\\') {
							if ($escaped) {
								$current .= '\\';
								$escapes = false;
								continue;
							}
							$escaped = true;
							continue;
						}
						$value .= $char;
					}
				}
				$res[$label] = trim($value);
			}
			return (self::process($in, $res/*$contract*/, $strict, true));
		} else if (!is_array($contract)) {
			throw new TµIOException("Bad contract.", TµIOException::BAD_FORMAT);
		}
		/* *** check contract type *** */
		// manage pass-thru
		if (array_key_exists('type', $contract) &&
		    ($contract['type'] === null || $contract['type'] === ''))
			return ($in);
		// check type not empty
		if (!isset($contract['type']) || empty($contract['type']))
			throw new TµIOException("Empty contract type.", TµIOException::BAD_FORMAT);
		// process type as a string, and search for null type
		$contractNullable = false;
		$contractStrict = $strict;
		if (is_string($contract['type'])) {
			$addNull = $hasNull = false;
			// search for forced (un)strictness
			if (str_starts_with($contract['type'], '~')) {
				$contractStrict = false;
				$contract['type'] = mb_substr($contract['type'], 1);
			} else if (str_starts_with($contract['type'], '=')) {
				$contractStrict = true;
				$contract['type'] = mb_substr($contract['type'], 1);
			}
			// search for nullable type
			if (str_starts_with($contract['type'], '?')) {
				$addNull = true;
				$contract['type'] = mb_substr($contract['type'], 1);
			}
			// manage multiple types
			$contract['type'] = explode('|', $contract['type']);
			// loop on types
			$contract['type'] = array_map(function($type) use (&$addNull, &$hasNull) {
				$type = strtolower(trim($type));
				// check type
				if (!in_array($type, self::SUPPORTED_TYPES))
					throw new TµIOException("Bad contract type '$type'.", TµIOException::BAD_FORMAT);
				// manage null type
				if ($type == 'null') {
					$addNull = false;
					$hasNull = true;
				}
				return ($type);
			}, $contract['type']);
			// manage nullable type
			if ($addNull)
				array_unshift($contract['type'], 'null');
			if ($addNull || $hasNull)
				$contractNullable = true;
		} else {
			// search for nullable type
			$contractNullable = in_array('null', $contract['type']);
		}
		/* *** get contract parameters *** */
		$contractType = $contract['type'];
		// default value
		$contractDefault = $contract['default'] ?? null;
		// minimum value
		$contractMin = $contract['min'] ?? null;
		if (isset($contractMin) && !is_scalar($contractMin))
			throw new TµIOException("Bad contract 'min' parameter.", TµIOException::BAD_FORMAT);
		// maximum value
		$contractMax = $contract['max'] ?? null;
		if (isset($contractMax) && !is_scalar($contractMax))
			throw new TµIOException("Bad contract 'max' parameter.", TµIOException::BAD_FORMAT);
		// date format
		$contractFormat = $contract['format'] ?? null;
		$contractInFormat = $contractOutFormat = null;
		if (isset($contractFormat) && !is_string($contractFormat))
			throw new TµIOException("Bad contract 'format' parameter.", TµIOException::BAD_FORMAT);
		// date input format
		$contractInFormat = $contract['inFormat'] ?? $contractFormat;
		if (isset($contractInFormat) && !is_string($contractInFormat))
			throw new TµIOException("Bad contract 'inFormat' parameter.", TµIOException::BAD_FORMAT);
		// date output format
		$contractOutFormat = $contract['outFormat'] ?? $contractFormat;
		if (isset($contractOutFormat) && !is_string($contractOutFormat))
			throw new TµIOException("Bad contract 'outFormat' parameter.", TµIOException::BAD_FORMAT);
		// minimum length
		$contractMinLen = null;
		if (isset($contract['minLen']))
			$contractMinLen = self::_parseLength($contract['minLen']);
		// maximum length
		$contractMaxLen = null;
		if (isset($contract['maxLen']))
			$contractMaxLen = self::_parseLength($contract['maxLen']);
		// mask
		$contractMask = null;
		if (isset($contract['mask'])) {
			if (!is_string($contract['mask']))
				throw new TµIOException("Bad contract 'mask' parameter.", TµIOException::BAD_FORMAT);
			$contractMask = $contract['mask'];
		}
		// keys
		$contractKeys = null;
		if (isset($contract['keys'])) {
			if (is_string($contract['keys']))
				$contractKeys = array_map('trim', explode(',', $contract['keys']));
			else if (is_array($contract['keys']))
				$contractKeys = $contract['keys'];
			else
				throw new TµIOException("Bad contract 'keys' parameter.", TµIOException::BAD_FORMAT);
		}
		// enumeration values
		$contractValues = null;
		if (isset($contract['values'])) {
			if (is_string($contract['values']))
				$contractValues = array_map('trim', explode(',', $contract['values']));
			else if (is_array($contract['values']))
				$contractValues = $contract['values'];
			else
				throw new TµIOException("Bad contract 'values' parameter.", TµIOException::BAD_FORMAT);
		}
		// sub-contract
		$contractSubcontract = $contract['contract'] ?? null;
		if (!is_null($contractSubcontract) && !is_string($contractSubcontract) && !is_array($contractSubcontract))
			throw new TµIOException("Bad sub-contract type.", TµIOException::BAD_FORMAT);
		// mime
		$contractMime = null;
		if (isset($contract['mime'])) {
			if (is_string($contract['mime']))
				$contractMime = array_map('trim', explode(',', $contract['mime']));
			else if (is_array($contract['mime']))
				$contractMime = $contract['mime'];
			else
				throw new TµIOException("Bad contract 'mime' parameter.", TµIOException::BAD_FORMAT);
		}
		// loop on types
		$lastException = null;
		foreach ($contract['type'] as $contractType) {
			try {
				switch ($contractType) {
					case 'null':
						return (self::_processNull($in));
					case 'false':
						return (self::_processFalse($in, $inline, $contractStrict, $contractDefault));
					case 'true':
						return (self::_processTrue($in, $inline, $contractStrict, $contractDefault));
					case 'bool':
						return (self::_processBool($in, $inline, $contractStrict, $contractDefault));
					case 'int':
						return (self::_processInt($in, $inline, $contractStrict, $contractDefault, $contractMin, $contractMax));
					case 'float':
						return (self::_processFloat($in, $inline, $contractStrict, $contractDefault, $contractMin, $contractMax));
					case 'string':
						return (self::_processString($in, $contractStrict, $contractDefault, $contractMinLen, $contractMaxLen, $contractMask));
					case 'email':
						return (self::_processEmail($in, $contractDefault, $contractMask));
					case 'url':
						return (self::_processUrl($in, $contractDefault, $contractMinLen, $contractMaxLen, $contractMask));
					case 'enum':
						if (!$contractValues)
							throw new TµIOException("Enum without values.", TµIOException::BAD_FORMAT);
						return (self::_processEnum($in, $contractDefault, $contractValues));
					case 'array':
					case 'list':
						return (self::_processList($in, $contractStrict, $contractDefault, $contractMinLen, $contractMaxLen, $contractSubcontract));
					case 'assoc':
						if (!$contractKeys)
							throw new TµIOException("Associative array without sub-keys contract.", TµIOException::BAD_FORMAT);
						return (self::_processAssoc($in, $contractStrict, $contractDefault, $contractKeys));
					case 'date':
						$contractInFormat = $contractInFormat ?? 'Y-m-d';
						$contractOutFormat = $contractOutFormat ?? 'Y-m-d';
						return (self::_processDate($in, $contractStrict, $contractInFormat, $contractOutFormat, $contractDefault, $contractMin, $contractMax));
					case 'time':
						$contractInFormat = $contractInFormat ?? 'H:i:s';
						$contractOutFormat = $contractOutFormat ?? 'H:i:s';
						return (self::_processTime($in, $contractStrict, $contractInFormat, $contractOutFormat, $contractDefault, $contractMin, $contractMax));
					case 'datetime':
						$contractInFormat = $contractInFormat ?? 'Y-m-d H:i:s';
						$contractOutFormat = $contractOutFormat ?? 'Y-m-d H:i:s';
						return (self::_processDateTime($in, $contractStrict, $contractInFormat, $contractOutFormat, $contractDefault, $contractMin, $contractMax));
					case 'uuid':
						return (self::_processUuid($in, $contractDefault));
					case 'isbn':
						return (self::_processIsbn($in, $contractDefault));
					case 'ean':
						return (self::_processEan($in, $contractDefault));
					case 'ip':
						return (self::_processIp($in, $contractDefault));
					case 'ipv4':
						return (self::_processIpv4($in, $contractDefault));
					case 'ipv6':
						return (self::_processIpv6($in, $contractDefault));
					case 'mac':
						return (self::_processMac($in, $contractDefault));
					case 'port':
						return (self::_processPort($in, $contractStrict, $contractDefault, $contractMin, $contractMax));
					case 'slug':
						return (self::_processSlug($in, $contractStrict, $contractDefault));
					case 'json':
						return (self::_processJson($in, $contractStrict, $contractDefault, $contractSubcontract, $contractMinLen, $contractMaxLen));
					case 'base64':
						return (self::_processBase64($in, $contractStrict, $contractDefault, $contractMime, $contractMinLen, $contractMaxLen));
					case 'binary':
						return (self::_processBinary($in, $contractStrict, $contractDefault, $contractMime, $contractMinLen, $contractMaxLen));
					case 'color':
						return (self::_processColor($in, $contractDefault));
					case 'geo':
						return (self::_processGeo($in, $contractStrict, $contractDefault, $contractMin, $contractMax));
					case 'phone':
						return (self::_processPhone($in, $contractStrict, $contractDefault));
					default:
						throw new TµIOException("Incorrect type '$contractType'.", TµIOException::BAD_FORMAT);
				}
			} catch (TµIOException $ie) {
				// ill-formed contract
				throw $ie;
			} catch (TµApplicationException $ae) {
				$lastException = $ae;
			}
		}
		throw new TµApplicationException("Data doesn't validate the contract.", TµApplicationException::API);
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Parse a length string (e.g. '10M', '5K').
	 * @param	mixed	$size	The size string.
	 * @return	int	The parsed size in bytes.
	 */
	static private function _parseLength(mixed $len) : int {
		if (is_int($len) || ctype_digit($len))
			return ($len);
		if (!is_string($len))
			throw new TµIOException("Bad contract 'minLen/maxLen' parameter.", TµIOException::BAD_FORMAT);
		$len = trim($len);
		$last = mb_strtolower(mb_substr($len, -1));
		$len = mb_substr($len, 0, -1);
		if (!ctype_digit($len))
			throw new TµIOException("Bad contract 'minLen/maxLen' parameter.", TµIOException::BAD_FORMAT);
		switch ($last) {
			case 'k': $len *= 1024; break;
			case 'm': $len *= 1024 * 1024; break;
			case 'g': $len *= 1024 * 1024 * 1024; break;
			case 't': $len *= 1024 * 1024 * 1024 * 1024; break;
			case 'p': $len *= 1024 * 1024 * 1024 * 1024 * 1024; break;
			case 'e': $len *= 1024 * 1024 * 1024 * 1024 * 1024 * 1024; break;
			default: throw new TµIOException("Bad contract 'minLen/maxLen' parameter.", TµIOException::BAD_FORMAT);
		}
		return ($len);
	}

	/**
	 * Process a null value.
	 * @param	mixed	$in		Input value.
	 * @return	null	Always null.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processNull(mixed $in) : null {
		if ($in !== null)
			throw new TµApplicationException("Data is not null.", TµApplicationException::API);
		return (null);
	}
	/**
	 * Process a false value.
	 * @param	mixed	$in		Input value.
	 * @param	bool	$inline		Inline contract.
	 * @param	bool	$strict		Strictness.
	 * @param	mixed	$default	(optional) Default value.
	 * @return	bool	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processFalse(mixed $in, bool $inline, bool $strict, mixed $default=null) : bool {
		if (($strict && $in === false) || (!$strict && !$in))
			return (false);
		$in = ($inline && $default === 'false') ? false : $default;
		if (($strict && $in === false) || (!$strict && !$in))
			return (false);
		throw new TµApplicationException("Data is not false.", TµApplicationException::API);
	}
	/**
	 * Process a true value.
	 * @param	mixed	$in		Input value.
	 * @param	bool	$inline		Inline contract.
	 * @param	bool	$strict		Strictness.
	 * @param	mixed	$default	(optional) Default value.
	 * @return	bool	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processTrue(mixed $in, bool $inline, bool $strict, mixed $default=null) : bool {
		if (($strict && $in === true) || (!$strict && $in))
			return (true);
		$in = ($inline && $default === 'true') ? true : $default;
		if (($strict && $in === true) || (!$strict && $in))
			return (true);
		throw new TµApplicationException("Data is not true.", TµApplicationException::API);
	}
	/**
	 * Process a boolean type.
	 * @param	mixed	$in		Input value.
	 * @param	bool	$inline		Inline contract.
	 * @param	bool	$strict		Strictness.
	 * @param	mixed	$default	(optional) Default value.
	 * @return	bool	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processBool(mixed $in, bool $inline, bool $strict, mixed $default=null) : bool {
		if (!$strict)
			return ((bool)$in);
		if (!is_bool($in)) {
			if ($inline && $default === 'true')
				$in = true;
			else if ($inline && $default ==='false')
				$in = false;
			else
				$in = $default;
		}
		if ($in === true || $in === false)
			return ($in);
		throw new TµApplicationException("Data is not boolean.", TµApplicationException::API);
	}
	/**
	 * Process an integer type.
	 * @param	mixed			$in		Input value.
	 * @param	bool			$inline		Inline contract.
	 * @param	bool			$strict		Strictness.
	 * @param	mixed			$default	(optional) Default value.
	 * @param	null|int|float|string	$min		(optional) Minimum value.
	 * @param	null|int|float|string	$max		(optional) Maximum value.
	 * @return	int	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processInt(mixed $in, bool $inline, bool $strict, mixed $default=null, null|int|float|string $min=null, null|int|float|string $max=null) : int {
		try {
			// converts booleans and floats
			if (!$strict) {
				if (is_bool($in))
					$in = $in ? 1 : 0;
				else if (is_float($in))
					$in = (int)$in;
			}
			// manage inline contracts
			if ($inline && is_numeric($default))
				$default = (int)$default;
			// manage integer input
			if (is_int($in)) {
				if ($strict) {
					if ((is_numeric($min) && $in < $min) ||
					    (is_numeric($max) && $in > $max))
						throw new TµApplicationException("Data doesn't respect contract (out of range integer).", TµApplicationException::API);
				} else {
					if (is_numeric($min))
						$in = max($in, (int)$min);
					if (is_numeric($max))
						$in = min($in, (int)$max);
				}
				return ($in);
			}
			// strict mode and not an integer: try the default value
			if ($strict) {
				$in = $default;
				if (!is_int($in))
					throw new TµApplicationException("Data doesn't respect contract (can't cast to int).", TµApplicationException::API);
			}
			// converts string input
			$options = [
				'options' => [],
				'flags'   => FILTER_FLAG_ALLOW_OCTAL | FILTER_FLAG_ALLOW_HEX,
			];
			if (is_numeric($default))
				$options['options']['default'] = $default;
			if ($strict) {
				if (is_numeric($min))
					$options['options']['min_range'] = $min;
				if (is_numeric($max))
					$options['options']['max_range'] = $max;
			}
			if (($in2 = filter_var($in, FILTER_VALIDATE_INT, $options)) === false) {
				if (($in2 = filter_var($in, FILTER_VALIDATE_FLOAT)) === false)
					throw new TµApplicationException("Data doesn't respect contract (can't cast to int).", TµApplicationException::API);
				$in2 = (int)$in2;
			}
			$in = $in2;
			if (!$strict) {
				if (is_numeric($min))
					$in = max($in, (int)$min);
				if (is_numeric($max))
					$in = min($in, (int)$max);
			}
		} catch (TµApplicationException $e) {
			if ($default !== null)
				return (self::_processInt($default, $inline, $strict, null, $min, $max));
			throw $e;
		}
		return ($in);
	}
	/**
	 * Process a float type.
	 * @param	mixed			$in		Input value.
	 * @param	bool			$inline		Inline contract.
	 * @param	bool			$strict		Strictness.
	 * @param	mixed			$default	(optional) Default value.
	 * @param	null|int|float|string	$min		(optional) Minimum value.
	 * @param	null|int|float|string	$max		(optional) Maximum value.
	 * @return	float	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processFloat(mixed $in, bool $inline, bool $strict, mixed $default=null, null|int|float|string $min=null, null|int|float|string $max=null) : float {
		try {
			// converts booleans and integers
			if (!$strict && is_bool($in))
				$in = $in ? 1.0 : 0.0;
			if (is_int($in))
				$in = (float)$in;
			// manage inline contract
			if ($inline && is_numeric($default))
				$default = (float)$default;
			// manage float input
			if (is_float($in)) {
				if ($strict) {
					if ((is_numeric($min) && $in < $min) ||
					    (is_numeric($max) && $in > $max))
						throw new TµApplicationException("Data doesn't respect contract (out of range float).", TµApplicationException::API);
				} else {
					if (is_numeric($min))
						$in = max($in, (float)$min);
					if (is_numeric($max))
						$in = min($in, (float)$max);
				}
				return ($in);
			}
			// strict mode and not a float: try the default value
			if ($strict) {
				$in = $default;
				if (!is_float($in))
					throw new TµApplicationException("Data doesn't respect contract (can't cast to float).", TµApplicationException::API);
			}
			// converts string input
			$options = [
				'options' => [],
				'flags'   => FILTER_FLAG_ALLOW_THOUSAND,
			];
			if (is_numeric($default))
				$options['options']['default'] = $default;
			if ($strict) {
				if (is_numeric($min))
					$options['options']['min_range'] = $min;
				if (is_numeric($max))
					$options['options']['max_range'] = $max;
			}
			if (($in = filter_var($in, FILTER_VALIDATE_FLOAT, $options)) === false)
				throw new TµApplicationException("Data doesn't respect contract (can't cast to float).", TµApplicationException::API);
			if (!$strict) {
				if (is_numeric($min))
					$in = max($in, (float)$min);
				if (is_numeric($max))
					$in = min($in, (float)$max);
			}
		} catch (TµApplicationException $e) {
			if (is_null($default))
				throw $e;
			return (self::_processFloat($default, $strict, null, $min, $max));
		}
		return ($in);
	}
	/**
	 * Process a string type.
	 * @param	mixed			$in		Input value.
	 * @param	bool			$strict		Strictness.
	 * @param	mixed			$default	(optional) Default value.
	 * @param	null|int|float|string	$minLen		(optional) Minimum length.
	 * @param	null|int|float|string	$maxLen		(optional) Maximum length.
	 * @param	?string			$mask		(optional) Regex mask.
	 * @return	string	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processString(mixed $in, bool $strict, mixed $default=null, null|int|float|string $minLen=null, null|int|float|string $maxLen=null, ?string $mask=null) : string {
		try {
			$len = is_string($in) ? mb_strlen($in) : 0;
			if ($strict) {
				if (!is_string($in))
					throw new TµApplicationException("Data doesn't respect contract (not a string).", TµApplicationException::API);
				if (is_numeric($maxLen) && $len > $maxLen)
					throw new TµApplicationException("Data doesn't respect contract (string too long).", TµApplicationException::API);
			} else {
				if (!is_scalar($in))
					throw new TµApplicationException("Data doesn't respect contract (can't cast to string).", TµApplicationException::API);
				if (is_bool($in))
					$in = $in ? 'true' : 'false';
				else
					$in = (string)$in;
				if (is_numeric($maxLen))
					$in = mb_substr($in, 0, $maxLen);
			}
			if (is_numeric($minLen) && $len < $minLen)
				throw new TµApplicationException("Data doesn't respect contract (string too short).", TµApplicationException::API);
			if ($mask && !preg_match('{' . $mask . '}u', $in, $matches))
				throw new TµApplicationException("Data doesn't respect contract (string doesn't match the given mask).", TµApplicationException::API);
		} catch (TµApplicationException $e) {
			if ($default !== null)
				return (self::_processString($default, $strict, null, $minLen, $maxLen, $mask));
			throw $e;
		}
		return ($in);
	}
	/**
	 * Process an email type.
	 * @param	mixed		$in		Input value.
	 * @param	?string		$default	(optional) Default value.
	 * @param	?string		$mask		(optional) Regex mask.
	 * @return	string	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processEmail(mixed $in, ?string $default=null, ?string $mask=null) : string {
		$options = [
			'options' => [],
		];
		if (is_string($default))
			$options['options']['default'] = $default;
		if (($in = filter_var($in, FILTER_VALIDATE_EMAIL, $options)) === false) {
			throw new TµApplicationException("Data is not a valid email address.", TµApplicationException::API);
		}
		if ($mask && !preg_match('{' . $mask . '}u', $in, $matches)) {
			if ($default !== null)
				return (self::_processEmail($default, null, $mask));
			throw new TµApplicationException("Data doesn't respect contract (email doesn't match the given mask).", TµApplicationException::API);
		}
		return ($in);
	}
	/**
	 * Process an URL type.
	 * @param	mixed			$in		Input value.
	 * @param	?string			$default	(optional) Default value.
	 * @param	null|int|float|string	$minLen		(optional) Minimum length.
	 * @param	null|int|float|string	$maxLen		(optional) Maximum length.
	 * @param	string			$mask		(optional) Regex mask.
	 * @return	string	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processUrl(mixed $in, ?string $default=null, null|int|float|string $minLen=null, null|int|float|string $maxLen=null, ?string $mask=null) : string {
		$options = [
			'options' => [],
		];
		if (is_numeric($default))
			$options['options']['default'] = $default;
		if (is_numeric($maxLen))
			$in = mb_substr($in, 0, $maxLen);
		if (($in = filter_var($in, FILTER_VALIDATE_URL, $options)) === false) {
			throw new TµApplicationException("Data is not a valid URL.", TµApplicationException::API);
		}
		if (is_numeric($minLen) && mb_strlen($in) < $minLen) {
			if ($default !== null)
				return (self::_processUrl($default, null, $minLen, $maxLen, $mask));
			throw new TµApplicationException("Data doesn't respect contract (URL too short).", TµApplicationException::API);
		}
		if ($mask && !preg_match('{' . $mask . '}u', $in, $matches)) {
			if ($default !== null)
				return (self::_processUrl($default, null, $minLen, $maxLen, $mask));
			throw new TµApplicationException("Data doesn't respect contract (URL doesn't match the given mask).", TµApplicationException::API);
		}
		return ($in);
	}
	/**
	 * Process an enumeration type.
	 * @param	mixed	$in		Input value.
	 * @param	?string	$default	(optional) Default value.
	 * @param	array	$values		(optional) Possible values.
	 * @return	string	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processEnum(mixed $in, ?string $default=null, array $values=null) : string {
		if (in_array($in, $values))
			return ($in);
		if ($default !== null)
			return (self::_processEnum($default, null, $values));
		throw new TµApplicationException("Data doesn't respect contract (bad enum value '$in').", TµApplicationException::API);
	}
	/**
	 * Process a list type.
	 * @param	mixed			$in		Input value.
	 * @param	bool			$strict		Strictness.
	 * @param	mixed			$default	Default value.
	 * @param	?int			$minLen		(optional) Minimum length of the list.
	 * @param	?int			$maxLen		(optional) Maximum length of the list.
	 * @param	null|string|array	$subcontract	Sub-contract.
	 * @return	array	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processList(mixed $in, bool $strict, mixed $default, ?int $minLen=null, ?int $maxLen=null, null|string|array $subcontract=null) : array {
		if (!is_array($in)) {
			if ($default !== null)
				return (self::_processList($default, $strict, null, $minLen, $maxLen, $subcontract));
			throw new TµApplicationException("Data doesn't respect contract (not a list).", TµApplicationException::API);
		}
		// check size
		if ($minLen || $maxLen) {
			$count = count($in);
			if (($minLen && $count < $minLen) || ($maxLen && $count > $maxLen)) {
				if ($default !== null)
					return (self::_processList($default, $strict, null, $minLen, $maxLen, $subcontract));
				throw new TµApplicationException("Data size doesn't respect the contract.", TµApplicationException::API);
			}
		}
		if ($subcontract === null)
			return ($in);
		foreach ($in as $k => &$v) {
			if (!is_int($k))
				throw new TµApplicationException("Data doesn't respect contract (not a list).", TµApplicationException::API);
			$v = self::process($v, $subcontract, $strict);
		}
		return ($in);
	}
	/**
	 * Process an associative array type.
	 * @param	mixed	$in		Input value.
	 * @param	bool	$strict		Strictness.
	 * @param	mixed	$default	Default value.
	 * @param	array	$contractKeys	Sub keys.
	 * @return	array	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processAssoc(mixed $in, bool $strict, mixed $default, array $contractKeys) : array {
		if (!is_array($in)) {
			if ($default !== null)
				return (self::_processAssoc($default, $strict, null, $contractKeys));
			throw new TµApplicationException("Data doesn't respect contract (not an array).", TµApplicationException::API);
		}
		$out = [];
		$foundWildcard = false;
		foreach ($contractKeys as $k => $v) {
			$key = null;
			$subcontract = [
				'type'      => null,
				'mandatory' => true,
			];
			$subcontract = null;
			if (is_int($k)) {
				$key = $v;
			} else {
				$key = $k;
				$subcontract = $v;
			}
			if ($key === '...' || $key === '…') {
				$foundWildcard = true;
				continue;
			}
			if (str_ends_with($key, '?')) {
				if (is_array($subcontract)) {
					$subcontract['mandatory'] = false;
				} else {
					$subcontract = [
						'type'      => $subcontract,
						'mandatory' => false,
					];
				}
				$key = mb_substr($key, 0, -1);
			}
			if (!array_key_exists($key, $in)) {
				if (($subcontract['mandatory'] ?? true) === false)
					continue;
				throw new TµApplicationException("Data doesn't respect contract (mandatory key '$key').", TµApplicationException::API);
			}
			$res = self::process(($in[$key] ?? null), $subcontract, $strict);
			$out[$key] = $res;
		}
		// manage "..." (wildcard)
		if ($foundWildcard) {
			// copy all extra keys
			$extra = array_diff_key($in, $out);
			foreach ($extra as $k => $v) {
				$out[$k] = $v;
			}
		} else if ($strict) {
			// check for extra keys
			$extra = array_diff_key($in, $out);
			if (!empty($extra)) {
				$extraStr = implode(', ', array_keys($extra));
				throw new TµApplicationException("Data doesn't respect contract (extra keys '$extraStr').", TµApplicationException::API);
			}
		}
		return ($out);
	}
	/**
	 * Process a date type.
	 * @param	mixed	$in		Input value.
	 * @param	bool	$strict		Strictness.
	 * @param	string	$inFormat	Input format.
	 * @param	string	$outFormat	Output format.
	 * @param	?string	$default	(optional) Default value.
	 * @param	?string	$min		(optional) Minimum value.
	 * @param	?string	$max		(optional) Maximum value.
	 * @return	string	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processDate(mixed $in, bool $strict, string $inFormat, string $outFormat, ?string $default=null, ?string $min=null, ?string $max=null) : string {
		return (self::_processDateTime($in, $strict, $inFormat, $outFormat, $default, $min, $max));
	}
	/**
	 * Process a time type.
	 * @param	mixed	$in		Input value.
	 * @param	bool	$strict		Strictness.
	 * @param	string	$inFormat	Input format.
	 * @param	string	$outFormat	Output format.
	 * @param	?string	$default	(optional) Default value.
	 * @param	?string	$min		(optional) Minimum value.
	 * @param	?string	$max		(optional) Maximum value.
	 * @return	string	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processTime(mixed $in, bool $strict, string $inFormat, string $outFormat, ?string $default=null, ?string $min=null, ?string $max=null) : string {
		return (self::_processDateTime($in, $strict, $inFormat, $outFormat, $default, $min, $max));
	}
	/**
	 * Process a datetime type.
	 * @param	mixed	$in		Input value.
	 * @param	bool	$strict		Strictness.
	 * @param	string	$inFormat	Input format.
	 * @param	string	$outFormat	Output format.
	 * @param	?string	$default	(optional) Default value.
	 * @param	?string	$min		(optional) Minimum value.
	 * @param	?string	$max		(optional) Maximum value.
	 * @return	string	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processDateTime(mixed $in, bool $strict, string $inFormat, string $outFormat, ?string $default=null, ?string $min=null, ?string $max=null) : string {
		// manage input value
		$d = false;
		if (is_int($in) || is_float($in) || is_numeric($in)) {
			$d = \DateTimeImmutable::createFromFormat('U', $in);
		} else if (is_string($in)) {
			$d = \DateTimeImmutable::createFromFormat($inFormat, $in);
			if ($strict && $d->format($inFormat) != $in)
				throw new TµApplicationException("Data is not a valid date/time, or bad input format.", TµApplicationException::API);
		}
		if ($d === false) {
			// manage default value
			if ($default === null)
				throw new TµApplicationException("Data is not a valid date/time, or bad input format.", TµApplicationException::API);
			if (is_int($default) || is_float($default) || is_numeric($default))
				$d = new \DateTimeImmutable('U', $default);
			else
				$d = \DateTimeImmutable::createFromFormat($inFormat, $default);
		}
		if ($min) {
			if (($dMin = \DateTimeImmutable::createFromFormat($inFormat, $min)) === false)
				throw new TµApplicationException("Min value is not a valid date/time, or bad input format.", TµApplicationException::API);
			if ($d < $dMin) {
				if (!$strict)
					$d = $dMin;
				else if ($default !== null)
					return (self::_processDateTime($default, $strict, $inFormat, $outFormat, null, $min, $max));
				else
					throw new TµApplicationException("Data doesn't respect contract (date/time too early).", TµApplicationException::API);
			}
		}
		if ($max) {
			if (($dMax = \DateTimeImmutable::createFromFormat($inFormat, $max)) === false)
				throw new TµApplicationException("Max value is not a valid date/time, or bad input format.", TµApplicationException::API);
			if ($d > $dMax) {
				if (!$strict)
					$d = $dMax;
				else if ($default !== null)
					return (self::_processDateTime($default, $strict, $inFormat, $outFormat, null, $min, $max));
				else
					throw new TµApplicationException("Data doesn't respect contract (date/time too late).", TµApplicationException::API);
			}
		}
		return ($d->format($outFormat));
	}
	/**
	 * Process an UUID type.
	 * @param	mixed	$in		Input value.
	 * @param	?string	$default	(optional) Default value.
	 * @return	string	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processUuid(mixed $in, ?string $default=null) : string {
		if (is_string($in) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $in))
			return strtolower($in);
		if ($default !== null && is_string($default) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $default))
			return (self::_processUuid($default));
		throw new TµApplicationException("Data is not a valid UUID.", TµApplicationException::API);
	}
	/**
	 * Process an ISBN type.
	 * @param	mixed	$in		Input value.
	 * @param	?string	$default	(optional) Default value.
	 * @return	string	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processIsbn(mixed $in, ?string $default=null) : string {
		if (!is_string($in)) {
			if ($default !== null)
				return (self::_processIsbn($default));
			throw new TµApplicationException("Data is not a valid ISBN.", TµApplicationException::API);
		}
		$in = preg_replace('/[^0-9X]/', '', strtoupper($in));
		$len = mb_strlen($in);
		if ($len != 10 && $len != 13) {
			if ($default !== null)
				return (self::_processIsbn($default));
			throw new TµApplicationException("Data is not a valid ISBN.", TµApplicationException::API);
		}
		if ($len == 10) {
			$sum = 0;
			for ($i = 0; $i < 9; $i++)
				$sum += (int)mb_substr($in, $i, 1) * (10 - $i);
			$check = (11 - ($sum % 11)) % 11;
			$check = ($check == 10) ? 'X' : (string)$check;
			if (mb_substr($in, 9, 1) != $check) {
				if ($default !== null)
					return (self::_processIsbn($default));
				throw new TµApplicationException("Data is not a valid ISBN-10.", TµApplicationException::API);
			}
		} else {
			$sum = 0;
			for ($i = 0; $i < 12; $i++)
				$sum += (int)mb_substr($in, $i, 1) * (($i % 2 == 0) ? 1 : 3);
			$check = (10 - ($sum % 10)) % 10;
			if (mb_substr($in, 12, 1) != $check) {
				if ($default !== null)
					return (self::_processIsbn($default));
				throw new TµApplicationException("Data is not a valid ISBN-13.", TµApplicationException::API);
			}
		}
		return ($in);
	}
	/**
	 * Process an EAN type.
	 * @param	mixed	$in		Input value.
	 * @param	?string	$default	(optional) Default value.
	 * @return	string	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processEan(mixed $in, ?string $default=null) : string {
		if (!is_string($in) && !is_int($in)) {
			if ($default !== null)
				return (self::_processEan($default));
			throw new TµApplicationException("Data is not a valid EAN.", TµApplicationException::API);
		}
		$in = preg_replace('/[^0-9]/', '', (string)$in);
		$len = strlen($in);
		if ($len != 8 && $len != 13) {
			if ($default !== null)
				return (self::_processEan($default));
			throw new TµApplicationException("Data is not a valid EAN.", TµApplicationException::API);
		}
		$sum = 0;
		for ($i = 0; $i < $len - 1; $i++)
			$sum += (int)mb_substr($in, $i, 1) * (($i % 2 == ($len == 13 ? 1 : 0)) ? 3 : 1);
		$check = (10 - ($sum % 10)) % 10;
		if (mb_substr($in, ($len - 1), 1) != $check) {
			if ($default !== null)
				return (self::_processEan($default));
			throw new TµApplicationException("Data is not a valid EAN.", TµApplicationException::API);
		}
		return ($in);
	}
	/**
	 * Process an IP type.
	 * @param	mixed	$in		Input value.
	 * @param	?string	$default	(optional) Default value.
	 * @return	string	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processIp(mixed $in, ?string $default=null) : string {
		if (is_string($in) && filter_var($in, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6))
			return ($in);
		if ($default !== null)
			return (self::_processIp($default));
		throw new TµApplicationException("Data is not a valid IP address.", TµApplicationException::API);
	}
	/**
	 * Process an IPv4 type.
	 * @param	mixed	$in		Input value.
	 * @param	?string	$default	(optional) Default value.
	 * @return	string	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processIpv4(mixed $in, ?string $default=null) : string {
		if (is_string($in) && filter_var($in, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
			return ($in);
		if ($default !== null)
			return (self::_processIpv4($default));
		throw new TµApplicationException("Data is not a valid IPv4 address.", TµApplicationException::API);
	}
	/**
	 * Process an IPv6 type.
	 * @param	mixed	$in		Input value.
	 * @param	?string	$default	(optional) Default value.
	 * @return	string	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processIpv6(mixed $in, ?string $default=null) : string {
		if (is_string($in) && filter_var($in, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))
		return ($in);
		if ($default !== null)
			return (self::_processIpv6($default));
		throw new TµApplicationException("Data is not a valid IPv6 address.", TµApplicationException::API);
	}
	/**
	 * Process a MAC address type.
	 * @param	mixed	$in		Input value.
	 * @param	?string	$default	(optional) Default value.
	 * @return	string	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processMac(mixed $in, ?string $default=null) : string {
		if (is_string($in) && filter_var($in, FILTER_VALIDATE_MAC))
			return ($in);
		if ($default !== null)
			return (self::_processMac($default));
		throw new TµApplicationException("Data is not a valid MAC address.", TµApplicationException::API);
	}
	/**
	 * Process a port type.
	 * @param	mixed	$in		Input value.
	 * @param	bool	$strict		Strictness.
	 * @param	?int	$default	(optional) Default value.
	 * @param	?int	$min		(optional) Minimum value.
	 * @param	?int	$max		(optional) Maximum value.
	 * @return	int	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processPort(mixed $in, bool $strict, ?int $default=null, ?int $min=null, ?int $max=null) : int {
		$min ??= 1;
		$max = min(($max ?? 65535), 65535);
		return (self::_processInt($in, $strict, $default, $min, $max));
	}
	/**
	 * Process a slug type.
	 * @param	mixed	$in		Input value.
	 * @param	bool	$strict		Strictness.
	 * @param	?string	$default	(optional) Default value.
	 * @return	string	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processSlug(mixed $in, bool $strict, ?string $default=null) : string {
		if (!is_string($in) || !$in) {
			if ($default !== null)
				return (self::_processSlug($default, $strict));
			throw new TµApplicationException("Data is not a valid slug.", TµApplicationException::API);
		}
		$slug = \Temma\Utils\Text::urlize($in);
		if ($strict && $in != $slug) {
			if ($default !== null)
				return (self::_processSlug($default, $strict));
			throw new TµApplicationException("Data is not a valid slug.", TµApplicationException::API);
		}
		return ($slug);
	}
	/**
	 * Process a JSON string type.
	 * @param	mixed			$in		Input value.
	 * @param	bool			$strict		Strictness.
	 * @param	?string			$default	(optional) Default value.
	 * @param	null|string|array	$contract	(optional) DataFilter contract.
	 * @param	?int			$minLen		(optional) Minimum length of the JSON string.
	 * @param	?int			$maxLen		(optional) Maximum length of the JSON string.
	 * @return	mixed			The decoded object/array.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processJson(mixed $in, bool $strict, ?string $default=null, null|string|array $contract=null, ?int $minLen=null, ?int $maxLen=null) : mixed {
		if (!is_string($in) || !$in) {
			if ($default !== null)
				return (self::_processJson($default, $strict, null, $contract, $minLen, $maxLen));
			throw new TµApplicationException("Data is not a valid JSON string.", TµApplicationException::API);
		}
		// check size
		if ($minLen || $maxLen) {
			$len = mb_strlen($in, 'ascii');
			if (($minLen && $len < $minLen) || ($maxLen && $len > $maxLen)) {
				if ($default !== null)
					return (self::_processJson($default, $strict, null, $contract, $minLen, $maxLen));
				throw new TµApplicationException("JSON data size doesn't respect minLen/maxLen.", TµApplicationException::API);
			}
		}
		// decode
		$data = json_decode($in, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			if ($default !== null)
				return (self::_processJson($default, $strict, null, $contract, $minLen, $maxLen));
			throw new TµApplicationException("Data is not a valid JSON string.", TµApplicationException::API);
		}
		if (!$contract)
			return ($data);
		// validate the JSON content using the given contract
		try {
			return (self::process($data, $contract, $strict));
		} catch (TµApplicationException $e) {
			if ($default !== null)
				return (self::_processJson($default, $strict, null, $contract, $minLen, $maxLen));
			throw $e;
		}
	}
	/**
	 * Process a base64 string type.
	 * @param	mixed	$in		Input value.
	 * @param	bool	$strict		Strictness.
	 * @param	?string	$default	(optional) Default value.
	 * @param	?array	$mime		(optional) List of allowed MIME types.
	 * @param	?int	$minLen		(optional) Minimum length.
	 * @param	?int	$maxLen		(optional) Maximum length.
	 * @return	string	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processBase64(mixed $in, bool $strict, ?string $default=null, ?array $mime=null, ?int $minLen=null, ?int $maxLen=null) : string {
		if (!is_string($in) || !$in || ($decoded = base64_decode($in, true)) === false) {
			if ($default !== null)
				return (self::_processBase64($default, $strict, null, $mime, $minLen, $maxLen));
			throw new TµApplicationException("Data is not a valid base64 string.", TµApplicationException::API);
		}
		// check size
		if ($minLen || $maxLen) {
			$len = mb_strlen($decoded, 'ascii');
			if (($minLen && $len < $minLen) || ($maxLen && $len > $maxLen)) {
				if ($default !== null)
					return (self::_processBase64($default, $strict, null, $mime, $minLen, $maxLen));
				throw new TµApplicationException("Data size doesn't respect the contract.", TµApplicationException::API);
			}
		}
		// strict check: re-encode and compare
		if ($strict && base64_encode($decoded) !== $in) {
			if ($default !== null)
				return (self::_processBase64($default, $strict, null, $mime, $minLen, $maxLen));
			throw new TµApplicationException("Data is not a valid base64 string.", TµApplicationException::API);
		}
		// check MIME type
		if ($mime) {
			try {
				return (self::_processBinary($decoded, $strict, null, $mime, null, null));
			} catch (TµApplicationException $e) {
				if ($default !== null)
					return (self::_processBase64($default, $strict, null, $mime, $minLen, $maxLen));
				throw $e;
			}
		}
		return ($decoded);
	}
	/**
	 * Process a binary type.
	 * @param	mixed	$in		Input value.
	 * @param	bool	$strict		Strictness.
	 * @param	?string	$default	Default value.
	 * @param	?array	$mime		(optional)List of allowed MIME types.
	 * @param	?int	$minLen		(optional) Minimum length.
	 * @param	?int	$maxLen		(optional) Maximum length.
	 * @return	string	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processBinary(mixed $in, bool $strict, ?string $default=null, ?array $mime=null, ?int $minLen=null, ?int $maxLen=null) : string {
		if (!is_string($in)) {
			if ($default !== null)
				return (self::_processBinary($default, $strict, null, $mime, $minLen, $maxLen));
			throw new TµApplicationException("Data is not a string.", TµApplicationException::API);
		}
		// check size
		if ($minLen || $maxLen) {
			$len = mb_strlen($in, 'ascii');
			if (($minLen && $len < $minLen) || ($maxLen && $len > $maxLen)) {
				if ($default !== null)
					return (self::_processBinary($default, $strict, null, $mime, $minLen, $maxLen));
				throw new TµApplicationException("Data size doesn't respect the contract.", TµApplicationException::API);
			}
		}
		// check MIME type
		if ($mime) {
			$finfo = new \finfo(FILEINFO_MIME_TYPE);
			$type = $finfo->buffer($in);
			$found = false;
			foreach ($mime as $m) {
				$m = trim($m);
				if ($type === $m || str_starts_with($type, "$m/")) {
					$found = true;
					break;
				}
			}
			if (!$found) {
				if ($default !== null)
					return (self::_processBinary($default, $strict, null, $mime, $minLen, $maxLen));
				throw new TµApplicationException("Data doesn't respect contract (bad MIME type '$type').", TµApplicationException::API);
			}
		}
		return ($in);
	}
	/**
	 * Process a hex color type.
	 * @param	mixed	$in		Input value.
	 * @param	?string	$default	(optional) Default value.
	 * @return	string	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processColor(mixed $in, ?string $default=null) : string {
		if (!is_string($in) || !preg_match('/^#?(?:[0-9a-f]{3}){1,2}$/i', $in)) {
			if ($default !== null)
				return (self::_processColor($default));
			throw new TµApplicationException("Data is not a valid hex color.", TµApplicationException::API);
		}
		if (mb_substr($in, 0, 1) != '#')
			$in = '#' . $in;
		return (strtolower($in));
	}
	/**
	 * Process a geo coordinates type.
	 * @param	mixed	$in		Input value.
	 * @param	bool	$strict		Strictness.
	 * @param	?string	$default	(optional) Default value.
	 * @param	?string	$min		(optional) Minimum value (top-left corner).
	 * @param	?string	$max		(optional) Maximum value (bottom-right corner).
	 * @return	string	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processGeo(mixed $in, bool $strict, ?string $default=null, ?string $min=null, ?string $max=null) : string {
		if (!is_string($in) || !preg_match('/^[-+]?([1-8]?\d(\.\d+)?|90(\.0+)?),\s*[-+]?(180(\.0+)?|((1[0-7]\d)|([1-9]?\d))(\.\d+)?)$/', $in)) {
			if ($default !== null)
				return (self::_processGeo($default, $strict, null, $min, $max));
			throw new TµApplicationException("Data is not a valid geo coordinates string.", TµApplicationException::API);
		}
		list($lat, $lon) = array_map('trim', explode(',', $in));
		$lat = (float)$lat;
		$lon = (float)$lon;
		// mini (south-west)
		if ($min) {
			if (is_string($min) && preg_match('/^[-+]?([1-8]?\d(\.\d+)?|90(\.0+)?),\s*[-+]?(180(\.0+)?|((1[0-7]\d)|([1-9]?\d))(\.\d+)?)$/', $min)) {
				list($minLat, $minLon) = array_map('trim', explode(',', $min));
				$minLat = (float)$minLat;
				$minLon = (float)$minLon;
				if ($lat < $minLat || $lon < $minLon) {
					if ($strict) {
						if ($default !== null)
							return (self::_processGeo($default, $strict, null, $min, $max));
						throw new TµApplicationException("Data doesn't respect contract (geo coordinates too low).", TµApplicationException::API);
					}
					$lat = max($lat, $minLat);
					$lon = max($lon, $minLon);
				}
			}
		}
		// maxi (north-east)
		if ($max) {
			if (is_string($max) && preg_match('/^[-+]?([1-8]?\d(\.\d+)?|90(\.0+)?),\s*[-+]?(180(\.0+)?|((1[0-7]\d)|([1-9]?\d))(\.\d+)?)$/', $max)) {
				list($maxLat, $maxLon) = array_map('trim', explode(',', $max));
				$maxLat = (float)$maxLat;
				$maxLon = (float)$maxLon;
				if ($lat > $maxLat || $lon > $maxLon) {
					if ($strict) {
						if ($default !== null)
							return (self::_processGeo($default, $strict, null, $min, $max));
						throw new TµApplicationException("Data doesn't respect contract (geo coordinates too high).", TµApplicationException::API);
					}
					$lat = min($lat, $maxLat);
					$lon = min($lon, $maxLon);
				}
			}
		}
		return "$lat, $lon";
	}
	/**
	 * Process a phone number type.
	 * @param	mixed	$in		Input value.
	 * @param	bool	$strict		Strictness.
	 * @param	?string	$default	(optional) Default value.
	 * @return	string	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processPhone(mixed $in, bool $strict, ?string $default=null) : string {
		if (!is_string($in) && !is_numeric($in)) {
			if ($default !== null)
				return (self::_processPhone($default, $strict, null));
			throw new TµApplicationException("Data is not a valid phone number.", TµApplicationException::API);
		}
		$clean = str_replace([' ', '-', '.', '(', ')'], '', trim($in));
		if (!preg_match('/^00\d{1,15}$/', $clean) &&
		    !preg_match('/^\+\d{1,15}$/', $clean) &&
		    !preg_match('/^\d{1,15}$/', $clean)) {
			if ($default !== null)
				return (self::_processPhone($default, $strict, null));
			throw new TµApplicationException("Data is not a valid phone number.", TµApplicationException::API);
		}
		if ($strict)
			return ($clean);
		return ($in);
	}
}

