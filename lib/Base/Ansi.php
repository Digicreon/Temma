<?php

namespace Temma\Base;

/**
 * Helper object used to write character strings in a terminal, with ANSI formatting.
 *
 * <b>Usage</b>
 *
 * <code>
 * // write bold text
 * print(\Temma\Base\Ansi::bold('blah blah blah'));
 * // write "thin" text
 * print(\Temma\Base\Ansi::faint('blah blah blah'));
 * // write underlined text
 * print(\Temma\Base\Ansi::underline('blah blah blah'));
 * // write reversed video text
 * print(\Temma\Base\Ansi::negative('blah blah blah'));
 * // write text in red
 * // (available colors are: black, red, green, yellow, blue, magenta, cyan, white)
 * print(\Temma\Base\Ansi::color('red', 'blah blah blah'));
 * // write text in red over a blue background
 * print(\Temma\Base\Ansi::backColor('blue', 'red', 'bla bla bla'));
 * </code>
 *
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2008-2019, Amaury Bouchard
 * @package	Temma
 * @subpackage	Base
 */
class Ansi {
	/** Colors definition. */
	static public $colors = array(
		'black'		=> 0,
		'red'		=> 1,
		'green'		=> 2,
		'yellow'	=> 3,
		'blue'		=> 4,
		'magenta'	=> 5,
		'cyan'		=> 6,
		'white'		=> 7
	);

	/**
	 * Bold text.
	 * @param	string	$text	Input text.
	 * @return	string	The formatted text.
	 */
	static public function bold($text) {
		return (chr(27) . '[1m' . $text . chr(27) . '[0m');
	}
	/**
	 * "Thin" text.
	 * @param	string	$text	Input text.
	 * @return	string	The formatted text.
	 */
	static public function faint($text) {
		return (chr(27) . '[2m' . $text . chr(27) . '[0m');
	}
	/**
	 * Underlined text.
	 * @param	string	$text	Input text.
	 * @return	string	The formatted text.
	 */
	static public function underline($text) {
		return (chr(27) . '[4m' . $text . chr(27) . '[0m');
	}
	/**
	 * Reverse video text.
	 * @param	string	$text	Input text.
	 * @return	string	The formatted text.
	 */
	static public function negative($text) {
		return (chr(27) . '[7m' . $text . chr(27) . '[0m');
	}
	/**
	 * Colored text.
	 * @param	string	$color	Color name (black, red, green, yellow, blue, magenta, cyan, white).
	 * @param	string	$text	Input text.
	 * @return	string	The formatted text.
	 */
	static public function color($color, $text) {
		return (chr(27) . '[9' . self::$colors[$color] . 'm' . $text . chr(27) . '[0m');
	}
	/**
	 * Background colored text.
	 * @param	string	$backColor	Background color.
	 * @param	string	$color		Text color.
	 * @param	string	$text		Input text.
	 * @return	string	The formatted text.
	 */
	static public function backColor($backColor, $color, $text) {
		return (chr(27) . '[4' . self::$colors[$backColor] . 'm' . chr(27) . '[9' . self::$colors[$color] . 'm' . $text . chr(27) . '[0m');
	}
}

