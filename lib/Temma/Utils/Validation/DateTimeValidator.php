<?php

/**
 * DateTimeValidator
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-datafilter
 */

namespace Temma\Utils\Validation;

use \Temma\Exceptions\IO as TµIOException;
use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Validator for date/time values.
 */
class DateTimeValidator implements Validator, JsonSchemaExportable {
	/**
	 * Validate data.
	 * @param	mixed	$data		Data to validate.
	 * @param	array	$contract	(optional) Contract parameters.
	 * @param	mixed	&$output	(optional) Reference to output variable.
	 * @return	mixed	The filtered data.
	 * @throws	\Temma\Exceptions\Application	If the data is invalid.
	 */
	public function validate(mixed $data, array $contract=[], mixed &$output=null) : mixed {
		// get parameters
		$strict = $contract['strict'] ?? false;
		$min = $contract['min'] ?? null;
		$max = $contract['max'] ?? null;
		$format = $contract['format'] ?? null;
		$inFormat = $contract['inFormat'] ?? $format ?? 'Y-m-d H:i:s';
		$outFormat = $contract['outFormat'] ?? $format ?? 'Y-m-d H:i:s';
		// manage input value
		$d = false;
		if (is_int($data) || is_float($data) || is_numeric($data)) {
			$d = \DateTimeImmutable::createFromFormat('U', (string)$data);
		} else if (is_string($data)) {
			$d = \DateTimeImmutable::createFromFormat($inFormat, $data);
			if ($d && $strict && $d->format($inFormat) != $data)
				return $this->_processDefault($contract, "Data is not a valid date/time, or bad input format.", $output);
		} else {
			return $this->_processDefault($contract, "Data is not a valid date/time (not a string or a number).", $output);
		}
		if ($d === false)
			return $this->_processDefault($contract, "Data is not a valid date/time, or bad input format.", $output);
		if ($min) {
			if (($dMin = \DateTimeImmutable::createFromFormat($inFormat, $min)) === false)
				throw new TµIOException("Min value is not a valid date/time, or bad input format.", TµIOException::BAD_FORMAT);
			if ($d < $dMin) {
				if ($strict)
					return $this->_processDefault($contract, "Data doesn't respect contract (date/time too early).", $output);
				$d = $dMin;
			}
		}
		if ($max) {
			if (($dMax = \DateTimeImmutable::createFromFormat($inFormat, $max)) === false)
				throw new TµIOException("Max value is not a valid date/time, or bad input format.", TµIOException::BAD_FORMAT);
			if ($d > $dMax) {
				if ($strict)
					return $this->_processDefault($contract, "Data doesn't respect contract (date/time too late).", $output);
				$d = $dMax;
			}
		}
		$tz = $d->getTimezone();
		$tzName = $tz ? $tz->getName() : null;
		$output = [
			'iso'       => $d->format(\DateTimeInterface::RFC3339_EXTENDED),
			'timestamp' => $d->getTimestamp(),
			'timezone'  => $tzName,
			'offset'    => $d->getOffset(),
			'year'      => (int)$d->format('Y'),
			'month'     => (int)$d->format('m'),
			'day'       => (int)$d->format('d'),
			'hour'      => (int)$d->format('H'),
			'minute'    => (int)$d->format('i'),
			'second'    => (int)$d->format('s'),
			'micro'     => (int)$d->format('u'),
		];
		return ($d->format($outFormat));
	}
	/**
	 * Convert a contract to JSON Schema.
	 * @param	array	$contract	Contract parameters.
	 * @return	array	The JSON Schema.
	 */
	public function toJsonSchema(array $contract) : array {
		$format = $contract['inFormat'] ?? $contract['format'] ?? 'Y-m-d H:i:s';
		$schema = ['type' => 'string'];
		// standard formats (RFC 3339)
		if (in_array($format, ['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:sp', 'Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:s.vp', 'Y-m-d\TH:i:s.uP', 'Y-m-d\TH:i:s.up']))
			$schema['format'] = 'date-time';
		else if ($format == 'Y-m-d')
			$schema['format'] = 'date';
		else if (in_array($format, ['H:i:sP', 'H:i:sp']))
			$schema['format'] = 'time';
		else // other formats: an example gives the expected format
			$schema['examples'] = [(new \DateTimeImmutable('2026-01-31 14:30:00'))->format($format)];
		return ($schema);
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

