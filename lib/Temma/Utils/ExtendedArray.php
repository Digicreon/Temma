<?php

/**
 * ExtendedArray.
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2024, Amaury Bouchard
 * @link	https://www.temma.net/en/documentation/helper-extendedarray
 */

namespace Temma\Utils;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\IO as TµIOException;

/**
 * Object used to add features on top of PHP regular arrays.
 */
class ExtendedArray {
	/**
	 * Merge two arrays.
	 * For simple lists (numeric keys), act like array_merge_recursive().
	 * For associative arrays (string keys), act like array_replace_recursive().
	 * If a key with '__prepend' suffix exists in the second array, its content is added at the beginning of the list in the first array.
	 * @param	?array	$array1	The first array.
	 * @param	?array	$array2	The second array.
	 * @return	array	The merged array.
	 */
	static public function fusion(?array $array1, ?array $array2) : array {
		if (!$array2)
			return ($array1 ?: []);
		if (!$array1)
			$array1 = [];
		foreach ($array2 as $key => $value) {
			// numeric key, act like array_merge_recursive()
			if (is_int($key)) {
				$array1[] = $value;
				continue;
			}
			// 'prepend' textual key
			if (str_ends_with($key, '__prepend')) {
				$realKey = mb_substr($key, 0, -mb_strlen('__prepend'));
				if (!isset($array1[$realKey]))
					$array1[$realKey] = $value;
				else if (is_array($array1[$realKey]) && is_array($value))
					$array1[$realKey] = array_merge($value, $array1[$realKey]);
				else if (is_array($array1[$realKey]))
					array_unshift($array1[$realKey], $value);
				else if (is_array($value)) {
					$value[] = $array1[$realKey];
					$array1[$realKey] = $value;
				} else
					$array1[$realKey] = $value;
				continue;
			}
			// textual key, undefined in the first array
			if (!isset($array1[$key])) {
				$array1[$key] = $value;
				continue;
			}
			// two arrays: recursive merges
			if (is_array($array1[$key]) && is_array($value))
				$array1[$key] = self::fusion($array1[$key], $value);
			else if (is_array($array1[$key]))
				$array1[$key][] = $value;
			else if (is_array($value))
				$array1[$key] = array_merge([$array1[$key]], $value);
			else
				$array1[$key] = $value;
		}
		return ($array1);
	}
}

