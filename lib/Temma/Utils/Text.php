<?php

/**
 * Text
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2011-2019, Amaury Bouchard
 */

namespace Temma\Utils;

/**
 * Text management object.
 */
class Text {
	/** Constant: snake case. */
	const SNAKE_CASE = 'SNAKE_CASE';
	/** Constant: kebab case. */
	const KEBAB_CASE = 'KEBAB_CASE';
	/** Constant: camel case. */
	const CAMEL_CASE = 'CAMEL_CASE';
	/** Constant: pascal case. */
	const PASCAL_CASE = 'PASCAL_CASE';

	/**
	 * Checks the syntax of an HTML stream.
	 * @param	string	$html	HTML content to check.
	 * @return	bool	True if the syntax is correct, False otherwise.
	 */
	static public function isValidHtmlSyntax(string $html) : bool {
		if (empty($html))
			return (true);
		$html = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
			<html lang="fr-FR">
				<head>
					<meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/>
				</head>
				<body>
					' . $html . ' 
				</body>
			</html>';
		try {
			libxml_use_internal_errors(true);
			$xmlObj = new \SimpleXMLElement($html);
		} catch (\Exception $e) {
			libxml_clear_errors();
			return (false);
		}
		unset($xmlObj);
		return (true);
	}
	/**
	 * Checks if an UTF-8 string contains only characters compatible with a given encoding.
	 * @param	string	$text		Text to check.
	 * @param	string	$encoding	(optional) The target encoding (defaults to 'iso-8859-15').
	 * @return	bool	True if the input string is compatible with the given encoding.
	 */
	static public function encodingCompatible(string $text, string $encoding='iso-8859-15') : bool {
		$s = mb_convert_encoding($text, $encoding, 'utf-8');
		$s = mb_convert_encoding($s, 'utf-8', $encoding);
		return ($s == $text);
	}
	/**
	 * Transform an HTML stream into a plain text.
	 * @param	string	$html		The input HTML stream.
	 * @param	bool	$cleanup	(optional) True to remove <blockquote>, <pre> and <code> content. False by default.
	 * @return	string	The generated text.
	 */
	static public function htmlToText(string $html, bool $cleanup=false) : string {
		if ($cleanup) {
			$dom = new \DomDocument();
			$dom->loadHtml('<' . '?xml encoding="utf-8" ?' . ">\n" . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD/* | LIBXML_NOXMLDECL*/);
			foreach ($dom->getElementsByTagName('blockquote') as $node) {
				$node->parentNode->removeChild($node);
			}
			foreach ($dom->getElementsByTagName('pre') as $node) {
				$node->parentNode->removeChild($node);
			}
			foreach ($dom->getElementsByTagName('code') as $node) {
				$node->parentNode->removeChild($node);
			}
			$html = $dom->saveHTML();
		}
		$text = strip_tags($html);
		$text = html_entity_decode($text);
		return ($text);
	}
	/**
	 * Transform a text to a filename-compatible string.
	 * @param	string	$filename	The text to convert.
	 * @param	bool	$hyphenSpaces	Tell if spaces must be replaced by hyphens (default=true).
	 * @param	bool	$lowercase	Tell if the output must be in lowercase (default=true).
	 * @return	string	The converted text.
	 * @link	https://stackoverflow.com/questions/2021624/string-sanitizer-for-filename
	 */
	static public function filenamize(string $filename, bool $hyphenSpaces=true, bool $lowercase=true) : string {
		$filename = preg_replace(
			'~
			 [<>:"/\\|?*]|            # file system reserved https://en.wikipedia.org/wiki/Filename#Reserved_characters_and_words
			 [\x00-\x1F]|             # control characters http://msdn.microsoft.com/en-us/library/windows/desktop/aa365247%28v=vs.85%29.aspx
			 [\x7F]|                  # non-printing character DEL
			 [#\[\]@!$&\'()+,;=]|     # URI reserved https://tools.ietf.org/html/rfc3986#section-2.2
			 [{}^\~`]                 # URL unsafe characters https://www.ietf.org/rfc/rfc1738.txt
			 ~x',
			'-', $filename);
		// avoids ".", ".." or ".hiddenFiles"
		$filename = ltrim($filename, '.-');
		// spaces processing
		if ($hyphenSpaces) {
			// "file   name.zip" becomes "file-name.zip"
			$filename = preg_replace('/ +/', '-', $filename);
		} else {
			// "file   name.zip" becomes "file name.zip"
			$filename = preg_replace('/ +/', ' ', $filename);
		}
		// reduce consecutive characters
		$filename = preg_replace([
			// "file___name.zip" becomes "file-name.zip"
			'/_+/',
			// "file---name.zip" becomes "file-name.zip"
			'/-+/'
		], '-', $filename);
		$filename = preg_replace([
			// "file--.--.-.--name.zip" becomes "file.name.zip"
			'/-*\.-*/',
			// "file...name..zip" becomes "file.name.zip"
			'/\.{2,}/'
		], '.', $filename);
		// lowercase for windows/unix interoperability http://support.microsoft.com/kb/100625
		if ($lowercase)
			$filename = mb_convert_case($filename, MB_CASE_LOWER, mb_detect_encoding($filename));
		// ".file-name.-" becomes "file-name"
		$filename = trim($filename, '.-');
		// maximise filename length to 255 bytes http://serverfault.com/a/9548/44086
		$ext = pathinfo($filename, PATHINFO_EXTENSION);
		$filename = mb_strcut(pathinfo($filename, PATHINFO_FILENAME), 0, 255 - ($ext ? strlen($ext) + 1 : 0), mb_detect_encoding($filename)) . ($ext ? '.' . $ext : '');
		return $filename;
	}
	/**
	 * Converts a string to ASCII-only string.
	 * @param	?string	$txt			The text to convert.
	 * @return	string	The converted text.
	 */
	static public function ascii(?string $txt) : string {
		if (!$txt)
			return ('');
		if (extension_loaded('intl')) {
			$transliterator = \Transliterator::create('Any-Latin; Latin-ASCII');
			$txt = $transliterator->transliterate($txt);
		} else {
			if (function_exists('iconv'))
				$txt = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $txt);
			// vowels
			$mask = ['à', 'á', 'â', 'ã', 'ä', 'å', '@', 'À', 'Á', 'Â', 'Ã', 'Ä', 'Å'];
			$txt = str_replace($mask, 'a', $txt);
			$mask = ['é', 'è', 'ê', 'ë', '€', 'È', 'É', 'Ê', 'Ë'];
			$txt = str_replace($mask, 'e', $txt);
			$mask = ['í', 'ï', 'ì', 'î', 'Ì', 'Í', 'Î', 'Ï'];
			$txt = str_replace($mask, 'i', $txt);
			$mask = ['ò', 'ó', 'ô', 'õ', 'ö', 'ø', 'Ò', 'Ó', 'Ô', 'Õ', 'Ö', 'Ø'];
			$txt = str_replace($mask, 'o', $txt);
			$mask = ['ù', 'ú', 'û', 'ü', 'Ù', 'Ú', 'Û', 'Ü'];
			$txt = str_replace($mask, 'u', $txt);
			$mask = ['ý', 'ÿ', 'Ý', 'Ÿ'];
			$txt = str_replace($mask, 'y', $txt);
			// consonants
			$mask = ['ç', 'Ç'];
			$txt = str_replace($mask, 'c', $txt);
			$mask = ['ð', 'Ð'];
			$txt = str_replace($mask, 'd', $txt);
			$mask = ['ñ', 'Ñ'];
			$txt = str_replace($mask, 'n', $txt);
			// digraphs
			$mask = ['æ', 'Æ'];
			$txt = str_replace($mask, 'ae', $txt);
			$mask = ['œ', 'Œ'];
			$txt = str_replace($mask, 'oe', $txt);
			$mask = ['ǳ', 'ǆ', 'ǲ', 'Ǳ', 'ǅ', 'Ǆ'];
			$txt = str_replace($mask, 'dz', $txt);
			$mask = ['ĳ', 'Ĳ'];
			$txt = str_replace($mask, 'ij', $txt);
			$mask = ['ǉ', 'ǈ', 'Ǉ'];
			$txt = str_replace($mask, 'lj', $txt);
			$mask = ['ǌ', 'ǋ', 'Ǌ'];
			$txt = str_replace($mask, 'nj', $txt);
			$mask = ['ß', 'ẞ'];
			$txt = str_replace($mask, 'ss', $txt);
			$mask = '&';
			$txt = str_replace($mask, 'et', $txt);
			// greek alphabet
			$greekToLatin = [
				// lowercase
				'α' => 'a', 'β' => 'b', 'γ' => 'g', 'δ' => 'd', 'ε' => 'e',
				'ζ' => 'z', 'η' => 'i', 'θ' => 'th', 'ι' => 'i', 'κ' => 'k',
				'λ' => 'l', 'μ' => 'm', 'ν' => 'n', 'ξ' => 'x', 'ο' => 'o',
				'π' => 'p', 'ρ' => 'r', 'σ' => 's', 'τ' => 't', 'υ' => 'y',
				'φ' => 'f', 'χ' => 'ch', 'ψ' => 'ps', 'ω' => 'o',
				// uppercase
				'Α' => 'A', 'Β' => 'B', 'Γ' => 'G', 'Δ' => 'D', 'Ε' => 'E',
				'Ζ' => 'Z', 'Η' => 'I', 'Θ' => 'TH', 'Ι' => 'I', 'Κ' => 'K',
				'Λ' => 'L', 'Μ' => 'M', 'Ν' => 'N', 'Ξ' => 'X', 'Ο' => 'O',
				'Π' => 'P', 'Ρ' => 'R', 'Σ' => 'S', 'Τ' => 'T', 'Υ' => 'Y',
				'Φ' => 'F', 'Χ' => 'CH', 'Ψ' => 'PS', 'Ω' => 'O',
				// common diacritical marks
				'ά' => 'a', 'έ' => 'e', 'ή' => 'i', 'ί' => 'i', 'ό' => 'o',
				'ύ' => 'y', 'ώ' => 'o', 'ϊ' => 'i', 'ϋ' => 'y', 'ΐ' => 'i',
				'ΰ' => 'y', 'Ά' => 'A', 'Έ' => 'E', 'Ή' => 'I', 'Ί' => 'I',
				'Ό' => 'O', 'Ύ' => 'Y', 'Ώ' => 'O',
				// other
				'ς' => 's',  // final sigma
			];
			$txt = strtr($txt, $greekToLatin);
			// cyrillic alphabet
			$cyrillicToLatin = [
				// lowercase
				'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
				'е' => 'e', 'ё' => 'yo', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
				'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
				'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
				'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch',
				'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
				'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
				// uppercase
				'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D',
				'Е' => 'E', 'Ё' => 'Yo', 'Ж' => 'Zh', 'З' => 'Z', 'И' => 'I',
				'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M', 'Н' => 'N',
				'О' => 'O', 'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T',
				'У' => 'U', 'Ф' => 'F', 'Х' => 'Kh', 'Ц' => 'Ts', 'Ч' => 'Ch',
				'Ш' => 'Sh', 'Щ' => 'Sch', 'Ъ' => '', 'Ы' => 'Y', 'Ь' => '',
				'Э' => 'E', 'Ю' => 'Yu', 'Я' => 'Ya',
				// common Cyrillic diacritics (Serbian, Macedonian)
				'Љ' => 'Lj', 'љ' => 'lj', 'Њ' => 'Nj', 'њ' => 'nj', 'Ћ' => 'C',
				'ћ' => 'c', 'Ђ' => 'Dj', 'ђ' => 'dj', 'Џ' => 'Dz', 'џ' => 'dz',
				'Ґ' => 'G', 'ґ' => 'g', 'Є' => 'Ye', 'є' => 'ye', 'І' => 'I',
				'і' => 'i', 'Ї' => 'Yi', 'ї' => 'yi',
			];
			$txt = strtr($txt, $cyrillicToLatin);
		}
                return ($txt);
	}
	/**
	 * Transform a text to an URL-compatible string.
	 * @param	?string	$txt			The text to convert.
	 * @param	bool	$avoidUnderscores	Set to true to replace underscores with dashes. (default: true)
	 * @return	string	The converted text.
	 */
	static public function urlize(?string $txt, bool $avoidUnderscores=true) : string {
		if (!$txt)
			return ('');
		$txt = self::ascii($txt);
		// all other letters
		$txt = preg_replace("/[^a-zA-Z0-9-_ \+]/", ' ', $txt);
		// remove multiple spaces
                $txt = preg_replace("/\s+/", ' ', $txt);
		// process spaces
		$mask = [' ', '&nbsp;', '&#160;'];
		if ($avoidUnderscores)
			$mask[] = '_';
                $txt = str_replace($mask, '-', $txt);
		// replace plus with minus
		$txt = str_replace('+', '-', $txt);
		// remove multiple minus
                $txt = preg_replace('/-+/', '-', $txt);
		// to lower characters
                $txt = strtolower($txt);
		// trim spaces and minus
                $txt = trim($txt, '-_');
                $txt = trim($txt);
                $txt = empty($txt) ? '-' : $txt;
                return ($txt);
	}
	/**
	 * Returns the end of a string after a given separator.
	 * @param	string	$str		String.
	 * @param	string	$separator	Substring separator.
	 * @return	string	The last chunk of the string or an empty string if the separator wasn't found.
	 */
	static public function lastChunk(string $str, string $separator) : string {
		$pos = mb_strrpos($str, $separator);
		if ($pos === false)
			return ('');
		$chunk = mb_substr($str, $pos + mb_strlen($separator));
		return ($chunk);
	}
	/**
	 * Returns the beginning of a string before a given separator.
	 * @param	string	$str		String.
	 * @param	string	$separator	Substring separator.
	 * @return	string	The first chunk of the string or an empty string if the separator wasn't found.
	 */
	static public function firstChunk(string $str, string $separator) : string {
		$pos = mb_strpos($str, $separator);
		if ($pos === false)
			return ('');
		$chunk = mb_substr($str, 0, $pos);
		return ($chunk);
	}
	/**
	 * Tells if a string contains a lower case character.
	 * @param	?string	$txt	The input string, or null.
	 * @return	bool	True if the string contains a lower case character.
	 */
	static public function hasLower(?string $txt) : bool {
		if (!$txt)
			return (false);
		$length = mb_strlen($txt);
		for ($i = 0; $i < $length; $i++) {
			$char = mb_substr($txt, $i, 1);
			if ($char == mb_convert_case($char, MB_CASE_LOWER))
				return (true);
		}
		return (false);
	}
	/**
	 * Tells if a string contains an upper case character.
	 * @param	?string	$txt	The input string, or null.
	 * @return	bool	True if the string contains an upper case character.
	 */
	static public function hasUpper(?string $txt) : bool {
		if (!$txt)
			return (false);
		$length = mb_strlen($txt);
		for ($i = 0; $i < $length; $i++) {
			$char = mb_substr($txt, $i, 1);
			if ($char == mb_convert_case($char, MB_CASE_UPPER))
				return (true);
		}
		return (false);
	}
	/**
	 * Converts a string from a given case to another one.
	 * @param	?string	$txt		The input string, or null.
	 * @param	string	$inCase		The input case (self::KEBAB_CASE, self::CAMEL_CASE, self::PASCALECASE, self::SNAKE_CASE).
	 * @param	string	$outCase	The output case (self::KEBAB_CASE, self::CAMEL_CASE, self::PASCAL_CASE, self::SNAKE_CASE).
	 * @param	?bool	$upperCase	(optional) True for upper case output. False for lower case output.
	 *					Defaults to null, to avoid upper/lower case modification.
	 * @param	bool	$ascii		(optional) Set to true to convert all characters to ASCII. Defaults to false.
	 * @return	?string	The converted string, or null if the input was null.
	 */
	static public function convertCase(?string $txt, string $inCase, string $outCase, ?bool $upperCase=null, bool $ascii=false) : ?string {
		if (!$txt)
			return ($txt);
		$separator = null;
		// ASCII conversion
		if ($ascii)
			$txt = self::ascii($txt);
		// snake case to snake case
		if ($inCase == self::SNAKE_CASE && $outCase == self::SNAKE_CASE) {
			$txt = str_replace('-', '_', $txt);
			$separator = '_';
			goto finalize;
		}
		// kebab case to kebab case
		if ($inCase == self::KEBAB_CASE && $outCase == self::KEBAB_CASE) {
			$txt = str_replace('_', '-', $txt);
			$separator = '-';
			goto finalize;
		}
		// camel case to camel case, or pascal case to pascal case
		if ($inCase == $outCase)
			goto finalize;
		// snake case to kebab case
		if ($inCase == self::SNAKE_CASE && $outCase == self::KEBAB_CASE) {
			$txt = str_replace('_', '-', $txt);
			$separator = '-';
			goto finalize;
		}
		// kebab case to snake case
		if ($inCase == self::KEBAB_CASE && $outCase == self::SNAKE_CASE) {
			$txt = str_replace('-', '_', $txt);
			$separator = '_';
			goto finalize;
		}
		// camel case to pascal case
		if ($inCase == self::CAMEL_CASE && $outCase == self::PASCAL_CASE) {
			$txt = mb_convert_case(mb_substr($txt, 0, 1), MB_CASE_UPPER) . mb_substr($txt, 1);
			goto finalize;
		}
		// pascal case to camel case
		if ($inCase == self::PASCAL_CASE && $outCase == self::CAMEL_CASE) {
			$txt = mb_convert_case(mb_substr($txt, 0, 1), MB_CASE_LOWER) . mb_substr($txt, 1);
			goto finalize;
		}
		// snake case or kebab case to camel case or pascal case
		if ($outCase == self::CAMEL_CASE || $outCase == self::PASCAL_CASE) {
			if ($inCase == self::SNAKE_CASE) {
				$txt = str_replace('-', '_', $txt);
				$char = '_';
			} else if ($inCase == self::KEBAB_CASE) {
				$txt = str_replace('_', '-', $txt);
				$char = '-';
			}
			$pos = 0;
			while (($pos = mb_strpos($txt, $char, $pos)) !== false) {
				$begin = mb_substr($txt, 0, $pos);
				$letter = mb_substr($txt, $pos + 1, 1);
				$rest = mb_substr($txt, $pos + 2);
				$txt = $begin . mb_convert_case($letter, MB_CASE_UPPER) . $rest;
			}
			if ($outCase == self::PASCAL_CASE)
				$txt = mb_convert_case(mb_substr($txt, 0, 1), MB_CASE_UPPER) . mb_substr($txt, 1);
			goto finalize;
		}
		// from camel case or pascal case to snake case or kebab case
		$separator = ($outCase == self::SNAKE_CASE) ? '_' : '-';
		$res = '';
		$length = mb_strlen($txt);
		for ($i = 0; $i < $length; $i++) {
			$c = mb_substr($txt, $i, 1);
			$lower = mb_convert_case($c, MB_CASE_LOWER);
			if ($c == $lower)
				$res .= $c;
			else
				$res .= $separator . $lower;
		}
		$txt = $res;
	finalize:
		// remove multiple separators
		if ($separator) {
			$txt = trim($txt, $separator);
			$txt = preg_replace("/$separator+/", $separator, $txt);
		}
		// process lower/upper-case
		if ($upperCase === true)
			$txt = mb_convert_case($txt, MB_CASE_UPPER);
		if ($upperCase === false)
			$txt = mb_convert_case($txt, MB_CASE_LOWER);
		return ($txt);
	}
}

