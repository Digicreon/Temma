<?php

/**
 * UrlValidator
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-datafilter
 */

namespace Temma\Utils\Validation;

use \Temma\Utils\Text as TµText;
use \Temma\Exceptions\Application as TµApplicationException;
use \Temma\Exceptions\IO as TµIOException;

/**
 * Validator for URLs.
 */
class UrlValidator implements Validator {
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
		$scheme = $contract['scheme'] ?? null;
		$strict = $contract['strict'];
		// checks
		if (!is_string($data))
			return $this->_processDefault($contract, "Data is not a string.");
		$dataLen = mb_strlen($data);
		if (is_int($maxLen) && $maxLen < $dataLen) {
			if ($strict)
				return $this->_processDefault($contract, "URL is too long ($dataLen for a maximum of $maxLen).");
			$data = mb_substr($data, 0, $maxLen);
		}
		if (is_int($minLen) && $dataLen < $minLen)
			return $this->_processDefault($contract, "Data doesn't respect contract (URL too short).");
		if ($mask && !preg_match('{' . $mask . '}u', $data, $matches))
			return $this->_processDefault($contract, "Data doesn't respect contract (URL doesn't match the given mask).");
		// process
		if (($data = filter_var($data, FILTER_VALIDATE_URL)) === false)
			return $this->_processDefault($contract, "Data is not a valid URL.");
		// check other parameters
		$enableParams = ['scheme', 'host', 'domain', 'port', 'user', 'pass', 'path', 'query', 'fragment'];
		$checkParams = [];
		foreach ($enableParams as $param) {
			if (isset($contract[$param]))
				$checkParams[$param] = array_map('trim', explode(',', $contract[$param]));
		}
		if ($checkParams) {
			// extract data from URL
			$urlData = parse_url($data);
			if ($urlData === false)
				return $this->_processDefault($contract, "Data is not a valid URL.");
			$urlData['domain'] = $urlData['host'] ?? null;
			if (isset($urlData['host']) &&
			    ($pos = mb_strrpos($urlData['host'], '.')) !== false &&
			    ($pos = mb_strrpos($urlData['host'], '.', $pos)) !== false)
				$urlData['domain'] = mb_substr($urlData['host'], $pos + 1);
			// check
			foreach ($checkParams as $param => $subContract) {
				$val = $urlData[$param] ?? null;
				if (!in_array($val, $subContract))
					return $this->_processDefault($contract, "Data doesn't respect contract (bad URL $param).");
			}
		}
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

