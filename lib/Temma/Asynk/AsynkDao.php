<?php

/**
 * AsynkDao
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023-2026, Amaury Bouchard
 */

namespace Temma\Asynk;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Framework as TµFrameworkException;

/**
 * DAO for asynchronous procesings.
 * Must be called only by Asynk objects and scripts.
 *
 * Tasks have four possible states:
 * - "waiting": the task is waiting to be processed;
 * - "delayed": the processing of the task has failed, a new attempt is planned (see the "retries" configuration);
 * - "processing": the task is being processed;
 * - "failed": the processing of the task has failed, and no new attempt is planned.
 */
class AsynkDao implements \Temma\Base\Loadable {
	/** Constant: Name of the Redis queue. */
	const REDIS_QUEUE_NAME = 'asynk';
	/** Constant: Name of the Redis queue used for processing tasks. */
	const REDIS_PROCESSING_QUEUE_NAME = 'asynk_processing';
	/** Constant: Name of the Redis list of delayed tasks (waiting for a new attempt). */
	const REDIS_DELAYED_QUEUE_NAME = 'asynk_delayed';
	/** Constant: Name of the Redis list of failed tasks. */
	const REDIS_FAILED_QUEUE_NAME = 'asynk_failed';
	/** Constant: Minimal duration between two checks of the Redis delayed tasks, in seconds. */
	const REDIS_DELAYED_CHECK_INTERVAL = 10;
	/** Constant: Default delays between attempts (used when the "retries" configuration is true). */
	const DEFAULT_RETRIES = ['1m', '2m', '15m', '1h', '4h', '12h', '1d', '2d'];
	/** Constant: Maximum size of the stored error messages, in bytes. */
	const MAX_ERROR_SIZE = 10000;
	/** Constant: Task states. */
	const STATES = ['waiting', 'delayed', 'processing', 'failed'];
	/** Constant: transports using task notification. */
	const TRANSPORT_NOTIFY = [
		'Temma\Datasources\Socket',
	];
	/** Constant: message queue transport objects. */
	const TRANSPORT_QUEUES = [
		'Temma\Datasources\Sqs',
		'Temma\Datasources\Beanstalk',
	];
	/** Constant: storage allowed without transport. */
	const STORAGE_NO_TRANSPORT = [
		'Temma\Datasources\Sql',
		'Temma\Datasources\Redis',
	];
	/** Constant: storage allowed with "notified" transports. */
	const STORAGE_NOTIFIED_TRANSPORT = [
		'Temma\Datasources\Sql',
		'Temma\Datasources\Redis',
	];
	/** Constant: names of the fields of the SQL table (they can be renamed in the configuration). */
	const SQL_FIELDS = ['dateCreation', 'dateUpdate', 'status', 'token', 'target', 'action', 'data'];
	/** Constant: names of the fields of the SQL table used when retries are enabled. */
	const SQL_RETRY_FIELDS = ['attempts', 'dateNextAttempt', 'error'];
	/** Transport data source. */
	protected ?\Temma\Base\Datasource $_transport = null;
	/** Storage data source. */
	protected ?\Temma\Base\Datasource $_storage = null;
	/** Redis storage (same object as the storage data source, if it is a Redis data source). */
	protected ?\Temma\Datasources\Redis $_redis = null;
	/** Transport class name. */
	protected ?string $_transportClass = null;
	/** Storage class name. */
	protected ?string $_storageClass = null;
	/** Loader. */
	protected \Temma\Base\Loader $_loader;
	/** SQL DAO. */
	protected ?\Temma\Dao\Dao $_dao = null;
	/** Delays between attempts, in seconds (null if retries are not enabled). */
	protected ?array $_retries = null;
	/** Timestamp of the next check of the Redis delayed tasks. */
	protected int $_nextDelayedCheck = 0;

	/**
	 * Constructor.
	 * @param	\Temma\Base\Loader	$loader	Loader.
	 * @throws	\Temma\Exceptions\Framework	If the configuration is not valid.
	 */
	public function __construct(\Temma\Base\Loader $loader) {
		$this->_loader = $loader;
		// read configuration
		$storage = $loader->config->xtra('asynk', 'storage');
		$transport = $loader->config->xtra('asynk', 'transport');
		$storageObject = ($storage && isset($loader->dataSources[$storage])) ? $loader->dataSources[$storage] : null;
		$transportObject = ($transport && isset($loader->dataSources[$transport])) ? $loader->dataSources[$transport] : null;
		$storageClass = $storageObject ? get_class($storageObject) : null;
		$transportClass = $transportObject ? get_class($transportObject) : null;
		// checks
		if ((!$transport && in_array($storageClass, self::STORAGE_NO_TRANSPORT)) ||
		    (in_array($transportClass, self::TRANSPORT_NOTIFY) && in_array($storageClass, self::STORAGE_NOTIFIED_TRANSPORT)) ||
		    (in_array($transportClass, self::TRANSPORT_QUEUES) && !$storage)) {
			$this->_storage = $storageObject;
			$this->_redis = ($storageObject instanceof \Temma\Datasources\Redis) ? $storageObject : null;
			$this->_transport = $transportObject;
			$this->_storageClass = $storageClass;
			$this->_transportClass = $transportClass;
		} else {
			throw new TµFrameworkException("Invalid storage '$storage' with '$transport' transport.", TµFrameworkException::CONFIG);
		}
		// delays between attempts
		$this->_retries = self::_parseRetries($loader->config->xtra('asynk', 'retries'));
		// SQL DAO
		if ($storageClass == 'Temma\Datasources\Sql') {
			$daoClass = $loader->config->xtra('asynk', 'dao', '\Temma\Dao\Dao');
			$dbName = $loader->config->xtra('asynk', 'base');
			$tableName = $loader->config->xtra('asynk', 'table', 'Task');
			$idField = $loader->config->xtra('asynk', 'id', 'id');
			// list of fields: given only if some of them are renamed (otherwise, all the fields of the table are read)
			$fieldNames = ($this->_retries === null) ? self::SQL_FIELDS : array_merge(self::SQL_FIELDS, self::SQL_RETRY_FIELDS);
			$renamed = ($idField != 'id');
			$fields = [($renamed ? $idField : 0) => 'id'];
			foreach ($fieldNames as $fieldName) {
				$column = $loader->config->xtra('asynk', $fieldName);
				if ($column && $column != $fieldName) {
					$renamed = true;
					$fields[$column] = $fieldName;
				} else {
					$fields[] = $fieldName;
				}
			}
			$this->_dao = new $daoClass($storageObject, null, $tableName, $idField, $dbName, ($renamed ? $fields : null));
		}
	}

