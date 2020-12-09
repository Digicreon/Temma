<?php

/**
 * Smarty modifier to translate text strings.
 * @param	string		$str	String to translate
 * @param	array|int	$count	(optional) Information about singular/plural form.
 *					If an array is given, take plural if it has more than 1 element.
 *					If an integer is given, take plural is it greater than 1.
 *					Singular by default.
 * @param	mixed		$data	(optional) List of other arguments.
 * @return	string	The translated string.
 * @link	http://www.smarty.net/docs/en/language.modifier.escape
 */
function smarty_modifier_l10n($str, $count=false, ...$data) {
	global $smarty;

	// search if there was a specified domain ('default' as default domain)
	$chunks = explode('#', $str);
	$str = $chunks[count($chunks) - 1];
	$domain = (count($chunks) == 1) ? 'default' : $chunks[0];
	// search for the translated string
	$l10n = $smarty->getTemplateVars('l10n');
	$res = $l10n[$domain][$str] ?? null;
	if (!isset($res)) {
		// not found: use the given string
		$res = $str;
	} else if (isset($res['plural']) &&
	           ((is_array($count) && count($count) > 1) || $count > 1)) {
		// use the plural form
		$res = $res['plural'];
	} else if (isset($res['singular'])) {
		// use the singular form
		$res = $res['singular'];
	} // otherwise: keep the taken string
	// process the string with data if needed
	if (isset($data)) {
		$res = sprintf($res, ...$data);
	}
	// escaping
	$res = htmlspecialchars($res, ENT_COMPAT, 'UTF-8', true);
	return ($res);
}

