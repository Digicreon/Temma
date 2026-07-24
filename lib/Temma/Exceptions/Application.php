<?php

/**
 * Application
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2024, Amaury Bouchard
 */

namespace Temma\Exceptions;

/**
 * Exception for application errors.
 */
class Application extends \Exception {
	/** Unknown error. */
	const UNKNOWN = -1;
	/** API call error. */
	const API = 0;
	/** System error. */
	const SYSTEM = 1;
	/** Authentication error. */
	const AUTHENTICATION = 2;
	/** Authorization error. */
	const UNAUTHORIZED = 3;
	/** Dependency error. */
	const DEPENDENCY = 4;
	/** Ask for retry. */
	const RETRY = 5;
	/** Bad parameter. */
	const BAD_PARAM = 6;
}

