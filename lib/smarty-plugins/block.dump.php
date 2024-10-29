<?php

use \Temma\Utils\Dumper as TµDumper;

/**
 * Smarty block tag used to dump data.
 * @param	array			$params		Opening tag attributes.
 * @param	string			$content	Not used.
 * @param	\Smarty\Template	$template	Template.
 * @param	bool			$repeat		False for the closing tag.
 * @return	?string	The HTML dump.
 * @author	Amaury Bouchard <amaury@æmaury.net>
 * @copyright	© 2024, Amaury Bouchard
 * @link	https://www.temma.net/en/documentation/helper-smarty_dumper
 * @link	https://smarty-php.github.io/smarty/stable/api/extending/block-tags/
 */
function smarty_block_dump($params, $content, $template, &$repeat) {
	global $smarty;

	if ($repeat/* || !$content*/)
		return (null);
	if (!isset($params['data']))
		return (null);
	$data = $params['data'];
	$type = $params['type'] ?? 'html';
	$container = $params['container'] ?? true;
	if ($type != 'html' && $type != 'text')
		return (null);
	$res = '';
	if ($type == 'text') {
		if ($container)
			$res = '<pre style="margin: 5px; padding: 5px; background-color: #fff;">';
		$res .= TµDumper::dumpText($data);
		if ($container)
			$res .= '</pre>';
		return ($res);
	}
	if ($container)
		$res = '<div class="tµ-dump" style="margin: 5px; padding: 5px; background-color: #fff;">';
	$res .= TµDumper::dumpHtml($data);
	if ($container)
		$res .= '</div>';
	return ($res);
}

