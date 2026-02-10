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
	 * @param	array	$contract	Contract parameters.
	 * @return	mixed	The filtered data.
	 * @throws	\Temma\Exceptions\Application	If the data is invalid.
	 */
	public function validate(mixed $data, array $contract=[]) : mixed {
		// get parameters
		$strict = $contract['strict'] ?? false;
		$minLen = TµText::parseSize($contract['minLen'] ?? null);
		$maxLen = TµText::parseSize($contract['maxLen'] ?? null);
		$subcontract = $contract['contract'] ?? null;
		// check type
		if (!is_string($data) || !$data)
			return $this->_processDefault($contract, "Data is not a valid JSON string.");
		// check size
		if ($minLen || $maxLen) {
			$len = mb_strlen($data, 'ascii');
			if (($minLen && $len < $minLen) || ($maxLen && $len > $maxLen))
				return $this->_processDefault($contract, "JSON data size doesn't respect minLen/maxLen.");
		}
		// decode
		$decoded = json_decode($data, true);
		if (json_last_error() !== JSON_ERROR_NONE)
			return $this->_processDefault($contract, "Data is not a valid JSON string.");
		if (!$subcontract)
			return ($decoded);
		// validate the JSON content using the given contract
		try {
			return TµDataFilter::process($decoded, $subcontract, $strict);
		} catch (TµApplicationException $e) {
			return $this->_processDefault($contract, $e->getMessage());
		}
	}
	/** Manage default value. */
	private function _processDefault(array $contract, string $exceptionMsg) : mixed {
		$default = $contract['default'] ?? null;
		if (is_null($default))
			throw new TµApplicationException($exceptionMsg, TµApplicationException::API);
		$contract['default'] = null;
		return $this->validate($default, $contract);
	}
}

