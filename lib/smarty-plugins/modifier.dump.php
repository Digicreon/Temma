<?php

use \Temma\Utils\Dumper as TµDumper;

/**
 * Smarty modifier used to dump data.
 * @param	mixed	$data		The data to dump.
 * @param	string	$type		(optional) 'html' or 'text'. Defaults to 'html'.
 * @param	bool	$container	(optional) False to remove the container. Defaults to true.
 * @return	string	The generated HTML stream.
 * @author	Amaury Bouchard <amaury@æmaury.net>
 * @copyright	© 2024, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-smarty_dumper
 */
function smarty_modifier_dump(mixed $data, string $type='html', bool $container=true) {
	global $smarty;

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

