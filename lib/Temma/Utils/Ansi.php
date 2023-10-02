<?php

/**
 * Ansi
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2008-2023, Amaury Bouchard
 */

namespace Temma\Utils;

use \Temma\Base\Log as TµLog;
use \Temma\Utils\Term as TµTerm;

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
 * print(\Temma\Utils\Ansi::backColor('blue', 'red', 'blah blah blah'));
 * </code>
 *
 * @see	https://gist.github.com/fnky/458719343aabd01cfb17a3a4f7296797
 * @see	https://gist.github.com/JBlond/2fea43a3049b38287e5e9cefc87b2124
 * @see	https://en.wikipedia.org/wiki/Box-drawing_character
 * @see	https://gist.github.com/egmontkob/eb114294efbcd5adb1944c9f3cb5feda
 * @see	https://stackoverflow.com/questions/1176904/how-to-remove-all-non-printable-characters-in-a-string
 */
class Ansi {
	/** Textual color definitions. */
	const COLORS = [
		'light-black' => '0',
		'red'         => '1',
		'maroon'      => '1',
		'dark-green'  => '2',
		'olive'       => '3',
		'blue'        => '4',
		'navy'        => '4',
		'purple'      => '5',
		'teal'        => '6',
		'silver'      => '7',
		'gray'        => '8',
		'grey'        => '8',
		'light-red'   => '9',
		'green'       => '10',
		'lime'        => '10',
		'yellow'      => '11',
		'light-blue'  => '12',
		'magenta'     => '13',
		'fuchsia'     => '13',
		'cyan'        => '14',
		'aqua'        => '14',
		'white'       => '15',
		'black'       => '16',
	];

	/** Defined styles. */
	static protected array $_styles = [
		// title 1
		'h1' => [
			'display'      => 'block',
			'backColor'    => '17',
			'textColor'    => 'white',
			'borderColor'  => 'white',
			'labelColor'   => '17',
			'bold'         => true,
			'italic'       => false,
			'underline'    => false,
			'faint'        => false,
			'strikeout'    => false,
			'blink'        => false,
			'reverse'      => false,
			'line'         => '┏━┓┃┛━┗┃',
			'padding'      => 3,
			'marginTop'    => 1,
			'marginBottom' => 1,
		],
		// title 2
		'h2' => [
			'display'      => 'block',
			'backColor'    => 'blue',
			'textColor'    => 'white',
			'borderColor'  => 'white',
			'bold'         => false,
			'italic'       => false,
			'underline'    => false,
			'faint'        => false,
			'strikeout'    => false,
			'blink'        => false,
			'reverse'      => false,
			'line'         => '╭─╮│╯─╰│',
			'padding'      => 1,
			'marginTop'    => 0,
			'marginBottom' => 1,
		],
		// title 3
		'h3' => [
			'display'      => 'block',
			'backColor'    => '39', // light blue
			'textColor'    => 'black',
			'borderColor'  => false,
			'bold'         => false,
			'italic'       => false,
			'underline'    => false,
			'faint'        => false,
			'strikeout'    => false,
			'blink'        => false,
			'reverse'      => false,
			'line'         => '',
			'padding'      => 1,
			'marginTop'    => 0,
			'marginBottom' => 1,
		],
		// title 4
		'h4' => [
			'display'      => 'block',
			'backColor'    => '117', // very light blue
			'textColor'    => 'black',
			'borderColor'  => 'white',
			'bold'         => false,
			'italic'       => false,
			'underline'    => false,
			'faint'        => false,
			'strikeout'    => false,
			'blink'        => false,
			'reverse'      => false,
			'line'         => '',
			'padding'      => 0,
			'marginTop'    => 0,
			'marginBottom' => 1,
		],
		// p
		'p' => [
			'display'      => 'block',
			'backColor'    => 'default',
			'textColor'    => 'default',
			'borderColor'  => null,
			'bold'         => null,
			'italic'       => null,
			'underline'    => null,
			'faint'        => null,
			'strikeout'    => null,
			'blink'        => null,
			'reverse'      => null,
			'line'         => '',
			'padding'      => 0,
			'marginTop'    => 0,
			'marginBottom' => 1,
		],
		// code
		'code' => [
			'display'      => 'block',
			'backColor'    => 'black',
			'textColor'    => 'green',
			'borderColor'  => 'dark-green',
			'bold'         => false,
			'italic'       => false,
			'underline'    => false,
			'faint'        => false,
			'strikeout'    => false,
			'blink'        => false,
			'reverse'      => false,
			'line'         => '       ▌',
			'padding'      => 1,
			'marginTop'    => 0,
			'marginBottom' => 1,
		],
		// pre
		'pre' => [
			'display'      => 'block',
			'backColor'    => 'silver',
			'textColor'    => 'black',
			'borderColor'  => 'gray',
			'bold'         => false,
			'italic'       => false,
			'underline'    => false,
			'faint'        => false,
			'strikeout'    => false,
			'blink'        => false,
			'reverse'      => false,
			'line'         => '       ▌',
			'padding'      => 1,
			'marginTop'    => 0,
			'marginBottom' => 1,
		],
		// comment
		'comment' => [
			'display'      => 'block',
			'label'        => '',
			'backColor'    => 189,
			'textColor'    => 'black',
			'borderColor'  => 'blue',
			'bold'         => false,
			'italic'       => true,
			'underline'    => false,
			'faint'        => false,
			'strikeout'    => false,
			'blink'        => false,
			'reverse'      => false,
			'line'         => '       ▌',
			'padding'      => 1,
			'marginTop'    => 0,
			'marginBottom' => 1,
		],
		// success
		'success' => [
			'display'      => 'block',
			'backColor'    => 'green',
			'textColor'    => 'black',
			'borderColor'  => 22, // dark green
			'bold'         => false,
			'italic'       => false,
			'underline'    => false,
			'faint'        => false,
			'strikeout'    => false,
			'blink'        => false,
			'reverse'      => false,
			'line'         => '       ▌',
			'padding'      => 1,
			'marginTop'    => 0,
			'marginBottom' => 1,
		],
		// info
		'info' => [
			'display'      => 'block',
			//'backColor'    => 'yellow',
			'backColor'    => 221,
			'textColor'    => 'black',
			'borderColor'  => 130, // orange
			'bold'         => false,
			'italic'       => false,
			'underline'    => false,
			'faint'        => false,
			'strikeout'    => false,
			'blink'        => false,
			'reverse'      => false,
			'line'         => '       ▌',
			'padding'      => 1,
			'marginTop'    => 0,
			'marginBottom' => 1,
		],
		// alert
		'alert' => [
			'display'      => 'block',
			'backColor'    => 'light-red',
			'textColor'    => 'white',
			'borderColor'  => 'red',
			'bold'         => false,
			'italic'       => false,
			'underline'    => false,
			'faint'        => false,
			'strikeout'    => false,
			'blink'        => false,
			'reverse'      => false,
			'line'         => '       ▌',
			'padding'      => 1,
			'marginTop'    => 0,
			'marginBottom' => 1,
		],
		// span
		'span' => [
			'display' => 'inline',
		],
		// inline styles
		'b' => [
			'display' => 'inline',
			'bold'    => true,
		],
		'i' => [
			'display' => 'inline',
			'italic'  => true,
		],
		'u' => [
			'display'   => 'inline',
			'underline' => true,
		],
		'faint' => [
			'display' => 'inline',
			'faint'   => true,
		],
		's' => [
			'display'   => 'inline',
			'strikeout' => true,
		],
		'blink' => [
			'display' => 'inline',
			'blink'   => true,
		],
		'tt' => [
			'display' => 'inline',
			'reverse' => true,
		],
	];

