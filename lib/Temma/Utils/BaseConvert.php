<?php

/**
 * BaseConvert
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2017-2023 Amaury Bouchard
 */

namespace Temma\Utils;

/**
 * Big int converter.
 * @see	http://stackoverflow.com/questions/4964197/converting-a-number-base-10-to-base-62-a-za-z0-9
 * @see	http://www.technischedaten.de/pmwiki2/pmwiki.php?n=Php.BaseConvert
 * @see	http://i.imgur.com/gfYw57t.png
 * @see	https://openauthentication.org/token-specs/
 */
class BaseConvert {
	/** Base 31, same as base 36 (0-9a-z) without characters '0o1il'. */
	const BASE31 = '23456789abcdefghjkmnpqrstuvwxyz';
	/** Base 54, same as base 62 (0-9a-zA-Z) without characters '0oO1iIlL'. */
	const BASE54 = '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ';
	/** Base 60, same as base 71 (all URL-allowed characters) without characters "0oO1ilIL!~'". */
	const BASE60 = "23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ()*-._";
	/** Base 61, same as base 71 (all URL-allowed characters) without characters '0oO1ilIL!~'. */
	const BASE61 = "23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ'()*-._";
	/**
	 * Base 64, same as base 62 (0-9a-zA-Z) with '+/' characters
	 * @link	https://www.ietf.org/rfc/rfc4648.txt
	 */
	const BASE64 = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ+/';
	/** Base 70, same as base 71 (all URL-allowed characters) without the quote character ('). */
	const BASE70 = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!()*-._~';
	/**
	 * Base 71. Contains all characters that are not encoded in an URL.
	 * @link	http://www.ietf.org/rfc/rfc2396.txt
	 */
	const BASE71 = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!'()*-._~";
	/** Base 73, same as base 54 with some special characters. */
	const BASE73 = '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ!#%()*+,-./:;=?@[]_';
	/** Base 79, same as base 80 without ':' (used to separate logins and passwords). */
	const BASE79 = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ.-+=^!*()[]{}@%$#';
	/** Base 80, same as base 85 without HTML special characters '&<>?/'. */
	const BASE80 = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ.-:+=^!*()[]{}@%$#';
	/** Base 84, same as base 85 without ':' (used to separate logins and passwords). */
	const BASE84 = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ.-+=^!/*?&<>()[]{}@%$#';
	/**
	 * Base 85, ZeroMQ fashion. Contains only printable characters that could be used on command-line arguments.
	 * Superset of base 36 (0-9a-z) and base 62 (0-9a-zA-Z).
	 * @link	https://en.wikipedia.org/wiki/Ascii85
	 * @link	https://rfc.zeromq.org/spec:32/Z85/
	 */
	const BASE85 = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ.-:+=^!/*?&<>()[]{}@%$#';
	/** Base 95, extension of base 85 using all printable ASCII characters. */
	const BASE95 = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ.-:+=^!/*?&<>()[]{}@%$#"\',;\\_`|~ ';

	/**
	 * Convert a positive number from any base to any special base.
	 * @param	string	$input		The number to convert.
	 * @param	int	$inBase		Size of the input base (any number between 2 and 95).
	 * @param	int	$outBase	Size of the output base. Could be 31, 54, 61, 64, 71, 73, 79, 80, 84, 85 or 95.
	 * @return	string	The converted number.
	 ù @throws	Exception	If the parameters are incorrect.
	 */
	static public function convertToSpecialBase(string $input, int $inBase, int $outBase) : string {
		if ($inBase < 2 || $inBase > 95)
			throw new \Exception("Bad input base.");
		$inDigits = mb_substr(self::BASE95, 0, $inBase, 'ascii');
		if ($outBase == 31)
			$outDigits = self::BASE31;
		else if ($outBase == 54)
			$outDigits = self::BASE54;
		else if ($outBase == 61)
			$outDigits = self::BASE61;
		else if ($outBase == 64)
			$outDigits = self::BASE64;
		else if ($outBase == 71)
			$outDigits = self::BASE71;
		else if ($outBase == 73)
			$outDigits = self::BASE73;
		else if ($outBase == 79)
			$outDigits = self::BASE79;
		else if ($outBase == 80)
			$outDigits = self::BASE80;
		else if ($outBase == 84)
			$outDigits = self::BASE84;
		else if ($outBase == 85)
			$outDigits = self::BASE85;
		else if ($outBase == 95)
			$outDigits = self::BASE95;
		else
			throw new \Exception("Bad output base.");
		return (self::convertBase($input, $inDigits, $outDigits));
	}
	/**
	 * Convert a positive number from any special base to any base.
	 * @param	string	$input		The number to convert.
	 * @param	int	$inBase		Size of the input base. Could be 31, 54, 61, 64, 71, 73, 79, 80, 84, 85 or 95.
	 * @param	int	$outBase	Size of the output base (any number between 2 and 95).
	 * @return	string	The converted number.
	 * @throws	\Exception	If the parameters are incorrect.
	 */
	static public function convertFromSpecialBase(string $input, int $inBase, int $outBase) : string {
		if ($inBase == 31)
			$inDigits = self::BASE31;
		else if ($inBase == 54)
			$inDigits = self::BASE54;
		else if ($inBase == 61)
			$inDigits = self::BASE61;
		else if ($inBase == 64)
			$inDigits = self::BASE64;
		else if ($inBase == 71)
			$inDigits = self::BASE71;
		else if ($inBase == 73)
			$inDigits = self::BASE73;
		else if ($inBase == 79)
			$inDigits = self::BASE79;
		else if ($inBase == 80)
			$inDigits = self::BASE80;
		else if ($inBase == 84)
			$inDigits = self::BASE84;
		else if ($inBase == 85)
			$inDigits = self::BASE85;
		else if ($inBase == 95)
			$inDigits = self::BASE95;
		else
			throw new \Exception("Bad input base.");
		if ($outBase < 2 || $outBase > 95)
			throw new \Exception("Bad output base.");
		$outDigits = mb_substr(self::BASE95, 0, $outBase, 'ascii');
		return (self::convertBase($input, $inDigits, $outDigits));
	}
	/**
	 * Convert a positive number from any base to any other base (subsets of base95).
	 * @param	string		$input		The number to convert.
	 * @param	int		$inBase		Size of the input base (any number between 2 and 95).
	 * @param	int		$outBase	Size of the output base (any number between 2 and 95).
	 * @return	string		The converted number.
	 * @throws	\Exception	If a digit is outside the base.
	 */
	static public function convert(string $input, int $inBase, int $outBase) : string {
		if ($inBase < 2 || $inBase > 95 || $outBase < 2 || $outBase > 95)
			throw new \Exception("Bad base.");
		$inDigits = mb_substr(self::BASE95, 0, $inBase, 'ascii');
		$outDigits = mb_substr(self::BASE95, 0, $outBase, 'ascii');
		return (self::convertBase($input, $inDigits, $outDigits));
	}
	/**
	 * Convert a positive number from any base to any other base.
	 * @param	int|string	$value		The number to convert.
	 * @param	string		$inDigits	The input base's digits.
	 * @param	string		$outDigits	The output base's digits.
	 * @return	string		The converted number.
	 * @throws	\Exception	If a digit is outside the base.
	 * @link	http://www.technischedaten.de/pmwiki2/pmwiki.php?n=Php.BaseConvert
	 */
	static public function convertBase(int|string $value, string $inDigits, string $outDigits) : string {
		$inBase = mb_strlen($inDigits, 'ascii');
		$outBase = mb_strlen($outDigits, 'ascii');
		$decimal = '0';
		$level = 0;
		$result = '';
		$value = trim((string)$value, "\r\n\t" . ((strpos($inDigits, ' ') === false) ? '' : ' '));
		$value = ltrim($value, '-0');
		$len = mb_strlen($value, 'ascii');
		for ($i = 0; $i < $len; $i++) {
			$newValue = strpos($inDigits, $value[$len - 1 - $i]);
			if ($newValue === false)
				throw new \Exception('Bad Char in input 1', E_USER_ERROR);
			if ($newValue >= $inBase)
				throw new \Exception('Bad Char in input 2', E_USER_ERROR);
			$decimal = bcadd($decimal, bcmul(bcpow($inBase, $i), $newValue));
		}
		if ($outBase == 10)
			return ($decimal); // shortcut
		while (bccomp(bcpow($outBase, $level++), $decimal) !== 1)
			;
		for ($i = ($level - 2); $i >= 0; $i--) {
			$factor = bcpow($outBase, $i);
			$number = bcdiv($decimal, $factor, 0);
			$decimal = bcmod($decimal, $factor);
			$result .= mb_substr($outDigits, $number, 1);
		}
		$result = empty($result) ? '0' : $result;
		return ($result);
	}
}

