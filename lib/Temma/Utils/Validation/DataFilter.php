<?php

/**
 * DataFilter
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2020-2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-datafilter
 */

namespace Temma\Utils\Validation;

use \Temma\Exceptions\IO as TµIOException;
use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Object used to cleanup data using a contract declaration.
 * This class acts as an orchestrator for specific Validator classes.
 */
class DataFilter {
	/** Loader instance. */
	static protected ?\Temma\Base\Loader $_loader = null;
	/** Cache of validator instances. */
	static protected array $_instances = [];
	/** List of aliases. */
	static protected array $_aliases = [
		'null'     => \Temma\Utils\Validation\NullValidator::class,
		'false'    => \Temma\Utils\Validation\BoolValidator::class, // mapped to BoolValidator with specific params
		'true'     => \Temma\Utils\Validation\BoolValidator::class, // mapped to BoolValidator with specific params
		'bool'     => \Temma\Utils\Validation\BoolValidator::class,
		'int'      => \Temma\Utils\Validation\IntValidator::class,
		'float'    => \Temma\Utils\Validation\FloatValidator::class,
		'string'   => \Temma\Utils\Validation\StringValidator::class,
		'email'    => \Temma\Utils\Validation\EmailValidator::class,
		'url'      => \Temma\Utils\Validation\UrlValidator::class,
		'enum'     => \Temma\Utils\Validation\EnumValidator::class,
		'array'    => \Temma\Utils\Validation\ListValidator::class,
		'list'     => \Temma\Utils\Validation\ListValidator::class,
		'assoc'    => \Temma\Utils\Validation\AssocValidator::class,
		'date'     => \Temma\Utils\Validation\DateValidator::class,
		'time'     => \Temma\Utils\Validation\TimeValidator::class,
		'datetime' => \Temma\Utils\Validation\DateTimeValidator::class,
		'uuid'     => \Temma\Utils\Validation\UuidValidator::class,
		'isbn'     => \Temma\Utils\Validation\IsbnValidator::class,
		'ean'      => \Temma\Utils\Validation\EanValidator::class,
		'ip'       => \Temma\Utils\Validation\IpValidator::class,
		'ipv4'     => \Temma\Utils\Validation\IpValidator::class, // handled by IpValidator
		'ipv6'     => \Temma\Utils\Validation\IpValidator::class, // handled by IpValidator
		'mac'      => \Temma\Utils\Validation\MacValidator::class,
		'port'     => \Temma\Utils\Validation\IntValidator::class, // handled by IntValidator with ranges
		'slug'     => \Temma\Utils\Validation\SlugValidator::class,
		'json'     => \Temma\Utils\Validation\JsonValidator::class,
		'base64'   => \Temma\Utils\Validation\Base64Validator::class,
		'binary'   => \Temma\Utils\Validation\BinaryValidator::class,
		'color'    => \Temma\Utils\Validation\ColorValidator::class,
		'geo'      => \Temma\Utils\Validation\GeoValidator::class,
		'phone'    => \Temma\Utils\Validation\PhoneValidator::class,
		'hash'     => \Temma\Utils\Validation\HashValidator::class,
		'md5'      => ['type' => \Temma\Utils\Validation\HashValidator::class, 'algo' => 'md5'],
		'sha1'     => ['type' => \Temma\Utils\Validation\HashValidator::class, 'algo' => 'sha1'],
		'sha256'   => ['type' => \Temma\Utils\Validation\HashValidator::class, 'algo' => 'shai256'],
		'sha512'   => ['type' => \Temma\Utils\Validation\HashValidator::class, 'algo' => 'sha512'],
	];

