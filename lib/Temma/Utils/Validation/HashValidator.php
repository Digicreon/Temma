<?php

/**
 * HashValidator
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-datafilter
 */

namespace Temma\Utils\Validation;

use \Temma\Utils\Text as TµText;
use \Temma\Exceptions\IO as TµIOException;
use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Validator for hashes.
 */
class HashValidator implements Validator {
	/**
	 * Validate data.
	 * @param	mixed	$data		Data to validate.
	 * @param	array	$contract	Contract parameters.
	 * @return	mixed	The filtered data.
	 * @throws	\Temma\Exceptions\Application	If the data is invalid.
	 */
	public function validate(mixed $data, array $contract=[]) : mixed {
		// get parameters
		$algorithms = $contract['algo'] ?? null;
		$source = $contract['source'] ?? null;
		// check contract
		if (!$algorithms)
			throw new TµIOException("Empty hash algorithm.", TµIOException::BAD_FORMAT);
		if (is_string($algorithms))
			$algorithms = array_map('trim', explode(',', $algorithms));
		else if (!is_array($algorithms))
			throw new TµIOException("Bad contract 'algo' parameter.", TµIOException::BAD_FORMAT);
		// check data type
		if (!is_string($data) || !ctype_xdigit($data))
			return $this->_processDefault($contract, "Data doesn't respect contract (not a valid non-empty hexadecimal string).");
		// loop on algorithms
		foreach ($algorithms as $algo) {
			// compute targeted hash length
			try {
				$test = \hash($algo, 'a');
				$algoLength = mb_strlen($test, 'ascii');
				unset($test);
			} catch (\ValueError $ve) {
				throw new TµIOException("Unknown hash algorithm '$algo'.", TµIOException::BAD_FORMAT);
			}
			// check data length
			$len = mb_strlen($data, 'ascii');
			if ($len != $algoLength)
				continue;
			// compare to source
			if (!$source)
				return ($data);
			$computed = \hash($algo, $source);
			if ($computed == $data)
				return ($data);
		}
		return $this->_processDefault($contract, "Data doesn't respect any given contract.");
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

