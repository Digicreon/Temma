<?php

/**
 * Base64Validator
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-datafilter
 */

namespace Temma\Utils\Validation;

use \Temma\Exceptions\IO as TµIOException;
use \Temma\Exceptions\Application as TµApplicationException;
use \Temma\Utils\Text as TµText;
use \Temma\Utils\Validation\BinaryValidator as TµBinaryValidator;

/**
 * Validator for Base64 encoded strings.
 */
class Base64Validator implements Validator {
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
		$minLen = TµText::parseSize($contract['minLen'] ?? null);
		$maxLen = TµText::parseSize($contract['maxLen'] ?? null);
		$mime = $contract['mime'] ?? null;
		if (isset($mime)) {
			if (is_string($mime))
				$mime = array_map('trim', explode(',', $mime));
			else if (!is_array($mime))
				throw new TµIOException("Bad contract 'mime' parameter.", TµIOException::BAD_FORMAT);
		}
		// validation
		if (!is_string($data) || !$data || ($decoded = base64_decode($data, true)) === false)
			return $this->_processDefault($contract, "Data is not a valid base64 string.");
		// check size
		if ($minLen || $maxLen) {
			$len = mb_strlen($decoded, 'ascii');
			if (($minLen && $len < $minLen) || ($maxLen && $len > $maxLen))
				return $this->_processDefault($contract, "Data size doesn't respect the contract.");
		}
		// strict check: re-encode and compare
		if ($strict && base64_encode($decoded) !== $data)
			return $this->_processDefault($contract, "Data is not a valid base64 string.");
		// check MIME type
		if ($mime) {
			try {
				// Delegate to BinaryValidator for MIME check on decoded data
				$validator = new TµBinaryValidator();
				$binaryContract = ['mime' => $mime];
				$binData = $validator->validate($decoded, $binaryContract);
				if (isset($binData['binary']))
					return ($binData['binary']);
				throw new TµApplicationException("Data is not a valid binary string.", TµApplicationException::API);
			} catch (TµApplicationException $e) {
				return $this->_processDefault($contract, $e->getMessage());
			}
		}
		return ($decoded);
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

