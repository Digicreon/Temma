<?php

/**
 * JsonValidator
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-datafilter
 */

namespace Temma\Utils\Validation;

use \Temma\Exceptions\IO as TµIOException;
use \Temma\Exceptions\Application as TµApplicationException;
use \Temma\Utils\Validation\DataFilter as TµDataFilter;
use \Temma\Utils\Text as TµText;

/**
 * Validator for JSON strings.
 */
class JsonValidator implements Validator {
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
		$minLen = TµText::parseSize($contract['minLen'] ?? null);
		$maxLen = TµText::parseSize($contract['maxLen'] ?? null);
		$subcontract = $contract['contract'] ?? null;
		$inline = $contract['inline'] ?? false;
		$isDefault = $contract['isDefault'] ?? false;
		// check type
		if (!is_string($data) || !$data)
			return $this->_processDefault($contract, "Data is not a valid JSON string.", $output);
		// check size
		if ($minLen || $maxLen) {
			$len = mb_strlen($data, 'ascii');
			if (($minLen && $len < $minLen) || ($maxLen && $len > $maxLen))
				return $this->_processDefault($contract, "JSON data size doesn't respect minLen/maxLen.", $output);
		}
		// decode
		$decoded = json_decode($data, true);
		if (json_last_error() !== JSON_ERROR_NONE)
			return $this->_processDefault($contract, "Data is not a valid JSON string.", $output);
		if (!$subcontract) {
			$output = $decoded;
			return (($inline && $isDefault) ? $decoded : $data);
		}
		// validate the JSON content using the given contract
		try {
			$result = TµDataFilter::process($decoded, $subcontract, $strict, $inline);
			$output = $decoded;
			return ($data);
		} catch (TµApplicationException $e) {
			return $this->_processDefault($contract, $e->getMessage(), $output);
		}
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
		$contract['inline'] = true;
		$contract['isDefault'] = true;
		return $this->validate($default, $contract, $output);
	}
}

