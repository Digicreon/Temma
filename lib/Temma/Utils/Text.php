<?php

/**
 * Text
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2011-2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-text
 */

namespace Temma\Utils;

use \Temma\Exceptions\IO as TµIOException;

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
	/** Constant: upper case. */
	const UPPER_CASE = 'UPPER_CASE';
	/** Regular expression matching ANSI escape sequences (string sequences like OSC, CSI, charset designation, other two-character sequences). */
	private const ANSI_SEQUENCES_REGEX = '/(\e(?:[\]PX^_][^\a\e]*(?:\a|\e\\\\)?|\[[0-?]*[ -\/]*[@-~]?|[()*+][0-9A-Za-z]?|[0-~])?)/';
	/** ANSI palette of the 16 base colors (normal and bright). */
	private const ANSI_PALETTE = ['#000', '#c00', '#080', '#a60', '#00c', '#a0a', '#088', '#bbb',
	                              '#555', '#f55', '#5f5', '#ff5', '#55f', '#f5f', '#5ff', '#fff'];

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
	 * Transform a text containing ANSI escape sequences (terminal output) into HTML.
	 * The text is HTML-escaped. SGR sequences (bold, dim, italic, underline, reverse, hidden, strikethrough,
	 * 16, 256 and 24-bit colors) are converted into styled <span> tags, OSC 8 hyperlinks are converted into
	 * <a> tags (only for http, https and mailto URLs), and all other escape sequences are removed.
	 * Line breaks are kept as is, the result should be displayed in a <pre> tag (or with a 'white-space: pre' style).
	 * @param	?string	$text	The text to convert.
	 * @return	string	The generated HTML.
	 */
	static public function ansiToHtml(?string $text) : string {
		if (!isset($text) || $text === '')
			return ('');
		$parts = preg_split(self::ANSI_SEQUENCES_REGEX, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
		$html = '';
		// desired state (from the escape sequences) and state of the currently open tags
		$state = [];
		$link = null;
		$openCss = '';
		$openLink = null;
		foreach ($parts as $i => $part) {
			if ($part === '')
				continue;
			// escape sequence
			if ($i % 2) {
				if (preg_match('/^\e\[([0-9;:]*)m$/', $part, $matches))
					$state = self::_applyAnsiSgr($state, $matches[1]);
				else if (preg_match('/^\e\]8;[^;\a\e]*;([^\a\e]*)/', $part, $matches))
					$link = preg_match('/^(https?:\/\/|mailto:)/i', $matches[1]) ? $matches[1] : null;
				continue;
			}
			// text chunk: open the needed tags
			$css = self::_ansiStateToCss($state);
			if ($css !== $openCss || $link !== $openLink) {
				if ($openCss !== '')
					$html .= '</span>';
				if ($link !== $openLink) {
					if (isset($openLink))
						$html .= '</a>';
					if (isset($link))
						$html .= '<a href="' . htmlspecialchars($link) . '">';
					$openLink = $link;
				}
				if ($css !== '')
					$html .= '<span style="' . $css . '">';
				$openCss = $css;
			}
			$html .= htmlspecialchars($part);
		}
		// close the open tags
		if ($openCss !== '')
			$html .= '</span>';
		if (isset($openLink))
			$html .= '</a>';
		return ($html);
	}
	/**
	 * Remove all ANSI escape sequences (colors, styles, cursor moves, hyperlinks...) from a text.
	 * The text of OSC 8 hyperlinks is kept.
	 * @param	?string	$text	The text to clean.
	 * @return	string	The text without escape sequences.
	 */
	static public function ansiToText(?string $text) : string {
		if (!isset($text) || $text === '')
			return ('');
		return (preg_replace(self::ANSI_SEQUENCES_REGEX, '', $text));
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
	 * @param	string	$inCase		The input case (self::KEBAB_CASE, self::CAMEL_CASE, self::PASCAL_CASE, self::SNAKE_CASE, self::UPPER_CASE).
	 * @param	string	$outCase	The output case (self::KEBAB_CASE, self::CAMEL_CASE, self::PASCAL_CASE, self::SNAKE_CASE, self::UPPER_CASE).
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
		// upper case input is processed as a lower-cased snake case
		if ($inCase == self::UPPER_CASE) {
			$txt = mb_convert_case($txt, MB_CASE_LOWER);
			$inCase = self::SNAKE_CASE;
		}
		// upper case output is processed as a snake case forced to upper case,
		// unless lower case output was explicitly requested (then it stays a snake case)
		if ($outCase == self::UPPER_CASE) {
			$outCase = self::SNAKE_CASE;
			if ($upperCase !== false)
				$upperCase = true;
		}
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
		if (($inCase == self::SNAKE_CASE || $inCase == self::KEBAB_CASE) &&
		    ($outCase == self::CAMEL_CASE || $outCase == self::PASCAL_CASE)) {
			if ($inCase == self::SNAKE_CASE) {
				$txt = str_replace('-', '_', $txt);
				$char = '_';
			} else {
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
	/**
	 * Check if two MIME types are compatibles.
	 * Both MIME types must have the same upper/lower-case.
	 * @param	string|array	$mime1	First MIME type.
	 *					String in the form "image/png", "image/*" or "image".
	 *					Array in the form ['image', 'png'], ['image', '*'] or ['image'].
	 * @param	string|array	$mime2	Second MIME type.
	 * @return	bool	True if the MIME types are compatible.
	 */
	static public function mimeTypesMatch(string|array $mime1, string|array $mime2) : bool {
		if (is_string($mime1))
			$mime1 = explode('/', $mime1);
		$mime1[1] ??= '*';
		if (is_string($mime2))
			$mime2 = explode('/', $mime2);
		$mime2[1] ??= '*';
		if (($mime1[0] == $mime2[0] && $mime1[1] == $mime2[1]) ||
		    ($mime1[0] == $mime2[0] && ($mime1[1] == '*' || $mime2[1] == '*')) ||
		    ($mime1[0] == '*' && $mime1[1] == '*') ||
		    ($mime2[0] == '*' && $mime2[1] == '*'))
			return (true);
		return (false);
	}
	/**
	 * Parse a sze string (e.g. '10M', '5K').
	 * @param	mixed	$len	The size string.
	 * @param	int	$base	(optional) The desired base. (default: 1024)
	 * @return	?int	The parsed size in bytes, or null if it received null.
	 * @throws	\Temma\Exceptions\IO	If the string is not valid.
	 */
	static public function parseSize(mixed $len, int $base=1024) : ?int {
		if (is_null($len))
			return (null);
		if (is_float($len))
			return ((int)$len);
		if (is_int($len) || ctype_digit($len))
			return ($len);
		if (!is_string($len))
			throw new TµIOException("Bad size string format.", TµIOException::BAD_FORMAT);
		$len = trim($len);
		$last = mb_strtolower(mb_substr($len, -1));
		$len = rtrim(mb_substr($len, 0, -1));
		if (!ctype_digit($len))
			throw new TµIOException("Bad size string format.", TµIOException::BAD_FORMAT);
		switch ($last) {
			case 'k': $len *= $base; break;
			case 'm': $len *= ($base ** 2); break;
			case 'g': $len *= ($base ** 3); break;
			case 't': $len *= ($base ** 4); break;
			case 'p': $len *= ($base ** 5); break;
			case 'e': $len *= ($base ** 6); break;
			default: throw new TµIOException("Bad size string format.", TµIOException::BAD_FORMAT);
		}
		return ($len);
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Apply the parameters of an ANSI SGR sequence ("\e[...m") to a style state.
	 * @param	array	$state	The current style state.
	 * @param	string	$params	The parameters of the sequence (e.g. "1;38;5;196").
	 * @return	array	The new style state.
	 */
	static private function _applyAnsiSgr(array $state, string $params) : array {
		$codes = explode(';', $params);
		$count = count($codes);
		for ($i = 0; $i < $count; $i++) {
			// sub-parameters separated by colons (e.g. "38:5:196" or "4:3")
			$sub = explode(':', $codes[$i]);
			$code = (int)$sub[0];
			if ($code == 38 || $code == 48) {
				if (count($sub) > 1) {
					// colon form: "38:5:N", "38:2:R:G:B" or "38:2:colorspace:R:G:B"
					$args = array_slice($sub, 1);
					if (($args[0] ?? '') == '2' && count($args) > 4)
						array_splice($args, 1, 1);
				} else {
					// semicolon form: the color parameters are the next codes
					$length = (($codes[$i + 1] ?? '') == '2') ? 4 : 2;
					$args = array_slice($codes, $i + 1, $length);
					$i += $length;
				}
				$color = self::_ansiExtendedColor($args);
				if (isset($color))
					$state[($code == 38) ? 'color' : 'background'] = $color;
				continue;
			}
			switch ($code) {
				case 0: $state = []; break;
				case 1: $state['bold'] = true; break;
				case 2: $state['dim'] = true; break;
				case 3: $state['italic'] = true; break;
				case 4:
				case 21:
					if (($sub[1] ?? '') === '0')
						unset($state['underline']);
					else
						$state['underline'] = true;
					break;
				case 7: $state['reverse'] = true; break;
				case 8: $state['hidden'] = true; break;
				case 9: $state['strike'] = true; break;
				case 22: unset($state['bold'], $state['dim']); break;
				case 23: unset($state['italic']); break;
				case 24: unset($state['underline']); break;
				case 27: unset($state['reverse']); break;
				case 28: unset($state['hidden']); break;
				case 29: unset($state['strike']); break;
				case 39: unset($state['color']); break;
				case 49: unset($state['background']); break;
				default:
					if ($code >= 30 && $code <= 37)
						$state['color'] = self::ANSI_PALETTE[$code - 30];
					else if ($code >= 40 && $code <= 47)
						$state['background'] = self::ANSI_PALETTE[$code - 40];
					else if ($code >= 90 && $code <= 97)
						$state['color'] = self::ANSI_PALETTE[$code - 82];
					else if ($code >= 100 && $code <= 107)
						$state['background'] = self::ANSI_PALETTE[$code - 92];
			}
		}
		return ($state);
	}
	/**
	 * Compute the CSS color of an extended ANSI color (256-color palette or 24-bit color).
	 * @param	array	$args	Color parameters: ['5', index] or ['2', red, green, blue].
	 * @return	?string	The CSS color, or null if the parameters are not valid.
	 */
	static private function _ansiExtendedColor(array $args) : ?string {
		if (($args[0] ?? '') == '2' && count($args) >= 4) {
			// 24-bit color
			$rgb = array_map(fn($c) => min(255, max(0, (int)$c)), array_slice($args, 1, 3));
			return (sprintf('#%02x%02x%02x', ...$rgb));
		}
		if (($args[0] ?? '') != '5' || !isset($args[1]) || !ctype_digit($args[1]) || $args[1] > 255)
			return (null);
		$index = (int)$args[1];
		// 16 base colors
		if ($index < 16)
			return (self::ANSI_PALETTE[$index]);
		// grayscale
		if ($index >= 232)
			return (sprintf('#%1$02x%1$02x%1$02x', 8 + ($index - 232) * 10));
		// 6x6x6 color cube
		$index -= 16;
		$levels = [0, 95, 135, 175, 215, 255];
		return (sprintf('#%02x%02x%02x', $levels[intdiv($index, 36)], $levels[intdiv($index, 6) % 6], $levels[$index % 6]));
	}
	/**
	 * Generate the CSS style matching an ANSI style state.
	 * @param	array	$state	The style state.
	 * @return	string	The CSS style (empty string if there is no style).
	 */
	static private function _ansiStateToCss(array $state) : string {
		$color = $state['color'] ?? null;
		$background = $state['background'] ?? null;
		if (isset($state['reverse'])) {
			$color = $background ?? '#fff';
			$background = $state['color'] ?? '#000';
		}
		$css = [];
		if (isset($color))
			$css[] = "color:$color";
		if (isset($background))
			$css[] = "background-color:$background";
		if (isset($state['bold']))
			$css[] = 'font-weight:bold';
		if (isset($state['dim']))
			$css[] = 'opacity:.6';
		if (isset($state['italic']))
			$css[] = 'font-style:italic';
		$decoration = trim((isset($state['underline']) ? 'underline ' : '') . (isset($state['strike']) ? 'line-through' : ''));
		if ($decoration)
			$css[] = "text-decoration:$decoration";
		if (isset($state['hidden']))
			$css[] = 'visibility:hidden';
		return (implode(';', $css));
	}
}