	/* ********** TASK CREATION AND PROCESSING ********** */
	/**
	 * Create a task.
	 * @param	string	$target	Name of the object to execute.
	 * @param	string	$action	Name of the method to execute.
	 * @param	array	$params	Parameters list.
	 * @throws	\Temma\Exceptions\Framework	If the configuration is incorrect.
	 */
	public function createTask(string $target, string $action, array $params) : void {
		$taskId = null;
		// storage
		if ($this->_storageClass == 'Temma\Datasources\Sql') {
			// MySQL storage (with or without transport)
			$taskId = $this->_storeTaskSql($target, $action, $params);
		} else if ($this->_storageClass == 'Temma\Datasources\Redis') {
			// Redis storage
			if ($this->_transport) {
				// key-value storage (Redis used with xinetd)
				$taskId = $this->_storeTaskKeyValue($target, $action, $params);
			} else {
				// Redis queue (Redis storage and no transport)
				$this->_storeTaskRedisQueue($target, $action, $params);
			}
		} else if (!in_array($this->_transportClass, self::TRANSPORT_QUEUES)) {
			throw new TµFrameworkException("Unknown Asynk storage '{$this->_storageClass}'.", TµFrameworkException::CONFIG);
		}
		// transport
		if (!$this->_transport) {
			// crontab
			return;
		}
		if (in_array($this->_transportClass, self::TRANSPORT_NOTIFY)) {
			// (x)inetd
			$this->_notifyTask($taskId);
		} else if (in_array($this->_transportClass, self::TRANSPORT_QUEUES)) {
			// SQS or Beanstalk
			$this->_transportQueue($target, $action, $params);
		} else {
			throw new TµFrameworkException("Unknown Asynk transport '{$this->_transportClass}'.", TµFrameworkException::CONFIG);
		}
	}
	/**
	 * Get a task from its ID for processing.
	 * Should be called only by Asynk clients.
	 * @param	int|string	$taskId	Task identifier.
	 * @return	?array	Associative array.
	 */
	public function getTaskFromId(int|string $taskId) : ?array {
		if ($this->_dao) {
			/* MySQL storage */
			// reserve the task
			$token = bin2hex(random_bytes(4));
			$count = $this->_dao->update(
				$this->_dao->criteria()->equal('id', $taskId)
				                       ->equal('status', 'waiting'),
				[
					'token'  => $token,
					'status' => 'processing',
				],
			);
			if (!$count)
				return (null);
			// fetch the reserved task
			$task = $this->_dao->get($taskId);
			if (($task['status'] ?? null) != 'processing' ||
			    ($task['token'] ?? null) != $token)
				return (null);
			if (($task['data'] ?? null))
				$task['data'] = json_decode($task['data'], true);
			return ($task);
		}
		// Redis storage?
		if (!$this->_storage)
			return (null);
		if ($this->_storageClass != 'Temma\Datasources\Redis')
			return (null);
		/* Redis storage */
		// fetch the task
		$task = $this->_storage[$taskId];
		if (($task['status'] ?? null) != 'waiting')
			return (null);
		// update task's status
		$task['status'] = 'processing';
		$this->_storage[$taskId] = $task;
		$task['id'] = $taskId;
		return ($task);
	}
	/**
	 * Returns the next task to process.
	 * For a Beanstalkd transport, the function blocks until a message is available; for an SQS transport,
	 * it waits during the long polling duration defined in the DSN (20 seconds at most).
	 * @return	?array	Associative array or null.
	 */
	public function getNextTask() : ?array {
		// Beanstalkd or SQS transport
		if (in_array($this->_transportClass, self::TRANSPORT_QUEUES)) {
			$task = $this->_transport[''];
			if (!($task['id'] ?? null) || !($task['data'] ?? null))
				return (null);
			$task['data']['id'] = $task['id'];
			// SQS: number of previous attempts
			if (isset($task['receiveCount']))
				$task['data']['attempts'] = max(0, $task['receiveCount'] - 1);
			return ($task['data']);
		}
		// MySQL storage
		if ($this->_dao) {
			// reserve a task
			$token = bin2hex(random_bytes(8));
			$count = $this->_dao->update(
				$this->_getSqlStateCriteria('waiting')->equal('token', null),
				[
					'token'  => $token,
					'status' => 'reserved',
				],
				sort: 'id',
				limit: 1,
			);
			if (!$count)
				return (null);
			// get the task
			$result = $this->_dao->search(
				$this->_dao->criteria()->equal('token', $token),
				sort: 'id',
				limitOffset: 0,
				limit: 1,
			);
			$task = $result ? current($result) : null;
			if (($task['data'] ?? null))
				$task['data'] = json_decode($task['data'], true);
			// update the task's status
			if (($task['id'] ?? null))
				$this->_dao->update($task['id'], ['status' => 'processing']);
			return ($task);
		}
		// xinetd transport
		if ($this->_transportClass == 'Temma\Datasources\Socket')
			return (null);
		/* Redis queue */
		// put back in the queue the delayed tasks whose new attempt date is reached
		$this->_promoteRedisDelayedTasks();
		// fetch the task
		$task = $this->_redis->rPopLPush(self::REDIS_QUEUE_NAME, self::REDIS_PROCESSING_QUEUE_NAME);
		if (!is_array($task))
			return (null);
		// add the processing start date (used to find the tasks of crashed workers; the modified task is added before
		// the removal of the original one, so it can't be lost)
		$startedTask = $task;
		$startedTask['dateStart'] = date('c');
		$this->_redis->lPush(self::REDIS_PROCESSING_QUEUE_NAME, $startedTask);
		$this->_redis->lRem(self::REDIS_PROCESSING_QUEUE_NAME, 1, $task);
		return ($startedTask);
	}
	/**
	 * Execute a task fetched with getNextTask() or getTaskFromId().
	 * If the processing fails, the task is invalidated (see invalidateFetchedTask()) and a WARN message is logged.
	 * New attempts are not planned if the failure comes from a PHP error (\Error) or if the target object or method
	 * doesn't exist. The task is not removed when the processing is successful.
	 * @param	array	$task	Task data.
	 * @return	bool	True if the processing was successful.
	 */
	public function executeTask(array $task) : bool {
		$retry = true;
		try {
			$object = $this->_loader[$task['target']];
			if (!$object) {
				$retry = false;
				throw new \Exception("Unable to instanciate object '{$task['target']}'.");
			}
			if (!method_exists($object, $task['action'])) {
				$retry = false;
				throw new \Exception("No method '{$task['action']}' in object '{$task['target']}'.");
			}
			$method = $task['action'];
			$object->$method(...($task['data'] ?? []));
		} catch (\Throwable $t) {
			// PHP errors are not retried (they come from bugs)
			if ($t instanceof \Error)
				$retry = false;
			$error = get_class($t) . ': ' . $t->getMessage();
			$delay = $this->invalidateFetchedTask($task, $error, $retry);
			$taskName = ($task['target'] ?? '') . '::' . ($task['action'] ?? '') . '()';
			if ($delay !== null)
				TµLog::log('Temma/Asynk', 'WARN', "Task '$taskName' failed, new attempt in $delay seconds. $error");
			else
				TµLog::log('Temma/Asynk', 'WARN', "Task '$taskName' failed, put aside. $error");
			return (false);
		}
		return (true);
	}
	/**
	 * Remove a task fetched with getNextTask().
	 * @param	array	$task	Task informations.
	 */
	public function removeFetchedTask(array $task) : void {
		// Beanstalkd or SQS transport
		if (in_array($this->_transportClass, self::TRANSPORT_QUEUES)) {
			if (isset($task['id']))
				unset($this->_transport[$task['id']]);
			return;
		}
		// MySQL storage
		if ($this->_dao) {
			$this->_dao->remove($task['id']);
			return;
		}
		// xinetd transport
		if ($this->_transportClass == 'Temma\Datasources\Socket')
			return;
		// Redis queue
		if (!$this->_redis->lRem(self::REDIS_PROCESSING_QUEUE_NAME, 1, $task))
			TµLog::log('Temma/Asynk', 'WARN', "Unable to remove the task '" . ($task['id'] ?? '') . "' from the processing list.");
	}
	/**
	 * Invalidate a task, after a processing failure (task fetched with getNextTask() or getTaskFromId()).
	 *
	 * If retries are enabled (see the "retries" configuration) and if the maximum number of attempts is not reached,
	 * a new attempt is planned:
	 * - MySQL storage: the task gets back the "waiting" status, with the date of the next attempt;
	 * - Redis queue: the task is moved to the list of delayed tasks;
	 * - Beanstalkd transport: the job is released with a delay;
	 * - SQS transport: the visibility timeout of the message is changed (12 hours at most).
	 *
	 * Otherwise, the task is put aside:
	 * - MySQL storage: the task gets the "error" status;
	 * - Redis queue: the task is moved to the list of failed tasks;
	 * - Redis storage with xinetd: the task gets the "error" status (retries are not possible);
	 * - Beanstalkd transport: the job is buried (it is not delivered anymore, but it can be put back in the queue);
	 * - SQS transport: nothing is done; the message is delivered again after its visibility timeout (configure
	 *   a dead-letter queue to put aside the failed messages).
	 * @param	array	$task	Task data.
	 * @param	?string	$error	(optional) Error message, stored with the task (MySQL storage with retries enabled, Redis storage).
	 * @param	bool	$retry	(optional) False to put aside the task without new attempt (true by default).
	 * @return	?int	Number of seconds before the next attempt, or null if no new attempt is planned.
	 */
	public function invalidateFetchedTask(array $task, ?string $error=null, bool $retry=true) : ?int {
		if ($error !== null)
			$error = mb_strcut($error, 0, self::MAX_ERROR_SIZE);
		// Beanstalkd transport
		if ($this->_transport instanceof \Temma\Datasources\Beanstalk) {
			if (!isset($task['id']))
				return (null);
			try {
				// new attempt: the job is released with a delay
				if ($retry && $this->_retries) {
					$stats = $this->_transport->stats($task['id']);
					$delay = $this->_getRetryDelay($stats['releases'] ?? 0);
					if ($delay !== null) {
						$this->_transport->release($task['id'], $delay);
						return ($delay);
					}
				}
				// the job is buried (kept by Beanstalkd, but not delivered anymore until it is kicked)
				$this->_transport->bury($task['id']);
			} catch (\Exception $e) {
				// the job will be delivered again at the end of its reservation
				TµLog::log('Temma/Asynk', 'WARN', "Unable to bury or release the job '{$task['id']}': " . $e->getMessage());
			}
			return (null);
		}
		// SQS transport
		if ($this->_transport instanceof \Temma\Datasources\Sqs) {
			// new attempt: the visibility timeout is changed
			// (otherwise, the message is delivered again after its visibility timeout, until the end of the retention
			// period of the queue; a dead-letter queue can be configured to keep the failed messages)
			if (!isset($task['id']) || !$retry || !$this->_retries)
				return (null);
			$delay = $this->_getRetryDelay($task['attempts'] ?? 0);
			if ($delay === null)
				return (null);
			$delay = min($delay, \Temma\Datasources\Sqs::MAX_VISIBILITY_TIMEOUT);
			try {
				$this->_transport->changeVisibility($task['id'], $delay);
			} catch (\Exception $e) {
				TµLog::log('Temma/Asynk', 'WARN', "Unable to change the visibility timeout of the message '{$task['id']}': " . $e->getMessage());
				return (null);
			}
			return ($delay);
		}
		// MySQL storage
		if ($this->_dao) {
			$delay = null;
			$data = [
				'token'  => null,
				'status' => 'error',
			];
			if ($this->_retries !== null) {
				$attempts = (int)($task['attempts'] ?? 0);
				$delay = $retry ? $this->_getRetryDelay($attempts) : null;
				$data['attempts'] = $attempts + 1;
				$data['error'] = $error;
				if ($delay !== null) {
					$data['status'] = 'waiting';
					$data['dateNextAttempt'] = date('Y-m-d H:i:s', time() + $delay);
				}
			}
			$this->_dao->update($task['id'], $data);
			return ($delay);
		}
		// Redis storage with xinetd transport (tasks can't be fetched by the crontab, so retries are not possible)
		if ($this->_transportClass == 'Temma\Datasources\Socket') {
			$storedTask = $this->_storage[$task['id']];
			if (!is_array($storedTask))
				return (null);
			$storedTask['status'] = 'error';
			$storedTask['attempts'] = ($storedTask['attempts'] ?? 0) + 1;
			$storedTask['error'] = $error;
			$this->_storage[$task['id']] = $storedTask;
			return (null);
		}
		/* Redis queue */
		// the task is removed from the processing list
		if (!$this->_redis->lRem(self::REDIS_PROCESSING_QUEUE_NAME, 1, $task))
			TµLog::log('Temma/Asynk', 'WARN', "Unable to remove the task '" . ($task['id'] ?? '') . "' from the processing list.");
		// update the task
		unset($task['dateStart']);
		$attempts = (int)($task['attempts'] ?? 0);
		$delay = ($retry && $this->_retries) ? $this->_getRetryDelay($attempts) : null;
		$task['attempts'] = $attempts + 1;
		$task['error'] = $error;
		// new attempt: the task is added to the list of delayed tasks
		if ($delay !== null) {
			$task['status'] = 'waiting';
			$task['dateNextAttempt'] = date('c', time() + $delay);
			$this->_redis->lPush(self::REDIS_DELAYED_QUEUE_NAME, $task);
			return ($delay);
		}
		// the task is added to the list of failed tasks
		$task['status'] = 'error';
		$task['dateFailure'] = date('c');
		$this->_redis->lPush(self::REDIS_FAILED_QUEUE_NAME, $task);
		return (null);
	}
	/**
	 * Set the status of a task.
	 * @param	int|string	$taskId	Task identifier.
	 * @param	string		$status	Task status.
	 */
	public function setTaskStatusFromId(int|string $taskId, string $status) : void {
		if ($this->_dao) {
			/* MySQL storage */
			$this->_dao->update($taskId, [
				'token'  => null,
				'status' => $status
			]);
			return;
		}
		/* Redis storage */
		$task = $this->_storage[$taskId];
		if (!$task)
			return;
		$task['token'] = null;
		$task['status'] = $status;
		$this->_storage[$taskId] = $task;
	}
	/**
	 * Remove a task from its ID.
	 * @param	int|string	$taskId	Task identifier.
	 */
	public function removeTaskFromId(int|string $taskId) : void {
		if ($this->_dao) {
			/* MySQL storage */
			$this->_dao->remove($taskId);
			return;
		}
		/* Redis storage */
		unset($this->_storage[$taskId]);
	}

