<?php

/**
 * Json
 */

namespace Temma\Utils;

/**
 * Json utility class.
 */
class Json {
	/**
	 * Decode a JSON string into PHP data. If the JSON stream contains comments, they are discarded.
	 * Takes the same parameters than the json_decode() function.
	 * @link	https://stackoverflow.com/questions/8148797/a-json-parser-for-php-that-supports-comments/43439966#43439966
	 * @param	string	$json		JSON data.
	 * @param	bool	$assoc		(optional) True to generate associative arrays instead of objects. False by default.
	 * @param	int	$depth		(optional) Maximum recursion depth. 512 by default.
	 * @param	int	$options	(optional) Bitmask of option constants. None by default.
	 * @return	mixed	The decoded data.
	 * @link	
	 */
	static public function decode(string $json, bool $assoc=false, int $depth=512, $options = 0) /* : mixed */ {
		$json = preg_replace('~(" (?:[^"\\\\] | \\\\\\\\ | \\\\")*+ ") | \# [^\v]*+ | // [^\v]*+ | /\* .*? \*/~xs', '$1', $json);
		return (json_decode($json, $assoc, $depth, $options));
	}
}

