<?php

/**
 * Smarty block tag to translate text strings.
 * @param	array			$params		Opening tag attributes.
 *							If a "count" param is given:
 *							- boolean: take plural if true
 *							- integer: take plural is greater than 1
 *							- array: take plural if it contains more than 1 element
 * @param	string			$content	Textual content.
 * @param	\Smarty\Template	$template	Template.
 * @param	bool			$repeat		False for the closing tag.
 * @return	string	The translated string.
 * @link	https://smarty-php.github.io/smarty/stable/api/extending/block-tags/
 * @author	Amaury Bouchard <amaury@æmaury.net>
 * @copyright	© 2024, Amaury Bouchard
 */
function smarty_block_l10n($params, $content, $template, &$repeat) {
	global $smarty;

	if ($repeat || !$content)
		return (null);
	// count parameter management
	$count = $params['count'] ?? false;
	// search if there was a specified domain ('default' as default domain)
	$chunks = explode('#', $content);
	$content = $chunks[count($chunks) - 1];
	$domain = (count($chunks) == 1) ? 'default' : $chunks[0];
	// search for the translated string
	$l10n = $smarty->getTemplateVars('l10n');
	$res = $l10n[$domain][$content] ?? null;
	if (!isset($res)) {
		// not found: use the given string
		$res = $content;
	} else if (isset($res['plural']) &&
	           ($count === true ||
	            (is_int($count) && $count > 1) ||
	            (is_array($count) && count($count) > 1))) {
		// use the plural form
		$res = $res['plural'];
	} else if (isset($res['singular'])) {
		// use the singular form
		$res = $res['singular'];
	} // otherwise: keep the taken string
	// process the string with data if needed
	if ($data)
		$res = sprintf($res, ...$data);
	// escaping
	$res = htmlspecialchars($res, ENT_COMPAT, 'UTF-8', true);
	return ($res);
}

