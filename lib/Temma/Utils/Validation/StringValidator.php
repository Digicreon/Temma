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
	 * @param	array	$contract	(optional) Contract parameters.
	 * @param	mixed	&$output	(optional) Reference to output variable.
	 * @return	mixed	The filtered data.
	 * @throws	\Temma\Exceptions\IO		If the contract is invalid.
	 * @throws	\Temma\Exceptions\Application	If the data is invalid.
	 */
	public function validate(mixed $data, array $contract=[], mixed &$output=null) : mixed {
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
				return $this->_processDefault($contract, "Data doesn't respect contract (not a string).", $output);
			if (isset($maxLen) && mb_strlen($data) > $maxLen)
				return $this->_processDefault($contract, "Data doesn't respect contract (string too long).", $output);
		} else {
			if (!is_scalar($data))
				return $this->_processDefault($contract, "Data doesn't respect contract (can't cast to string).", $output);
			if (is_bool($data))
				$data = $data ? 'true' : 'false';
			else
				$data = (string)$data;
			if (isset($maxLen))
				$data = mb_substr($data, 0, $maxLen);
		}
		if (isset($minLen) && mb_strlen($data) < $minLen)
			return $this->_processDefault($contract, "Data doesn't respect contract (string too short).", $output);
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
						return $this->_processDefault($contract, "Data doesn't respect contract (charset mismatch: expected one of [" . implode(', ', $charset) . "], got '$detectedCharset').", $output);
					$data = mb_convert_encoding($data, $targetCharset, $detectedCharset);
				}
			}
		}
		if ($mask && !preg_match('{' . $mask . '}u', $data, $matches))
			return $this->_processDefault($contract, "Data doesn't respect contract (string doesn't match the given mask).", $output);
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
		$contract['default'] = null;
		return $this->validate($default, $contract, $output);
	}
}

