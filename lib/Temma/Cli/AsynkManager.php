<?php

/**
 * AsynkManager
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/asynk
 */

namespace Temma\Cli;

use \Temma\Utils\Ansi as TµAnsi;

/**
 * Asynk tasks management CLI controller.
 *
 * Tasks can be in four states: "waiting" (waiting to be processed), "delayed" (waiting for a new attempt after
 * a failure), "processing" and "failed" (the processing has failed, and no new attempt is planned).
 *
 * Usage:
 * <code>
 * bin/comma AsynkManager/stats
 * bin/comma AsynkManager/list
 * bin/comma AsynkManager/list/failed
 * bin/comma AsynkManager/list --state=waiting --limit=50
 * bin/comma AsynkManager/show/123
 * bin/comma AsynkManager/retry/123
 * bin/comma AsynkManager/retry/all
 * bin/comma AsynkManager/remove/123
 * bin/comma AsynkManager/purge
 * bin/comma AsynkManager/recover --minutes=120
 * </code>
 *
 * Possibilities depend on the configuration:
 * - MySQL storage and Redis queue: all actions are available;
 * - Redis storage with xinetd: tasks can't be counted nor listed;
 * - Beanstalkd: tasks can be counted, but only the first failed task can be listed; "recover" is useless;
 * - SQS: tasks can only be counted (approximately); failed messages are managed by a dead-letter queue in AWS.
 */
class AsynkManager extends \Temma\Web\Controller {
	/** Constant: colors of the task states. */
	const STATE_COLORS = [
		'waiting'    => 'blue',
		'delayed'    => 'yellow',
		'processing' => 'cyan',
		'failed'     => 'red',
	];
	/** Asynk DAO. */
	private ?\Temma\Asynk\AsynkDao $_asynkDao = null;

	/** Init. */
	public function __wakeup() {
		try {
			$this->_asynkDao = $this->_loader['\Temma\Asynk\AsynkDao'];
		} catch (\Exception $e) {
			// invalid configuration
			$this->_error($e->getMessage());
		}
	}
	/**
	 * Show the number of tasks in each state.
	 */
	public function stats() {
		$stats = $this->_call(fn() => $this->_asynkDao->getStats());
		if ($stats === null)
			$this->_error('Tasks can\'t be counted with this configuration.');
		foreach ($stats as $state => $count) {
			print(TµAnsi::color(self::STATE_COLORS[$state], str_pad($state, 12)) .
			      ($count === null ? TµAnsi::faint('unknown') : TµAnsi::bold((string)$count)) . "\n");
		}
	}
	/**
	 * List tasks, the oldest first.
	 * @param	?string	$state	(optional) State of the tasks: "waiting", "delayed", "processing" or "failed". All states by default.
	 * @param	int	$limit	(optional) Maximum number of tasks (for each state). 20 by default.
	 */
	public function list(?string $state=null, int $limit=20) {
		$tasks = $this->_call(fn() => $this->_asynkDao->listTasks($state, $limit));
		if ($tasks === null)
			$this->_error('Tasks can\'t be listed with this configuration' . ($state ? " (state '$state')" : '') . '.');
		if (!$tasks) {
			print(TµAnsi::faint("No task.\n"));
			return;
		}
		foreach ($tasks as $task) {
			$line = TµAnsi::bold($task['id'] ?? '-') . ' ' .
			        TµAnsi::color(self::STATE_COLORS[$task['state']], $task['state']) . ' ' .
			        TµAnsi::faint($task['dateCreation'] ?? '') . ' ' .
			        ($task['target'] ?? '') . '::' . ($task['action'] ?? '') . '()';
			if ($task['attempts'])
				$line .= TµAnsi::faint(" attempts: {$task['attempts']}");
			if ($task['dateNextAttempt'])
				$line .= TµAnsi::faint(" next attempt: {$task['dateNextAttempt']}");
			print("$line\n");
		}
	}
	/**
	 * Show a task.
	 * @param	string	$id	Task identifier.
	 */
	public function show(string $id) {
		$task = $this->_call(fn() => $this->_asynkDao->getTask($id));
		if (!$task)
			$this->_error("Unknown task '$id'.");
		$labels = [
			'id'              => 'Identifier',
			'state'           => 'State',
			'dateCreation'    => 'Creation date',
			'dateStart'       => 'Processing start',
			'target'          => 'Object',
			'action'          => 'Method',
			'attempts'        => 'Failed attempts',
			'dateNextAttempt' => 'Next attempt',
			'error'           => 'Last error',
		];
		foreach ($labels as $key => $label) {
			if ($task[$key] === null || $task[$key] === '')
				continue;
			$value = (string)$task[$key];
			if ($key == 'state')
				$value = TµAnsi::color(self::STATE_COLORS[$value], $value);
			else if ($key == 'error')
				$value = TµAnsi::color('red', $value);
			print(TµAnsi::faint(str_pad($label, 18)) . "$value\n");
		}
		print(TµAnsi::faint('Parameters') . "\n");
		print(json_encode($task['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
	}
	/**
	 * Put back in the queue a failed or delayed task, to process it as soon as possible.
	 * Its number of attempts is reset.
	 * @param	string	$id	Task identifier, or "all" for all the failed tasks.
	 */
	public function retry(string $id) {
		if ($id == 'all') {
			$count = $this->_call(fn() => $this->_asynkDao->retryFailedTasks());
			print(TµAnsi::color('green', "$count task(s) put back in the queue.\n"));
			return;
		}
		if (!$this->_call(fn() => $this->_asynkDao->retryTask($id)))
			$this->_error("Task '$id' not found, or not failed nor delayed.");
		print(TµAnsi::color('green', "Task put back in the queue.\n"));
	}
	/**
	 * Remove a task permanently.
	 * @param	string	$id	Task identifier.
	 */
	public function remove(string $id) {
		if (!$this->_call(fn() => $this->_asynkDao->removeTask($id)))
			$this->_error("Unknown task '$id'.");
		print(TµAnsi::color('green', "Task removed.\n"));
	}
	/**
	 * Remove permanently all the failed tasks.
	 */
	public function purge() {
		$count = $this->_call(fn() => $this->_asynkDao->purgeFailedTasks());
		print(TµAnsi::color('green', "$count failed task(s) removed.\n"));
	}
	/**
	 * Put back in the queue the tasks whose processing has started for a long time (their worker has probably crashed).
	 * Useless with Beanstalkd and SQS, which do it automatically.
	 * @param	int	$minutes	(optional) Number of minutes since the processing start (60 by default). It must be
	 *					greater than the maximum processing duration of a task, otherwise a task could
	 *					be processed twice.
	 */
	public function recover(int $minutes=60) {
		$count = $this->_call(fn() => $this->_asynkDao->recoverTasks($minutes));
		print(TµAnsi::color('green', "$count task(s) put back in the queue.\n"));
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Call a function, and display the error message if an Application exception is thrown.
	 * @param	callable	$function	The function to call.
	 * @return	mixed	The value returned by the function.
	 */
	private function _call(callable $function) : mixed {
		try {
			return ($function());
		} catch (\Temma\Exceptions\Application $e) {
			$this->_error($e->getMessage());
		}
	}
	/**
	 * Display an error message and exit.
	 * @param	string	$message	The message.
	 */
	private function _error(string $message) : never {
		print(TµAnsi::color('red', $message) . "\n");
		exit(1);
	}
}
