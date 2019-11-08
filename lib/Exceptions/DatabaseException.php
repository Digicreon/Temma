<?php

namespace Temma\Exceptions;

/**
 * Exception for database errors.
 *
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2019, Amaury Bouchard
 * @package	Temma
 * @subpackage	Exceptions
 */
class DatabaseException extends \Exception {
	/** Fundemental error. */
	const FUNDAMENTAL = 0;
	/** Connection error. */
	const CONNECTION = 1;
	/** Query error. */
	const QUERY = 2;
}

