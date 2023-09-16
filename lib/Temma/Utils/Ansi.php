<?php

/**
 * Ansi
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2008-2023, Amaury Bouchard
 */

namespace Temma\Utils;

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
 */
class Ansi {
	/** Color definitions. */
	const COLORS = [
		'black'   => 0,
		'red'     => 1,
		'green'   => 2,
		'yellow'  => 3,
		'blue'    => 4,
		'magenta' => 5,
		'cyan'    => 6,
		'white'   => 7,
	];

	/* ********** TITLE VARIABLES ********** */
	/** Title 1 background color. */
	static protected string $_backColor1 = 'red';
	/** Title 1 text color. */
	static protected string $_frontColor1 = 'white';
	/** Title 1 border color. */
	static protected string $_borderColor1 = 'white';
	/** Title 1 bold text. */
	static protected bool $_bold1 = true;
	/** Title 1 underlined text. */
	static protected bool $_underline1 = false;
	/** Title 1 border characters. */
	static protected string $_border1 = '┃━┏┓┛┗';
	/** Title 1 margin size. */
	static protected int $_margin1 = 2;
	/** Title 2 background color. */
	static protected string $_backColor2 = 'magenta';
	/** Title 2 text color. */
	static protected string $_frontColor2 = 'white';
	/** Title 2 border color. */
	static protected string $_borderColor2 = 'white';
	/** Title 2 bold text. */
	static protected bool $_bold2 = false;
	/** Title 2 underlined text. */
	static protected bool $_underline2 = false;
	/** Title 2 border characters. */
	static protected string $_border2 = '│─╭╮╯╰';
	/** Title 2 margin size. */
	static protected int $_margin2 = 1;
	/** Title 3 background color. */
	static protected string $_backColor3 = 'blue';
	/** Title 3 text color. */
	static protected string $_frontColor3 = 'white';
	/** Title 3 border color. */
	static protected string $_borderColor3 = 'white';
	/** Title 3 bold text. */
	static protected bool $_bold3 = false;
	/** Title 3 underlined text. */
	static protected bool $_underline3 = false;
	/** Title 3 border characters. */
	static protected string $_border3 = '';
	/** Title 3 margin size. */
	static protected int $_margin3 = 1;
	/** Title 4 background color. */
	static protected string $_backColor4 = 'cyan';
	/** Title 4 text color. */
	static protected string $_frontColor4 = 'white';
	/** Title 4 border color. */
	static protected string $_borderColor4 = 'white';
	/** Title 4 bold text. */
	static protected bool $_bold4 = false;
	/** Title 4 underlined text. */
	static protected bool $_underline4 = false;
	/** Title 4 border characters. */
	static protected string $_border4 = '';
	/** Title 4 margin size. */
	static protected int $_margin4 = 0;

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
	static protected string $_progressFrontColor = 'green';
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
	 * Bold text.
	 * @param	string	$text	Input text.
	 * @return	string	The formatted text.
	 */
	static public function bold(string $text) : string {
		return ("\e[1m$text\e[0m");
	}
	/**
	 * "Thin" text.
	 * @param	string	$text	Input text.
	 * @return	string	The formatted text.
	 */
	static public function faint(string $text) : string {
		return ("\e[2m$text\e[0m");
	}
	/**
	 * Underlined text.
	 * @param	string	$text	Input text.
	 * @return	string	The formatted text.
	 */
	static public function underline(string $text) : string {
		return ("\e[4m$text\e[0m");
	}
	/**
	 * Reverse video text.
	 * @param	string	$text	Input text.
	 * @return	string	The formatted text.
	 */
	static public function negative(string $text) : string {
		return ("\e[7m$text\e[0m");
	}
	/**
	 * Colored text.
	 * @param	string	$color	Color name (black, red, green, yellow, blue, magenta, cyan, white).
	 * @param	string	$text	Input text.
	 * @return	string	The formatted text.
	 */
	static public function color(string $color, string $text) : string {
		return ("\e[9" . self::COLORS[$color] . "m$text\e[0m");
	}
	/**
	 * Background colored text.
	 * @param	string	$backColor	Background color.
	 * @param	string	$color		Text color.
	 * @param	string	$text		Input text.
	 * @param	bool	$bold		(optional) True for bold text.
	 * @param	bool	$underline	(optinal) True for underlined text.
	 * @return	string	The formatted text.
	 */
	static public function backColor(string $backColor, string $color, string $text, bool $bold=false, bool $underline=false) : string {
		$frontIntensity = ($color == 'black') ? '3' : '9';
		$frontStyle = '';
		if ($underline)
			$frontStyle = '4;';
		if ($bold)
			$frontStyle = '1;';
		$backPrefix = ($backColor == 'white') ? '0;10' : '4';
		return ("\e[$backPrefix" . self::COLORS[$backColor] . "m\e[$frontStyle$frontIntensity" . self::COLORS[$color] . "m$text\e[0m");
	}

