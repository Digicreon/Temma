<?php

/**
 * Smarty modifier to translate text strings.
 * @param	string		$str	String to translate
 * @param	mixed		$data	(optional) List of other arguments.
 * @return	string	The translated string.
 * @link	http://www.smarty.net/docs/en/language.modifier.escape
 * @author	Amaury Bouchard <amaury@æmaury.net>
 * @copyright	© 2020, Amaury Bouchard
 * @link	https://www.temma.net/en/documentation/helper-plugin_language#doc-templates-modifier
 */
function smarty_modifier_l10n(string $str, ...$data) {
	global $smarty;

	// get domain, context and count
	$domain = $ctx = $count = null;
	$chunks = explode('#', $str);
	$str = $chunks[count($chunks) - 1];
	if (count($chunks) > 1) {
		$split = explode(',', $chunks[0]);
		$domain = trim($split[0] ?? '') ?: null;
		$ctx = trim($split[1] ?? '') ?: null;
		$count = trim($split[2] ?? '');
	}
	$domain ??= 'default';
	$count = is_numeric($count) ? $count : '*';
	// search for the translated string
	$l10n = $smarty->getTemplateVars('l10n');
	$res = $l10n[$domain][$str] ?? null;
	$res = _modifier_l10n_extract_context_count($res, $ctx, $count);
	if (!isset($res)) {
		// not found: use the given string
		$res = $str;
	}
	// process the string with data if needed
	$res = (string)$res;
	if ($data) {
		$replace = [];
		foreach ($data as $key => $val) {
			$replace['%' . (is_int($key) ? ($key + 1) : $key) . '%'] = $val;
		}
		$replace['%domain%'] = $domain;
		$replace['%ctx%'] = $ctx;
		$replace['%count%'] = $count;
		$res = strtr($res, $replace);
	}
	// escaping
	if (!$smarty->escape_html)
		$res = htmlspecialchars($res, ENT_COMPAT, 'UTF-8', true);
	return ($res);
}
/**
 * "private" function used by the block tag to find the text from context and count.
 * @param	mixed	$input	Data associated to the translated string.
 * @param	mixed	$ctx	Given context.
 * @param	mixed	$count	Given count.
 * @return	?string	The resulting string.
 */
function _modifier_l10n_extract_context_count(mixed $input, mixed $ctx, mixed $count) : ?string {
	if (is_null($input))
		return (null);
	if (!is_iterable($input) && !($input instanceof \ArrayAccess))
		return ((string)$input);
	// search for the given context
	if ($ctx && isset($input[$ctx])) {
		// searched context found
		$context = $input[$ctx];
		$value = _modifier_l10n_extract_count($context, $count);
		return ($value);
	}
	// search for what could be the context
	$context = current($input);
	if (is_null($context))
		return (null);
	// if this context's content is an array, it contains a list of counts
	if (is_iterable($context)) {
		$value = _modifier_l10n_extract_count($context, $count);
		return ($value);
	}
	// this context's content is not an array
	// maybe the input is a list of counts
	$value = _modifier_l10n_extract_count($input, $count);
	if (isset($value))
		return ($value);
	// as a last resort, use the context's value
	return ((string)$context);
}
/**
 * "private" function used by the block tag to find the text from count.
 * @param	mixed	$input	Input data.
 * @param	mixed	$count	Given count.
 * @return	?string	The resulting string.
 */
function _modifier_l10n_extract_count(mixed $input, mixed $count) : ?string {
	if (is_null($input))
		return (null);
	if (!is_iterable($input))
		return ((string)$input);
	foreach ($input as $key => $val) {
		$isLessOrEqual = $isLess = false;
		if (is_string($key)) {
			$isLessOrEqual = str_starts_with($key, '<=');
			$isLess = $isLessOrEqual ? false : str_starts_with($key, '<');
		}
		if ($key == '*' ||
		    $count == $key ||
		    (is_numeric($count) &&
		     (($isLessOrEqual && $count <= mb_substr($key, 2)) ||
		      ($isLess && $count < mb_substr($key, 1))))) {
			return ((string)$val);
		}
	}
	return (null);
}


