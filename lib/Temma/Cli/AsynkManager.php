<?php

/**
 * AsynkManager
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/asynk
 */

namespace Temma\Cli;

use \Temma\Utils\Ansi as TµAnsi;
use \Temma\Utils\Text as TµText;

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
 * - MySQL and Redis storages (with or without xinetd): all actions are available;
 * - Beanstalkd: tasks can be counted, but only the first failed task can be listed; "recover" is not available
 *   (Beanstalkd puts back the jobs automatically); "retry" creates the job again, with a new identifier;
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
			$this->_asynkDao = $this->_loader->get('\Temma\Asynk\AsynkDao');
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
		foreach ($stats as $state => $count) {
			print(TµAnsi::color(self::STATE_COLORS[$state], str_pad($state, 12)) .
			      (($count === null) ? TµAnsi::faint('unknown') : TµAnsi::bold((string)$count)) . "\n");
		}
	}
	/**
	 * List tasks (MySQL storage: sorted by identifier; Redis storage: the oldest first; Beanstalkd: only the first failed task).
	 * @param	?string	$state	(optional) State of the tasks: "waiting", "delayed", "processing" or "failed". All states by default.
	 * @param	int	$limit	(optional) Maximum number of tasks (for each state). 20 by default.
	 */
	public function list(?string $state=null, int $limit=20) {
		$tasks = $this->_call(fn() => $this->_asynkDao->listTasks($state, $limit));
		if ($tasks === null)
			$this->_error("Tasks can't be listed with this configuration" . ($state ? " (state '$state')" : '') . '.');
		if (!$tasks) {
			print(TµAnsi::faint("No task.\n"));
			return;
		}
		foreach ($tasks as $task) {
			$line = TµAnsi::bold(self::_clean($task['id'] ?? '-')) . ' ' .
			        TµAnsi::color(self::STATE_COLORS[$task['state']], $task['state']) . ' ' .
			        TµAnsi::faint($task['dateCreation'] ?? '') . ' ' .
			        self::_clean(($task['target'] ?? '') . '::' . ($task['action'] ?? '') . '()');
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
			$this->_error("Unknown task '" . self::_clean($id) . "'.");
		$labels = [
			'id'              => 'Identifier',
			'state'           => 'State',
			'dateCreation'    => 'Creation date',
			'dateStart'       => 'Processing start',
			'target'          => 'Object',
			'action'          => 'Method',
			'attempts'        => 'Attempts',
			'dateNextAttempt' => 'Next attempt',
			'error'           => 'Last error',
		];
		foreach ($labels as $key => $label) {
			if (($task[$key] === null) || ($task[$key] === ''))
				continue;
			// the values may come from user data: terminal control sequences are removed
			$value = self::_clean((string)$task[$key]);
			if ($key == 'state')
				$value = TµAnsi::color(self::STATE_COLORS[$value], $value);
			else if ($key == 'error')
				$value = TµAnsi::color('red', $value);
			print(TµAnsi::faint(str_pad($label, 18)) . "$value\n");
		}
		print(TµAnsi::faint('Parameters') . "\n");
		print(self::_clean(json_encode($task['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
	}
	/**
	 * Put back in the queue a failed or delayed task, to process it as soon as possible.
	 * Its number of attempts is reset (with Beanstalkd, the job is created again, with a new identifier).
	 * @param	string	$id	Task identifier, or "all" for all the failed tasks.
	 */
	public function retry(string $id) {
		if ($id == 'all') {
			$count = $this->_call(fn() => $this->_asynkDao->retryFailedTasks());
			print(TµAnsi::color('green', "$count task(s) put back in the queue.\n"));
			return;
		}
		if (!$this->_call(fn() => $this->_asynkDao->retryTask($id)))
			$this->_error("Task '" . self::_clean($id) . "' not found, or not failed nor delayed.");
		print(TµAnsi::color('green', "Task put back in the queue.\n"));
	}
	/**
	 * Remove a task permanently.
	 * @param	string	$id	Task identifier.
	 */
	public function remove(string $id) {
		if (!$this->_call(fn() => $this->_asynkDao->removeTask($id)))
			$this->_error("Unknown task '" . self::_clean($id) . "'.");
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
	 * Recover the tasks whose processing has started for a long time (their worker has probably crashed): if retries
	 * are enabled, a new attempt is planned or the task is put aside; otherwise, the task is put back in the queue.
	 * Not available with Beanstalkd and SQS, which do it automatically.
	 * @param	int	$minutes	(optional) Number of minutes since the processing start (60 by default). It must be
	 *					greater than the maximum processing duration of a task, otherwise a task could
	 *					be processed twice.
	 */
	public function recover(int $minutes=60) {
		$count = $this->_call(fn() => $this->_asynkDao->recoverTasks($minutes));
		print(TµAnsi::color('green', "$count task(s) recovered.\n"));
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Call a function, and display the error message if an exception is thrown.
	 * @param	callable	$function	The function to call.
	 * @return	mixed	The value returned by the function.
	 */
	private function _call(callable $function) : mixed {
		try {
			return ($function());
		} catch (\Temma\Exceptions\Application $e) {
			$this->_error($e->getMessage());
		} catch (\Exception $e) {
			// storage or message queue error
			$this->_error('Error: ' . get_class($e) . ': ' . $e->getMessage());
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

	/* ********** PRIVATE STATIC METHODS ********** */
	/**
	 * Remove the terminal control sequences and characters (except line feeds and tabulations) from a string which
	 * may contain user data (task parameters, error messages), so that they can't be interpreted by the terminal.
	 * @param	string	$text	The text.
	 * @return	string	The cleaned text.
	 */
	static private function _clean(string $text) : string {
		$text = TµText::ansiToText($text);
		return (preg_replace('/[\x00-\x08\x0B-\x1F\x7F]|\xC2[\x80-\x9F]/', '?', $text));
	}
}
