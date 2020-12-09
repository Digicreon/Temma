<?php

/**
 * BaseConvert
 * @copyright	© Amaury Bouchard <amaury@amaury.net>
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
	/** Base 61, same as base 71 (all URL-allowed characters) without characters '0oO1ilIL!i~'. */
	const BASE61 = "23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ'()*-._";
	/**
	 * Base 71. Contains all characters that are not encoded in an URL.
	 * @link	http://www.ietf.org/rfc/rfc2396.txt
	 */
	const BASE71 = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!'()*-._~";
	/** Base 73, same as base base 54 with some special characters. */
	const BASE73 = '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ!#%()*+,-./:;=?@[]_';
	/**
	 * Base 85, ZeroMQ fashion. Contains only printable characters that could be used on command-line arguments.
	 * Superset of base 36 (0-9a-z) and base 62 (0-9a-zA-Z).
	 * @link	https://en.wikipedia.org/wiki/Ascii85
	 * @link	https://rfc.zeromq.org/spec:32/Z85/
	 */
	const BASE85 = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ.-:+=^!/*?&<>()[]{}@%$#';

	/**
	 * Convert a number from any base to any special base.
	 * @param	string	$input		The number to convert.
	 * @param	int	$inBase		Size of the input base (any number between 2 and 85).
	 * @param	int	$outBase	Size of the output base. Could be 31, 54, 61, 71 or 73.
	 * @return	string	The converted number.
	 */
	static public function convertToSpecialBase($input, $inBase, $outBase) {
		if ((!is_int($inBase) && !ctype_digit($inBase)) || $inBase < 2 || $inBase > 85)
			throw new \Exception("Bad input base.");
		$inDigits = substr(self::BASE85, 0, $inBase);
		if ($outBase == 31)
			$outDigits = self::BASE31;
		else if ($outBase == 54)
			$outDigits = self::BASE54;
		else if ($outBase == 61)
			$outDigits = self::BASE61;
		else if ($outBase == 71)
			$outDigits = self::BASE71;
		else if ($outBase == 73)
			$outDigits = self::BASE73;
		else
			throw new \Exception("Bad output base.");
		return (self::convertBase($input, $inDigits, $outDigits));
	}
	/**
	 * Convert a number from any special base to any base.
	 * @param	string	$input		The number to convert.
	 * @param	int	$inBase		Size of the input base. Could be 31, 54, 71 or 73.
	 * @param	int	$outBase	Size of the output base (any number between 2 and 85).
	 * @return	string	The converted number.
	 */
	static public function convertFromSpecialBase($input, $inBase, $outBase) {
		if ($inBase == 31)
			$inDigits = self::BASE31;
		else if ($inBase == 54)
			$inDigits = self::BASE54;
		else if ($inBase == 61)
			$inDigits = self::BASE61;
		else if ($inBase == 71)
			$inDigits = self::BASE71;
		else if ($inBase == 73)
			$inDigits = self::BASE73;
		else
			throw new \Exception("Bad input base.");
		if ((!is_int($outBase) && !ctype_digit($outBase)) || $outBase < 2 || $outBase > 85)
			throw new \Exception("Bad output base.");
		$outDigits = substr(self::BASE85, 0, $outBase);
		return (self::convertBase($input, $inDigits, $outDigits));
	}
	/**
	 * Convert a number from any base to any other base (subsets of base85).
	 * @param	string		$input		The number to convert.
	 * @param	int		$inBase		Size of the input base (any number between 2 and 85).
	 * @param	int		$outBase	Size of the output base (any number between 2 and 85).
	 * @return	string		The converted number.
	 * @throws	Exception	If a digit is outside the base.
	 */
	static public function convert($input, $inBase, $outBase) {
		if ((!is_int($inBase) && !ctype_digit($inBase)) || $inBase < 2 || $inBase > 85 ||
		    (!is_int($outBase) && !ctype_digit($outBase)) || $outBase < 2 || $outBase > 85)
			throw new \Exception("Bad base.");
		$inDigits = substr(self::BASE85, 0, $inBase);
		$outDigits = substr(self::BASE85, 0, $outBase);
		return (self::convertBase($input, $inDigits, $outDigits));
	}
	/**
	 * Convert a number from any base to any other base.
	 * @param	int|string	$value		The number to convert.
	 * @param	string		$inDigits	The input base's digits.
	 * @param	string		$outDigits	The output base's digits.
	 * @return	string		The converted number.
	 * @throws	Exception	If a digit is outside the base.
	 * @link	http://www.technischedaten.de/pmwiki2/pmwiki.php?n=Php.BaseConvert
	 */
	static public function convertBase($value, $inDigits, $outDigits) {
		$inBase = strlen($inDigits);
		$outBase = strlen($outDigits);
		$decimal = '0';
		$level = 0;
		$result = '';
		$value = trim((string)$value, "\r\n\t +");
		$signe = ($value{0} === '-') ? '-' : '';
		$value = ltrim($value, '-0');
		$len = strlen($value);
		for ($i = 0; $i < $len; $i++) {
			$newValue = strpos($inDigits, $value{$len - 1 - $i});
			if ($newValue === false)
				throw new \Exception('Bad Char in input 1', E_USER_ERROR);
			if ($newValue >= $inBase)
				throw new \Exception('Bad Char in input 2', E_USER_ERROR);
			$decimal = bcadd($decimal, bcmul(bcpow($inBase, $i), $newValue));
		}
		if ($outBase == 10)
			return ($signe.$decimal); // shortcut
		while (bccomp(bcpow($outBase, $level++), $decimal) !== 1)
			;
		for ($i = ($level - 2); $i >= 0; $i--) {
			$factor = bcpow($outBase, $i);
			$number = bcdiv($decimal, $factor, 0);
			$decimal = bcmod($decimal, $factor);
			$result .= $outDigits{$number};
		}
		$result = empty($result) ? '0' : $result;
		return ($signe.$result);
	}
}
