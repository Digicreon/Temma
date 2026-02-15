<?php

/**
 * AssocValidator
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-datafilter
 */

namespace Temma\Utils\Validation;

use \Temma\Exceptions\IO as TµIOException;
use \Temma\Exceptions\Application as TµApplicationException;
use \Temma\Utils\Validation\DataFilter as TµDataFilter;

/**
 * Validator for associative arrays.
 */
class AssocValidator implements Validator {
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
		$keys = $contract['keys'] ?? null;
		if (is_string($keys))
			$keys = array_map('trim', explode(',', $keys));
		else if (!is_array($keys) && $keys !== null)
			throw new TµIOException("Bad contract 'keys' parameter.", TµIOException::BAD_FORMAT);
		if (!$keys)
			throw new TµIOException("Associative array without sub-keys contract.", TµIOException::BAD_FORMAT);
		// validation
		if (!is_array($data))
			return $this->_processDefault($contract, "Data doesn't respect contract (not an array).");
		$out = [];
		$foundWildcard = false;
		$wildcardContract = null;
		foreach ($keys as $k => $v) {
			$key = null;
			$subcontract = null;
			if (is_int($k)) {
				$key = $v;
				$subcontract = [
					'type'      => null,
					'mandatory' => true,
				];
			} else {
				$key = $k;
				$subcontract = $v;
			}
			// check for wildcard (as key or as value in indexed array)
			if ($key === '...' || $key === '…') {
				$foundWildcard = true;
				$wildcardContract = ($subcontract !== '...' && $subcontract !== '…') ? $subcontract : null;
				continue;
			}
			if (is_string($subcontract) && ($subcontract === '...' || $subcontract === '…')) {
				$foundWildcard = true;
				continue;
			}
			// check for optional key
			if (str_ends_with($key, '?')) {
				if (is_array($subcontract)) {
					$subcontract['mandatory'] = false;
				} else {
					$subcontract = [
						'type'      => $subcontract,
						'mandatory' => false,
					];
				}
				$key = mb_substr($key, 0, -1);
			}
			// check for mandatory key
			$mandatory = true;
			if (is_array($subcontract) && isset($subcontract['mandatory'])) {
				$mandatory = $subcontract['mandatory'];
			}
			if (!array_key_exists($key, $data)) {
				if (!$mandatory)
					continue;
				return $this->_processDefault($contract, "Data doesn't respect contract (mandatory key '$key').");
			}
			// process key
			$res = TµDataFilter::process(($data[$key] ?? null), $subcontract, $strict);
			$out[$key] = $res;
		}
		// manage "..." (wildcard)
		if ($foundWildcard) {
			// process extra keys with wildcard contract
			$extra = array_diff_key($data, $out);
			foreach ($extra as $k => $v) {
				if ($wildcardContract) {
					// validate extra key with contract
					$out[$k] = TµDataFilter::process($v, $wildcardContract, $strict);
				} else {
					// no contract: just copy
					$out[$k] = $v;
				}
			}
		} else if ($strict) {
			// check for extra keys
			$extra = array_diff_key($data, $out);
			if (!empty($extra)) {
				$extraStr = implode(', ', array_keys($extra));
				return $this->_processDefault($contract, "Data doesn't respect contract (extra keys '$extraStr').");
			}
		}
		return ($out);
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

