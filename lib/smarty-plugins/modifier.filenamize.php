<?php

/**
 * Smarty modifier that transforms any text into a string that could be used as a file name.
 * @param	string	$text		The text to process.
 * @param	bool	$hyphenSpaces	Tell if spaces must be replaced by hyphens. (default: true)
 * @param	bool	$lowercase	Tell if the output must be in lowercase. (default: true)
 * @return	string	The processed text.
 * @author	Amaury Bouchard <amaury@æmaury.net>
 * @copyright	© 2017, Amaury Bouchard
 * @link	https://www.temma.net/en/documentation/helper-smarty_filenamize
 */
function smarty_modifier_filenamize(string $text, bool $hyphenSpaces=true, bool $lowercase=true) : string {
	if (!class_exists('\Temma\Utils\Text'))
		require_once('Temma/Utils/Text.php');
	$url = \Temma\Utils\Text::filenamize($text, $hyphenSpaces, $lowercase);
	return ($url);
}
