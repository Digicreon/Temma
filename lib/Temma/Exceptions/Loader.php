<?php

/**
 * Loader
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2019, Amaury Bouchard
 */

namespace Temma\Exceptions;

/**
 * Exception for Loader errors.
 */
class Loader extends \Exception {
	/** Constant: Bad parameter for a loaded object. */
	const BAD_PARAM = 1;
	/** Constant: The requested class is abstract. */
	const ABSTRACT_CLASS = 2;
}

