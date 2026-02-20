<?php

/**
 * FloatValidator
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-datafilter
 */

namespace Temma\Utils\Validation;

use \Temma\Exceptions\IO as TµIOException;
use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Validator for float values.
 */
class FloatValidator implements Validator {
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
		$inline = $contract['inline'] ?? false;
		$min = $contract['min'] ?? null;
		$max = $contract['max'] ?? null;
		// manage inline contract
		if ($inline || !$strict) {
			if (is_numeric($min))
				$min = (float)$min;
			if (is_numeric($max))
				$max = (float)$max;
		}
		// converts booleans and integers
		if (!$strict) {
			if (is_bool($data))
				$data = $data ? 1.0 : 0.0;
			else if (is_int($data))
				$data = (float)$data;
		}
		// strict mode: check min/max type
		if ($strict && ((isset($min) && !is_float($min)) ||
		                (isset($max) && !is_float($max)))) {
			throw new TµIOException("Bad contract min/max parameter.", TµIOException::BAD_FORMAT);
		}
		// manage float input
		if (is_float($data)) {
			if ($strict) {
				if ((isset($min) && $data < $min) ||
				    (isset($max) && $data > $max))
					return $this->_processDefault($contract, "Data doesn't respect contract (out of range float).", $output);
			} else {
				if (is_numeric($min))
					$data = max($data, (float)$min);
				if (is_numeric($max))
					$data = min($data, (float)$max);
			}
			$output = $data;
			return ($data);
		}
		// strict mode and not a float: try the default value
		if ($strict)
			return $this->_processDefault($contract, "Data doesn't respect contract (can't cast to float).", $output);
		// converts string input
		if (($in = filter_var($data, FILTER_VALIDATE_FLOAT, FILTER_FLAG_ALLOW_THOUSAND)) === false)
			return $this->_processDefault($contract, "Data doesn't respect contract (can't cast to float).", $output);
		$data = $in;
		if (isset($min))
			$data = max($data, (float)$min);
		if (isset($max))
			$data = min($data, (float)$max);
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
		if (($contract['inline'] ?? false) && is_numeric($default))
			$default = (float)$default;
		$contract['default'] = null;
		return $this->validate($default, $contract, $output);
	}
}

