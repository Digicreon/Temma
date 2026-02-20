<?php

/**
 * IsbnValidator
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-datafilter
 */

namespace Temma\Utils\Validation;

use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Validator for ISBNs.
 */
class IsbnValidator implements Validator {
	/**
	 * Validate data.
	 * @param	mixed	$data		Data to validate.
	 * @param	array	$contract	(optional) Contract parameters.
	 * @param	mixed	&$output	(optional) Reference to output variable.
	 * @return	mixed	The filtered data.
	 * @throws	\Temma\Exceptions\Application	If the data is invalid.
	 */
	public function validate(mixed $data, array $contract=[], mixed &$output=null) : mixed {
		if (!is_string($data))
			return $this->_processDefault($contract, "Data is not a valid ISBN.", $output);
		$data = preg_replace('/[^0-9X]/', '', mb_strtoupper($data));
		$len = mb_strlen($data);
		if ($len != 10 && $len != 13)
			return $this->_processDefault($contract, "Data is not a valid ISBN.", $output);
		if ($len == 10) {
			$sum = 0;
			for ($i = 0; $i < 9; $i++)
				$sum += (int)mb_substr($data, $i, 1) * (10 - $i);
			$check = (11 - ($sum % 11)) % 11;
			$check = ($check == 10) ? 'X' : (string)$check;
			if (mb_substr($data, 9, 1) != $check)
				return $this->_processDefault($contract, "Data is not a valid ISBN-10.", $output);
		} else {
			$sum = 0;
			for ($i = 0; $i < 12; $i++)
				$sum += (int)mb_substr($data, $i, 1) * (($i % 2 == 0) ? 1 : 3);
			$check = (10 - ($sum % 10)) % 10;
			if (mb_substr($data, 12, 1) != $check)
				return $this->_processDefault($contract, "Data is not a valid ISBN-13.", $output);
		}
		$output = $data;
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
		return $this->validate($default, output: $output);
	}
}

