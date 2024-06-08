<?php

/**
 * Smarty block tag to translate text strings.
 * @param	array			$params		Opening tag attributes.
 * @param	string			$content	Textual content.
 * @param	\Smarty\Template	$template	Template.
 * @param	bool			$repeat		False for the closing tag.
 * @return	string	The translated string.
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
	$domain = 'default';
	if (isset($params['_domain']))
		$domain = $params['_domain'];
	// search for the translated string
	$l10n = $smarty->getTemplateVars('l10n');
	$res = $l10n[$domain][$content] ?? null;
	if (!isset($res)) {
		// not found: use the given string
		$res = $content;
	}
	// process the string with data if needed
	if ($params) {
		$replace = [];
		foreach ($params as $key => $val) {
			if ($key != '_domain')
				$replace['%' . $key . '%'] = $val;
		}
		$res = strtr($res, $replace);
	}
	return ($res);
}

