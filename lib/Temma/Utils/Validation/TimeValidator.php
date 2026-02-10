<?php

/**
 * TimeValidator
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-datafilter
 */

namespace Temma\Utils\Validation;

/**
 * Validator for times.
 * Delegates to DateTimeValidator.
 */
class TimeValidator extends DateTimeValidator {
	/**
	 * Validate data.
	 * @param	mixed	$data		Data to validate.
	 * @param	array	$contract	Contract parameters.
	 * @return	mixed	The filtered data.
	 * @throws	\Temma\Exceptions\IO		If the contract is invalid.
	 * @throws	\Temma\Exceptions\Application	If the data is invalid.
	 */
	public function validate(mixed $data, array $contract=[]) : mixed {
		$contract['inFormat'] = $contract['inFormat'] ?? $contract['format'] ?? 'H:i:s';
		$contract['outFormat'] = $contract['outFormat'] ?? $contract['format'] ?? 'H:i:s';
		return parent::validate($data, $contract);
	}
}

