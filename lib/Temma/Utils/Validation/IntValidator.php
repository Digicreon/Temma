<?php

/**
 * IntValidator
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-datafilter
 */

namespace Temma\Utils\Validation;

use \Temma\Exceptions\IO as TµIOException;
use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Validator for integer values.
 */
class IntValidator implements Validator {
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
		$strict = $contract['strict'] ?? false;
		$inline = $contract['inline'] ?? false;
		$min = $contract['min'] ?? null;
		$max = $contract['max'] ?? null;
		$type = $contract['currentType'] ?? 'int';
		// manage inline contracts
		if ($inline || !$strict) {
			if (is_numeric($min))
				$min = (int)$min;
			if (is_numeric($max))
				$max = (int)$max;
		}
		// manage port type
		if ($type === 'port') {
			$min ??= 1;
			$max = min(($max ?? 65535), 65535);
		}
		// converts booleans and floats
		if (!$strict) {
			if (is_bool($data))
				$data = $data ? 1 : 0;
			else if (is_float($data))
				$data = (int)$data;
		}
		// strict mode: check min/max type
		if ($strict && ((isset($min) && !is_int($min)) ||
		                (isset($max) && !is_int($max)))) {
			throw new TµIOException("Bad contract min/max parameter.", TµIOException::BAD_FORMAT);
		}
		// manage integer input
		if (is_int($data)) {
			if ($strict || $type == 'port') {
				if ((isset($min) && $data < $min) ||
				    (isset($max) && $data > $max))
					return $this->_processDefault($contract, "Data doesn't respect contract (out of range integer).", $output);
			} else {
				if (is_numeric($min))
					$data = max($data, (int)$min);
				if (is_numeric($max))
					$data = min($data, (int)$max);
			}
			$output = $data;
			return ($data);
		}
		// strict mode and not an integer: try the default value
		if ($strict)
			return $this->_processDefault($contract, "Data doesn't respect contract (can't cast to int).", $output);
		// converts string input
		if (($in2 = filter_var($data, FILTER_VALIDATE_INT, FILTER_FLAG_ALLOW_OCTAL | FILTER_FLAG_ALLOW_HEX)) === false) {
			if (($in2 = filter_var($data, FILTER_VALIDATE_FLOAT, FILTER_FLAG_ALLOW_THOUSAND)) === false)
				return $this->_processDefault($contract, "Data doesn't respect contract (can't cast to int).", $output);
			$in2 = (int)$in2;
		}
		$data = $in2;
		if ($type == 'port') {
			if ((isset($min) && $data < $min) ||
			    (isset($max) && $data > $max))
				return $this->_processDefault($contract, "Data doesn't respect contract (out of range port number).", $output);
		} else {
			if (isset($min))
				$data = max($data, (int)$min);
			if (isset($max))
				$data = min($data, (int)$max);
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
		if (($contract['inline'] ?? false) && is_numeric($default))
			$default = (int)$default;
		$contract['default'] = null;
		return $this->validate($default, $contract, $output);
	}
}

