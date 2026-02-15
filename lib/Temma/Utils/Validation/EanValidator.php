<?php

/**
 * EanValidator
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-datafilter
 */

namespace Temma\Utils\Validation;

use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Validator for EANs.
 */
class EanValidator implements Validator {
	/**
	 * Validate data.
	 * @param	mixed	$data		Data to validate.
	 * @param	array	$contract	Contract parameters.
	 * @return	mixed	The filtered data.
	 * @throws	\Temma\Exceptions\Application	If the data is invalid.
	 */
	public function validate(mixed $data, array $contract=[]) : mixed {
		if (!is_string($data) && !is_int($data))
			return $this->_processDefault($contract, "Data is not a valid EAN.");
		$data = preg_replace('/[^0-9]/', '', (string)$data);
		$len = mb_strlen($data);
		if ($len != 8 && $len != 13)
			return $this->_processDefault($contract, "Data is not a valid EAN.");
		$sum = 0;
		for ($i = 0; $i < $len - 1; $i++)
			$sum += (int)mb_substr($data, $i, 1) * (($i % 2 == ($len == 13 ? 1 : 0)) ? 3 : 1);
		$check = (10 - ($sum % 10)) % 10;
		if (mb_substr($data, ($len - 1), 1) != $check)
			return $this->_processDefault($contract, "Data is not a valid EAN.");
		return ($data);
	}
	/** Manage default value. */
	private function _processDefault(array $contract, string $exceptionMsg) : mixed {
		$default = $contract['default'] ?? null;
		if (is_null($default))
			throw new TµApplicationException($exceptionMsg, TµApplicationException::API);
		return $this->validate($default);
	}
}

