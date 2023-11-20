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
			$filename = mb_strtolower($filename, mb_detect_encoding($filename));
		// ".file-name.-" becomes "file-name"
		$filename = trim($filename, '.-');
		// maximise filename length to 255 bytes http://serverfault.com/a/9548/44086
		$ext = pathinfo($filename, PATHINFO_EXTENSION);
		$filename = mb_strcut(pathinfo($filename, PATHINFO_FILENAME), 0, 255 - ($ext ? strlen($ext) + 1 : 0), mb_detect_encoding($filename)) . ($ext ? '.' . $ext : '');
		return $filename;
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
}

