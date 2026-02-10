<?php

/**
 * StringValidator
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-datafilter
 */

namespace Temma\Utils\Validation;

use \Temma\Exceptions\IO as TµIOException;
use \Temma\Exceptions\Application as TµApplicationException;
use \Temma\Utils\Text as TµText;

/**
 * Validator for string values.
 */
class StringValidator implements Validator {
	/**
	 * Validate data.
	 * @param	mixed	$data		Data to validate.
	 * @param	array	$contract	Contract parameters.
	 * @return	mixed	The filtered data.
	 * @throws	\Temma\Exceptions\IO		If the contract is invalid.
	 * @throws	\Temma\Exceptions\Application	If the data is invalid.
	 */
	public function validate(mixed $data, array $contract=[]) : mixed {
		// get parameters
		$strict = $contract['strict'] ?? false;
		$mask = $contract['mask'] ?? null;
		$charset = $contract['charset'] ?? null;
		$minLen = TµText::parseSize($contract['minLen'] ?? null);
		$maxLen = TµText::parseSize($contract['maxLen'] ?? null);
		// ensure charset is an array if provided
		if (is_string($charset))
			$charset = array_map('trim', explode(',', $charset));
		// process
		if ($strict) {
			if (!is_string($data))
				return $this->_processDefault($contract, "Data doesn't respect contract (not a string).");
			if (isset($maxLen) && mb_strlen($data) > $maxLen)
				return $this->_processDefault($contract, "Data doesn't respect contract (string too long).");
		} else {
			if (!is_scalar($data))
				return $this->_processDefault($contract, "Data doesn't respect contract (can't cast to string).");
			if (is_bool($data))
				$data = $data ? 'true' : 'false';
			else
				$data = (string)$data;
			if (isset($maxLen))
				$data = mb_substr($data, 0, $maxLen);
		}
		if (isset($minLen) && mb_strlen($data) < $minLen)
			return $this->_processDefault($contract, "Data doesn't respect contract (string too short).");
		// charset validation/conversion
		if ($charset) {
			$detectedCharset = mb_detect_encoding($data, mb_detect_order(), true);
			if ($detectedCharset) {
				$targetCharset = $charset[0]; // first = target
				$detectedLower = mb_strtolower($detectedCharset);
				$foundCharset = false;
				foreach ($charset as $acceptedCharset) {
					if ($detectedLower === mb_strtolower($acceptedCharset)) {
						$foundCharset = true;
						break;
					}
				}
				if (!$foundCharset) {
					if ($strict)
						return $this->_processDefault($contract, "Data doesn't respect contract (charset mismatch: expected one of [" . implode(', ', $charset) . "], got '$detectedCharset').");
					$data = mb_convert_encoding($data, $targetCharset, $detectedCharset);
				}
			}
		}
		if ($mask && !preg_match('{' . $mask . '}u', $data, $matches))
			return $this->_processDefault($contract, "Data doesn't respect contract (string doesn't match the given mask).");
		return ($data);
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

