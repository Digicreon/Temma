<?php

/**
 * EmailValidator
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-datafilter
 */

namespace Temma\Utils\Validation;

use \Temma\Exceptions\Application as TµApplicationException;
use \Temma\Utils\Text as TµText;

/**
 * Validator for email addresses.
 */
class EmailValidator implements Validator {
	/**
	 * Validate data.
	 * @param	mixed	$data		Data to validate.
	 * @param	array	$contract	(optional) Contract parameters.
	 * @param	mixed	&$output	(optional) Reference to output variable.
	 * @return	mixed	The filtered data.
	 * @throws	\Temma\Exceptions\Application	If the data is invalid.
	 */
	public function validate(mixed $data, array $contract=[], mixed &$output=null) : mixed {
		$mask = $contract['mask'] ?? null;
		$minLen = TµText::parseSize($contract['minLen'] ?? null);
		$maxLen = TµText::parseSize($contract['maxLen'] ?? null);
		// validation
		if (($data = filter_var($data, FILTER_VALIDATE_EMAIL)) === false)
			return $this->_processDefault($contract, "Data is not a valid email address.", $output);
		if ($mask && !preg_match('{' . $mask . '}u', $data, $matches))
			return $this->_processDefault($contract, "Data doesn't respect contract (email doesn't match the given mask).", $output);
		if (isset($minLen) || isset($maxLen)) {
			$len = mb_strlen($data);
			if (isset($minLen) && $len < $minLen)
				return $this->_processDefault($contract, "Data doesn't respect contract (string too short).", $output);
			if (isset($maxLen) && $len > $maxLen)
				return $this->_processDefault($contract, "Data doesn't respect contract (string too long).", $output);
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
		$contract['default'] = null;
		return $this->validate($default, $contract, $output);
	}
}

