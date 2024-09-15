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
class Client implements \Temma\Base\Loadable, \ArrayAccess {
	/** Dependency injection component. */
	private \Temma\Base\Loader $_loader;

	/**
	 * Constructor.
	 * @param	\Temma\Base\Loader	$loader	Loader.
	 */
	public function __construct(\Temma\Base\Loader $loader) {
		$this->_loader = $loader;
	}
	/* ********** OBJECT-LIKE ACCESS ********** */
	/**
	 * Fetch the name of the object that must be executed.
	 * @param	string	$name	Name of the requested object.
	 * @return	\Temma\Asynk\ClientExec	A new instance of the asynchronous method execution object.
	 */
	public function __get(string $name) {
		return (new \Temma\Asynk\ClientExec($this->_loader, $name));
	}
	/* ********** ARRAY-LIKE ACCESS ********** */
	/**
	 * Fetch the name of the object that must be executed.
	 * @param	mixed	$offset	Name of the requested object.
	 * @return	mixed	A new instance of the asynchronous method execution object.
	 */
	public function offsetGet(mixed $offset) : mixed {
		return (new \Temma\Asynk\ClientExec($this->_loader, $offset));
	}
	/**
	 * Disabled.
	 * @throws	\Exception	Always throw an exception.
	 */
	public function offsetExists(mixed $offset) : bool {
		throw new \Exception("Forbidden operation.");
	}
	/**
	 * Disabled.
	 * @throws	\Exception	Always throw an exception.
	 */
	public function offsetSet(mixed $offset, mixed $value) : void {
		throw new \Exception("Forbidden operation.");
	}
	/**
	 * Disabled.
	 * @throws	\Exception	Always throw an exception.
	 */
	public function offsetUnset(mixed $offset) : void {
		throw new \Exception("Forbidden operation.");
	}
}

