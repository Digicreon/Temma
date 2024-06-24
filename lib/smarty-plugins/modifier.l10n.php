<?php

/**
 * Smarty modifier to translate text strings.
 * @param	string		$str	String to translate
 * @param	mixed		$data	(optional) List of other arguments.
 * @return	string	The translated string.
 * @link	http://www.smarty.net/docs/en/language.modifier.escape
 * @author	Amaury Bouchard <amaury@æmaury.net>
 * @copyright	© 2020, Amaury Bouchard
 */
function smarty_modifier_l10n(string $str, ...$data) {
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
	}
	// process the string with data if needed
	if ($data)
		$res = sprintf($res, ...$data);
	// context and count
	if (is_iterable($res)) {
		if (isset($res['*']) && is_scalar($res['*']))
			$res = $res['*'];
		else
			$res = current($res);
	}
	if (is_iterable($res)) {
		if (isset($res['*']) && is_scalar($res['*']))
			$res = $res['*'];
		else
			$res = current($res);
	}
	// escaping
	$res = (string)$res;
	if (!$smarty->escape_html)
		$res = htmlspecialchars($res, ENT_COMPAT, 'UTF-8', true);
	return ($res);
}

