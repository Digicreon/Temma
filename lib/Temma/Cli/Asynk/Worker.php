<?php

/**
 * Worker
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023-2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/asynk
 */

namespace Temma\Cli\Asynk;

use \Temma\Base\Log as TµLog;

/**
 * Asynk worker executed by crontab or running as a daemon.
 *
 * This objet fetch waiting tasks, process them and remove them.
 * When the processing of a task fails, a new attempt is planned if retries are enabled ("retries" key of the "x-asynk"
 * configuration), except if the failure comes from a PHP error (\Error) or if the target object or method doesn't
 * exist. Otherwise, the task is put aside (except with SQS: the message is delivered again after its visibility
 * timeout). See the AsynkManager command.
 * If the storage or the message queue can't be reached, an ERROR message is logged; the daemon waits during the loop
 * delay before continuing, the crontab execution stops.
 */
class Worker extends \Temma\Web\Controller {
	/** Constant: default delay between two loops (in seconds). */
	const DEFAULT_LOOP_DELAY = 60;
	/** Asynk DAO. */
	private ?\Temma\Asynk\AsynkDao $_asynkDao = null;

	/** Init. */
	public function __wakeup() {
		$this->_asynkDao = $this->_loader['\Temma\Asynk\AsynkDao'];
	}
	/**
	 * Loop indefinitely (worker).
	 * When no task is waiting, the worker sleeps during the loop delay ("loopDelay" key of the "x-asynk" configuration),
	 * except with a Beanstalkd transport: the worker waits for the next task, and processes it as soon as it is available.
	 */
	public function __invoke() {
		$this->_process(true);
	}
	/**
	 * Loop as long as there are waiting tasks (crontab).
	 * Not suitable for a Beanstalkd transport: the worker waits for new tasks, so this method never ends.
	 */
	public function crontab() {
		$this->_process(false);
	}

	/* ********** PRIVATE FUNCTIONS ********** */
	/**
	 * Fetch tasks and process them. Can loop indefinitely or as long as tasks are waiting.
	 * @param	bool	$infiniteLoop	True to loop indefinitely.
	 * @param	int	$sleep		(optional) For infinite loops, waiting duration between each loop, in seconds.
	 */
	private function _process(bool $infiniteLoop, int $sleep=0) {
		// infinite loop: if the sleep wasn't given, use the configuration
		if ($infiniteLoop && !$sleep)
			$sleep = $this->_loader->config->xtra('asynk', 'loopDelay', self::DEFAULT_LOOP_DELAY);
		// loop
		for (; ; ) {
			try {
				// get next task
				$task = $this->_asynkDao->getNextTask();
				// no task found?
				if (!$task) {
					if ($infiniteLoop) {
						sleep($sleep);
						continue;
					}
					break;
				}
				// process the task, and remove it if the processing was successful
				if ($this->_asynkDao->executeTask($task))
					$this->_asynkDao->removeFetchedTask($task);
			} catch (\Exception $e) {
				// storage or message queue error (unreachable server, deadlock...)
				TµLog::log('Temma/Asynk', 'ERROR', 'Asynk worker error: ' . get_class($e) . ': ' . $e->getMessage());
				if (!$infiniteLoop)
					break;
				sleep($sleep);
			}
		}
	}
}
