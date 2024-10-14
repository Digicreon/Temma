<?php

/**
 * Smarty modifier that transforms any text into a string that could be used in an URL.
 * @param	?string	$text	The text to process.
 * @return	string	The processed text.
 * @author	Amaury Bouchard <amaury@æmaury.net>
 * @copyright	© 2007, Amaury Bouchard
 * @link	https://www.temma.net/en/documentation/helper-smarty_urlize
 */
function smarty_modifier_urlize(?string $text) : string {
	if (!$text)
		return ('');
	if (!class_exists('\Temma\Utils\Text'))
		require_once('Temma/Utils/Text.php');
	$url = \Temma\Utils\Text::urlize($text);
	return ($url);
}
