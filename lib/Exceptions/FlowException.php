<?php

/**
 * FlowException
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2020, Amaury Bouchard
 */

namespace Temma\Exceptions;

/**
 * Exception used to control the execution flow of the framework.
 */
class FlowException extends \Exception {
	/** Raise an exception that stops the current layer (preplugins, controller or postplugins) of execution and go to the next one. */
	static public function stop() : void {
		throw new self(null, \Temma\Web\Controller::EXEC_STOP);
	}
	/** Raise an exception that stops all processings and go straight to the view or redirection. */
	static public function halt() : void {
		throw new self(null, \Temma\Web\Controller::EXEC_HALT);
	}
	/** Raise an exception that stops all processings and don't execute the view nor redirect. */
	static public function quit() : void {
		throw new self(null, \Temma\Web\Controller::EXEC_QUIT);
	}
	/** Raise an exception that restarts the current layer (preplugins, controller or postplugins) of execution. */
	static public function restart() : void {
		throw new self(null, \Temma\Web\Controller::EXEC_RESTART);
	}
	/** Raise an exception that restarts the whole processing from the beginning. */
	static public function reboot() : void {
		throw new self(null, \Temma\Web\Controller::EXEC_REBOOT);
	}
}

