<?php

/**
 * Smarty modifier to translate text strings.
 * @param	string		$str	String to translate
 * @param	bool|int|array	$count	(optional) Information about singular/plural form.
 *					If a boolean is given, take plural if it is true.
 *					If an integer is given, take plural if it is greater than 1.
 *					If an array is given, take plural if it has more than 1 element.
 *					Singular by default.
 * @param	mixed		$data	(optional) List of other arguments.
 * @return	string	The translated string.
 * @link	http://www.smarty.net/docs/en/language.modifier.escape
 * @author	Amaury Bouchard <amaury@æmaury.net>
 * @copyright	© 2020, Amaury Bouchard
 */
function smarty_modifier_l10n(string $str, bool|int|array $count=false, ...$data) {
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

