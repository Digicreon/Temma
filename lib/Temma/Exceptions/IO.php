<?php

/**
 * IO
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2021, Amaury Bouchard
 */

namespace Temma\Exceptions;

/**
 * Exception for IO errors.
 */
class IO extends \Exception {
	/** Fundamental error. */
	const FUNDAMENTAL = 0;
	/** File not found. */
	const NOT_FOUND = 1;
	/** Read error. */
	const UNREADABLE = 2;
	/** Write error. */
	const UNWRITABLE = 3;
	/** Badly formatted file.*/
	const BAD_FORMAT = 4;
	/** Unlockable file. */
	const UNLOCKABLE = 5;
}

