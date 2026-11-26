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
 * The task is processed and removed, if it is waiting (a delayed task, or a task already processed by the crontab,
 * is ignored). If its processing fails, a new attempt is planned (if retries are enabled; the new attempt is
 * processed by the crontab) or the task is put aside.
 */
class Inetd extends \Temma\Web\Controller {
	/** Asynk DAO. */
	private ?\Temma\Asynk\AsynkDao $_asynkDao = null;

	/** Init. */
	public function __wakeup() {
		$this->_asynkDao = $this->_loader['\Temma\Asynk\AsynkDao'];
	}
	/**
	 * Get a task identifier on the standard input, process the task and remove it (if its processing was successful).
	 */
	public function __invoke() {
		// get task identifier (limited length, the identifier comes from the network)
		$taskId = trim((string)fgets(STDIN, 128));
		if (!$taskId) {
			TµLog::log('Temma/Asynk', 'INFO', "Empty task identifier.");
			exit(0);
		}
		// get the task
		$task = $this->_asynkDao->getTaskFromId($taskId);
		if (!$task) {
			// the task may have been processed by the crontab
			TµLog::log('Temma/Asynk', 'INFO', 'Unknown task ' . json_encode($taskId) . '.');
			exit(0);
		}
		// delayed task, or task processed by the crontab
		if (!$this->_asynkDao->isTaskReady($task)) {
			TµLog::log('Temma/Asynk', 'INFO', "Task '{$task['id']}' not ready to be processed.");
			exit(0);
		}
		// reserve the task
		$task = $this->_asynkDao->reserveTask($task);
		if (!$task) {
			TµLog::log('Temma/Asynk', 'INFO', "Task '$taskId' reserved by another process.");
			exit(0);
		}
		// task processing (the new attempts are processed by the crontab)
		if (!$this->_asynkDao->executeTask($task))
			exit(1);
		// task deletion
		$this->_asynkDao->removeFetchedTask($task);
	}
}
