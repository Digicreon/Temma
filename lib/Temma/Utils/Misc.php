<?php

/**
 * Misc
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 * @link	https://www.temma.net/en/documentation/helper-misc
 */

namespace Temma\Utils;

use \Temma\Base\Log as TµLog;

/**
 * Helper object.
 */
class Misc {
	/**
	 * Clone any kind of variable. For arrays, clone all its content.
	 * @param	mixed	$input	The data to clone.
	 * @return	mixed	The cloned data.
	 * @see	https://stackoverflow.com/questions/1532618/is-there-a-function-to-make-a-copy-of-a-php-array-to-another#17729234
	 */
	static public function clone(mixed $input) : mixed {
		if (is_null($input))
			return (null);
		if (is_object($input))
			return (clone $input);
		if (is_array($input)) {
			$res = array_map(function($elem) {
				if (is_array($elem))
					return (self::clone($elem));
				if (is_object($elem))
					return (clone $elem);
				return ($elem);
			}, $input);
			return ($res);
		}
		return ($input);
	}
}

