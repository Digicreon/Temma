<?php

/**
 * Smarty block tag to translate text strings.
 * @param	array			$params		Opening tag attributes.
 * @param	string			$content	Textual content.
 * @param	\Smarty\Template	$template	Template.
 * @param	bool			$repeat		False for the closing tag.
 * @return	?string	The translated string.
 * @link	https://www.temma.net/en/documentation/helper-plugin_language
 * @link	https://smarty-php.github.io/smarty/stable/api/extending/block-tags/
 * @link	https://stackoverflow.com/a/23178267
 * @author	Amaury Bouchard <amaury@æmaury.net>
 * @copyright	© 2024, Amaury Bouchard
 */
function smarty_block_l10n($params, $content, $template, &$repeat) {
	global $smarty;

	if ($repeat || !$content)
		return (null);
	$content = trim($content);
	// get parameters
	$split = $domain = $ctx = $count = null;
	if (isset($params['_']))
		$split = explode(',', $params['_']);
	$domain = $params['_domain'] ?? $split[0] ?? null;
	$domain = (is_string($domain) ? trim($domain) : null) ?: 'default';
	$ctx = $params['_ctx'] ?? $split[1] ?? null;
	$ctx = (is_string($ctx) ? trim($ctx) : null) ?: null;
	$count = $params['_count'] ?? $split[2] ?? null;
	$count = is_string($count) ? trim($count) : $count;
	if (!is_numeric($count)) {
		if (is_countable($count))
			$count = count($count);
		else if ($count || is_null($count))
			$count = '*';
		else
			$count = 0;
	}
	// search for the translated string
	$l10n = $smarty->getTemplateVars('l10n');
	$res = $l10n[$domain][$content] ?? null;
	$res = _l10n_extract_context_count($res, $ctx, $count);
	if (!isset($res)) {
		// not found: use the given string
		$res = $content;
	}
	// process the string with data if needed
	if ($params) {
		$replace = [];
		foreach ($params as $key => $val) {
			if (!in_array($key, ['_domain', '_ctx', '_count']))
				$replace['%' . $key . '%'] = $val;
		}
		if (isset($ctx))
			$replace['%_ctx%'] = $ctx;
		if (isset($count))
			$replace['%_count%'] = $count;
		$res = strtr($res, $replace);
	}
	return ($res);
}
/**
 * "private" function used by the block tag to find the text from context and count.
 * @param	mixed	$input	Data associated to the translated string.
 * @param	mixed	$ctx	Given context.
 * @param	mixed	$count	Given count.
 * @return	?string	The resulting string.
 */
function _l10n_extract_context_count(mixed $input, mixed $ctx, mixed $count) : ?string {
	if (is_null($input))
		return (null);
	if (!is_iterable($input) && !($input instanceof \ArrayAccess))
		return ((string)$input);
	// search for the given context
	if ($ctx && isset($input[$ctx])) {
		// searched context found
		$context = $input[$ctx];
		$value = _l10n_extract_count($context, $count);
		return ($value);
	}
	// search for what could be the context
	$context = current($input);
	if (is_null($context))
		return (null);
	// if this context's content is an array, it contains a list of counts
	if (is_iterable($context)) {
		$value = _l10n_extract_count($context, $count);
		return ($value);
	}
	// this context's content is not an array
	// maybe the input is a list of counts
	$value = _l10n_extract_count($input, $count);
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
function _l10n_extract_count(mixed $input, mixed $count) : ?string {
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

