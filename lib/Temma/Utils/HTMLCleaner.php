<?php

/**
 * HTMLCleaner
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2010-2019, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-htmlcleaner
 */

namespace Temma\Utils;

if (!class_exists('\HTMLPurifier_Config')) {
	if (!stream_resolve_include_path('HTMLPurifier.auto.php')) {
		throw new \RuntimeException("Unable to load the HTMLPurifier library.");
	}
	require_once('HTMLPurifier.auto.php');
}

/**
 * Object for HMTL code cleaning. Based on HTMLPurifier.
 *
 * Examples:
 * <code>
 * // transform a simple raw text into a clean HTML
 * $html = \Temma\Utils\HTMLCleaner::text2html($text);
 *
 * // clean an HTML stream
 * $html = \Temma\Utils\HTMLCleaner::clean($html);
 * </code>
 *
 * @see		http://htmlpurifier.org/
 */
class HTMLCleaner {
	/**
	 * Transform a raw text into a clean HTML code.
	 * @param	string	$text		The input text.
	 * @param	bool	$urlProcess	(optional) Tell if URLs embedded in the text must be processed. True by default.
	 * @param	bool	$nofollow	(optional) Tell if links must be in nofollow. True by default.
	 * @return	string	Le code HTML nettoyé.
	 */
	static public function text2html(string $text, bool $urlProcess=true, bool $nofollow=true) : string {
		$text = htmlspecialchars($text, ENT_COMPAT, 'UTF-8');
		$text = strip_tags($text);
		$text = nl2br($text);
		if ($urlProcess) {
			$text = preg_replace_callback('/(http[s]?:\/\/)([^\s<\),]+)/', function($matches) {
				$url = $matches[1] . $matches[2];
				$str = "<a href=\"$url\" title=\"$url\">";
				if (mb_strlen($url) > 55)
					$str .= mb_substr($url, 0, 55) . '…';
				else
					$str .= $url;
				$str .= '</a>';
				return ($str);
			}, $text);
		}
		$text = self::clean($text, null, $nofollow);
		return ($text);
	}
	/**
	 * Clean HTML code.
	 * @param	string	$html		The input HTML code.
	 * @param	?bool	$targetBlank	(optional) Tell if links must be open in a new tab.
	 *					True to transform all links. False to never transform links.
	 *					Null to transform only external links. Null by default.
	 * @param	?bool	$nofollow	(optional) Tell if links must be in nofollow.
	 *					True to transform all links. False to never transform links.
	 *					Null to transform only external links. Null by default.
	 * @param	bool	$removeNbsp	(optional) Tell if non-breakable spaces must be removed. True by default.
	 * @return	string	The cleaned HTML code.
	 */
	static public function clean(string $html, ?bool $targetBlank=null, ?bool $nofollow=null, bool $removeNbsp=true) : string {
		$html = trim($html);
		if (!$html)
			return ('');
		$html = str_replace(['<br />', '<br/>'], '<br>', $html);
		$html = str_replace(['<p></p>', '<p><br></p>'], '', $html);
		// basic tags transformation
		$from = ['<strong>', '</strong>', '<em>', '</em>', '<strike>', '</strike>'];
		$to   = ['<b>',      '</b>',      '<i>',  '<i>',   '<s>',      '</s>'];
		$html = str_replace($from, $to, trim($html));
		// all texts must start with an opening <p> tag (otherwise HTMLPurifier remove all <ul> and <li> tags but not their contents)
		if ($html[0] != '<')
			$html = '<p>' . $html;
		// replace double-br to generate paragraphs
		$html = preg_replace('/<br>\s*<br>\s*/m', "\n\n", $html);
		// sub-lists management
		$from = ['</li><ul><li>', '</li><ol><li>'];
		$to   = ['<ul><li>',      '<ol><li>'];
		$html = str_replace($from, $to, $html);
		// useless code
		$html = str_replace(' class=""', '', $html);
		while (($pos1 = strpos($html, '<div class="medium-insert-buttons"')) !== false &&
		       ($pos2 = strpos($html, '</div>', $pos1)) !== false) {
			$html = substr_replace($html, '', $pos1, ($pos2 - $pos1 + strlen('</div>')));
		}
		$from = ['<p><br>', '<br></p>', '&nbsp;</', '>&nbsp;'];
		$to   = ['<p>',     '</p>',     '</',       '> '];
		$html = str_replace($from, $to, $html);
		// text management
		$from = ['« ',      '« ',      ' »',      ' »'];
		$to   = ['«&nbsp;', '«&nbsp;', '&nbsp;»', '&nbsp;»'];
		$html = str_replace($from, $to, $html);
		// config
		$config = \HTMLPurifier_Config::createDefault();
		//$config->set("HTML.DefinitionID", "temma-html-definition");
		//$config->set('HTML.DefinitionRev', 1); 
		$config->set('Core.Encoding', 'UTF-8');
		$config->set('Core.EscapeNonASCIICharacters', false);
		$allowedHtml = 'h1,h2,h3,h4,h5,h6,div[style],p[style|class],span[style],b,i,u,s,blockquote,pre,font[color],ul,ol,li,br,hr,table,thead,tbody,tr,th[colspan|rowspan],td[colspan|rowspan],sup,sub,img[src|alt|data-filename|style|class],ins,del,mark,figure,figcaption,small,a[href|name]';
		$config->set('HTML.Allowed', $allowedHtml);
		$config->set('URI.AllowedSchemes', [
			'http'		=> true,
			'https'		=> true,
			'data'		=> true,
			'mailto'	=> true,
			'ftp'		=> true,
			'nntp'		=> true,
			'news'		=> true,
			'tel'		=> true,
		]);
		$allowedCss = 'background-color,text-align,margin-left';
		$config->set('CSS.AllowedProperties', $allowedCss);
		$config->set('AutoFormat.AutoParagraph', true);
		$config->set('AutoFormat.RemoveEmpty', true);
		$config->set('AutoFormat.RemoveEmpty.RemoveNbsp', $removeNbsp);
		$def = $config->getHTMLDefinition(true);
		$def->addAttribute('img', 'src', 'CDATA');
		$def->addAttribute('img', 'alt', 'CDATA');
		$def->addAttribute('img', 'data-filename', 'CDATA');
		$def->addElement('mark', 'Inline', 'Inline', 'Common');
		$def->addElement('figure', 'Block', 'Flow', 'Common');
		$def->addElement('figcaption', 'Block', 'Inline', 'Common');
		// purifier
		$purifier = new \HTMLPurifier($config);
		$html = $purifier->purify($html);
		// links processing
		if ($targetBlank === true && is_bool($nofollow)) {
			// target="_blank" on all links
			// rel="nofollow" on all links or on no link
			$from = '<a href';
			if ($nofollow === true)
				$to = '<a target="_blank" rel="nofollow" href';
			else if ($nofollow === false)
				$to = '<a target="_blank" href';
			$html = str_replace($from, $to, $html);
		} else if ($targetBlank === true && $nofollow === null) {
			// target="_blank" on all links
			// rel="nofollow" on external links only
			$from = [
				'<a href="http://',
				'<a href="https://',
				'<a href="ftp://',
			];
			$to = [
				'<a target="_blank" rel="nofollow" href="http://',
				'<a target="_blank" rel="nofollow" href="https://',
				'<a target="_blank" rel="nofollow" href="ftp://',
			];
			$html = str_replace($from, $to, $html);
			$from = '<a href';
			$to = '<a target="_blank" href';
			$html = str_replace($from, $to, $html);
		} else if ($targetBlank === false && ($nofollow === true || $nofollow === null)) {
			// target="_blank" on no link
			if ($nofollow === true) {
				// rel="nofollow" on all links
				$from = '<a href';
				$to = '<a rel="nofollow" href';
			} else {
				// rel="nofollow" on external links only
				$from = [
					'<a href="http://',
					'<a href="https://',
					'<a href="ftp://',
				];
				$to = [
					'<a rel="nofollow" href="http://',
					'<a rel="nofollow" href="https://',
					'<a rel="nofollow" href="ftp://',
				];
			}
			$html = str_replace($from, $to, $html);
		} else if ($targetBlank === null) {
			// target="_blank" on external links only
			$from = [
				'<a href="http://',
				'<a href="https://',
				'<a href="ftp://',
			];
			if ($nofollow === true) {
				$to = [
					'<a target="_blank" rel="nofollow" href="http://',
					'<a target="_blank" rel="nofollow" href="https://',
					'<a target="_blank" rel="nofollow" href="ftp://',
				];
			} else {
				$to = [
					'<a target="_blank" href="http://',
					'<a target="_blank" href="https://',
					'<a target="_blank" href="ftp://',
				];
			}
			$html = str_replace($from, $to, $html);
		}
		// last cleaning
		$from = ['<p><br>', '<br></p>', "</p>\n\n<p", "<p>\n", "\n<"];
		$to =   ['<p>',     '</p>',     "</p>\n<p",   '<p>',   '<'];
		$html = str_replace($from, $to, $html);
		$html = trim($html);
		// return
		return ($html);
	}
}

