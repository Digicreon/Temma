<?php

/**
 * FlowHalt
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2020, Amaury Bouchard
 */

namespace Temma\Exceptions;

/**
 * Exception used to control the execution flow of the framework.
 */
class FlowHalt extends Flow {
	/** Constructor. */
	public function __construct() {
		parent::__construct(null, \Temma\Web\Controller::EXEC_HALT);
	}
}

