<?php

/**
 * Inetd
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023-2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/asynk
 */

namespace Temma\Cli\Asynk;

use \Temma\Base\Log as TµLog;

/**
 * Asynk worker executed by inetd super-daemon.
 *
 * This objet expects to receive a task identifier on its standard input.
 * The task is processed and removed.
 */
class Inetd extends \Temma\Web\Controller {
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
		// task processing (with a MySQL storage, the delayed tasks are processed by the crontab)
		if (!$this->_asynkDao->executeTask($task))
			exit(1);
		// task deletion
		$this->_asynkDao->removeTaskFromId($task['id']);
	}
}
