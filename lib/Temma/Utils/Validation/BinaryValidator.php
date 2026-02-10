<?php

/**
 * BinaryValidator
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-datafilter
 */

namespace Temma\Utils\Validation;

use \Temma\Utils\Text as TµText;
use \Temma\Exceptions\IO as TµIOException;
use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Validator for binary data.
 */
class BinaryValidator implements Validator {
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
		$default = $contract['default'] ?? null;
		$minLen = TµText::parseSize($contract['minLen'] ?? null);
		$maxLen = TµText::parseSize($contract['maxLen'] ?? null);
		$mime = $contract['mime'] ?? null;
		$charset = $contract['charset'] ?? null;
		// check parameters
		if (isset($mime)) {
			if (is_string($mime))
				$mime = array_map('trim', explode(',', $mime));
			else if (!is_array($mime))
				throw new TµIOException("Bad contract 'mime' parameter.", TµIOException::BAD_FORMAT);
		}
		if (isset($charset)) {
			if (is_string($charset))
				$charset = array_map('trim', explode(',', $charset));
			else if (!is_array($charset))
				throw new TµIOException("Bad contract 'charset' parameter.", TµIOException::BAD_FORMAT);
		}
		// check data type 
		if (!is_string($data))
			return $this->_processDefault($contract, "Data is not a string.");
		// check for empty buffer
		if (empty($data))
			return $this->_processDefault($contract, "Binary data is empty.");
		// check size
		if ($minLen || $maxLen) {
			$len = mb_strlen($data, 'ascii');
			if ($maxLen && $len > $maxLen) {
				if ($strict)
					return $this->_processDefault($contract, "Data size doesn't respect the contract.");
				$data = substr($data, 0, $maxLen);
			}
			if ($minLen && $len < $minLen)
				return $this->_processDefault($contract, "Data size doesn't respect the contract.");
		}
		// detect MIME type and charset
		$finfo = new \finfo(FILEINFO_MIME);
		$mimeInfo = $finfo->buffer($data);
		if ($mimeInfo === false) {
			// finfo failed, check if a MIME validation was requested
			if ($mime)
				return $this->_processDefault($contract, "Unable to detect MIME type.");
			// no MIME validation requested
			return [
				'binary'  => $data,
				'mime'    => null,
				'charset' => null,
			];
		}
		$parts = explode(';', $mimeInfo);
		$detectedMime = trim($parts[0]);
		$detectedCharset = null;
		if (isset($parts[1]) && str_contains($parts[1], 'charset=')) {
			$detectedCharset = trim(str_replace('charset=', '', $parts[1]));
			$detectedCharset = ($detectedCharset == 'us-ascii') ? 'ascii' : $detectedCharset;
		}
		// check MIME type constraint
		if ($mime) {
			$found = false;
			foreach ($mime as $m) {
				$m = trim($m);
				if ($detectedMime === $m || str_starts_with($detectedMime, "$m/")) {
					$found = true;
					break;
				}
			}
			if (!$found)
				return $this->_processDefault($contract, "Data doesn't respect contract (bad MIME type '$detectedMime').");
		}
		// charset validation/conversion
		if ($charset && $detectedCharset) {
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
					return $this->_processDefault($contract, "Data doesn't respect contract (charset mismatch: expected one of [" . implode(', ', $charset) . "], got '$detectedCharset').");
				$data = mb_convert_encoding($data, $targetCharset, $detectedCharset);
				$detectedCharset = $targetCharset;
			}
		}
		return ([
			'binary'  => $data,
			'mime'    => $detectedMime,
			'charset' => $detectedCharset,
		]);
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

