<?php

/**
 * AsynkCron
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 * @link	https://www.temma.net/documentation/asynk
 */

use \Temma\Base\Log as TµLog;

/**
 * Asynk worker executed by crontab or running as a daemon.
 *
 * This objet fetch waiting tasks, process them and remove them.
 */
class AsynkWorker extends \Temma\Web\Controller {
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
	 */
	public function __invoke() {
		$this->_process(true);
	}
	/**
	 * Loop as long as there are waiting tasks (crontab).
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
			// process the task
			try {
				$object = $this->_loader[$task['target']];
				if (!$object)
					throw new \Exception("Unable to instanciate object '{$task['target']}'.");
				if (!method_exists($object, $task['action']))
					throw new \Exception("No méthode '{$task['action']}' in object '{$task['target']}'.");
				$method = $task['action'];
				$object->$method(...$task['data']);
				// task deletion
				$this->_asynkDao->removeFetchedTask($task);
			} catch (\Exception $e) {
				TµLog::log('Temma/Asynk', 'WARN', $e->getMessage());
				$this->_asynkDao->invalidateFetchedTask($task);
			} catch (\Error $er) {
				TµLog::log('Temma/Asynk', 'WARN', $er->getMessage());
				$this->_asynkDao->invalidateFetchedTask($task);
			}
		}
	}
}

