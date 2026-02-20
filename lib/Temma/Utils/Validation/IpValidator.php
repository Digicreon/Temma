<?php

/**
 * IpValidator
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-datafilter
 */

namespace Temma\Utils\Validation;

use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Validator for IP addresses (v4 and v6).
 */
class IpValidator implements Validator {
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
		$type = $contract['currentType'] ?? 'ip';
		// define IP type
		$flags = FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6;
		if ($type === 'ipv4')
			$flags = FILTER_FLAG_IPV4;
		else if ($type === 'ipv6')
			$flags = FILTER_FLAG_IPV6;
		if (is_string($data) && filter_var($data, FILTER_VALIDATE_IP, $flags)) {
			$output = $data;
			return ($data);
		}
		// default value
		$default = $contract['default'] ?? null;
		if ($default !== null) {
			$contract['default'] = null;
			return $this->validate($default, $contract, $output);
		}
		$msg = "Data is not a valid IP address.";
		if ($type === 'ipv4')
			$msg = "Data is not a valid IPv4 address.";
		else if ($type === 'ipv6')
			$msg = "Data is not a valid IPv6 address.";
		throw new TµApplicationException($msg, TµApplicationException::API);
	}
}

