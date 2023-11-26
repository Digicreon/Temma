<?php

/**
 * DataFilter
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2020-2023, Amaury Bouchard
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
 *	'bool'
 *	'int'
 *	'float'
 *	'string'
 *
 * * Nullable types
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
	const SUPPORTED_TYPES = ['null', 'false', 'true', 'bool', 'int', 'float', 'string', 'email', 'url', 'enum', 'list', 'assoc'];

	/**
	 * Cleanup data using a contract.
	 * @param	mixed			$in		Input data.
	 * @param	null|string|array	$contract	The contract. If set to null, the function act as a pass-through.
	 * @return	mixed	The cleaned data.
	 * @throws	\Temma\Exceptions\IO		If the contract is not well formed (BAD_FORMAT).
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static public function process(mixed $in, null|string|array $contract) : mixed {
		// manage pass-thru
		if ($contract === null || $contract === '')
			return ($in);
		/* *** management of string contract *** */
		if (is_string($contract)) {
			$chunks = str_getcsv($contract, ';');
			$contract = [];
			$contract['type'] = array_shift($chunks);
			foreach ($chunks as $chunk) {
				$data = explode(':', $chunk);
				if (count($data) != 2)
					continue;
				$contract[trim($data[0])] = trim($data[1]);
			}
			return (self::process($in, $contract));
		} else if (!is_array($contract)) {
			throw new TµIOException("Bad contract.", TµIOException::BAD_FORMAT);
		}
		/* *** check contract type *** */
		// check type not empty
		if (!isset($contract['type']) || empty($contract['type']))
			throw new TµIOException("Empty contract type.", TµIOException::BAD_FORMAT);
		// process type as a string, and search for null type
		$contractNullable = false;
		if (is_string($contract['type'])) {
			$addNull = false;
			$hasNull = false;
			// search for nullable type
			if ($contract['type'][0] == '?') {
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
		$contractMin = null;
		if (isset($contract['min'])) {
			if (!is_numeric($contract['min']))
				throw new TµIOException("Bad contract 'min' parameter.", TµIOException::BAD_FORMAT);
			$contractMin = $contract['min'];
		}
		// maximum value
		$contractMax = null;
		if (isset($contract['max'])) {
			if (!is_numeric($contract['max']))
				throw new TµIOException("Bad contract 'max' parameter.", TµIOException::BAD_FORMAT);
			$contractMax = $contract['max'];
		}
		// minimum length
		$contractMinLen = null;
		if (isset($contract['minlen'])) {
			if (!is_numeric($contract['minlen']))
				throw new TµIOException("Bad contract 'minlen' parameter.", TµIOException::BAD_FORMAT);
			$contractMinLen = $contract['minlen'];
		}
		// maximum length
		$contractMaxLen = null;
		if (isset($contract['maxlen'])) {
			if (!is_numeric($contract['maxlen']))
				throw new TµIOException("Bad contract 'maxlen' parameter.", TµIOException::BAD_FORMAT);
			$contractMaxLen = $contract['maxlen'];
		}
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
		/* *** check null value/contract *** */
		// null value
		if (is_null($in)) {
			if ($contractDefault !== null)
				return ($contractDefault);
			if ($contractNullable)
				return (null);
		}
		if (count($contract['type']) == 1 && $contractNullable) {
			if ($in !== null)
				throw new TµApplicationException("Data doesn't respect contract (should be null).", TµApplicationException::API);
			return (null);
		}
		// loop on types
		$lastException = null;
		foreach ($contract['type'] as $contractType) {
			try {
				switch ($contractType) {
					case 'null':
						break;
					case 'false':
						return (self::_processFalse($in, $contractDefault));
					case 'true':
						return (self::_processTrue($in, $contractDefault));
					case 'bool':
						return (self::_processBool($in, $contractDefault));
					case 'int':
						return (self::_processInt($in, $contractDefault, $contractMin, $contractMax));
					case 'float':
						return (self::_processFloat($in, $contractDefault, $contractMin, $contractMax));
					case 'string':
						return (self::_processString($in, $contractDefault, $contractMinLen, $contractMaxLen, $contractMask));
					case 'email':
						return (self::_processEmail($in, $contractDefault, $contractMask));
					case 'url':
						return (self::_processUrl($in, $contractDefault, $contractMinLen, $contractMaxLen, $contractMask));
					case 'enum':
						if (!$contractValues)
							throw new TµIOException("Enum without values.", TµIOException::BAD_FORMAT);
						return (self::_processEnum($in, $contractDefault, $contractValues));
					case 'list':
						if (!$contractSubcontract)
							throw new TµIOException("List without sub-contract.", TµIOException::BAD_FORMAT);
						return (self::_processList($in, $contractDefault, $contractSubcontract));
					case 'assoc':
						if (!$contractKeys)
							throw new TµIOException("Associative array without sub-keys contract.", TµIOException::BAD_FORMAT);
						return (self::_processAssoc($in, $contractDefault, $contractKeys));
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
	 * Process a false value.
	 * @param	mixed	$in		Input value.
	 * @param	mixed	$default	(optional) Default value.
	 * @return	bool	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processFalse(mixed $in, mixed $default=null) : bool {
		$in = self::_processBool($in, $default);
		if ($in !== false)
			throw new TµApplicationException("Value is not false.", TµApplicationException::API);
		return ($in);
	}
	/**
	 * Process a true value.
	 * @param	mixed	$in		Input value.
	 * @param	mixed	$default	(optional) Default value.
	 * @return	bool	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processTrue(mixed $in, mixed $default=null) : bool {
		$in = self::_processBool($in, $default);
		if ($in !== true)
			throw new TµApplicationException("Value is not true.", TµApplicationException::API);
		return ($in);
	}
	/**
	 * Process a boolean type.
	 * @param	mixed	$in		Input value.
	 * @param	mixed	$default	(optional) Default value.
	 * @return	bool	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processBool(mixed $in, mixed $default=null) : bool {
		$options = [
			'options' => [],
			'flags'   => FILTER_NULL_ON_FAILURE,
		];
		if ($default !== null)
			$options['options']['default'] = $default;
		if (($in = filter_var($in, FILTER_VALIDATE_BOOLEAN, $options)) === null) {
			throw new TµApplicationException("Value is not boolean.", TµApplicationException::API);
		}
		return ($in);
	}
	/**
	 * Process an integer type.
	 * @param	mixed			$in		Input value.
	 * @param	mixed			$default	(optional) Default value.
	 * @param	null|int|float|string	$min		(optional) Minimum value.
	 * @param	null|int|float|string	$max		(optional) Maximum value.
	 * @return	int	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processInt(mixed $in, mixed $default=null, null|int|float|string $min=null, null|int|float|string $max=null) : int {
		$options = [
			'options' => [],
			'flags'   => FILTER_FLAG_ALLOW_OCTAL | FILTER_FLAG_ALLOW_HEX,
		];
		if (is_numeric($default))
			$options['options']['default'] = $default;
		if (($in = filter_var($in, FILTER_VALIDATE_INT, $options)) === false) {
			throw new TµApplicationException("Data doesn't respect contract (can't cast to int).", TµApplicationException::API);
		}
		if (is_numeric($min))
			$in = max($in, (int)$min);
		if (is_numeric($max))
			$in = min($in, (int)$max);
		return ($in);
	}
	/**
	 * Process a float type.
	 * @param	mixed			$in		Input value.
	 * @param	mixed			$default	(optional) Default value.
	 * @param	null|int|float|string	$min		(optional) Minimum value.
	 * @param	null|int|float|string	$max		(optional) Maximum value.
	 * @return	float	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processFloat(mixed $in, mixed $default=null, null|int|float|string $min=null, null|int|float|string $max=null) : float {
		$options = [
			'options' => [],
			'flags'   => FILTER_FLAG_ALLOW_THOUSAND,
		];
		if (is_numeric($default))
			$options['options']['default'] = $default;
		if (($in = filter_var($in, FILTER_VALIDATE_FLOAT, $options)) === false) {
			throw new TµApplicationException("Data doesn't respect contract (can't cast to float).", TµApplicationException::API);
		}
		if (is_numeric($min))
			$in = max($in, (float)$min);
		if (is_numeric($max))
			$in = min($in, (float)$max);
		return ($in);
	}
	/**
	 * Process a string type.
	 * @param	mixed			$in		Input value.
	 * @param	mixed			$default	(optional) Default value.
	 * @param	null|int|float|string	$minLen		(optional) Minimum length.
	 * @param	null|int|float|string	$maxLen		(optional) Maximum length.
	 * @param	?string			$mask		(optional) Regex mask.
	 * @return	string	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processString(mixed $in, mixed $default=null, null|int|float|string $minLen=null, null|int|float|string $maxLen=null, ?string $mask=null) : string {
		if (!is_scalar($in))
			throw new TµApplicationException("Data doesn't respect contract (can't cast to string).", TµApplicationException::API);
		if (is_bool($in))
			$in = $in ? 'true' : 'false';
		else
			$in = (string)$in;
		if (is_numeric($maxLen))
			$in = mb_substr($in, 0, $maxLen);
		if ($mask && !preg_match("/$mask/", $in, $matches)) {
			if ($default === null)
				throw new TµApplicationException("Data doesn't respect contract (string doesn't match the given mask).", TµApplicationException::API);
			$in = $default;
		}
		if (is_numeric($minLen) && mb_strlen($in) < $minLen) {
			if ($default === null)
				throw new TµApplicationException("Data doesn't respect contract (string too short).", TµApplicationException::API);
			$in = $default;
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
		if (is_numeric($default))
			$options['options']['default'] = $default;
		if (($in = filter_var($in, FILTER_VALIDATE_EMAIL, $options)) === false) {
			throw new TµApplicationException("Data is not a valid email address.", TµApplicationException::API);
		}
		if ($mask && !preg_match("/$mask/", $in, $matches)) {
			if ($default === null)
				throw new TµApplicationException("Data doesn't respect contract (email doesn't match the given mask).", TµApplicationException::API);
			$in = $default;
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
			if ($default === null)
				throw new TµApplicationException("Data doesn't respect contract (URL too short).", TµApplicationException::API);
			$in = $default;
		}
		if ($mask && !preg_match("/$mask/", $in, $matches)) {
			if ($default === null)
				throw new TµApplicationException("Data doesn't respect contract (URL doesn't match the given mask).", TµApplicationException::API);
			$in = $default;
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
			return ($default);
		throw new TµApplicationException("Data doesn't respect contract (bad enum value '$in').", TµApplicationException::API);
	}
	/**
	 * Process a list type.
	 * @param	mixed			$in		Input value.
	 * @param	mixed			$default	Default value.
	 * @param	null|string|array	$subcontract	Sub-contract.
	 * @return	array	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processList(mixed $in, mixed $default, null|string|array $subcontract) : array {
		if (!is_array($in)) {
			if ($default !== null)
				return ($default);
			throw new TµApplicationException("Data doesn't repect contract (not a list).", TµApplicationException::API);
		}
		if ($subcontract === null)
			return ($in);
		$out = [];
		foreach ($in as $k => $v) {
			$res = self::process($v, $subcontract);
			if (!is_null($res))
				$out[] = $res;
		}
		return ($out);
	}
	/**
	 * Process an associative array type.
	 * @param	mixed	$in		Input value.
	 * @param	mixed	$default	Default value.
	 * @param	array	$contractKeys	Sub keys.
	 * @return	array	The filtered input value.
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processAssoc(mixed $in, mixed $default, array $contractKeys) : array {
		if (!is_array($in)) {
			if ($default !== null)
				return ($default);
			throw new TµApplicationException("Data doesn't repect contract (not an array).", TµApplicationException::API);
		}
		$out = [];
		foreach ($contractKeys as $k => $v) {
			$key = null;
			$subcontract = null;
			if (is_int($k)) {
				$key = $v;
			} else {
				$key = $k;
				$subcontract = $v;
			}
			if (!isset($in[$key]) &&
			    (!isset($subcontract['mandatory']) || $subcontract['mandatory'] === true))
				throw new TµApplicationException("Data doesn't respect contract (mandatory key).", TµApplicationException::API);
			$res = self::process(($in[$key] ?? null), $subcontract);
			if (!is_null($res))
				$out[$key] = $res;
			else if (!isset($subcontract['mandatory']) || $subcontract['mandatory'] === true)
				$out[$key] = null;
		}
		return ($out);
	}
}

