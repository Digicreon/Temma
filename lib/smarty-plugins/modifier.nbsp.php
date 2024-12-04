<?php

/**
 * Smarty modifier that transforms spaces before or after some characters into non-breaking spaces.
 * @param	string	$text	The text to transform.
 * @return	string	The processed text.
 * @author	Amaury Bouchard <amaury@æmaury.net>
 * @copyright	© 2019, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-smarty_nbsp
 */
function smarty_modifier_nbsp(string $text) : string {
	$text = str_replace(
		[
			' ?',
			' !',
			' :',
			' ;',
			' -',
			' −',
			' +',
			' ×',
			' ÷',
			' €',
			' %',
			'« ',
			' »',
			'“ ',
			' ”',
		],
		[
			'&nbsp;?',
			'&nbsp;!',
			'&nbsp;:',
			'&nbsp;;',
			'&nbsp;-',
			'&nbsp;−',
			'&nbsp;+',
			'&nbsp;×',
			'&nbsp;÷',
			'&nbsp;€',
			'&nbsp;%',
			'«&nbsp;',
			'&nbsp;»',
			'“&nbsp;',
			'&nbsp;”',
		],
		$text,
	);
	return ($text);
}
