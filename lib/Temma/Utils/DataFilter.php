<?php

/**
 * DataContract
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2020, Amaury Bouchard
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
 *	'int'
 *	'string'
 *	'float'
 *	'bool'
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
 *		'type'		=> 'enum',
 *		'values'	=> ['red', 'green', 'blue'],
 *		'default'	=> 'red',
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
 * * Complexe example
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
	/**
	 * Cleanup data using a contract.
	 * @param	mixed	$in		Input data.
	 * @param	mixed	$contract	The contract. If set to null, the function act as a pass-through.
	 * @param	bool	$pedantic	(optional) True to throw an exception if the input data doesn't respect the contract. (default: true)
	 * @return	mixed	The cleaned data.
	 * @throws	\Temma\Exceptions\IO		If the contract is not well formed (BAD_FORMAT).
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static public function process($in, $contract, bool $pedantic=true) {
		if ($contract === null)
			return ($in);
		// process simple contracts
		if (in_array($contract, ['null', 'string', '?string', 'int', '?int', 'float', '?float', 'bool', '?bool'])) {
			return (self::_processScalar($in, $contract, null, null, null, null, null, null, null, $pedantic));
		} else if (!is_array($contract)) {
			throw new TµIOException("Bad contract.", TµIOException::BAD_FORMAT);
		}
		// get contract parameters
		if (!isset($contract['type']) ||
		    !in_array($contract['type'], ['null', 'string', '?string', 'int', '?int', 'float', '?float', 'bool', '?bool', 'enum', '?enum', 'assoc', '?assoc', 'list', '?list']))
			throw new TµIOException("Bad contract type '{$contract['type']}'.", TµIOException::BAD_FORMAT);
		$contractType = $contract['type'];
		if ($contractType == 'assoc' && (!isset($contract['keys']) || !is_array($contract['keys'])))
			throw new TµIOException("Missing sub-keys contract.", TµIOException::BAD_FORMAT);
		$contractKeys = $contract['keys'] ?? null;
		if ($contractType == 'list' && !isset($contract['contract']))
			throw new TµIOException("Missing sub-elements contract.", TµIOException::BAD_FORMAT);
		$contractSubcontract = $contract['contract'] ?? null;
		$contractDefault = $contract['default'] ?? null;
		if ($contractType == 'enum' && (!isset($contract['values']) || !is_array($contract['values'])))
			throw new TµIOException("Enum without values.", TµIOException::BAD_FORMAT);
		$contractValues = $contract['values'] ?? null;
		$contractMin = null;
		if (isset($contract['min'])) {
			if (!is_numeric($contract['min']))
				throw new TµIOException("Bad contract (min value).", TµIOException::BAD_FORMAT);
			$contractMin = (int)$contract['min'];
		}
		$contractMax = null;
		if (isset($contract['max'])) {
			if (!is_numeric($contract['max']))
				throw new TµIOException("Bad contract (max value).", TµIOException::BAD_FORMAT);
			$contractMax = (int)$contract['max'];
		}
		$contractMask = null;
		if (isset($contract['mask'])) {
			if (!is_string($contract['mask']))
				throw new TµIOException("Bad contract (mask is not a string).", TµIOException::BAD_FORMAT);
			$contractMask = $contract['mask'] ?? null;
		}
		// check input value
		if (is_null($in)) {
			if ($contractDefault !== null)
				return ($contractDefault);
			if ($contractType[0] == '?') {
				$contractType = substr($contractType, 1);
				return (null);
			}
		}
		if (in_array($contractType, ['assoc', 'list']) && !is_array($in))
			throw new TµApplicationException("Data doesn't respect contract (not an array).", TµApplicationException::API);
		// process scalar input
		if (!is_array($in)) {
			return (self::_processScalar($in, $contractType, $contractDefault, $contractMin, $contractMax, $contractMinLen, $contractMaxLen, $contractMask, $contractValues, $pedantic));
		}
		// process list input
		if ($contractType == 'list') {
			$out = [];
			foreach ($in as $k => $v) {
				$res = self::process($v, $contractSubcontract);
				if (!is_null($res))
					$out[] = $res;
			}
			return ($out);
		}
		// process associative array input
		if ($contractType == 'assoc') {
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
				if (!isset($in['key']) &&
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
		return (null);
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Process a scalar type.
	 * @param	mixed		$in		Input value.
	 * @param	string		$type		Specified type. Set to null (not the 'null' string) or an empty string to get a pass-through.
	 * @param	mixed		$default	(optional) Default value.
	 * @param	?float|int	$min		(optional) Number min or string min length.
	 * @param	?float|int	$max		(optional) Number max or string max length.
	 * @param	?string		$mask		(optional) Regexp mask.
	 * @param	?array		$values		(optional) Enum values.
	 * @param	bool		$pedantic	(optional) True to throw an exception if the input data doesn't respect the contract. (default: true)
	 * @return	mixed
	 * @throws	\Temma\Exceptions\IO		If the type is not supported (BAD_FORMAT).
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static private function _processScalar($in, ?string $type, $default=null, $min=null, $max=null, ?string $mask=null, ?array $values=null, bool $pedantic=true) {
		$nullable = false;
		// no type == passthru
		if (!$type) {
			return ($in);
		}
		// null type
		if ($type === 'null') {
			if ($pedantic && $in !== null)
				throw new TµApplicationException("Data doesn't respect contract (should be null).", TµApplicationException::API);
			return (null);
		}
		// check nullable types
		if ($type[0] == '?') {
			$type = substr($type, 1);
			$nullable = true;
		}
		// null value
		if ($in === null) {
			if ($default !== null)
				return ($default);
			if ($nullable)
				return (null);
			if ($pedantic)
				throw new TµApplicationException("Data doesn't respect contract (null value).", TµApplicationException::API);
		}
		// other scalar types
		if ($type === 'string') {
			if ($pedantic && !is_scalar($in))
				throw new TµApplicationException("Data doesn't respect contract (can't cast to string).", TµApplicationException::API);
			if (is_bool($in))
				$in = $in ? 'true' : 'false';
			else
				$in = (string)$in;
			if ($max)
				$in = mb_substr($in, 0, $max);
			if (($mask && preg_match("/$mask/", $in, $matches) === false) ||
			    ($min && mb_strlen($in) < $min)) {
				if ($default !== null)
					$in = $default;
				else if ($nullable)
					$in = null;
				else if ($pedantic)
					throw new TµApplicationException("Data doesn't respect contract (string too short).", TµApplicationException::API);
			}
			return ($in);
		}
		if ($type === 'int') {
			if ($pedantic && filter_var($in, FILTER_VALIDATE_INT) === false)
				throw new TµApplicationException("Data doesn't respect contract (can't cast to int).", TµApplicationException::API);
			$in = (int)$in;
			if ($min !== null)
				$in = min($in, (int)$min);
			if ($max !== null)
				$in = max($in, (int)$max);
			return ((int)$in);
		}
		if ($type === 'float') {
			if ($pedantic && filter_var($in, FILTER_VALIDATE_FLOAT) === false)
				throw new TµApplicationException("Data doesn't respect contract (can't cast to float).", TµApplicationException::API);
			$in = (float)$in;
			if ($min !== null)
				$in = min($in, $min);
			if ($max !== null)
				$in = max($in, $max);
			return ((float)$in);
		}
		if ($type === 'bool') {
			if (is_bool($in))
				return ($in);
			if (is_string($in)) {
				foreach (['true', 'yes', 'on'] as $s) {
					if (!strcasecmp($in, $s))
						return (true);
				}
				foreach (['false', 'no', 'off'] as $s) {
					if (!strcasecmp($in, $s))
						return (false);
				}
			}
			if ((is_int($in) && $in === 1) || (is_float($in) && $in === 1.0))
				return (true);
			if ((is_int($in) && $in === 0) || (is_float($in) && $in === 0.0))
				return (false);
			if ($pedantic)
				throw new TµApplicationException("Data doesn't respect contract (not a boolean).", TµApplicationException::API);
			return ((bool)$in);
		}
		if ($type === 'enum') {
			if (in_array($in, $values))
				return ($in);
			if ($pedantic || $default === null)
				throw new TµApplicationException("Data doesn't respect contract (bad enum value '$in').", TµApplicationException::API);
			return ($default);
		}
		throw new TµIOException("Bad type '$type'.", TµIOException::BAD_FORMAT);
	}
}
