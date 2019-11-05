<?php

require_once("HTMLPurifier.auto.php");

/**
 * Objet de nettoyage de code HTML.
 *
 * @author	Amaury Bouchard <amaury.bouchard@finemedia.fr>
 * @copyright	© 2010, Fine Media
 * @package	FineCommon
 * @see		http://htmlpurifier.org/
 */
class FineHTMLCleaner {
	/**
	 * Nettoie un texte brute de manière à en faire un code HTML propre.
	 * @param	string	$text	Le texte à nettoyer.
	 * @param	bool	$urlProcess	Indique s'il faut transformer les URLs présentes dans le texte. True par défaut.
	 * @param	bool	$nofollow	Indique si les liens doivent être en nofollow. True par défaut.
	 * @return	string	Le code HTML nettoyé.
	 */
	static public function processText($text, $urlProcess=true, $nofollow=true) {
		$text = htmlspecialchars($text, ENT_COMPAT, 'UTF-8');
		$text = strip_tags($text);
		$text = nl2br($text);
		$text = self::process($text, $urlProcess, $nofollow);
		return ($text);
	}
	/**
	 * Nettoie un code HTML de manière à le rendre acceptable sur nos sites.
	 * @param	string	$html		Le code HTML à nettoyer.
	 * @param	bool	$urlProcess	(optionnel) Indique s'il faut transformer les URLs présentes dans le texte. True par défaut.
	 * @param	bool	$nofollow	(optionnel) Indique si les liens doivent être en nofollow. True par défaut.
	 * @param	bool	$removeNbsp	(optionnel) Indique s'il faut supprimer les espaces insécables ou non. True par défaut.
	 * @return	string	Le code HTML après nettoyage.
	 */
	static public function process($html, $urlProcess=true, $nofollow=true, $removeNbsp=true) {
		// transformation de tags
		$from = array("<br>", "<b>", "</b>", "<i>", "</i>");
		$to = array("<br />", "<strong>", "</strong>", "<em>", "</em>");
		$html = str_replace($from, $to, trim($html));
		// tout texte doit forcément être commencé par un tag <p> ouvrant au minimum (car sinon, 'purify' suprime les tags <ul>, <li> et peut-être d'autres (mais pas leur contenu)
		if (substr($html, 0, 1) != '<')
			$html = '<p>' . $html;
		// remplacement des double-br (pour générer des paragraphes)
		$html = preg_replace('/<br \/>\s*<br \/>\s*/m', "\n\n", $html);
		// config
		$config = HTMLPurifier_Config::createDefault();
		$config->set("HTML.DefinitionID", "finemedia-html-definition");
		$config->set('HTML.DefinitionRev', 1);
		$config->set('Core.Encoding', 'UTF-8');
		$config->set('HTML.Doctype', 'XHTML 1.0 Transitional');
		$allowedHTML = 'h1,h2,h3,p[class],strong,em,ul,ol,li,br,img[src|alt],pre[class],hr,table[class],th,tr,td,tt';
		if (!$urlProcess)
			$allowedHTML .= ',a[href|name]';
		$config->set('HTML.Allowed', $allowedHTML);
		$config->set('AutoFormat.AutoParagraph', true);
		$config->set('AutoFormat.RemoveEmpty', true);
		$config->set('AutoFormat.RemoveEmpty.RemoveNbsp', $removeNbsp);
		$config->set('AutoFormat.RemoveEmpty.RemoveNbsp.Exceptions', '');
		//$config->set("URI.DisableExternal", true);
		//$config->set("URI.DisableExternalResources", true);
		// purifier
		$purifier = new HTMLPurifier($config);
		$html = $purifier->purify($html);
		// traitement des liens
		if ($urlProcess) {
			$html = preg_replace('/(http[s]?:\/\/)([^\s<\),]+)/e', "'<a href=\"\\1\\2\" title=\"\\1\\2\">'.((strlen('\\1\\2')>55)?(substr('\\1\\2',0,55).'...'):'\\1\\2').'</a>'", $html);
		}
		// traitement des liens
		if ($nofollow)
			$html = str_replace("<a href", "<a target=\"_blank\" rel=\"nofollow\" href", $html);
		else
			$html = str_replace("<a href", "<a target=\"_blank\" href", $html);
		// retour
		return ($html);
	}
}

?>
