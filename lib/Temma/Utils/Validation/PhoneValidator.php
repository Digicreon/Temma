<?php

/**
 * PhoneValidator
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-datafilter
 */

namespace Temma\Utils\Validation;

use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Validator for phone numbers.
 */
class PhoneValidator implements Validator {
	/**
	 * Validate data.
	 * @param	mixed	$data		Data to validate.
	 * @param	array	$contract	(optional) Contract parameters.
	 * @param	mixed	&$output	(optional) Reference to output variable.
	 * @return	mixed	The filtered data.
	 * @throws	\Temma\Exceptions\Application	If the data is invalid.
	 */
	public function validate(mixed $data, array $contract=[], mixed &$output=null) : mixed {
		// get parameters
		$strict = $contract['strict'] ?? false;
		// process
		if (!is_string($data) && !is_numeric($data))
			return $this->_processDefault($contract, "Data is not a valid phone number.", $output);
		$clean = str_replace([' ', '-', '.', '(', ')'], '', trim($data));
		if (!preg_match('/^00\d{1,15}$/', $clean) &&
		    !preg_match('/^\+\d{1,15}$/', $clean) &&
		    !preg_match('/^\d{1,15}$/', $clean))
			return $this->_processDefault($contract, "Data is not a valid phone number.", $output);
		$output = $clean;
		if ($strict)
			return ($clean);
		return ($data);
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

