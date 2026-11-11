<?php

/**
 * MacValidator
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-datafilter
 */

namespace Temma\Utils\Validation;

use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Validator for MAC addresses.
 */
class MacValidator implements Validator, JsonSchemaExportable {
	/**
	 * Validate data.
	 * @param	mixed	$data		Data to validate.
	 * @param	array	$contract	(optional) Contract parameters.
	 * @param	mixed	&$output	(optional) Reference to output variable.
	 * @return	mixed	The filtered data.
	 * @throws	\Temma\Exceptions\Application	If the data is invalid.
	 */
	public function validate(mixed $data, array $contract=[], mixed &$output=null) : mixed {
		// check data
		if (is_string($data) && filter_var($data, FILTER_VALIDATE_MAC)) {
			$output = $data;
			return ($data);
		}
		// process default
		$default = $contract['default'] ?? null;
		if (is_null($default))
			throw new TµApplicationException("Data is not a valid MAC address.", TµApplicationException::API);
		$contract['default'] = null;
		return $this->validate($default, $contract, $output);
	}
	/**
	 * Convert a contract to JSON Schema.
	 * @param	array	$contract	Contract parameters.
	 * @return	array	The JSON Schema.
	 */
	public function toJsonSchema(array $contract) : array {
		return ([
			'type'    => 'string',
			'pattern' => '^(?:[0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$|^(?:[0-9A-Fa-f]{4}\.){2}[0-9A-Fa-f]{4}$',
		]);
	}
}

