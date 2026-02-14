<?php

/**
 * ListValidator
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
 * Validator for lists (indexed arrays).
 */
class ListValidator implements Validator {
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
		$values = $contract['values'] ?? null;
		// check data
		if (!is_array($data))
			return $this->_processDefault($contract, "Data doesn't respect contract (not a list).");
		// check size
		if ($minLen || $maxLen) {
			$count = count($data);
			if (isset($maxLen) && $count > $maxLen) {
				if ($strict)
					return $this->_processDefault($contract, "Data size doesn't respect the contract (list too long).");
				$data = array_slice($data, 0, $maxLen);
			}
			if (($minLen && $count < $minLen))
				return $this->_processDefault($contract, "Data size doesn't respect the contract (list too short).");
		}
		// check values from specific types
		if ($values) {
			if (is_string($values))
				$values = str_getcsv($values, ',', '"', '\\');
			else if (!is_array($values))
				throw new TµApplicationException("Bad contract 'values' parameter.", TµApplicationException::API);
                        $truncate = null; // if the list must be truncated: index of the first element to remove
                        $global = false; // if true, the rest of the list will accepted as is
			foreach ($data as $k => &$v) {
				if (!is_int($k))
					return $this->_processDefault($contract, "Data doesn't respect contract (not a list).");
                                if (!array_key_exists($k, $values)) {
                                        if ($strict)
                                            return $this->_processDefault($contract, "Data doesn't respect contract (bad number of elements in list).");
                                        $truncate = $k;
                                        break;
                                }
				$sub = trim($values[$k]);
                                if ($sub == '...') {
                                        $global = true;
                                        break;
                                }
				try {
					$v = TµDataFilter::process($v, $sub, $strict);
				} catch (TµApplicationException $t) {
					return $this->_processDefault($contract, "Data doesn't respect contract (bad value for element {$k}).");
				}
			}
                        if ($strict && !$global && count($data) != count($values))
                            return $this->_processDefault($contract, "Data doesn't respect contract (bad number of elements in list).");
                        if (!is_null($truncate))
                                $data = array_slice($data, 0, $truncate);
			return ($data);
		}
		if ($subcontract === null)
			return ($data);
		foreach ($data as $k => &$v) {
			if (!is_int($k))
				return $this->_processDefault($contract, "Data doesn't respect contract (not a list).");
                        try {
				$v = TµDataFilter::process($v, $subcontract, $strict);
			} catch (TµApplicationException $t) {
				return $this->_processDefault($contract, "Data doesn't respect contract (bad value for element {$k}).");
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