	/* ********** TITLE BOXES MANAGEMENT ********** */
	/**
	 * Set the style of a title panel level.
	 * @param	int	$level		Title level (1, 2, 3 or 4).
	 * @param	?string	$border		(optional) Characters used to draw the box (vertical bar, horizontal bar, upperl left corner,
	 *					upper right corner, lower right corner, lower left corner). Empty string to have no border.
	 * @param	?int	$margin		(optional) Margin size. 0 for no margin, 1 for thin margin (1 character), 2 for large margin (2 characters),
	 *					3 for extra-large margin (3 characters).
	 * @param	?bool	$bold		(optional) True for bold text.
	 * @param	?bool	$underline	(optional) True for underlined text (only if the bold parameter is false).
	 * @param	?string	$backColor	(optional) Background color.
	 * @param	?string	$frontColor	(optional) Color of the text.
	 * @param	?string	$borderColor	(optional) Border color.
	 */
	static public function setTitleStyle(int $level, ?string $border=null, ?int $margin=null, ?bool $bold=null, ?bool $underline=null,
	                                     ?string $backColor=null, ?string $frontColor=null, ?string $borderColor=null) {
		if ($border !== null) {
			$param = "_border$level";
			self::$$param = $border;
		}
		if ($margin !== null) {
			$param = "_margin$level";
			self::$$param = $margin;
		}
		if ($bold !== null) {
			$param = "_bold$level";
			self::$$param = $bold;
		}
		if ($underline !== null) {
			$param = "_underline$level";
			self::$$param = $underline;
		}
		if ($backColor !== null) {
			$param = "_backColor$level";
			self::$$param = $backColor;
		}
		if ($frontColor !== null) {
			$param = "_frontColor$level";
			self::$$param = $frontColor;
		}
		if ($borderColor !== null) {
			$param = "_borderColor$level";
			self::$$param = $borderColor;
		}
	}
	/**
	 * Generate a first level title.
	 * @param	string	$s	The title text.
	 * @param	string	$index	(optional) Text written before the title.
	 * @return	string	The formatted text.
	 */
	static public function title1(string $s, string $index='') : string {
		return self::title($s, $index, self::$_border1, self::$_margin1, self::$_bold1, self::$_underline1, self::$_backColor1, self::$_frontColor1, self::$_borderColor1);
	}
	/**
	 * Display a second level title.
	 * @param	string	$s	The title text.
	 * @param	string	$index	(optional) Text written before the title.
	 * @return	string	The formatted text.
	 */
	static public function title2(string $s, string $index='') : string {
		return self::title($s, $index, self::$_border2, self::$_margin2, self::$_bold2, self::$_underline2, self::$_backColor2, self::$_frontColor2, self::$_borderColor2);
	}
	/**
	 * Display a third level title.
	 * @param	string	$s	The title text.
	 * @param	string	$index	(optional) Text written before the title.
	 * @return	string	The formatted text.
	 */
	static public function title3(string $s, string $index='') : string {
		return self::title($s, $index, self::$_border3, self::$_margin3, self::$_bold3, self::$_underline3, self::$_backColor3, self::$_frontColor3, self::$_borderColor3);
	}
	/**
	 * Display a fourth level title.
	 * @param	string	$s	The title text.
	 * @param	string	$index	(optional) Text written before the title.
	 * @return	string	The formatted text.
	 */
	static public function title4(string $s, string $index='') : string {
		return self::title($s, $index, self::$_border4, self::$_margin4, self::$_bold4, self::$_underline4, self::$_backColor4, self::$_frontColor4, self::$_borderColor4);
	}
	/**
	 * Generate a title box.
	 * @param	string	$s		The text of the title.
	 * @param	string	$index		(optional) Number added at the beginning of the text.
	 * @param	string	$border		(optional) Characters used to draw the box (vertical bar, horizontal bar, upperl left corner,
	 *					upper right corner, lower right corner, lower left corner). Empty string to have no border.
	 * @param	int	$margin		(optional) Margin size. 0 for no margin, 1 for thin margin (1 character), 2 for large margin (2 characters),
	 *					3 for extra-large margin (3 characters).
	 * @param	bool	$bold		(optional) True for bold text.
	 * @param	bool	$underline	(optional) True for underlined text.
	 * @param	string	$backColor	(optional) Color of the background.
	 * @param	string	$frontColor	(optional) Color of the text.
	 * @param	string	$borderColor	(optional) Border color.
	 * @return	string	The formatted string.
	 */
	static public function title(string $s, string $index='', string $border='│─╭╮╯╰', int $margin=1, bool $bold=false, bool $underline=false,
	                             string $backColor='magenta', string $frontColor='white', string $borderColor='white') : string {
		[$screenWidth, $screenHeight] = TµTerm::getScreenSize();
		$res = '';
		$vertical = $border ? mb_substr($border, 0, 1) : '';
		$horizontal = $border ? mb_substr($border, 1, 1) : ' ';
		$upperLeft = $border ? mb_substr($border, 2, 1) : ' ';
		$upperRight = $border ? mb_substr($border, 3, 1) : ' ';
		$lowerRight = $border ? mb_substr($border, 4, 1) : ' ';
		$lowerLeft = $border ? mb_substr($border, 5, 1) : ' ';
		$index = $index ? "$index " : '';
		$indexLen = mb_strlen($index);
		if ($margin)
			$res .= self::backColor($backColor, $borderColor, $upperLeft . str_repeat($horizontal, ($screenWidth - 2)) . $upperRight) . "\n";
		for ($i = 1; $i < $margin; $i++)
			$res .= self::backColor($backColor, $borderColor, $vertical . str_repeat(' ', ($screenWidth - 2)) . $vertical . ($vertical ? '' : '  ')) . "\n";
		$s = wordwrap($s, ($screenWidth - (($margin > 1) ? 8 : 4) - $indexLen));
		$lines = explode("\n", $s);
		foreach ($lines as $line) {
			$pad = str_repeat(' ', ($screenWidth - mb_strlen($line) - (($margin > 1) ? 8 : 4) - $indexLen));
			$res .= self::backColor($backColor, $borderColor, $vertical);
			$res .= self::backColor($backColor, $frontColor, (($margin > 1) ? '   ' : ' ') . "$index", $bold);
			$res .= self::backColor($backColor, $frontColor, $line, $bold, $underline);
			$res .= self::backColor($backColor, $frontColor, $pad . (($margin > 1) ? '   ' : ' ') . ($vertical ? '' : '  '));
			$res .= self::backColor($backColor, $borderColor, $vertical);
			$res .= "\n";
			$index = str_repeat(' ', $indexLen);
		}
		for ($i = 1; $i < $margin; $i++)
			$res .= self::backColor($backColor, $borderColor, $vertical . str_repeat(' ', ($screenWidth - 2)) . $vertical . ($vertical ? '' : '  ')) . "\n";
		if ($margin)
			$res .= self::backColor($backColor, $borderColor, $lowerLeft . str_repeat($horizontal, ($screenWidth - 2)) . $lowerRight) . "\n";
		$res .= "\n";
		return ($res);
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
	 * @param	?string	$frontColor	(optional) Color of the foreground.
	 * @param	?bool	$bold		(optional) True for bold text. Defaults to true.
	 */
	static public function setProgressStyle(?string $backColor=null, ?string $frontColor=null, ?bool $bold=null) {
		if ($backColor !== null)
			self::$_progressBackColor = $backColor;
		if ($frontColor !== null)
			self::$_progressFrontColor = $frontColor;
		if ($bold !== null)
			self::$_progressBold = $bold;
	}
	/**
	 * Starts a progress bar.
	 * @param	string	$text		(optional) Default title of the progress bar. Defaults to empty string.
	 * @param	int	$units		(optional) Number of total units. Defaults to 100.
	 * @param	bool	$percentage	(optional) True to display a percentage of progress.
	 *					False to display the exact value of progress. Defaults to true.
	 * @param	int	$width		(optional) Division of the screen to use for display. Defaults to 1 (full width).
	 */
	static public function progressStart(string $text='', int $units=100, bool $percentage=true, int $width=1) {
		self::$_progressText = $text;
		self::$_progressTotal = $units;
		self::$_progressCurrent = 0;
		self::$_progressPercentage = $percentage;
		self::$_progressWidth = $width ?: 1;
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
		print(' ' . self::backColor(self::$_progressBackColor, self::$_progressFrontColor, str_repeat('█', $progress) . str_repeat(' ', $rest)) .
		      ' ' . str_repeat(' ', $labelOffset) . $label);
	}
}

