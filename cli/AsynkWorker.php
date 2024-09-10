<?php

/**
 * AsynkCron
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 */

use \Temma\Base\Log as TµLog;

/**
 * Asynk worker executed by crontab.
 *
 * This objet fetch waiting tasks, process them and remove them.
 */
class AsynkCron extends \Temma\Web\Controller {
	/** Constant: number of tasks fetched on each loop. */
	const NBR_TASKS_PER_LOOP = 10;
	/** Asynk DAO. */
	private ?\Temma\Asynk\AsynkDao $_asynkDao = null;

	/** Init. */
	public function __wakeup() {
		$this->_asynkDao = $this->_loader['\Temma\Asynk\AsynkDao'];
	}
	/**
	 * Loop as long as there are waiting tasks.
	 */
	public function __invoke() {
		while (true) {
			// reserve tasks
			[$token, $nbrTasks] = $this->_asynkDao->reserveTasks(self::NBR_TASKS_PER_LOOP);
			if (!$nbrTasks) {
				// no task to process
				break;
			}
			// loop on reserved tasks
			$except = [];
			for ($i = 0; $i < $nbrTasks; $i++) {
				// fetch a reserved task
				$task = $this->_asynkDao->getNextReservedTask($token, $except);
				$except[] = $task['id'];
				if (!$task)
					continue;
				TµLog::log('Temma/Asynk', 'DEBUG', "Processing task '{$task['id']}' ({$task['target']}::{$task['action']}).");
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
					$this->_asynkDao->removeReservedTask($task);
				} catch (\Exception $e) {
					$this->_asynkDao->invalidateReservedTask($task);
				} catch (\Error $er) {
					$this->_asynkDao->invalidateReservedTask($task);
				}
			}
			if ($nbrTasks < self::NBR_TASKS_PER_LOOP)
				break;
		}
	}
}

