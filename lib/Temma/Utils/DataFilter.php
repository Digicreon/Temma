<?php

/**
 * DataFilter
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2020-2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-datafilter
 */

namespace Temma\Utils;

use \Temma\Exceptions\IO as TµIOException;
use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Object used to cleanup data using a contract declaration.
 * This class acts as an orchestrator for specific Validator classes.
 */
class DataFilter {
	/** JSON Schema dialect used by the toJsonSchema() method (the one used by MCP). */
	const JSON_SCHEMA_DIALECT = 'https://json-schema.org/draft/2020-12/schema';
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
		'sha256'   => ['type' => \Temma\Utils\Validation\HashValidator::class, 'algo' => 'sha256'],
		'sha512'   => ['type' => \Temma\Utils\Validation\HashValidator::class, 'algo' => 'sha512'],
	];
	/** Stack of contract aliases being converted to JSON Schema (used to detect recursive aliases). */
	static protected array $_jsonSchemaAliases = [];

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
	 * Convert a contract to a JSON Schema (draft 2020-12, as used by MCP).
	 * The generated schema describes the data expected by the contract (as in strict mode).
	 * It is less precise than the validation done by Temma (no data conversion, no checksum
	 * verification, etc.), its purpose is to give information about the expected data.
	 * Custom validators which don't implement the JsonSchemaExportable interface accept any value.
	 * @param	null|string|array	$contract	The contract. If set to null, any value is accepted.
	 * @return	array	The JSON Schema, as an associative array (to be encoded using json_encode()).
	 * @throws	\Temma\Exceptions\IO	If the contract is not well formed (BAD_FORMAT).
	 */
	static public function toJsonSchema(null|string|array $contract) : array {
		return (['$schema' => self::JSON_SCHEMA_DIALECT] + self::_buildJsonSchema($contract));
	}
	/**
	 * Convert a contract to a JSON sub-schema, without the '$schema' keyword.
	 * Used for nested contracts. An empty schema (any value accepted) is returned as an empty
	 * object, to be encoded as '{}' by json_encode().
	 * @param	null|string|array	$contract	The contract.
	 * @return	array|\stdClass	The JSON Schema, as an associative array or an empty object.
	 * @throws	\Temma\Exceptions\IO	If the contract is not well formed (BAD_FORMAT).
	 */
	static public function toJsonSubSchema(null|string|array $contract) : array|\stdClass {
		$schema = self::_buildJsonSchema($contract);
		return ($schema ?: new \stdClass());
	}
	/**
	 * Get a validator instance.
	 * @param	string	$type	The validator type (alias or class name).
	 * @return	\Temma\Utils\Validation\Validator	The validator instance.
	 * @throws	\Temma\Exceptions\IO	If the validator cannot be found.
	 */
	static private function _getValidatorInstance(string $type) : \Temma\Utils\Validation\Validator {
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
			if (!($obj instanceof \Temma\Utils\Validation\Validator))
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
				// unknown escape sequence: kept as is (useful for regular expressions)
				if ($escaped) {
					$value .= '\\';
					$escaped = false;
				}
				$value .= $char;
			}
		}
		if ($labelFound)
			$res[$label] = trim($value);
		return ($res);
	}
	/**
	 * Build the JSON Schema of a contract.
	 * @param	null|string|array	$contract	The contract.
	 * @return	array	The JSON Schema (empty array if any value is accepted).
	 * @throws	\Temma\Exceptions\IO	If the contract is not well formed (BAD_FORMAT).
	 */
	static private function _buildJsonSchema(null|string|array $contract) : array {
		// manage pass-thru
		if ($contract === null || $contract === '')
			return ([]);
		if (is_string($contract))
			$contract = self::_parseContractString($contract);
		if (array_key_exists('type', $contract) &&
		    ($contract['type'] === null || $contract['type'] === ''))
			return (self::_addJsonSchemaAnnotations([], $contract));
		// assume "assoc" contract if no type is defined
		if (!isset($contract['type']) || empty($contract['type'])) {
			$contract = [
				'type' => 'assoc',
				'keys' => $contract,
			];
		}
		// get the list of types
		if (is_string($contract['type'])) {
			$typeString = $contract['type'];
			// remove forced (un)strictness
			if (str_starts_with($typeString, '~') || str_starts_with($typeString, '='))
				$typeString = mb_substr($typeString, 1);
			// search for nullable type
			$nullable = false;
			if (str_starts_with($typeString, '?')) {
				$nullable = true;
				$typeString = mb_substr($typeString, 1);
			}
			$types = array_map('trim', explode('|', $typeString));
			if ($nullable && !in_array('null', array_map('mb_strtolower', $types)))
				array_unshift($types, 'null');
		} else {
			$types = (array)$contract['type'];
		}
		// conversion of each type
		$schemas = [];
		foreach ($types as $type)
			$schemas[] = self::_typeToJsonSchema($type, $contract);
		$schema = self::_combineJsonSchemas($schemas);
		return (self::_addJsonSchemaAnnotations($schema, $contract));
	}
	/**
	 * Convert one type of a contract to JSON Schema.
	 * @param	string	$type		The type (alias or class name).
	 * @param	array	$contract	The contract parameters.
	 * @return	array	The JSON Schema (empty array if any value is accepted).
	 * @throws	\Temma\Exceptions\IO	If the contract is not well formed (BAD_FORMAT).
	 */
	static private function _typeToJsonSchema(string $type, array $contract) : array {
		if (mb_strtolower($type) == 'null')
			$type = 'null';
		// check for contract alias
		if (isset(self::$_aliases[$type])) {
			$target = self::$_aliases[$type];
			if (is_array($target) || (is_string($target) && !class_exists($target))) {
				// recursive alias: any value is accepted
				if (in_array($type, self::$_jsonSchemaAliases))
					return ([]);
				$targetArray = is_string($target) ? self::_parseContractString($target) : $target;
				// alias without type: associative array
				if (!isset($targetArray['type']) || empty($targetArray['type']))
					$targetArray = ['type' => 'assoc', 'keys' => $targetArray];
				// usage parameters override the definition, except the type
				$mergedContract = array_merge($targetArray, $contract);
				$mergedContract['type'] = $targetArray['type'];
				self::$_jsonSchemaAliases[] = $type;
				try {
					return (self::_buildJsonSchema($mergedContract));
				} finally {
					array_pop(self::$_jsonSchemaAliases);
				}
			}
		}
		// get validator
		$validator = self::_getValidatorInstance($type);
		if (!($validator instanceof \Temma\Utils\Validation\JsonSchemaExportable))
			return ([]);
		$contract['currentType'] = $type;
		return ($validator->toJsonSchema($contract));
	}
	/**
	 * Combine the JSON Schemas of the multiple types of a contract.
	 * @param	array	$schemas	List of JSON Schemas.
	 * @return	array	The combined JSON Schema.
	 */
	static private function _combineJsonSchemas(array $schemas) : array {
		// separate the null type from the other types
		$hasNull = false;
		$others = [];
		foreach ($schemas as $schema) {
			// a type accepts any value
			if (!$schema)
				return ([]);
			if ($schema === ['type' => 'null'])
				$hasNull = true;
			else
				$others[] = $schema;
		}
		if (!$others)
			return (['type' => 'null']);
		// merge the non-null types
		if (count($others) == 1)
			$res = $others[0];
		else
			$res = self::_mergeJsonSchemas($others) ?? ['anyOf' => $others];
		if (!$hasNull)
			return ($res);
		// manage null type
		if (!isset($res['type'])) {
			if (array_keys($res) == ['anyOf']) {
				$res['anyOf'][] = ['type' => 'null'];
				return ($res);
			}
			return (['anyOf' => [$res, ['type' => 'null']]]);
		}
		$res['type'] = array_values(array_unique(array_merge((array)$res['type'], ['null'])));
		if (array_key_exists('const', $res)) {
			$res['enum'] = [$res['const']];
			unset($res['const']);
		}
		if (isset($res['enum']) && !in_array(null, $res['enum'], true))
			$res['enum'][] = null;
		return ($res);
	}
	/**
	 * Merge JSON Schemas of different types into one schema, if possible
	 * (most JSON Schema keywords apply to only one type).
	 * @param	array	$schemas	List of JSON Schemas.
	 * @return	?array	The merged schema, or null if the schemas can't be merged.
	 */
	static private function _mergeJsonSchemas(array $schemas) : ?array {
		$res = [];
		$types = [];
		foreach ($schemas as $schema) {
			if (!isset($schema['type']) || array_key_exists('const', $schema) ||
			    isset($schema['enum']) || isset($schema['anyOf']))
				return (null);
			$types = array_merge($types, (array)$schema['type']);
			unset($schema['type']);
			foreach ($schema as $key => $value) {
				// loose comparison (an integer and a float with the same value are equal)
				if (array_key_exists($key, $res) && $res[$key] != $value)
					return (null);
				$res[$key] = $value;
			}
		}
		$types = array_values(array_unique($types));
		$res = ['type' => ((count($types) == 1) ? $types[0] : $types)] + $res;
		return ($res);
	}
	/**
	 * Add annotations (title, description, default value, examples) to a JSON Schema.
	 * @param	array	$schema		The JSON Schema.
	 * @param	array	$contract	The contract.
	 * @return	array	The annotated JSON Schema.
	 */
	static private function _addJsonSchemaAnnotations(array $schema, array $contract) : array {
		$head = [];
		if (isset($contract['title']) && is_string($contract['title']))
			$head['title'] = $contract['title'];
		if (isset($contract['description']) && is_string($contract['description']))
			$head['description'] = $contract['description'];
		$schema = $head + $schema;
		if (isset($contract['default']))
			$schema['default'] = self::_typeJsonSchemaDefault($contract['default'], $schema);
		if (isset($contract['examples'])) {
			$examples = is_array($contract['examples']) ? array_values($contract['examples']) : [$contract['examples']];
			$schema['examples'] = array_map(fn($example) => self::_typeJsonSchemaDefault($example, $schema), $examples);
		}
		return ($schema);
	}
	/**
	 * Give the right JSON type to a default value or an example (they are strings in string contracts).
	 * @param	mixed	$default	The default value.
	 * @param	array	$schema		The JSON Schema.
	 * @return	mixed	The typed default value.
	 */
	static private function _typeJsonSchemaDefault(mixed $default, array $schema) : mixed {
		if (!is_string($default))
			return ($default);
		$types = (array)($schema['type'] ?? []);
		if (in_array('null', $types) && $default === 'null')
			return (null);
		if (in_array('boolean', $types) && ($default === 'true' || $default === 'false'))
			return ($default === 'true');
		if (in_array('integer', $types) && filter_var($default, FILTER_VALIDATE_INT) !== false)
			return ((int)$default);
		if (in_array('number', $types) && is_numeric($default))
			return ((float)$default);
		return ($default);
	}
}

