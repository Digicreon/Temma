<?php

/**
 * ColorValidator
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-datafilter
 */

namespace Temma\Utils\Validation;

use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Validator for hexadecimal colors.
 */
class ColorValidator implements Validator {
	/**
	 * Validate data.
	 * @param	mixed	$data		Data to validate.
	 * @param	array	$contract	(optional) Contract parameters.
	 * @param	mixed	&$output	(optional) Reference to output variable.
	 * @return	mixed	The filtered data.
	 * @throws	\Temma\Exceptions\Application	If the data is invalid.
	 */
	public function validate(mixed $data, array $contract=[], mixed &$output=null) : mixed {
		if (!is_string($data) || !preg_match('/^#?(?:[0-9a-f]{3}){1,2}$/i', $data)) {
			$default = $contract['default'] ?? null;
			if (is_null($default))
				throw new TµApplicationException("Data is not a valid hex color.", TµApplicationException::API);
			return $this->validate($default, output: $output);
		}
		if (mb_substr($data, 0, 1) != '#')
			$data = '#' . $data;
		$data = mb_strtolower($data);
		if (mb_strlen($data) == 4) {
			$data = '#' . mb_substr($data, 1, 1) . mb_substr($data, 1, 1) .
			              mb_substr($data, 2, 1) . mb_substr($data, 2, 1) .
			              mb_substr($data, 3, 1) . mb_substr($data, 3, 1);
		}
		$output = $data;
		return ($data);
	}
}