	/* ********** THROBBER VARIABLES ********** */
	/** Throbber text. */
	static protected string $_throbberText = 'Processing...';
	/** Throbber characters. */
	static protected string|array $_throbberCharacters = ['⢎⡰', '⢎⡡', '⢎⡑', '⢎⠱', '⠎⡱', '⢊⡱', '⢌⡱', '⢆⡱'];
	/** Throbber offset. */
	static protected int $_throbberOffset = 1;

	/* ********** PROGRESS BAR VARIABLES ********** */
	/** Progress bar background color. */
	static protected string $_progressBackColor = 'red';
	/** Progress bar foreground color. */
	static protected string $_progressTextColor = 'green';
	/** Progress bar bold text. */
	static protected bool $_progressBold = true;
	/** Progress bar text. */
	static protected string $_progressText = '';
	/** Progress bar total units. */
	static protected int $_progressTotal = 100;
	/** Progress bar current units. */
	static protected int $_progressCurrent = 0;
	/** Progress bar displayed as percentage. */
	static protected bool $_progressPercentage = true;
	/** Progress bar width (1 for full width, 2 for half width, 3 for third width, 4 for fourth width). */
	static protected int $_progressWidth = 1;

	/* ********** BASIC TEXT FUNCTIONS ********** */
	/**
	 * Returns the length of a string, counting only printable UTF-8 characters.
	 * Tabs are couted for 8 characters.
	 * @param	?string	$string	Input string.
	 * @return	int	The string length.
	 */
	static public function strlen(?string $string) : int {
		$res = 0;
		while ($string) {
			// search for ANSI control sequence
			if (preg_match("/e\\[[0-9;]*m/", $string, $matches) && ($matches[1] ?? null)) {
				$string = mb_substr($string, mb_strlen($matches[1]));
				continue;
			}
			$char = mb_substr($string, 0, 1);
			$string = mb_substr($string, 1);
			// search tabs
			if ($char == "\t") {
				$res += 8;
				continue;
			}
			// search for non printable character
			if (preg_match('/[\x00-\x1F\x7F\xA0]/u', $char))
				continue;
			$res++;
		}
		return ($res);
	}
	/**
	 * Wordwrap function that uses only printable characters.
	 * @param	?string	$string	Text to wrap.
	 * @param	int	$width	Maximum number of characters per line.
	 * @return	string	The wrapped text.
	 */
	static public function wordwrap(?string $string, int $width) : string {
		$res = '';
		$line = '';
		$lineLen = 0;
		$word = '';
		$wordLen = 0;
		while ($string) {
			// search for ANSI control sequence
			if (preg_match("/e\\[[0-9;]*m/", $string, $matches)) {
				$line .= $matches[1];
				$string = mb_substr($string, mb_strlen($matches[1]));
				continue;
			}
			$char = mb_substr($string, 0, 1);
			$string = mb_substr($string, 1);
			// search for non printable character
			if (!ctype_space($char) && preg_match('/[\x00-\x1F\x7F\xA0]/u', $char)) {
				$line .= $char;
				$string = mb_substr($string, 1);
				continue;
			}
			// character management
			if (!ctype_space($char)) {
				$word .= $char;
				$wordLen++;
			} else {
				while ($wordLen >= $width) {
					$res .= $line . "\n";
					$res .= mb_substr($word, 0, $width) . "\n";
					$word = mb_substr($word, $width);
					$wordLen -= $width;
					$line = '';
					$lineLen = 0;
				}
				$charSize = ($char == ' ') ? 1 :
				            (($char == "\t") ? 8 : 0);
				if (($lineLen + $wordLen + $charSize) >= ($width + 1)) {
					$res .= $line . "\n";
					$line = $word;
					$lineLen = $wordLen;
					$word = '';
					$wordLen = 0;
				}
				if ($word) {
					$line .= $word;
					$lineLen += $wordLen;
					$word = '';
					$wordLen = 0;
				}
				if ($char == "\n") {
					$res .= $line . "\n";
					$line = '';
					$lineLen = 0;
				} else {
					$line .= $char;
					$lineLen += $charSize;
				}
			}
			// size management
			if ($lineLen >= $width) {
				$res .= $line . "\n";
				$line = '';
				$lineLen = 0;
			}
		}
		// last processing
		while ($wordLen >= $width) {
			$res .= $line . "\n";
			$res .= mb_substr($word, 0, $width) . "\n";
			$word = mb_substr($word, $width);
			$wordLen -= $width;
			$line = '';
			$lineLen = 0;
		}
		if (($lineLen + $wordLen) >= $width) {
			$res .= $line . "\n";
			$line = $word;
			$lineLen = $wordLen;
			$word = '';
			$wordLen = 0;
		}
		if ($word)
			$line .= $word;
		if ($line)
			$res .= $line;
		return ($res);
	}