	/* ********** TASK MANAGEMENT ********** */
	/**
	 * Return the number of tasks in each state.
	 * @return	?array	Associative array whose keys are the states ("waiting", "delayed", "processing", "failed") and the values
	 *			are the numbers of tasks (null if the number is not available, approximate numbers with SQS).
	 *			Null if the configuration doesn't allow to count the tasks (Redis storage with xinetd).
	 */
	public function getStats() : ?array {
		// Beanstalkd transport
		if ($this->_transport instanceof \Temma\Datasources\Beanstalk) {
			$stats = $this->_transport->stats();
			return ([
				'waiting'    => $stats['ready'] ?? 0,
				'delayed'    => $stats['delayed'] ?? 0,
				'processing' => $stats['reserved'] ?? 0,
				'failed'     => $stats['buried'] ?? 0,
			]);
		}
		// SQS transport
		if ($this->_transport instanceof \Temma\Datasources\Sqs) {
			$stats = $this->_transport->stats();
			return ([
				'waiting'    => $stats['waiting'] ?? 0,
				'delayed'    => $stats['delayed'] ?? 0,
				'processing' => $stats['inFlight'] ?? 0,
				'failed'     => null,
			]);
		}
		// MySQL storage
		if ($this->_dao) {
			$stats = [];
			foreach (self::STATES as $state) {
				$criteria = $this->_getSqlStateCriteria($state);
				$stats[$state] = $criteria ? $this->_dao->count($criteria) : 0;
			}
			return ($stats);
		}
		// Redis storage with xinetd transport
		if ($this->_transportClass == 'Temma\Datasources\Socket')
			return (null);
		// Redis queue
		return ([
			'waiting'    => $this->_redis->lLen(self::REDIS_QUEUE_NAME),
			'delayed'    => $this->_redis->lLen(self::REDIS_DELAYED_QUEUE_NAME),
			'processing' => $this->_redis->lLen(self::REDIS_PROCESSING_QUEUE_NAME),
			'failed'     => $this->_redis->lLen(self::REDIS_FAILED_QUEUE_NAME),
		]);
	}
	/**
	 * Return a list of tasks, the oldest first.
	 * With a Beanstalkd transport, only the first failed task can be returned.
	 * @param	?string	$state	(optional) State of the tasks ("waiting", "delayed", "processing" or "failed"). All tasks if null.
	 * @param	int	$limit	(optional) Maximum number of tasks (for each state if no state is given). 20 by default.
	 * @return	?array	List of tasks (see getTask() for their content), or null if the configuration doesn't allow
	 *			to list the tasks (SQS transport, Redis storage with xinetd, Beanstalkd transport with a state
	 *			other than "failed").
	 * @throws	\Temma\Exceptions\Application	If the state is not valid.
	 */
	public function listTasks(?string $state=null, int $limit=20) : ?array {
		$this->_checkState($state);
		$limit = max(1, $limit);
		// Beanstalkd transport: only the first buried job
		if ($this->_transport instanceof \Temma\Datasources\Beanstalk) {
			if ($state != 'failed')
				return (null);
			$job = $this->_transport->peekBuried();
			return ($job ? [$this->_normalizeBeanstalkTask($job)] : []);
		}
		// SQS transport, or Redis storage with xinetd transport
		if (($this->_transport instanceof \Temma\Datasources\Sqs) ||
		    (!$this->_dao && $this->_transportClass == 'Temma\Datasources\Socket'))
			return (null);
		$states = $state ? [$state] : self::STATES;
		$tasks = [];
		// MySQL storage
		if ($this->_dao) {
			foreach ($states as $currentState) {
				$criteria = $this->_getSqlStateCriteria($currentState);
				if (!$criteria)
					continue;
				$result = $this->_dao->search($criteria, sort: 'id', limitOffset: 0, limit: $limit);
				foreach ($result as $task)
					$tasks[] = $this->_normalizeSqlTask($task);
			}
			return ($tasks);
		}
		// Redis queue (the newest tasks are at the beginning of the lists)
		foreach ($states as $currentState) {
			$list = $this->_redis->lRange(self::_getRedisListName($currentState), -$limit, -1);
			foreach (array_reverse($list) as $task)
				$tasks[] = $this->_normalizeRedisTask($task, $currentState);
		}
		return ($tasks);
	}
	/**
	 * Return a task.
	 * @param	int|string	$taskId	Task identifier.
	 * @return	?array	Associative array with the keys "id", "state" ("waiting", "delayed", "processing" or "failed"),
	 *			"dateCreation", "dateStart" (processing start date), "target", "action", "data" (parameters),
	 *			"attempts" (number of failed attempts), "dateNextAttempt" and "error" (last error message).
	 *			Unknown values are null. Returns null if the task doesn't exist.
	 * @throws	\Temma\Exceptions\Application	If the configuration doesn't allow to fetch a task (SQS transport).
	 */
	public function getTask(int|string $taskId) : ?array {
		// Beanstalkd transport
		if ($this->_transport instanceof \Temma\Datasources\Beanstalk) {
			$job = $this->_transport->peek((string)$taskId);
			return ($job ? $this->_normalizeBeanstalkTask($job) : null);
		}
		$this->_checkTaskManagement();
		// MySQL storage
		if ($this->_dao) {
			$task = $this->_dao->get($taskId);
			return ($task ? $this->_normalizeSqlTask($task) : null);
		}
		// Redis storage with xinetd transport
		if ($this->_transportClass == 'Temma\Datasources\Socket') {
			$task = $this->_storage[$taskId];
			if (!is_array($task))
				return (null);
			$task['id'] = $taskId;
			$state = (($task['status'] ?? null) == 'error') ? 'failed' : (($task['status'] ?? null) == 'processing' ? 'processing' : 'waiting');
			return ($this->_normalizeRedisTask($task, $state));
		}
		// Redis queue
		$found = $this->_findRedisTask($taskId);
		return ($found ? $this->_normalizeRedisTask($found['task'], $found['state']) : null);
	}
	/**
	 * Put back in the queue a failed or delayed task, to process it as soon as possible.
	 * The number of attempts is reset.
	 * @param	int|string	$taskId	Task identifier.
	 * @return	bool	True if the task was put back in the queue, false if it doesn't exist or is not failed nor delayed.
	 * @throws	\Temma\Exceptions\Application	If the configuration doesn't allow to retry a task (SQS transport).
	 */
	public function retryTask(int|string $taskId) : bool {
		// Beanstalkd transport
		if ($this->_transport instanceof \Temma\Datasources\Beanstalk)
			return ($this->_transport->kick((string)$taskId) > 0);
		$this->_checkTaskManagement();
		// MySQL storage
		if ($this->_dao) {
			$criteria = $this->_dao->criteria()->equal('id', $taskId);
			$stateCriteria = $this->_dao->criteria('or')->and($this->_getSqlStateCriteria('failed'));
			if (($delayedCriteria = $this->_getSqlStateCriteria('delayed')))
				$stateCriteria->or($delayedCriteria);
			$criteria->and($stateCriteria);
			if (!$this->_dao->update($criteria, $this->_getSqlRetryData()))
				return (false);
			$this->_notifyTask($taskId);
			return (true);
		}
		// Redis storage with xinetd transport
		if ($this->_transportClass == 'Temma\Datasources\Socket') {
			$task = $this->_storage[$taskId];
			if (($task['status'] ?? null) != 'error')
				return (false);
			$task['status'] = 'waiting';
			$task['attempts'] = 0;
			$task['error'] = null;
			$this->_storage[$taskId] = $task;
			$this->_notifyTask($taskId);
			return (true);
		}
		// Redis queue
		$found = $this->_findRedisTask($taskId, ['failed', 'delayed']);
		if (!$found)
			return (false);
		return ($this->_requeueRedisTask($found['task'], self::_getRedisListName($found['state'])));
	}
	/**
	 * Put back in the queue all the failed tasks. Their number of attempts is reset.
	 * @return	int	The number of tasks put back in the queue.
	 * @throws	\Temma\Exceptions\Application	If the configuration doesn't allow to retry tasks (SQS transport,
	 *						Redis storage with xinetd).
	 */
	public function retryFailedTasks() : int {
		// Beanstalkd transport
		if ($this->_transport instanceof \Temma\Datasources\Beanstalk)
			return ($this->_transport->kick());
		$this->_checkTaskManagement(true);
		// MySQL storage
		if ($this->_dao) {
			return ($this->_dao->update($this->_getSqlStateCriteria('failed'), $this->_getSqlRetryData()));
		}
		// Redis queue (the oldest tasks first, they are at the end of the list)
		$count = 0;
		foreach (array_reverse($this->_redis->lRange(self::REDIS_FAILED_QUEUE_NAME)) as $task) {
			if ($this->_requeueRedisTask($task, self::REDIS_FAILED_QUEUE_NAME))
				$count++;
		}
		return ($count);
	}
	/**
	 * Remove a task.
	 * @param	int|string	$taskId	Task identifier.
	 * @return	bool	True if the task was removed, false if it doesn't exist.
	 * @throws	\Temma\Exceptions\Application	If the configuration doesn't allow to remove a task (SQS transport).
	 */
	public function removeTask(int|string $taskId) : bool {
		// Beanstalkd transport
		if ($this->_transport instanceof \Temma\Datasources\Beanstalk) {
			try {
				$this->_transport->remove((string)$taskId);
			} catch (\Pheanstalk\Exception\JobNotFoundException $e) {
				return (false);
			}
			return (true);
		}
		$this->_checkTaskManagement();
		// MySQL storage
		if ($this->_dao)
			return ($this->_dao->remove($this->_dao->criteria()->equal('id', $taskId)) > 0);
		// Redis storage with xinetd transport
		if ($this->_transportClass == 'Temma\Datasources\Socket') {
			if (!is_array($this->_storage[$taskId]))
				return (false);
			unset($this->_storage[$taskId]);
			return (true);
		}
		// Redis queue
		$found = $this->_findRedisTask($taskId);
		if (!$found)
			return (false);
		return ($this->_redis->lRem(self::_getRedisListName($found['state']), 1, $found['task']) > 0);
	}
	/**
	 * Remove all the failed tasks.
	 * @return	int	The number of removed tasks.
	 * @throws	\Temma\Exceptions\Application	If the configuration doesn't allow to remove the failed tasks (SQS transport,
	 *						Redis storage with xinetd).
	 */
	public function purgeFailedTasks() : int {
		// Beanstalkd transport
		if ($this->_transport instanceof \Temma\Datasources\Beanstalk) {
			$count = 0;
			while (($job = $this->_transport->peekBuried())) {
				try {
					$this->_transport->remove($job['id']);
				} catch (\Pheanstalk\Exception\JobNotFoundException $e) {
					// already removed by another process
				}
				$count++;
			}
			return ($count);
		}
		$this->_checkTaskManagement(true);
		// MySQL storage
		if ($this->_dao)
			return ($this->_dao->remove($this->_getSqlStateCriteria('failed')));
		// Redis queue
		$count = 0;
		while ($this->_redis->rPop(self::REDIS_FAILED_QUEUE_NAME) !== null)
			$count++;
		return ($count);
	}
	/**
	 * Put back in the queue the tasks whose processing has started for a long time (their worker has probably crashed).
	 * With Beanstalkd and SQS transports, it is useless: tasks are automatically delivered again after their reservation
	 * or visibility timeout.
	 * @param	int	$minutes	Number of minutes since the processing start. It must be greater than the maximum
	 *					processing duration of a task, otherwise a task could be processed twice.
	 * @return	int	The number of tasks put back in the queue.
	 * @throws	\Temma\Exceptions\Application	If the configuration doesn't allow to recover tasks (Beanstalkd and SQS
	 *						transports, Redis storage with xinetd).
	 */
	public function recoverTasks(int $minutes) : int {
		if (in_array($this->_transportClass, self::TRANSPORT_QUEUES))
			throw new \Temma\Exceptions\Application("Tasks are automatically recovered by the message queue.", \Temma\Exceptions\Application::API);
		$this->_checkTaskManagement(true);
		$minutes = max(1, $minutes);
		// MySQL storage: the date of the database server is used, because the update date is set by the database server
		if ($this->_dao) {
			$now = $this->_dao->getDataBase()->queryOne('SELECT CURRENT_TIMESTAMP AS now', 'now');
			$limitDate = date('Y-m-d H:i:s', strtotime($now) - ($minutes * 60));
			$count = $this->_dao->update(
				$this->_getSqlStateCriteria('processing')->lessThan('dateUpdate', $limitDate),
				[
					'token'  => null,
					'status' => 'waiting',
				],
			);
			return ($count);
		}
		// Redis queue (tasks without start date were fetched by a previous version of Asynk)
		$limitTime = time() - ($minutes * 60);
		$count = 0;
		foreach (array_reverse($this->_redis->lRange(self::REDIS_PROCESSING_QUEUE_NAME)) as $task) {
			if (!is_array($task) || (isset($task['dateStart']) && strtotime($task['dateStart']) >= $limitTime))
				continue;
			if (!$this->_redis->lRem(self::REDIS_PROCESSING_QUEUE_NAME, 1, $task))
				continue;
			unset($task['dateStart']);
			$this->_redis->lPush(self::REDIS_QUEUE_NAME, $task);
			$count++;
		}
		return ($count);
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Return the delay before the next attempt.
	 * @param	int	$attempts	Number of failed attempts before the current one.
	 * @return	?int	Number of seconds, or null if no new attempt must be done.
	 */
	private function _getRetryDelay(int $attempts) : ?int {
		return ($this->_retries[$attempts] ?? null);
	}
	/**
	 * Send a task identifier to the notification transport (xinetd), if there is one.
	 * @param	int|string|null	$taskId	Task identifier.
	 */
	private function _notifyTask(int|string|null $taskId) : void {
		if (!in_array($this->_transportClass, self::TRANSPORT_NOTIFY))
			return;
		$this->_transport->set('', $taskId);
		if (method_exists($this->_transport, 'disconnect'))
			$this->_transport->disconnect();
	}
	/**
	 * Check that the configuration allows to manage tasks.
	 * @param	bool	$needList	(optional) True if the tasks must be listed (not possible with a Redis storage
	 *					and an xinetd transport).
	 * @throws	\Temma\Exceptions\Application	If the tasks can't be managed.
	 */
	private function _checkTaskManagement(bool $needList=false) : void {
		if ($this->_transport instanceof \Temma\Datasources\Sqs)
			throw new \Temma\Exceptions\Application("SQS messages can't be managed by Asynk.", \Temma\Exceptions\Application::API);
		if ($needList && !$this->_dao && $this->_transportClass == 'Temma\Datasources\Socket')
			throw new \Temma\Exceptions\Application("Tasks stored in Redis with an xinetd transport can't be listed.", \Temma\Exceptions\Application::API);
	}
	/**
	 * Check a task state.
	 * @param	?string	$state	The state to check (null is accepted).
	 * @throws	\Temma\Exceptions\Application	If the state is not valid.
	 */
	private function _checkState(?string $state) : void {
		if ($state !== null && !in_array($state, self::STATES))
			throw new \Temma\Exceptions\Application("Unknown task state '$state'.", \Temma\Exceptions\Application::BAD_PARAM);
	}
	/**
	 * Return the criteria used to search the tasks in a given state, in the MySQL storage.
	 * @param	string	$state	Task state ("waiting", "delayed", "processing" or "failed").
	 * @return	?\Temma\Dao\Criteria	The criteria, or null if no task can be in this state (delayed tasks when retries are disabled).
	 */
	private function _getSqlStateCriteria(string $state) : ?\Temma\Dao\Criteria {
		$criteria = $this->_dao->criteria();
		if ($state == 'processing')
			return ($criteria->equal('status', ['reserved', 'processing']));
		if ($state == 'failed')
			return ($criteria->equal('status', 'error'));
		$criteria->equal('status', 'waiting');
		if ($this->_retries === null)
			return (($state == 'waiting') ? $criteria : null);
		$now = date('Y-m-d H:i:s');
		if ($state == 'delayed')
			return ($criteria->greaterThan('dateNextAttempt', $now));
		return ($criteria->and(
			$this->_dao->criteria('or')->equal('dateNextAttempt', null)
			                           ->lessOrEqualTo('dateNextAttempt', $now)
		));
	}
	/**
	 * Return the data used to put back a task in the queue, in the MySQL storage.
	 * @return	array	Associative array.
	 */
	private function _getSqlRetryData() : array {
		$data = [
			'token'  => null,
			'status' => 'waiting',
		];
		if ($this->_retries !== null) {
			$data['attempts'] = 0;
			$data['dateNextAttempt'] = null;
			$data['error'] = null;
		}
		return ($data);
	}
	/**
	 * Normalize a task read from the MySQL storage.
	 * @param	array	$task	Task data.
	 * @return	array	Normalized task (see getTask()).
	 */
	private function _normalizeSqlTask(array $task) : array {
		$status = $task['status'] ?? null;
		$dateNextAttempt = $task['dateNextAttempt'] ?? null;
		$state = ($status == 'error') ? 'failed' :
		         (in_array($status, ['reserved', 'processing']) ? 'processing' :
		          (($dateNextAttempt && $dateNextAttempt > date('Y-m-d H:i:s')) ? 'delayed' : 'waiting'));
		$data = $task['data'] ?? null;
		return ([
			'id'              => $task['id'] ?? null,
			'state'           => $state,
			'dateCreation'    => $task['dateCreation'] ?? null,
			'dateStart'       => ($state == 'processing') ? ($task['dateUpdate'] ?? null) : null,
			'target'          => $task['target'] ?? null,
			'action'          => $task['action'] ?? null,
			'data'            => is_string($data) ? json_decode($data, true) : $data,
			'attempts'        => isset($task['attempts']) ? (int)$task['attempts'] : null,
			'dateNextAttempt' => ($state == 'delayed') ? $dateNextAttempt : null,
			'error'           => $task['error'] ?? null,
		]);
	}
	/**
	 * Normalize a task read from the Redis storage.
	 * @param	mixed	$task	Task data.
	 * @param	string	$state	Task state.
	 * @return	array	Normalized task (see getTask()).
	 */
	private function _normalizeRedisTask(mixed $task, string $state) : array {
		$task = is_array($task) ? $task : [];
		return ([
			'id'              => $task['id'] ?? null,
			'state'           => $state,
			'dateCreation'    => $task['creation'] ?? null,
			'dateStart'       => $task['dateStart'] ?? null,
			'target'          => $task['target'] ?? null,
			'action'          => $task['action'] ?? null,
			'data'            => $task['data'] ?? null,
			'attempts'        => $task['attempts'] ?? 0,
			'dateNextAttempt' => $task['dateNextAttempt'] ?? null,
			'error'           => $task['error'] ?? null,
		]);
	}
	/**
	 * Normalize a Beanstalkd job.
	 * @param	array	$job	Job data (keys "id" and "data").
	 * @return	array	Normalized task (see getTask()).
	 */
	private function _normalizeBeanstalkTask(array $job) : array {
		$task = json_decode($job['data'], true);
		$task = is_array($task) ? $task : [];
		$stats = ($this->_transport instanceof \Temma\Datasources\Beanstalk) ? $this->_transport->stats($job['id']) : null;
		$states = [
			'ready'    => 'waiting',
			'delayed'  => 'delayed',
			'reserved' => 'processing',
			'buried'   => 'failed',
		];
		$state = $states[$stats['state'] ?? ''] ?? 'waiting';
		return ([
			'id'              => $job['id'],
			'state'           => $state,
			'dateCreation'    => $task['creation'] ?? null,
			'dateStart'       => null,
			'target'          => $task['target'] ?? null,
			'action'          => $task['action'] ?? null,
			'data'            => $task['data'] ?? null,
			'attempts'        => $stats['releases'] ?? null,
			'dateNextAttempt' => ($state == 'delayed') ? date('c', time() + ($stats['timeLeft'] ?? 0)) : null,
			'error'           => null,
		]);
	}
	/**
	 * Search a task in the Redis lists.
	 * @param	int|string	$taskId	Task identifier.
	 * @param	?array		$states	(optional) List of states to search in. All states by default.
	 * @return	?array	Associative array with the keys "task" (task data) and "state", or null if the task was not found.
	 */
	private function _findRedisTask(int|string $taskId, ?array $states=null) : ?array {
		foreach (($states ?? self::STATES) as $state) {
			foreach ($this->_redis->lRange(self::_getRedisListName($state)) as $task) {
				if (is_array($task) && (string)($task['id'] ?? '') === (string)$taskId)
					return (['task' => $task, 'state' => $state]);
			}
		}
		return (null);
	}
	/**
	 * Move a Redis task from a list (failed or delayed tasks) to the queue. The number of attempts is reset.
	 * @param	mixed	$task		Task data.
	 * @param	string	$listName	Name of the list containing the task.
	 * @return	bool	True if the task was moved, false if it was not in the list anymore.
	 */
	private function _requeueRedisTask(mixed $task, string $listName) : bool {
		if (!is_array($task) || !$this->_redis->lRem($listName, 1, $task))
			return (false);
		unset($task['dateNextAttempt'], $task['dateFailure'], $task['error']);
		$task['status'] = 'waiting';
		$task['attempts'] = 0;
		$this->_redis->lPush(self::REDIS_QUEUE_NAME, $task);
		return (true);
	}
	/**
	 * Put back in the Redis queue the delayed tasks whose new attempt date is reached.
	 * The list of delayed tasks is checked at most every REDIS_DELAYED_CHECK_INTERVAL seconds.
	 */
	private function _promoteRedisDelayedTasks() : void {
		$now = time();
		if ($now < $this->_nextDelayedCheck)
			return;
		$this->_nextDelayedCheck = $now + self::REDIS_DELAYED_CHECK_INTERVAL;
		foreach (array_reverse($this->_redis->lRange(self::REDIS_DELAYED_QUEUE_NAME)) as $task) {
			if (!is_array($task) || (isset($task['dateNextAttempt']) && strtotime($task['dateNextAttempt']) > $now))
				continue;
			// the task is moved only if it was still in the list (it may have been moved by another worker)
			if (!$this->_redis->lRem(self::REDIS_DELAYED_QUEUE_NAME, 1, $task))
				continue;
			unset($task['dateNextAttempt']);
			$this->_redis->lPush(self::REDIS_QUEUE_NAME, $task);
		}
	}
	/**
	 * Store a task in MySQL.
	 * @param	string	$target	Name of the object to execute.
	 * @param	string	$action	Name of the method to execute.
	 * @param	array	$params	Parameters list.
	 * @return	int	Task identifier.
	 */
	private function _storeTaskSql(string $target, string $action, array $params) : int {
		$taskId = $this->_dao->create([
			'dateCreation' => date('c'),
			'target'       => $target,
			'action'       => $action,
			'status'       => 'waiting',
			'data'         => json_encode($params),
		]);
		return ($taskId);
	}
	/**
	 * Store a task in a Redis queue.
	 * @param	string	$target	Name of the object to execute.
	 * @param	string	$action	Name of the method to execute.
	 * @param	array	$params	Parameters list.
	 */
	private function _storeTaskRedisQueue(string $target, string $action, array $params) : void {
		$task = [
			'id'       => self::_generateTaskId(),
			'creation' => date('c'),
			'target'   => $target,
			'action'   => $action,
			'status'   => 'waiting',
			'data'     => $params,
		];
		// the task is normalized (objects converted to arrays), so it is encoded the same way each time it is
		// decoded and encoded again (needed to remove it from a list)
		$task = json_decode(json_encode($task), true);
		$this->_redis->lPush(self::REDIS_QUEUE_NAME, $task);
	}
	/**
	 * Store a task in a key-value data source.
	 * @param	string	$target	Name of the object to execute.
	 * @param	string	$action	Name of the method to execute.
	 * @param	array	$params	Parameters list.
	 * @return	string	Task identifier.
	 */
	private function _storeTaskKeyValue(string $target, string $action, array $params) : string {
		$taskId = self::_generateTaskId();
		$this->_storage[$taskId] = [
			'creation' => date('c'),
			'target'   => $target,
			'action'   => $action,
			'status'   => 'waiting',
			'data'     => $params,
		];
		return ($taskId);
	}
	/**
	 * Send task data to a message queue (SQS or Beanstalkd).
	 * @param	string	$target	Name of the object to execute.
	 * @param	string	$action	Name of the method to execute.
	 * @param	array	$params	Parameters list.
	 */
	private function _transportQueue(string $target, string $action, array $params) : void {
		$this->_transport->set('', [
			'creation' => date('c'),
			'target'   => $target,
			'action'   => $action,
			'status'   => 'waiting',
			'data'     => $params,
		]);
	}
	/**
	 * Generate a unique task identifier.
	 * @return	string	The identifier (timestamp with microseconds and a random string).
	 */
	static private function _generateTaskId() : string {
		$f = microtime(true);
		$s = bin2hex(random_bytes(4));
		$s = \Temma\Utils\BaseConvert::convert($s, 16, 62);
		return (sprintf('%.06f-%s', $f, $s));
	}
	/**
	 * Return the name of the Redis list containing the tasks in a given state.
	 * @param	string	$state	Task state.
	 * @return	string	The list name.
	 */
	static private function _getRedisListName(string $state) : string {
		return (match ($state) {
			'delayed'    => self::REDIS_DELAYED_QUEUE_NAME,
			'processing' => self::REDIS_PROCESSING_QUEUE_NAME,
			'failed'     => self::REDIS_FAILED_QUEUE_NAME,
			default      => self::REDIS_QUEUE_NAME,
		});
	}
	/**
	 * Parse the "retries" configuration.
	 * @param	mixed	$retries	Configuration value: true for the default delays, or a list of delays (integers in
	 *					seconds, or strings with a unit: "30s", "15m", "4h", "2d").
	 * @return	?array	List of delays in seconds, or null if retries are not enabled.
	 * @throws	\Temma\Exceptions\Framework	If the configuration is not valid.
	 */
	static private function _parseRetries(mixed $retries) : ?array {
		if ($retries === null || $retries === false)
			return (null);
		if ($retries === true)
			$retries = self::DEFAULT_RETRIES;
		if (!is_array($retries))
			throw new TµFrameworkException("Invalid Asynk 'retries' configuration.", TµFrameworkException::CONFIG);
		$units = [
			's' => 1,
			'm' => 60,
			'h' => 3600,
			'd' => 86400,
		];
		$delays = [];
		foreach ($retries as $delay) {
			if (is_int($delay) && $delay >= 0) {
				$delays[] = $delay;
				continue;
			}
			if (!is_string($delay) || !preg_match('/^\s*(\d+)\s*([smhd]?)\s*$/i', $delay, $matches))
				throw new TµFrameworkException("Invalid Asynk retry delay '" . json_encode($delay) . "'.", TµFrameworkException::CONFIG);
			$delays[] = (int)$matches[1] * $units[strtolower($matches[2] ?: 's')];
		}
		return ($delays);
	}
}