	/**
	 * Set the loader instance.
	 * @param	\Temma\Base\Loader	$loader		The loader instance.
	 */
	static public function setLoader(\Temma\Base\Loader $loader) : void {
		self::$_loader = $loader;
	}
	/**
	 * Register an alias (or multiple aliases).
	 * @param	string|array	$alias		The alias name, or an associative array of aliases.
	 * @param	?string		$target		(optional) The target class or contract.
	 */
	static public function registerAlias(string|array $alias, ?string $target=null) : void {
		if (is_array($alias))
			self::$_aliases = array_merge(self::$_aliases, $alias);
		else
			self::$_aliases[$alias] = $target;
	}
	/**
	 * Cleanup data using a contract.
	 * @param	mixed			$in		Input data.
	 * @param	null|string|array	$contract	The contract. If set to null, the function act as a pass-through.
	 * @param	bool			$strict		(optional) True to force strict type comparison.
	 * @param	bool			$inline		(optional) Set to true if the call was inline (by recursion only).
	 * @param	mixed			&$output	(optional) Reference to output variable.
	 * @return	mixed	The cleaned data.
	 * @throws	\Temma\Exceptions\IO		If the contract is not well formed (BAD_FORMAT).
	 * @throws	\Temma\Exceptions\Application	If the input data doesn't respect the contract (API).
	 */
	static public function process(mixed $in, null|string|array $contract, bool $strict=false, bool $inline=false, mixed &$output=null) : mixed {
		// manage pass-thru
		if ($contract === null || $contract === '')
			return ($in);
		/* *** management of string contract *** */
		if (is_string($contract)) {
			$res = self::_parseContractString($contract);
			return (self::process($in, $res, $strict, true, $output));
		} else if (!is_array($contract)) {
			throw new TµIOException("Bad contract.", TµIOException::BAD_FORMAT);
		}
		/* *** check contract type *** */
		// manage pass-thru
		if (array_key_exists('type', $contract) &&
		    ($contract['type'] === null || $contract['type'] === ''))
			return ($in);
		// assume "assoc" contract if no type is defined
		if (!isset($contract['type']) || empty($contract['type'])) {
			$contract = [
				'type' => 'assoc',
				'keys' => $contract,
			];
		}
		// process type as a string, and search for null type
		$contractNullable = false;
		$contractStrict = $strict;
		$types = [];
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
			$types = explode('|', $contract['type']);
			// loop on types
			$types = array_map(function ($type) use (&$addNull, &$hasNull) {
				$type = trim($type);
				// manage null type
				if (mb_strtolower($type) == 'null') {
					$addNull = false;
					$hasNull = true;
				}
				return ($type);
			}, $types);
			// manage nullable type
			if ($addNull)
				array_unshift($types, 'null');
		} else {
			// type is already an array? (not common but handle it)
			$types = (array)$contract['type'];
			// search for nullable type
			$contractNullable = in_array('null', $types);
		}
		// prepare params for validators
		$params = $contract;
		$params['strict'] = $contractStrict; // explicitly pass strictness in params
		$params['inline'] = $inline;
		// loop on types
		$lastException = null;
		foreach ($types as $type) {
			try {
				// check for contract alias
				if (isset(self::$_aliases[$type])) {
					$target = self::$_aliases[$type];
					$targetArray = $target;
					// checks if it is a contract alias (array or string)
					// (if it is a class name, it will be handled by _getValidatorInstance)
					if (is_array($target) || (is_string($target) && !class_exists($target))) {
						if (is_string($target))
							$targetArray = self::_parseContractString($target);

						// merge usage params into contract definition
						// usage params should override definition, except for 'type' which must remain the definition's type
						$mergedContract = array_merge($targetArray, $params);
						// force the type to be the aliased type
						if (isset($targetArray['type']))
							$mergedContract['type'] = $targetArray['type'];
						else if (isset($mergedContract['type']) && $mergedContract['type'] === $type)
							unset($mergedContract['type']); // Remove self-referencing type if not redefined in target

						return (self::process($in, $mergedContract, $strict, true, $output));
					}
				}
				// get validator
				$validator = self::_getValidatorInstance($type);
				// pass type to validator (used by multi-type validators)
				$params['currentType'] = $type;
				return ($validator->validate($in, $params, $output));
			} catch (TµIOException $ie) {
				// ill-formed contract
				throw $ie;
			} catch (TµApplicationException $ae) {
				$lastException = $ae;
			}
		}
		if ($lastException)
			throw $lastException;
		throw new TµApplicationException("Data doesn't validate the contract.", TµApplicationException::API);
	}
	/**
	 * Get a validator instance.
	 * @param	string	$type	The validator type (alias or class name).
	 * @return	Validator	The validator instance.
	 * @throws	\Temma\Exceptions\IO	If the validator cannot be found.
	 */
	static private function _getValidatorInstance(string $type) : Validator {
		$target = $type;
		// check aliases
		if (isset(self::$_aliases[$type]))
			$target = self::$_aliases[$type];
		// check for contract alias (if target is not a class)
		if (isset(self::$_aliases[$target]))
			$target = self::$_aliases[$target];
		// get validator instance
		if (is_string($target) && class_exists($target)) {
			if (isset(self::$_instances[$target]))
				return (self::$_instances[$target]);
			if (self::$_loader)
				$obj = self::$_loader[$target];
			else
				$obj = new $target();
			if (!($obj instanceof Validator))
				throw new TµIOException("Class '$target' is not a Validator.", TµIOException::BAD_FORMAT);
			self::$_instances[$target] = $obj;
			return ($obj);
		}
		// unknown validator
		throw new TµIOException("Unknown validation type '$type'.", TµIOException::BAD_FORMAT);
	}
	/**
	 * Parse a contract string.
	 * @param	string	$contract	The contract string.
	 * @return	array	The parsed contract as an associative array.
	 */
	static public function _parseContractString(string $contract): array {
		// check if there are parameters
		if (($pos = mb_strpos($contract, ';')) === false) {
			// no parameters, returns the contract as the type
			return ['type' => trim($contract)];
		}
		// parse parameters
		$res = [
			'type' => trim(mb_substr($contract, 0, $pos)),
		];
		$contract = mb_substr($contract, $pos + 1);
		$len = mb_strlen($contract);
		$labelFound = false;
		$quoted = false;
		$escaped = false;
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
						$value .= '\\';
						$escaped = false;
						continue;
					}
					$escaped = true;
					continue;
				}
				$value .= $char;
			}
		}
		if ($labelFound)
			$res[$label] = trim($value);
		return ($res);
	}
}

