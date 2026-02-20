<?php

/**
 * GeoValidator
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-datafilter
 */

namespace Temma\Utils\Validation;

use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Validator for geographic coordinates.
 */
class GeoValidator implements Validator {
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
		// check data
		if (!is_string($data) || !preg_match('/^[-+]?([1-8]?\d(\.\d+)?|90(\.0+)?),\s*[-+]?(180(\.0+)?|((1[0-7]\d)|([1-9]?\d))(\.\d+)?)$/', $data))
			return $this->_processDefault($contract, "Data is not a valid geo coordinates string.", $output);
		list($lat, $lon) = array_map('trim', explode(',', $data));
		$lat = (float)$lat;
		$lon = (float)$lon;
		// mini (south-west)
		if ($min) {
			if (is_string($min) && preg_match('/^[-+]?([1-8]?\d(\.\d+)?|90(\.0+)?),\s*[-+]?(180(\.0+)?|((1[0-7]\d)|([1-9]?\d))(\.\d+)?)$/', $min)) {
				list($minLat, $minLon) = array_map('trim', explode(',', $min));
				$minLat = (float)$minLat;
				$minLon = (float)$minLon;
				if ($lat < $minLat || $lon < $minLon) {
					if ($strict)
						return $this->_processDefault($contract, "Data doesn't respect contract (geo coordinates too low).", $output);
					$lat = max($lat, $minLat);
					$lon = max($lon, $minLon);
				}
			}
		}
		// maxi (north-east)
		if ($max) {
			if (is_string($max) && preg_match('/^[-+]?([1-8]?\d(\.\d+)?|90(\.0+)?),\s*[-+]?(180(\.0+)?|((1[0-7]\d)|([1-9]?\d))(\.\d+)?)$/', $max)) {
				list($maxLat, $maxLon) = array_map('trim', explode(',', $max));
				$maxLat = (float)$maxLat;
				$maxLon = (float)$maxLon;
				if ($lat > $maxLat || $lon > $maxLon) {
					if ($strict)
						return $this->_processDefault($contract, "Data doesn't respect contract (geo coordinates too high).", $output);
					$lat = min($lat, $maxLat);
					$lon = min($lon, $maxLon);
				}
			}
		}
		$output = "$lat, $lon";
		return ($output);
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