	/* ********** BASIC STYLING FUNCTIONS ********** */
	/**
	 * Bold text.
	 * @param	?string	$text	Input text.
	 * @return	string	The formatted text.
	 */
	static public function bold(?string $text) : string {
		return ($text ? "\e[1m$text\e[22m" : '');
	}
	/**
	 * "Thin" text.
	 * @param	?string	$text	Input text.
	 * @return	string	The formatted text.
	 */
	static public function faint(?string $text) : string {
		return ($text ? "\e[2m$text\e[22m" : '');
	}
	/**
	 * Italic text.
	 * @param	?string	$text	Input text.
	 * @return	string	The formatted text.
	 */
	static public function italic(?string $text) : string {
		return ($text ? "\e[3m;$text\e[23m" : '');
	}
	/**
	 * Underlined text.
	 * @param	?string	$text	Input text.
	 * @return	string	The formatted text.
	 */
	static public function underline(?string $text) : string {
		return ($text ? "\e[4m$text\e[24m" : '');
	}
	/**
	 * Blinking text.
	 * @param	?string	$text	Input text.
	 * @return	string	The formatted text.
	 */
	static public function blink(?string $text) : string {
		return ($text ? "\e[5m$text\e[25m" : '');
	}
	/**
	 * Reverse video text.
	 * @param	?string	$text	Input text.
	 * @return	string	The formatted text.
	 */
	static public function negative(?string $text) : string {
		return ($text ? "\e[7m$text\e[27m" : '');
	}
	/**
	 * Striked out text.
	 * @param	?string	$text	Input text.
	 * @return	string	The formatted text.
	 */
	static public function strikeout(?string $text) : string {
		return ($text ? "\e[9m$text\e[29m" : '');
	}
	/**
	 * Returns the escape sequence that resets all color and formatting.
	 * @return	string	The escape sequence.
	 */
	static public function resetStyle() : string {
		return ("\e[0m");
	}
	/**
	 * Colored text.
	 * @param	int|string	$textColor	Text color name (16 colors palette) or color number (256 colors palette) or "default".
	 * @param	?string	$text	Input text.
	 * @return	string	The formatted text.
	 */
	static public function color(int|string $textColor, ?string $text) : string {
		if (!$text)
			return ('');
		$textColor = (is_string($textColor) && ctype_digit($textColor)) ? intval($textColor) : $textColor;
		if ($textColor === "default" || (is_string($textColor) && !isset(self::COLORS[$textColor])))
			return ("\e[39m$text");
		if (is_string($textColor))
			return ("\e[38;5;" . self::COLORS[$textColor] . "m$text\e[39m");
		return ("\e[38;5;{$textColor}m$text\e[39m");
	}
	/**
	 * Background colored text.
	 * @param	int|string	$backColor	Background color name (16 colors palette) or color number (256 colors palette) or "default".
	 * @param	int|string	$textColor	Text color name (16 colors palette) or color number (256 colors palette) or "default".
	 * @param	?string		$text		Input text.
	 * @return	string	The formatted text.
	 */
	static public function backColor(int|string $backColor, int|string $textColor, ?string $text) : string {
		if (!$text)
			return ('');
		$prefix1 = $prefix2 = $suffix1 = $suffix2 = '';
		// background color
		$backColor = (is_string($backColor) && ctype_digit($backColor)) ? intval($backColor) : $backColor;
		if ($backColor === "default" || (is_string($backColor) && !isset(self::COLORS[$backColor]))) {
			$prefix1 = "\e[49m";
		} else if (is_int($backColor)) {
			$prefix1 = "\e[48;5;{$backColor}m";
			$suffix1 = "\e[49m";
		} else if (is_string($backColor)) {
			$prefix1 = "\e[48;5;" . self::COLORS[$backColor] . 'm';
			$suffix1 = "\e[49m";
		}
		// text color
		$textColor = (is_string($textColor) && ctype_digit($textColor)) ? intval($textColor) : $textColor;
		if ($textColor === "default" || (is_string($textColor) && !isset(self::COLORS[$textColor]))) {
			$prefix2 = "\e[39m";
		} else if (is_int($textColor)) {
			$prefix2 = "\e[38;5;{$textColor}m";
			$suffix2 = "\e[39m";
		} else if (is_string($textColor)) {
			$prefix2 = "\e[38;5;" . self::COLORS[$textColor] . 'm';
			$suffix2 = "\e[39m";
		}
		return ("$prefix1$prefix2$text$suffix2$suffix1");
	}
	/**
	 * Hyperlink.
	 * @param	?string	$url	Link URL.
	 * @param	?string	$title	(optional) Link title.
	 * @return	string	The formatted text.
	 */
	static public function link(?string $url, ?string $title=null) : string {
		if (!$url && !$title)
			return ('');
		if (!$url)
			return ($title);
		if (!$title) {
			$title = mb_substr($url, 0, 40);
			$title .= (mb_strlen($url) > 40) ? '...' : '';
		}
		return ("\e]8;;$url\e\\$title\e]8;;\e\\");
	}
	/**
	 * Generate a first level title.
	 * @param	?string	$s	The title text.
	 * @return	string	The formatted text.
	 */
	static public function title1(?string $s) : string {
		if (!$s)
			return ('');
		return (self::style('<h1>' . htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1) . '</h1>'));
	}
	/**
	 * Display a second level title.
	 * @param	?string	$s	The title text.
	 * @return	string	The formatted text.
	 */
	static public function title2(?string $s) : string {
		if (!$s)
			return ('');
		return (self::style('<h2>' . htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1) . '</h2>'));
	}
	/**
	 * Display a third level title.
	 * @param	?string	$s	The title text.
	 * @return	string	The formatted text.
	 */
	static public function title3(?string $s) : string {
		if (!$s)
			return ('');
		return (self::style('<h3>' . htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1) . '</h3>'));
	}
	/**
	 * Display a fourth level title.
	 * @param	?string	$s	The title text.
	 * @return	string	The formatted text.
	 */
	static public function title4(?string $s) : string {
		if (!$s)
			return ('');
		return (self::style('<h4>' . htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1) . '</h4>'));
	}
	/**
	 * Display a block content.
	 * @param	string	$block	Block name.
	 * @param	?string	$s	Block text.
	 * @return	string	The formatted text.
	 */
	static public function block(string $block, ?string $s) : string {
		if (!$s)
			return ('');
		return (self::style("<$block>" . htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1) . "</$block>"));
	}

	/* ********** STYLES MANAGEMENT ********** */
	/**
	 * Set the style of a tag.
	 * @param	string		$tag		Tag name (e.g. 'h1', 'h2', 'error').
	 * @param	?string		$display	(optional) Display type ('block' or 'inline').
	 * @param	null|int|string	$backColor	(optional) Background color.
	 * @param	null|int|string	$textColor	(optional) Color of the text.
	 * @param	null|int|string	$borderColor	(optional) Border color.
	 * @param	?string		$labelColor	(optional) Background color of the label.
	 * @param	?bool		$bold		(optional) True for bold text.
	 * @param	?bool		$italic		(optional) True for italic text.
	 * @param	?bool		$underline	(optional) True for underlined text (only if the bold parameter is false).
	 * @param	?bool		$faint		(optional) True for faint text.
	 * @param	?bool		$strikeout	(optional) True for striked out text.
	 * @param	?bool		$blink		(optional) True for blinked text.
	 * @param	?bool		$reverse	(optional) True for reverse video.
	 * @param	?string		$label		(optional) Text of the label which will appear above the box.
	 * @param	?string		$line		(optional) Characters used to draw the box (vertical bar, horizontal bar, upperl left corner,
	 *						upper right corner, lower right corner, lower left corner). Empty string to have no border.
	 * @param	?int		$padding	(optional) Padding size. 0 for no padding, 1 for thin padding (1 character), 2 for large padding (2 characters),
	 *						3 for extra-large padding (3 characters).
	 * @param	?int		$marginTop	(optional) Top margin size.
	 * @param	?int		$marginBottom	(optional) Bottom margin size.
	 * @return	array		The old style definition. Empty array if the style didn't exist.
	 */
	static public function setStyle(string $tag, ?string $display=null, null|int|string $backColor=null, null|int|string $textColor=null,
	                                null|int|string $borderColor=null, ?string $labelColor=null, ?bool $bold=null, ?bool $italic=null,
	                                ?bool $underline=null, ?bool $faint=null, ?bool $strikeout=null, ?bool $blink=null, ?bool $reverse=null,
	                                ?string $label=null, ?string $line=null, ?int $padding=null, ?int $marginTop=null, ?int $marginBottom=null) : array {
		self::$_styles[$tag] ??= [];
		$oldStyle = self::$_styles[$tag];
		if ($display !== null)
			self::$_styles[$tag]['display'] = $display;
		if ($backColor !== null)
			self::$_styles[$tag]['backColor'] = $backColor;
		if ($textColor !== null)
			self::$_styles[$tag]['textColor'] = $textColor;
		if ($borderColor !== null)
			self::$_styles[$tag]['borderColor'] = $borderColor;
		if ($labelColor !== null)
			self::$_styles[$tag]['labelColor'] = $labelColor;
		if ($bold !== null)
			self::$_styles[$tag]['bold'] = $bold;
		if ($italic !== null)
			self::$_styles[$tag]['italic'] = $italic;
		if ($underline !== null)
			self::$_styles[$tag]['underline'] = $underline;
		if ($faint !== null)
			self::$_styles[$tag]['faint'] = $faint;
		if ($strikeout !== null)
			self::$_styles[$tag]['strikeout'] = $strikeout;
		if ($blink !== null)
			self::$_styles[$tag]['blink'] = $blink;
		if ($reverse !== null)
			self::$_styles[$tag]['reverse'] = $reverse;
		if ($label !== null)
			self::$_styles[$tag]['label'] = $label;
		if ($line !== null)
			self::$_styles[$tag]['line'] = $line;
		if ($padding !== null)
			self::$_styles[$tag]['padding'] = $padding;
		if ($marginTop !== null)
			self::$_styles[$tag]['marginTop'] = $marginTop;
		if ($marginBottom !== null)
			self::$_styles[$tag]['marginBottom'] = $marginBottom;
		return ($oldStyle);
	}

	/* ********** XML FUNCTIONS ********** */
	/**
	 * Interprets XML tags.
	 *
	 * <h1>Title 1</h1>
	 * <h2>Title 2</h2>
	 * <h3>Title 3</h3>
	 * <h4>Title 4</h4>
	 *
	 * <code>green on black, with green left border</code>
	 * <pre>reverse video</pre>
	 * <success>green box</success>
	 * <info>yellow box</info>
	 * <alert>red box</alert>
	 *
	 * <p>Paragraph (blank line after the text)</p>
	 *
	 * <b>strong text</b>
	 * <i>italic text</i>
	 * <u>underlined text</u>
	 * <faint>faint text</faint>
	 * <s>striked out text</s>
	 * <blink>blinking text</blink>
	 * <tt>reverse video text</tt>
	 * <a href="https://www.temma.net">Temma website</a>
	 *
	 * @param	?string	$str	XML string.
	 * @return	string	ANSI-formatted string.
	 */
	static public function style(?string $str) : string {
		if (!$str)
			return ('');
		$rootTag = 'root-' . uniqid();
		$xml = new \DOMDocument();
		$xml->loadXML("<$rootTag>$str</$rootTag>");
		$result = self::_styleNode($xml->documentElement);
		return ($result);
	}
	/**
	 * Style the given XML node.
	 * @param	\DomNode	$node		The XML node to process.
	 * @param	array		$blockStyle	(optional) Style of the parent block node.
	 * @return	string	The formatted text.
	 */
	static protected function _styleNode(\DomNode $node, ?array $blockStyle=[]) {
		$result = '';
		foreach ($node->childNodes as $subnode) {
			if ($subnode instanceof \DOMText) {
				// text
				$s = $subnode->nodeValue;
				$s = trim($s, "\n");
				$result .= self::_styleBlockContent($s, $blockStyle);
			} else if ($subnode instanceof \DOMElement) {
				// subnode
				$tag = $subnode->nodeName;
				// fetch the subnode's style
				$style = self::$_styles[$tag] ?? [];
				// tell if we are in a block or not
				$inBlock = (($style['display'] ?? null) == 'block');
				// node attributes
				foreach ($subnode->attributes as $attr) {
					$attrName = $attr->name;
					$value = null;
					if ($tag == 'color') {
						if ($attrName == 't')
							$attrName = 'textColor';
						else if ($attrName == 'b')
							$attrName = 'backColor';
					}
					if ($attr->value == 'true')
						$value = true;
					else if ($attr->value == 'false')
						$value = false;
					else if ($attr->value != 'null')
						$value = $attr->value;
					$style[$attrName] = $value;
				}
				// specific tags
				if ($tag == 'br') {
					// <br /> tag
					$result .= "\n";
					continue;
				} else if ($tag == 'a') {
					// <a> tag
					$href = $style['href'] ?? null;
					$title = $subnode->nodeValue;
					if (!$href)
						$result .= $title;
					else
						$result .= self::link($style['href'], $title);
					continue;
				}
				// apply the node's style
				if ($inBlock)
					$result .= self::_styleStartBlock($style);
				//else
					$result .= self::_applyStyle($style);
				// process the subnodes
				$parentBlockStyle = $blockStyle ? $blockStyle : ($inBlock ? $style : []);
				$result .= self::_styleNode($subnode, $parentBlockStyle);
				// end block / restore the style
				if ($inBlock) {
					if (mb_substr($result, -1) != "\n")
						$result .= "\n";
					$result .= self::_styleEndBlock($style);
					$result .= self::_applyStyle(false);
				} else
					$result .= self::_applyStyle($style, reverse: true);
			}
		}
		return ($result);
	}
	/**
	 * Apply a block style on its content.
	 * @param	string	$s	Textual content.
	 * @param	array	$style	(optional) Block style.
	 * @return	string	The formatted string. If the given style is not for a block, the input string is left untouched.
	 */
	static protected function _styleBlockContent(string $s, array $style=[]) : string {
		if (($style['display'] ?? null) != 'block')
			return ($s);
		[$screenWidth, $screenHeight] = TµTerm::getScreenSize();
		$backColor = ($style['backColor'] ?? null) ?: 'default';
		$textColor = ($style['textColor'] ?? null) ?: 'default';
		$borderColor = ($style['borderColor'] ?? null) ?: 'default';
		$bold = $style['bold'] ?? false;
		$underline = $style['underline'] ?? false;
		$padding = $style['padding'] ?? 0;
		$lineRight = ($style['line'] ?? null) ? trim(mb_substr($style['line'], 3, 1)) : '';
		$lineLeft = ($style['line'] ?? null) ? trim(mb_substr($style['line'], 7, 1)) : '';
		$res = '';
		$s = self::wordwrap($s, ($screenWidth - (($padding > 1) ? 8 : 4)));
		$lines = explode("\n", $s);
		foreach ($lines as $line) {
			$lineLen = self::strlen($line);
			$pad = str_repeat(' ', ($screenWidth - $lineLen - (($padding > 1) ? 8 : 4)));
			$res .= self::backColor($backColor, $borderColor, $lineLeft);
			$res .= self::backColor($backColor, $textColor, (($padding > 1) ? '   ' : ' '), $bold);
			$res .= self::backColor($backColor, $textColor, $line, $bold, $underline);
			$res .= self::backColor($backColor, $textColor, $pad . (($padding > 1) ? '   ' : ' ') .
			                                                ($lineLeft ? '' : ' ') . ($lineRight ? '' : ' '));
			$res .= self::backColor($backColor, $borderColor, $lineRight);
			$res .= "\n";
		}
		return ($res);
	}
	/**
	 * Generate the text on top of a block.
	 * @param	array	$style	Style definition.
	 * @return	string	The formatted string.
	 */
	static protected function _styleStartBlock(array $style) : string {
		if (($style['display'] ?? null) != 'block')
			return ('');
		$res = '';
		[$screenWidth, $screenHeight] = TµTerm::getScreenSize();
		$lineTopLeft = ($style['line'] ?? null) ? trim(mb_substr($style['line'], 0, 1)) : '';
		$lineTop = ($style['line'] ?? null) ? trim(mb_substr($style['line'], 1, 1)) : '';
		$lineTopRight = ($style['line'] ?? null) ? trim(mb_substr($style['line'], 2, 1)) : '';
		$lineRight = ($style['line'] ?? null) ? trim(mb_substr($style['line'], 3, 1)) : '';
		$lineBottomRight = ($style['line'] ?? null) ? trim(mb_substr($style['line'], 4, 1)) : '';
		$lineBottom = ($style['line'] ?? null) ? trim(mb_substr($style['line'], 5, 1)) : '';
		$lineBottomLeft = ($style['line'] ?? null) ? trim(mb_substr($style['line'], 6, 1)) : '';
		$lineLeft = ($style['line'] ?? null) ? trim(mb_substr($style['line'], 7, 1)) : '';
		$backColor = $style['backColor'] ?? 'red';
		$borderColor = $style['borderColor'] ?? 'default';
		// margin
		if (($style['marginTop'] ?? null)) {
			$res .= str_repeat("\n", $style['marginTop']);
		}
		// label
		if (($style['label'] ?? null)) {
			$label = self::wordwrap($style['label'], ($screenWidth - 2));
			$label = str_replace("\n", " \n ", $label);
			$labelColor = ($style['labelColor'] ?? null) ?:
			              (($borderColor != 'white') ? $borderColor :
			               (($backColor != 'white') ? $backColor : 'black'));
			$res .= self::backColor($labelColor, 'white', ' ' . $style['label'] . ' ') . "\n";
		}
		// line
		if (($style['borderColor'] ?? null) && ($lineTopLeft || $lineTop || $lineTopRight)) {
			$res .= self::backColor($backColor, $borderColor, $lineTopLeft . str_repeat($lineTop, ($screenWidth - 2)) . $lineTopRight) . "\n";
		}
		// padding
		if (($style['padding'] ?? null)) {
			for ($i = 0; $i < $style['padding']; $i++) {
				$padding = $lineLeft . str_repeat(' ', ($screenWidth - 2)) .
				           ($lineLeft ? '' : ' ') . $lineRight . ($lineRight ? '' : ' ');
				$res .= self::backColor($backColor, $borderColor, $padding) . "\n";
			}
		}
		return ($res);
	}
	/**
	 * Generate the text under a block.
	 * @param	array	$style	Style definition.
	 * @return	string	The formatted string.
	 */
	static protected function _styleEndBlock(array $style) : string {
		if (($style['display'] ?? null) != 'block')
			return ('');
		[$screenWidth, $screenHeight] = TµTerm::getScreenSize();
		$res = '';
		$lineTopLeft = ($style['line'] ?? null) ? trim(mb_substr($style['line'], 0, 1)) : '';
		$lineTop = ($style['line'] ?? null) ? trim(mb_substr($style['line'], 1, 1)) : '';
		$lineTopRight = ($style['line'] ?? null) ? trim(mb_substr($style['line'], 2, 1)) : '';
		$lineRight = ($style['line'] ?? null) ? trim(mb_substr($style['line'], 3, 1)) : '';
		$lineBottomRight = ($style['line'] ?? null) ? trim(mb_substr($style['line'], 4, 1)) : '';
		$lineBottom = ($style['line'] ?? null) ? trim(mb_substr($style['line'], 5, 1)) : '';
		$lineBottomLeft = ($style['line'] ?? null) ? trim(mb_substr($style['line'], 6, 1)) : '';
		$lineLeft = ($style['line'] ?? null) ? trim(mb_substr($style['line'], 7, 1)) : '';
		$backColor = $style['backColor'] ?? 'red';
		$borderColor = $style['borderColor'] ?? 'default';
		// padding
		if (($style['padding'] ?? null)) {
			for ($i = 0; $i < $style['padding']; $i++) {
				$res .= self::backColor($backColor, $borderColor, $lineLeft . str_repeat(' ', ($screenWidth - 2)) .
				                                                  ($lineLeft ? '' : ' ') . $lineRight . ($lineRight ? '' : ' ')) . "\n";
			}
		}
		// line
		if (($style['borderColor'] ?? null) && ($lineBottomLeft || $lineBottom || $lineBottomRight)) {
			$res .= self::backColor($backColor, $borderColor, $lineBottomLeft . str_repeat($lineBottom, ($screenWidth - 2)) . $lineBottomRight) . "\n";
		}
		// margin
		if (($style['marginBottom'] ?? null)) {
			$res .= str_repeat("\n", $style['marginBottom']);
		}
		return ($res);
	}
	/**
	 * Generate an ANSI sequence in order to set the defined style.
	 * @param	bool|array	$style		Associative array containing the set of styles.
	 *						Or false to reset the style.
	 * @param	bool		$reverse	True to set the inverse style.
	 * @return	string	The ANSI sequence.
	 */
	static protected function _applyStyle(bool|array $style, bool $reverse=false) : string{
		$inBlock = (($style['display'] ?? null) == 'block');
		$chunks = [];
		$result = '';
		// bold
		if ($style === false ||
		    (!$inBlock && ($style['bold'] ?? null) === false && ($style['faint'] ?? null) !== true) ||
		    ($reverse && ($style['bold'] ?? null) === true))
			$chunks[] = '22';
		else if (($style['bold'] ?? null) === true)
			$chunks[] = '1';
		// italic
		if ($style === false ||
		    (!$inBlock && ($style['italic'] ?? null) === false) ||
		    ($reverse && ($style['italic'] ?? null) === true))
			$chunks[] = '23';
		else if (($style['italic'] ?? null) === true)
			$chunks[] = '3';
		// underline
		if ($style === false ||
		    (!$inBlock && ($style['underline'] ?? null) === false) ||
		    ($reverse && ($style['underline'] ?? null) === true))
			$chunks[] = '24';
		else if (($style['underline'] ?? null) === true)
			$chunks[] = '4';
		// faint
		if ($style === false ||
		    (!$inBlock && ($style['faint'] ?? null) === false && ($style['bold'] ?? null) !== true) ||
		    ($reverse && ($style['faint'] ?? null) === true))
			$chunks[] = '22';
		else if (($style['faint'] ?? null) === true)
			$chunks[] = '2';
		// strikeout
		if ($style === false ||
		    (!$inBlock && ($style['strikeout'] ?? null) === false) ||
		    ($reverse && ($style['strikeout'] ?? null) === true))
			$chunks[] = '29';
		else if (($style['strikeout'] ?? null) === true)
			$chunks[] = '9';
		// blink
		if ($style === false ||
		    (!$inBlock && ($style['blink'] ?? null) === false) ||
		    ($reverse && ($style['blink'] ?? null) === true))
			$chunks[] = '25';
		else if (($style['blink'] ?? null) === true)
			$chunks[] = '5';
		// reverse video
		if ($style === false ||
		    (!$inBlock && ($style['reverse'] ?? null) === false) ||
		    ($reverse && ($style['reverse'] ?? null) === true))
			$chunks[] = '27';
		else if (($style['reverse'] ?? null) === true)
			$chunks[] = '7';
		// foreground color
		$textColor = $style['textColor'] ?? null;
		$textColor = (is_string($textColor) && ctype_digit($textColor)) ? intval($textColor) : $textColor;
		if ($style === false ||
		    $textColor === 'default' ||
		    (is_string($textColor) && !isset(self::COLORS[$textColor])) ||
		    ($reverse && $textColor && $textColor !== 'default')) {
			$chunks[] = '39';
		} else if (is_int($textColor) || ctype_digit($textColor)) {
			$result .= "\e[38;5;{$textColor}m";
		} else if (is_string($textColor)) {
			$result .= "\e[38;5;" . self::COLORS[$textColor] . 'm';
		}
		// background color
		$backColor = $style['backColor'] ?? null;
		$backColor = (is_string($backColor) && ctype_digit($backColor)) ? intval($backColor) : $backColor;
		if ($style === false ||
		    $backColor === 'default' ||
		    (is_string($backColor) && !isset(self::COLORS[$backColor])) ||
		    ($reverse && $backColor && $backColor !== 'default')) {
			$chunks[] = '49';
		} else if (is_int($backColor)) {
			$result .= "\e[48;5;{$backColor}m";
		} else if (is_string($backColor)) {
			$result .= "\e[48;5;" . self::COLORS[$backColor] . 'm';
		}
		// result
		if ($chunks)
			$result .= "\e[" . implode(';', $chunks) . 'm';
		return ($result);
	}

	/* ********** THROBBER FUNCTIONS ********** */
	/**
	 * Starts a throbber.
	 * @param	?string			$text		(optional) Default text.
	 * @param	null|string|array	$characters	(optional) Characters of the throbber animation.
	 */
	static public function throbberStart(?string $text=null, null|string|array $characters=null) : void {
		if ($text !== null)
			self::$_throbberText = $text;
		if ($characters !== null)
			self::$_throbberCharacters = $characters;
		$char = is_array(self::$_throbberCharacters) ? self::$_throbberCharacters[0] : mb_substr(self::$_throbberCharacters, 0, 1);
		print(" $char " . self::$_throbberText);
		self::$_throbberOffset = 1;
	}
	/**
	 * Advances a throbber animation.
	 * @param	?string	$text	(optional) Text.
	 */
	static public function throbberGo(?string $text=null) : void {
		if (is_array(self::$_throbberCharacters)) {
			$offset = self::$_throbberOffset % count(self::$_throbberCharacters);
			$char = self::$_throbberCharacters[$offset];
			self::$_throbberOffset = (self::$_throbberOffset + 1) % count(self::$_throbberCharacters);
		} else {
			$offset = self::$_throbberOffset % mb_strlen(self::$_throbberCharacters);
			$char = mb_substr(self::$_throbberCharacters, $offset, 1);
			self::$_throbberOffset = (self::$_throbberOffset + 1) % mb_strlen(self::$_throbberCharacters);
		}
		TµTerm::clearLine();
		print(" $char " . ($text ?? self::$_throbberText));
	}
	/**
	 * Ends a throbber.
	 * @param	?string	$text	(optional) Text to write to replace the throbber.
	 */
	static public function throbberEnd(?string $text=null) : void {
		TµTerm::clearLine();
		if ($text)
			print("$text\n");
	}

	/* ********** PROGRESS BAR FUNCTIONS ********** */
	/**
	 * Defines the styling of progress bars.
	 * @param	?string	$backColor	(optional) Color of the background.
	 * @param	?string	$textColor	(optional) Color of the foreground.
	 * @param	bool	$percentage	(optional) True to display a percentage of progress.
	 *					False to display the exact value of progress. Defaults to true.
	 * @param	int	$width		(optional) Division of the screen to use for display. Defaults to 1 (full width).
	 * @param	?bool	$bold		(optional) True for bold text. Defaults to true.
	 */
	static public function setProgressStyle(?string $backColor=null, ?string $textColor=null, ?bool $percentage=null, ?int $width=null, ?bool $bold=null) {
		if ($backColor !== null)
			self::$_progressBackColor = $backColor;
		if ($textColor !== null)
			self::$_progressTextColor = $textColor;
		if ($percentage !== null)
			self::$_progressPercentage = $percentage;
		if ($width !== null)
			self::$_progressWidth = $width;
		if ($bold !== null)
			self::$_progressBold = $bold;
	}
	/**
	 * Starts a progress bar.
	 * @param	string	$text		(optional) Default title of the progress bar. Defaults to empty string.
	 * @param	int	$units		(optional) Number of total units. Defaults to 100.
	 */
	static public function progressStart(string $text='', int $units=100) {
		self::$_progressText = $text;
		self::$_progressTotal = $units;
		self::$_progressCurrent = 0;
		TµTerm::hideCursor();
		print("\n");
		self::_progressDraw(0, self::$_progressTotal, $text, self::$_progressWidth);
	}
	/**
	 * Advances the progress bar.
	 * @param	int	$units	(optional) Number of units to advance. Could be a negative number. Defaults to 1.
	 * @param	?string	$text	(optional) Title of the progress bar.
	 */
	static public function progressGo(int $units=1, ?string $text=null) {
		self::$_progressCurrent += $units;
		self::$_progressCurrent = max(self::$_progressCurrent, 0);
		self::$_progressCurrent = min(self::$_progressCurrent, self::$_progressTotal);
		$text = ($text === null) ? self::$_progressText : $text;
		self::_progressDraw(self::$_progressCurrent, self::$_progressTotal, $text, self::$_progressWidth);
	}
	/**
	 * Define the progression of the bar.
	 * @param	int	$units	(optional) Units of progression.
	 * @param	?string	$text	(optional) Title of the progress bar.
	 */
	static public function progressSet(int $units, ?string $text=null) {
		self::$_progressCurrent = $units;
		self::$_progressCurrent = max(self::$_progressCurrent, 0);
		self::$_progressCurrent = min(self::$_progressCurrent, self::$_progressTotal);
		$text = ($text === null) ? self::$_progressText : $text;
		self::_progressDraw(self::$_progressCurrent, self::$_progressTotal, $text, self::$_progressWidth);
	}
	/**
	 * Ends a progress bar.
	 * @param	string	$text	(optional) Text left at the end of the progress bar.
	 */
	static public function progressEnd(string $text='') {
		TµTerm::clearLine();
		TµTerm::moveCursorUp();
		TµTerm::clearLine();
		print("\r");
		if ($text) {
			if (self::$_progressBold)
				print(' ' . self::bold($text) . "\n");
			else
				print(" $text\n");
		}
	}
	/**
	 * Displays a progress bar.
	 * @param	int	$units	Units of progression.
	 * @param	int	$total	Total number of units.
	 * @param	string	$text	Title of the progress bar.
	 * @param	int	$width	Division of the screen to use for display.
	 */
	static protected function _progressDraw(int $units, int $total, string $text, int $width) {
		[$screenWidth, $screenHeight] = TµTerm::getScreenSize();
		if (self::$_progressPercentage) {
			$label = (string)floor($units * 100 / $total) . '%';
			$maxLabelSize = 4;
		} else {
			$label = "$units/$total";
			$maxLabelSize = mb_strlen("$total/$total");
		}
		$labelOffset = $maxLabelSize - mb_strlen($label);
		$width = floor($screenWidth / $width) - $maxLabelSize - 3;
		$progress = floor($units * $width / $total);
		$rest = $width - $progress;
		TµTerm::moveCursorUp();
		print("\r");
		TµTerm::clearLine();
		if (self::$_progressBold)
			print(' ' . self::bold($text) . "\n");
		else
			print(" $text\n");
		TµTerm::clearLine();
		print(' ' . self::backColor(self::$_progressBackColor, self::$_progressTextColor, str_repeat('█', $progress) . str_repeat(' ', $rest)) .
		      ' ' . str_repeat(' ', $labelOffset) . $label);
	}
}

