<?php

/**
 * BoolValidator
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-datafilter
 */

namespace Temma\Utils\Validation;

use \Temma\Exceptions\IO as TµIOException;
use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Validator for boolean values.
 */
class BoolValidator implements Validator {
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
		// extract contract parameters
		$strict = $contract['strict'] ?? false;
		$inline = $contract['inline'] ?? false;
		$default = $contract['default'] ?? null;
		$type = $contract['currentType'] ?? 'bool';
		// specific handling for 'false' type
		if ($type === 'false') {
			if (($strict && $data === false) || (!$strict && !$data)) {
				$output = false;
				return (false);
			}
			$data = ($inline && $default === 'false') ? false : $default;
			if (($strict && $data === false) || (!$strict && !$data)) {
				$output = false;
				return (false);
			}
			throw new TµApplicationException("Data is not false.", TµApplicationException::API);
		}
		// specific handling for 'true' type
		if ($type === 'true') {
			if (($strict && $data === true) || (!$strict && $data)) {
				$output = true;
				return (true);
			}
			$data = ($inline && $default === 'true') ? true : $default;
			if (($strict && $data === true) || (!$strict && $data)) {
				$output = true;
				return (true);
			}
			throw new TµApplicationException("Data is not true.", TµApplicationException::API);
		}
		/* standard 'bool' type */
		// non strict contract
		if (!$strict) {
			$output = (bool)$data;
			return ((bool)$data);
		}
		// strict contract
		if (is_bool($data)) {
			$output = $data;
			return ($data);
		}
		if ($inline) {
			if ($default === 'true')
				$default = true;
			else if ($default === 'false')
				$default = false;
		}
		if (isset($default)) {
			if (is_bool($default)) {
				$output = $default;
				return ($default);
			}
			throw new TµIOException("Bad contract 'default' parameter.", TµIOException::BAD_FORMAT);
		}
		throw new TµApplicationException("Data is not boolean.", TµApplicationException::API);
	}
}

