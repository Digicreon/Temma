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
	 * @param	array	$contract	(optional) Contract parameters.
	 * @param	mixed	&$output	(optional) Reference to output variable.
	 * @return	mixed	The filtered data.
	 * @throws	\Temma\Exceptions\IO		If the contract is invalid.
	 * @throws	\Temma\Exceptions\Application	If the data is invalid.
	 */
	public function validate(mixed $data, array $contract=[], mixed &$output=null) : mixed {
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
			return $this->_processDefault($contract, "Data is not a valid base64 string.", $output);
		// check size
		if ($minLen || $maxLen) {
			$len = mb_strlen($decoded, 'ascii');
			if (($minLen && $len < $minLen) || ($maxLen && $len > $maxLen))
				return $this->_processDefault($contract, "Data size doesn't respect the contract.", $output);
		}
		// strict check: re-encode and compare
		if ($strict && base64_encode($decoded) !== $data)
			return $this->_processDefault($contract, "Data is not a valid base64 string.", $output);
		// check MIME type
		$binaryContract = [];
		if ($mime)
			$binaryContract = ['mime' => $mime];
		$validator = new TµBinaryValidator(); // delegate to BinaryValidator for MIME check on decoded data
		try {
			$binOutput = null;
			$binData = $validator->validate($decoded, $binaryContract, $binOutput);
			if (isset($binData)) {
				$output = $binOutput;
				return ($data);
			}
			throw new TµApplicationException("Data is not a valid binary string.", TµApplicationException::API);
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
	private function _processDefault(array $contract, string $exceptionMsg, mixed &$output=null) : mixed {
		$default = $contract['default'] ?? null;
		if (is_null($default))
			throw new TµApplicationException($exceptionMsg, TµApplicationException::API);
		$contract['default'] = null;
		return $this->validate($default, $contract, $output);
	}
}

