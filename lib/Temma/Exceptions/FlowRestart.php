<?php

/**
 * FlowRestart
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2020-2023, Amaury Bouchard
 */

namespace Temma\Exceptions;

/**
 * Exception used to control the execution flow of the framework.
 */
class FlowRestart extends Flow {
	/** Constructor. */
	public function __construct() {
		parent::__construct('', \Temma\Web\Controller::EXEC_RESTART);
	}
}

