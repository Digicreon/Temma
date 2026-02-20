<?php

/**
 * EnumValidator
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-datafilter
 */

namespace Temma\Utils\Validation;

use \Temma\Exceptions\IO as TµIOException;
use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Validator for enumeration values.
 */
class EnumValidator implements Validator {
	/**
	 * Validate data.
	 * @param	mixed	$data		Data to validate.
	 * @param	array	$contract	(optional) Contract parameters.
	 * @param	mixed	&$output	(optional) Reference to output variable.
	 * @return	mixed	The filtered data.
	 * @throws	\Temma\Exceptions\IO		If the contract is invalid.
	 * @throws	\Temma\Exceptions\Application	If the data is invalid.
	 */
	public function validate(mixed $data, array $contract=[], mixed &$output=null) : mixed {
		// get parameters
		$values = $contract['values'] ?? null;
		// check type
		if (!is_string($data))
			return $this->_processDefault($contract, "Data is not a string.", $output);
		// check valuess
		if (is_string($values))
			$values = array_map('trim', explode(',', $values));
		else if (!is_array($values))
			throw new TµIOException("Bad contract 'values' parameter.", TµIOException::BAD_FORMAT); // Or "Enum without values" as in legacy
		if (!$values)
			throw new TµIOException("Enum without values.", TµIOException::BAD_FORMAT);
		// validation
		if (in_array($data, $values)) {
			$output = $data;
			return ($data);
		}
		// error
		return $this->_processDefault($contract, "Data doesn't respect contract (bad enum value '$data').", $output);
	}
	/**
	 * Manage default value.
	 * @param	array	$contract	Validation contract.
	 * @param	string	$exceptionMsg	Exception message if no default value.
	 * @param	mixed	&$output	Reference to output variable.
	 */
	private function _processDefault(array $contract, string $exceptionMsg, mixed &$output) : mixed {
		$default = $contract['default'] ?? null;
		if (is_null($default))
			throw new TµApplicationException($exceptionMsg, TµApplicationException::API);
		$contract['default'] = null;
		return $this->validate($default, $contract, $output);
	}
}

