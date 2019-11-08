<?php

namespace Temma\Exceptions;

/**
 * Exception for DAO errors.
 *
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2012-2019, Amaury Bouchard
 * @package	Temma
 * @subpackage	Exceptions
 */
class DaoException extends \Exception {
	/** Bad search criteria. */
	const CRITERIA = 0;
	/** Bad field. */
	const FIELD = 1;
	/** Bad value. */
	const VALUE = 2;
}

