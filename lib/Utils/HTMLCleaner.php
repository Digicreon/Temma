<?php

/**
 * HTMLCleaner
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2010-2019, Amaury Bouchard
 */

namespace Temma\Utils;

require_once("HTMLPurifier.auto.php");

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
		$text = self::clean($text, $nofollow);
		if ($urlProcess) {
			$text = preg_replace('/(http[s]?:\/\/)([^\s<\),]+)/e', "'<a href=\"\\1\\2\" title=\"\\1\\2\">'.((strlen('\\1\\2')>55)?(substr('\\1\\2',0,55).'...'):'\\1\\2').'</a>'", $text);
		}
		return ($text);
	}
	/**
	 * Clean HTML code.
	 * @param	string	$html		The input HTML code.
	 * @param	bool	$nofollow	(optional) Tell if links must be in nofollow. True by default.
	 * @param	bool	$removeNbsp	(optional) Tell if non-breakable spaces must be removed. True by default.
	 * @return	string	The cleaned HTML code.
	 */
	static public function clean(string $html, bool $nofollow=true, bool $removeNbsp=true) : string {
		$html = trim($html);
		$html = str_replace(['<br />', '<br/>'], '<br>', $html);
		$html = str_replace(['<p></p>', '<p><br></p>'], '', $html);
		// basic tags transformation
		$from = ['<strong', '</strong>', '<em>', '</em>', '<strike>', '</strike>'];
		$to   = ['<b>',     '</b>',      '<i>',  '<i>',   '<s>',      '</s>'];
		$html = str_replace($from, $to, trim($html));
		// all texts must start with an opening <p> tag (otherwise HTMLPurifier remove all <ul> and <li> tags but not their contents)
		if (substr($html, 0, 1) != '<')
			$html = '<p>' . $html;
		// replace double-br to generate paragraphs
		$html = preg_replace('/<br \/>\s*<br \/>\s*/m', "\n\n", $html);
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
		$from = ['<p><br>', '<br></p>', '&nbsp;<', '>&nbsp;'];
		$to   = ['<p>',     '</p>',     '<',       '>'];
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
		$allowedHtml = 'h1,h2,h3,h4,h5,h6,div[style],p[style|class],span[style],b,i,u,s,blockquote,pre,font[color],ul,ol,li,br,hr,table,thead,tbody,tr,th,td,sup,sub,img[src|alt|data-filename|style|class],ins,del,mark,figure,figcaption,small,a[href|name]';
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
		$config->set('AutoFormat.RemoveEmpty.RemoveNbsp', true);
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
		if ($nofollow)
			$html = str_replace("<a href", "<a target=\"_blank\" rel=\"nofollow\" href", $html);
		else
			$html = str_replace("<a href", "<a target=\"_blank\" href", $html);
		// last cleaning
		$from = ['<p><br>', '<br></p>', "</p>\n\n<p"];
		$to =   ['<p>',     '</p>',     "</p>\n<p"];
		$html = str_replace($from, $to, $html);
		// return
		return ($html);
	}
}

