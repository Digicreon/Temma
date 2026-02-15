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
	 * @param	array	$contract	Contract parameters.
	 * @return	mixed	The filtered data.
	 * @throws	\Temma\Exceptions\IO		If the contract is invalid.
	 * @throws	\Temma\Exceptions\Application	If the data is invalid.
	 */
	public function validate(mixed $data, array $contract=[]) : mixed {
		// get parameters
		$values = $contract['values'] ?? null;
		// check type
		if (!is_string($data))
			return $this->_processDefault($contract, "Data is not a string.");
		// check valuess
		if (is_string($values))
			$values = array_map('trim', explode(',', $values));
		else if (!is_array($values))
			throw new TµIOException("Bad contract 'values' parameter.", TµIOException::BAD_FORMAT); // Or "Enum without values" as in legacy
		if (!$values)
			throw new TµIOException("Enum without values.", TµIOException::BAD_FORMAT);
		// validation
		if (in_array($data, $values))
			return ($data);
		// error
		return $this->_processDefault($contract, "Data doesn't respect contract (bad enum value '$data').");
	}
	/* Manage default value. */
	private function _processDefault(array $contract, string $exceptionMsg) : mixed {
		$default = $contract['default'] ?? null;
		if (is_null($default))
			throw new TµApplicationException($exceptionMsg, TµApplicationException::API);
		$contract['default'] = null;
		return $this->validate($default, $contract);
	}
}

