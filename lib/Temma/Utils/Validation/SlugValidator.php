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
	 * @param	array	$contract	(optional) Contract parameters.
	 * @param	mixed	&$output	(optional) Reference to output variable.
	 * @return	mixed	The filtered data.
	 * @throws	\Temma\Exceptions\IO		If the contract is invalid.
	 * @throws	\Temma\Exceptions\Application	If the data is invalid.
	 */
	public function validate(mixed $data, array $contract=[], mixed &$output=null) : mixed {
		// get parameters
		$minLen = TµText::parseSize($contract['minLen'] ?? null);
		$maxLen = TµText::parseSize($contract['maxLen'] ?? null);
		$mask = $contract['mask'] ?? null;
		$strict = $contract['strict'];
		// checks
		if (!is_string($data)) {
			if ($strict || !is_scalar($data))
				return $this->_processDefault($contract, "Data is not a string.", $output);
			$data = (string)$data;
		}
		$dataLen = mb_strlen($data);
		if (is_int($maxLen) && $maxLen < $dataLen) {
			if ($strict)
				return $this->_processDefault($contract, "Data doesn't respect contract (slug too long).", $output);
			$data = mb_substr($data, 0, $maxLen);
		}
		if (is_int($minLen) && $dataLen < $minLen)
			return $this->_processDefault($contract, "Data doesn't respect contract (slug too short).", $output);
		if ($mask && !preg_match('{' . $mask . '}u', $data, $matches))
			return $this->_processDefault($contract, "Data doesn't respect contract (slug doesn't match the given mask).", $output);
		// process
		$slug = TµText::urlize($data);
		if (!$strict)
			$data = $slug;
		if (!preg_match('/^[a-z0-9-]+$/', $data))
			return $this->_processDefault($contract, "Data is not a valid slug.", $output);
		$output = $slug;
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

