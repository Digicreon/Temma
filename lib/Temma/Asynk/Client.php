<?php

/**
 * Client
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 */

namespace Temma\Asynk;

use \Temma\Base\Log as TµLog;

/**
 * Client object for the management of asynchronous calls.
 */
class Client implements \Temma\Base\Loadable {
	/** Dependency injection component. */
	private \Temma\Base\Loader $_loader;

	/**
	 * Constructor.
	 * @param	\Temma\Base\Loader	$loader	Loader.
	 */
	public function __construct(\Temma\Base\Loader $loader) {
		$this->_loader = $loader;
	}
	/**
	 * Fetch the name of the object that must be executed.
	 * @param	string	$name	Name of the requested object.
	 * @return	\Temma\Asynk\ClientExec	A new instance of the asynchronous method execution object.
	 */
	public function __get(string $name) {
		return (new \Temma\Asynk\ClientExec($this->_loader, $name));
	}
}

