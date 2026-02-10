<?php

/**
 * UuidValidator
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-datafilter
 */

namespace Temma\Utils\Validation;

use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Validator for UUIDs.
 */
class UuidValidator implements Validator {
	/**
	 * Validate data.
	 * @param	mixed	$data		Data to validate.
	 * @param	array	$contract	Contract parameters.
	 * @return	mixed	The filtered data.
	 * @throws	\Temma\Exceptions\Application	If the data is invalid.
	 */
	public function validate(mixed $data, array $contract=[]) : mixed {
		if (!is_string($data))
			throw new TµApplicationException("Data is not a valid UUID (not a string).", TµApplicationException::API);
		// check data
		if (is_string($data) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $data))
			return mb_strtolower($data);
		// default value
		$default = $contract['default'] ?? null;
		if (is_null($default))
			throw new TµApplicationException("Data is not a valid UUID.", TµApplicationException::API);
		$contract['default'] = null;
		return $this->validate($default, $contract);
	}
}

