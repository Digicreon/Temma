<?php

/**
 * DaoException
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2012-2019, Amaury Bouchard
 */

namespace Temma\Exceptions;

/**
 * Exception for DAO errors.
 */
class DaoException extends \Exception {
	/** Bad search criteria. */
	const CRITERIA = 0;
	/** Bad field. */
	const FIELD = 1;
	/** Bad value. */
	const VALUE = 2;
}

