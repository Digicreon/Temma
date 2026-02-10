<?php

/**
 * SlugValidator
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-datafilter
 */

namespace Temma\Utils\Validation;

use \Temma\Utils\Text as TµText;
use \Temma\Exceptions\Application as TµApplicationException;
use \Temma\Exceptions\IO as TµIOException;

/**
 * Validator for slugs.
 */
class SlugValidator implements Validator {
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
		$minLen = TµText::parseSize($contract['minLen'] ?? null);
		$maxLen = TµText::parseSize($contract['maxLen'] ?? null);
		$mask = $contract['mask'] ?? null;
		$strict = $contract['strict'];
		// checks
		if (!is_string($data)) {
			if ($strict || !is_scalar($data))
				return $this->_processDefault($contract, "Data is not a string.");
			$data = (string)$data;
		}
		$dataLen = mb_strlen($data);
		if (is_int($maxLen) && $maxLen < $dataLen) {
			if ($strict)
				return $this->_processDefault($contract, "Data doesn't respect contract (slug too long).");
			$data = mb_substr($data, 0, $maxLen);
		}
		if (is_int($minLen) && $dataLen < $minLen)
			return $this->_processDefault($contract, "Data doesn't respect contract (slug too short).");
		if ($mask && !preg_match('{' . $mask . '}u', $data, $matches))
			return $this->_processDefault($contract, "Data doesn't respect contract (slug doesn't match the given mask).");
		// process
		if (!$strict)
			$data = TµText::urlize($data);
		if (!preg_match('/^[a-z0-9-]+$/', $data))
			return $this->_processDefault($contract, "Data is not a valid slug.");
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

