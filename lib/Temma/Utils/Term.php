<?php

/**
 * Term
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 */

namespace Temma\Utils;

// declare ticks for SIGWINCH manaagement
declare(ticks = 1);

/**
 * Helper object used to manage terminals (TTY).
 *
 * @see	https://gist.github.com/fnky/458719343aabd01cfb17a3a4f7296797
 * @see	https://gist.github.com/JBlond/2fea43a3049b38287e5e9cefc87b2124
 */
class Term {
	/** Terminal width. */
	static private int $_width = 0;
	/** Terminal height. */
	static private int $_height = 0;

	/**
	 * Read user input.
	 * @return      string  The typed text.
	 */
	static public function input() : string {
		return trim(fread(STDIN, 4096));
	}
	/**
	 * Read user input, with invisible characters displayed on the terminal.
	 * @return      string  The typed text.
	 */
	static public function password() : string {
		print("\e[8m");
		$s = self::input();
		print("\e[28m");
		return ($s);
	}
	/** Clear the terminal. */
	static public function clear() : void {
		print("\e[H\e[J");
	}
	/** Reset the terminal. */
	static public function reset() : void {
		print("\ec");
	}
	/** Clear the current line and move the cursor at the beginning of the line. */
	static public function clearLine() : void {
		print("\e[2K\r");
	}
	/** Hide the cursor. */
	static public function hideCursor() : void {
		print("\e?25l");
	}
	/** Show the cursor. */
	static public function showCursor() : void {
		print("\e?25h");
	}
	/** Move cursor home (0,0 position). */
	static public function moveCursorHome() : void {
		print("\e[H");
	}
	/** Move cursor to the beginning of the current line. */
	static public function moveCursorLineStart() : void {
		print("\r");
	}
	/**
	 * Move the cursor up.
	 * @param	int	$nbrLines	(optional) Number of lines to move up. Default to 1.
	 */
	static public function moveCursorUp(int $nbrLines=1) : void {
		print("\e[{$nbrLines}A");
	}
	/**
	 * Move the cursor down.
	 * @param	int	$nbrLines	(optional) Number of lines to move down. Default to 1.
	 */
	static public function moveCursorDown(int $nbrLines=1) : void {
		print("\e[{$nbrLines}B");
	}
	/**
	 * Move the cursor right.
	 * @param	int	$nbrColumns	(optional) Number of columns to move right. Default to 1.
	 */
	static public function moveCursorRight(int $nbrColumns=1) : void {
		print("\e[{$nbrColumns}C");
	}
	/**
	 * Move the cursor left.
	 * @param	int	$nbrColumns	(optional) Number of columns to move left. Default to 1.
	 */
	static public function moveCursorLeft(int $nbrColumns=1) : void {
		print("\e[{$nbrColumns}D");
	}
	/**
	 * Fetch screen size.
	 * @param	bool	$force	(optional) True to get real values.
	 * @return	array	Screen width and height.
	 */
	static public function getScreenSize(bool $force=false) : array {
		if (!$force && self::$_width && self::$_height)
			return [self::$_width, self::$_height];
		self::$_width = (int)shell_exec('tput cols');
		self::$_height = (int)shell_exec('tput lines');
		pcntl_signal(SIGWINCH, function() {
			\Temma\Utils\Term::getScreenSize(true);
		});
		return [self::$_width, self::$_height];
	}
}

