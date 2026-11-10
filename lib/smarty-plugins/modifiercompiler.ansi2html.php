<?php

/**
 * Smarty modifier that transforms a text containing ANSI escape sequences (terminal output) into HTML.
 * The text is HTML-escaped, styles and colors are converted into <span> tags, hyperlinks into <a> tags,
 * and other escape sequences are removed (see \Temma\Utils\Text::ansiToHtml()).
 * It is a compiler modifier: with Smarty 5, the generated HTML is marked as safe and is not escaped
 * again by the auto-escaping. With Smarty 4, the 'nofilter' flag must be used when auto-escaping
 * is enabled (e.g. `{$output|ansi2html nofilter}`).
 * Usage: `<pre>{$output|ansi2html}</pre>`
 * @param	array	$params		Compiled parameters (the first one is the text to process).
 * @param	object	$compiler	The Smarty template compiler.
 * @return	string	The PHP code that generates the HTML.
 * @author	Amaury Bouchard <amaury@æmaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-smarty_ansi2html
 */
function smarty_modifiercompiler_ansi2html(array $params, object $compiler) : string {
	// Smarty 5: the output is already escaped, the auto-escaping must not be applied
	if (method_exists($compiler, 'setRawOutput'))
		$compiler->setRawOutput(true);
	return ('\Temma\Utils\Text::ansiToHtml((string)(' . $params[0] . '))');
}
