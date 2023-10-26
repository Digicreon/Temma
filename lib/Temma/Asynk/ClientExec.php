<?php

/**
 * ClientExec
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 */

namespace Temma\Asynk;

use \Temma\Base\Log as TµLog;

/**
 * Client object used to manage asynchronous calls.
 * This object must be called only by the \Temma\Asynk\Client object.
 */
class ClientExec {
	/** Loader. */
	private \Temma\Base\Loader $_loader;
	/** Nom de l'objet à exécuter. */
	private string $_className;

	/**
	 * Constructor.
	 * @param	\Temma\Base\Loader	$loader		Loader.
	 * @param	string			$className	Name of the object to execute.
	 */
	public function __construct(\Temma\Base\Loader $loader, string $className) {
		$this->_loader = $loader;
		$this->_className = $className;
	}
	/**
	 * Intercepts the requested method.
	 * @param	string	$methodName	Method name.
	 * @param	array	$params		Parameters passed to the method.
	 */
	public function __call(string $methodName, array $params) {
		// create the task
		$taskId = $this->_loader->AsynkDao->createTask($this->_className, $methodName, $params);
	}
}

