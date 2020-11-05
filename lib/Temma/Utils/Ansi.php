<?php

/**
 * Ansi
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2008-2019, Amaury Bouchard
 */

namespace Temma\Utils;

/**
 * Helper object used to write character strings in a terminal, with ANSI formatting.
 *
 * <b>Usage</b>
 *
 * <code>
 * // write bold text
 * print(\Temma\Utils\Ansi::bold('blah blah blah'));
 * // write "thin" text
 * print(\Temma\Utils\Ansi::faint('blah blah blah'));
 * // write underlined text
 * print(\Temma\Utils\Ansi::underline('blah blah blah'));
 * // write reversed video text
 * print(\Temma\Utils\Ansi::negative('blah blah blah'));
 * // write text in red
 * // (available colors are: black, red, green, yellow, blue, magenta, cyan, white)
 * print(\Temma\Utils\Ansi::color('red', 'blah blah blah'));
 * // write text in red over a blue background
 * print(\Temma\Utils\Ansi::backColor('blue', 'red', 'bla bla bla'));
 * </code>
 */
class Ansi {
	/** Colors definition. */
	const COLORS = [
		'black'		=> 0,
		'red'		=> 1,
		'green'		=> 2,
		'yellow'	=> 3,
		'blue'		=> 4,
		'magenta'	=> 5,
		'cyan'		=> 6,
		'white'		=> 7,
	];

	/**
	 * Bold text.
	 * @param	string	$text	Input text.
	 * @return	string	The formatted text.
	 */
	static public function bold(string $text) : string {
		return (chr(27) . '[1m' . $text . chr(27) . '[0m');
	}
	/**
	 * "Thin" text.
	 * @param	string	$text	Input text.
	 * @return	string	The formatted text.
	 */
	static public function faint(string $text) : string {
		return (chr(27) . '[2m' . $text . chr(27) . '[0m');
	}
	/**
	 * Underlined text.
	 * @param	string	$text	Input text.
	 * @return	string	The formatted text.
	 */
	static public function underline(string $text) : string {
		return (chr(27) . '[4m' . $text . chr(27) . '[0m');
	}
	/**
	 * Reverse video text.
	 * @param	string	$text	Input text.
	 * @return	string	The formatted text.
	 */
	static public function negative(string $text) : string {
		return (chr(27) . '[7m' . $text . chr(27) . '[0m');
	}
	/**
	 * Colored text.
	 * @param	string	$color	Color name (black, red, green, yellow, blue, magenta, cyan, white).
	 * @param	string	$text	Input text.
	 * @return	string	The formatted text.
	 */
	static public function color(string $color, string $text) : string {
		return (chr(27) . '[9' . self::COLORS[$color] . 'm' . $text . chr(27) . '[0m');
	}
	/**
	 * Background colored text.
	 * @param	string	$backColor	Background color.
	 * @param	string	$color		Text color.
	 * @param	string	$text		Input text.
	 * @return	string	The formatted text.
	 */
	static public function backColor(string $backColor, string $color, string $text) : string {
		return (chr(27) . '[4' . self::COLORS[$backColor] . 'm' . chr(27) . '[9' . self::COLORS[$color] . 'm' . $text . chr(27) . '[0m');
	}
}

