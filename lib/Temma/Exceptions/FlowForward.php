<?php

/**
 * FlowForward
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2021, Amaury Bouchard
 */

namespace Temma\Exceptions;

/**
 * Exception used to control the execution flow of the framework.
 */
class FlowForward extends \Temma\Exceptions\FlowException {
	/** Constructor. */
	public function __construct() {
		parent::__construct(null, \Temma\Web\Controller::EXEC_FORWARD);
	}
}

