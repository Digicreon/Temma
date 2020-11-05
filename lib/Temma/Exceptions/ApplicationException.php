<?php

/**
 * ApplicationException
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2019, Amaury Bouchard
 */

namespace Temma\Exceptions;

/**
 * Exception for application errors.
 */
class ApplicationException extends \Exception {
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
}

