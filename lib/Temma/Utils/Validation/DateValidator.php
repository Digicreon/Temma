<?php

/**
 * DateValidator
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2020-2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-datafilter
 */

namespace Temma\Utils\Validation;

/**
 * Validator for dates.
 * Delegates to DateTimeValidator.
 */
class DateValidator extends DateTimeValidator {
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
		$contract['inFormat'] = $contract['inFormat'] ?? $contract['format'] ?? 'Y-m-d';
		$contract['outFormat'] = $contract['outFormat'] ?? $contract['format'] ?? 'Y-m-d';
		return parent::validate($data, $contract, $output);
	}
}
