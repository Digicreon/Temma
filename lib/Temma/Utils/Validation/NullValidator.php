<?php

/**
 * NullValidator
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-datafilter
 */

namespace Temma\Utils\Validation;

use \Temma\Exceptions\IO as TµIOException;
use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Validator for null values.
 */
class NullValidator implements Validator {
	/**
	 * Validate data.
	 * @param	mixed	$data		Data to validate.
	 * @param	array	$contract	(optional) Contract parameters.
	 * @return	mixed	The filtered data.
	 * @throws	\Temma\Exceptions\IO		If the contract is invalid.
	 * @throws	\Temma\Exceptions\Application	If the data is invalid.
	 */
	public function validate(mixed $data, array $contract=[]) : mixed {
		$inline = $contract['inline'] ?? false;
		if (is_null($data))
			return (null);
		if (array_key_exists('default', $contract)) {
			if (is_null($contract['default']) || ($inline && $contract['default'] == 'null'))
				return (null);
			throw new TµIOException("Bad contract 'default' value.", TµIOException::BAD_FORMAT);
		}
		throw new TµApplicationException("Data is not null.", TµApplicationException::API);
	}
}

