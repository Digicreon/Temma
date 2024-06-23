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
	// search if there was a specified domain ('default' as default domain)
	$domain = ($params['_domain'] ?? null) ?: 'default';
	// search if there was a count parameter
	$count = $params['_count'] ?? null;
	if (!is_numeric($count)) {
		if (is_countable($count))
			$count = count($count);
		else if ($count || is_null($count))
			$count = '*';
		else
			$count = 0;
	}
	// search if there was a context
	$ctx = $params['_ctx'] ?? null;
	// search for the translated string
	$l10n = $smarty->getTemplateVars('l10n');
	$res = $l10n[$domain][$content] ?? null;
	if (is_iterable($res)) {
		// found an array
		// search for context
		if (isset($res['default']) && (!$ctx || !isset($res[$ctx]))) {
			// no context asked, or the asked context doesn't exist
			// => use the default context
			$res = $res['default'];
		} else if ($ctx && isset($res[$ctx])) {
			// a context was asked, and it exists
			// => use it
			$res = $res[$ctx];
		}
		if (!is_iterable($res)) {
			// direct string associated to the context
			$res = (string)$res;
		} else {
			// loop on count definitions
			$found = false;
			foreach ($res as $key => $val) {
				$isLessOrEqual = str_starts_with($key, '<=');
				$isLess = $isLessOrEqual ? false : str_starts_with($key, '<');
				if ($key == '*' ||
				    $count == $key ||
				    (is_numeric($count) &&
				     (($isLessOrEqual && $count <= mb_substr($key, 2)) ||
				      ($isLess && $count < mb_substr($key, 1))))) {
					$res = (string)$val;
					$found = true;
					break;
				}
			}
			if (!$found)
				$res = null;
		}
	}
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

