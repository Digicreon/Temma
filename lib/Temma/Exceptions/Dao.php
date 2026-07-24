<?php

/**
 * Dao
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2012-2021, Amaury Bouchard
 */

namespace Temma\Exceptions;

/**
 * Exception for DAO errors.
 */
class Dao extends \Exception {
	/** Bad search criteria. */
	const CRITERIA = 0;
	/** Bad field. */
	const FIELD = 1;
	/** Bad value. */
	const VALUE = 2;
}

