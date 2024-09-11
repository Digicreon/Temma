<?php

/**
 * AsynkInetd
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 */

use \Temma\Base\Log as TµLog;

/**
 * Asynk worker executed by inetd super-daemon.
 *
 * This objet expects to receive a task identifier on its standard input.
 * The task is processed and removed.
 */
class AsynkInetd extends \Temma\Web\Controller {
	/** Asynk DAO. */
	private ?\Temma\Asynk\AsynkDao $_asynkDao = null;

	/** Init. */
	public function __wakeup() {
		$this->_asynkDao = $this->_loader['\Temma\Asynk\AsynkDao'];
	}
	/**
	 * Get a task identifier on the standard input, process the task and remove it.
	 */
	public function __invoke() {
		// get task identifier
		$taskId = trim(fgets(STDIN));
		if (!$taskId) {
			TµLog::log('Temma/Asynk', 'INFO', "Empty task identifier.");
			exit(0);
		}
		// reserve the task, set its status and get it
		$task = $this->_asynkDao->getTaskFromId($taskId);
		if (!$task) {
			TµLog::log('Temma/Asynk', 'WARN', "Unknown task '$taskId'.");
			exit(1);
		}
		// task processing
		try {
			$object = $this->_loader[$task['target']];
			if (!$object)
				throw new \Exception("Unable to instanciate object '{$task['target']}'.");
			if (!method_exists($object, $task['action']))
				throw new \Exception("No méthode '{$task['action']}' in object '{$task['target']}'.");
			$method = $task['action'];
			$object->$method(...$task['data']);
			// task deletion
			$this->_asynkDao->removeTaskFromId($task['id']);
		} catch (\Exception $e) {
			TµLog::log('Temma/Asynk', 'WARN', "Asynk error: " . $e->getMessage());
			$this->_asynkDao->setTaskStatusFromId($task['id'], 'error');
			exit(1);
		}
	}
}

