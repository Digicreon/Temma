<?php

/**
 * AsynkDao
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023-2026, Amaury Bouchard
 */

namespace Temma\Asynk;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Framework as TµFrameworkException;
use \Temma\Exceptions\Application as TµApplicationException;

/**
 * DAO for asynchronous processings.
 *
 * The task processing methods (creation, fetching, execution, invalidation) are used by the Asynk objects and
 * scripts. The task management methods (getStats(), listTasks(), getTask(), retryTask(), retryFailedTasks(),
 * removeTask(), purgeFailedTasks(), recoverTasks()) can be used by the application, and are used by the
 * AsynkManager command.
 *
 * Tasks have four possible states:
 * - "waiting": the task is waiting to be processed;
 * - "delayed": the processing of the task has failed, a new attempt is planned (see the "retries" configuration);
 * - "processing": the task is being processed;
 * - "failed": the processing of the task has failed, and no new attempt is planned.
 *
 * When retries are enabled, the number of attempts of a task is incremented each time its processing starts. So the
 * processings interrupted by a crash of the worker are counted as attempts.
 *
 * With a Redis storage (with or without xinetd transport), the tasks are stored in the "asynk_tasks" hash (task
 * identifier => task data), and their identifiers are stored in the "asynk" list (waiting tasks), the
 * "asynk_processing" list (tasks being processed), the "asynk_delayed" sorted set (delayed tasks, sorted by date of
 * next attempt) and the "asynk_failed" list (failed tasks). A task is reserved by a worker by creating its field in the
 * "asynk_claims" hash (atomic operation), so a task can't be processed twice even if its identifier is in a list
 * several times.
 */
class AsynkDao implements \Temma\Base\Loadable {
	/** Constant: Name of the Redis hash containing the tasks. */
	const REDIS_TASKS_HASH = 'asynk_tasks';
	/** Constant: Name of the Redis hash containing the reservations of the tasks. */
	const REDIS_CLAIMS_HASH = 'asynk_claims';
	/** Constant: Name of the Redis list of waiting tasks. */
	const REDIS_QUEUE_NAME = 'asynk';
	/** Constant: Name of the Redis list of tasks being processed. */
	const REDIS_PROCESSING_QUEUE_NAME = 'asynk_processing';
	/** Constant: Name of the Redis sorted set of delayed tasks (waiting for a new attempt), sorted by date of next attempt. */
	const REDIS_DELAYED_QUEUE_NAME = 'asynk_delayed';
	/** Constant: Name of the Redis list of failed tasks. */
	const REDIS_FAILED_QUEUE_NAME = 'asynk_failed';
	/** Constant: Minimal duration between two checks of the Redis delayed tasks, in seconds. */
	const REDIS_DELAYED_CHECK_INTERVAL = 10;
	/** Constant: Maximum number of Redis delayed tasks put back in the queue at each check. */
	const REDIS_DELAYED_CHECK_LIMIT = 100;
	/** Constant: Time to run of the Beanstalkd jobs (duration of a reservation), in seconds. */
	const BEANSTALK_TTR = 3600;
	/** Constant: Default delays between attempts (used when the "retries" configuration is true). */
	const DEFAULT_RETRIES = ['1m', '2m', '15m', '1h', '4h', '12h', '1d', '2d'];
	/** Constant: Maximum size of the stored error messages, in bytes. */
	const MAX_ERROR_SIZE = 10000;
	/** Constant: Error message of the tasks whose processing was interrupted. */
	const INTERRUPTED_ERROR = 'Processing interrupted (crashed worker?).';
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
	/** Redis storage. */
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
				if ($column && ($column != $fieldName)) {
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
	 * @throws	\Temma\Exceptions\Application	If the parameters can't be JSON-encoded.
	 */
	public function createTask(string $target, string $action, array $params) : void {
		// check the parameters (with a margin of depth, for the task data containing the parameters)
		if (json_encode($params, 0, 500) === false)
			throw new TµApplicationException("The parameters of the Asynk task '$target::$action()' can't be JSON-encoded.", TµApplicationException::BAD_PARAM);
		// storage
		$taskId = null;
		if ($this->_dao) {
			// MySQL storage (with or without transport)
			$taskId = $this->_storeTaskSql($target, $action, $params);
		} else if ($this->_redis) {
			// Redis storage (with or without transport)
			$taskId = $this->_storeTaskRedis($target, $action, $params);
		} else if (!in_array($this->_transportClass, self::TRANSPORT_QUEUES)) {
			throw new TµFrameworkException("Unknown Asynk storage '{$this->_storageClass}'.", TµFrameworkException::CONFIG);
		}
		// transport
		if (!$this->_transport) {
			// crontab or workers
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
	 * Return a task from its identifier, whatever its state (MySQL and Redis storages).
	 * Used with an xinetd transport: the task must then be reserved with reserveTask() before being processed.
	 * @param	int|string	$taskId	Task identifier.
	 * @return	?array	Associative array (with decoded parameters in the "data" key), or null if the task doesn't exist.
	 */
	public function getTaskFromId(int|string $taskId) : ?array {
		if (!$this->_isValidTaskId($taskId))
			return (null);
		// MySQL storage
		if ($this->_dao) {
			$task = $this->_dao->get($taskId);
			if (!$task)
				return (null);
			if (is_string($task['data'] ?? null))
				$task['data'] = json_decode($task['data'], true);
			return ($task);
		}
		// Redis storage
		if (!$this->_redis)
			return (null);
		$task = $this->_redis->hGet(self::REDIS_TASKS_HASH, (string)$taskId);
		return (is_array($task) ? $task : null);
	}
	/**
	 * Tell if a task (returned by getTaskFromId()) can be processed now: it is waiting, and the date of its next attempt
	 * (if any) is reached.
	 * @param	array	$task	Task data.
	 * @return	bool	True if the task can be processed.
	 */
	public function isTaskReady(array $task) : bool {
		if (($task['status'] ?? null) != 'waiting')
			return (false);
		$dateNextAttempt = $task['dateNextAttempt'] ?? null;
		if (!$dateNextAttempt)
			return (true);
		// MySQL storage: dates of next attempt are stored in UTC
		if ($this->_dao)
			return ($dateNextAttempt <= gmdate('Y-m-d H:i:s'));
		return (strtotime($dateNextAttempt) <= time());
	}
	/**
	 * Reserve a task returned by getTaskFromId(), and start its processing (atomic operation: a task can be reserved
	 * only once). The task must be ready (see isTaskReady()).
	 * @param	array	$task	Task data.
	 * @return	?array	The updated task data, or null if the task was not ready or was reserved by another process.
	 */
	public function reserveTask(array $task) : ?array {
		if (!isset($task['id']))
			return (null);
		// MySQL storage
		if ($this->_dao) {
			$token = bin2hex(random_bytes(8));
			$data = [
				'token'  => $token,
				'status' => 'processing',
			];
			if ($this->_retries !== null)
				$data['attempts'] = (int)($task['attempts'] ?? 0) + 1;
			$count = $this->_dao->update(
				$this->_getSqlStateCriteria('waiting')->equal('id', $task['id'])->equal('token', null),
				$data,
			);
			if (!$count)
				return (null);
			$task = $this->_dao->get($task['id']);
			if ((($task['status'] ?? null) != 'processing') || (($task['token'] ?? null) != $token))
				return (null);
			if (is_string($task['data'] ?? null))
				$task['data'] = json_decode($task['data'], true);
			return ($task);
		}
		// Redis storage: the identifier stays in the waiting list (a worker which fetches it will see that the task
		// is reserved); it is added to the processing list before the reservation, so the task can't be lost
		if (!$this->_redis)
			return (null);
		$taskId = (string)$task['id'];
		$this->_redis->lPush(self::REDIS_PROCESSING_QUEUE_NAME, $taskId);
		$task = $this->_reserveRedisTask($taskId);
		if (!$task) {
			$this->_redis->lRem(self::REDIS_PROCESSING_QUEUE_NAME, 1, $taskId);
			return (null);
		}
		// flag used to remove the identifier from the waiting list at the end of the processing
		$task['_queued'] = true;
		return ($task);
	}
	/**
	 * Returns the next task to process.
	 * For a Beanstalkd transport, the function blocks until a message is available; for an SQS transport,
	 * it waits during the long polling duration defined in the DSN (20 seconds at most).
	 * With message queues and retries enabled, the messages received too many times (because their processing was
	 * interrupted by a crash of the worker) are put aside without being processed. Invalid messages are put aside too.
	 * @return	?array	Associative array or null.
	 */
	public function getNextTask() : ?array {
		// Beanstalkd or SQS transport
		if (in_array($this->_transportClass, self::TRANSPORT_QUEUES))
			return ($this->_getNextQueueTask());
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
			if (!($task['id'] ?? null))
				return (null);
			// start the processing: status and number of attempts
			$data = ['status' => 'processing'];
			if ($this->_retries !== null)
				$data['attempts'] = $task['attempts'] = (int)($task['attempts'] ?? 0) + 1;
			$this->_dao->update($task['id'], $data);
			$task['status'] = 'processing';
			if (is_string($task['data'] ?? null))
				$task['data'] = json_decode($task['data'], true);
			return ($task);
		}
		// Redis storage
		if (!$this->_redis)
			return (null);
		// put back in the queue the delayed tasks whose new attempt date is reached
		$this->_promoteRedisDelayedTasks();
		// fetch the identifiers until a task can be reserved
		while (true) {
			$taskId = $this->_redis->rPopLPush(self::REDIS_QUEUE_NAME, self::REDIS_PROCESSING_QUEUE_NAME);
			if ($taskId === null)
				return (null);
			if (!is_string($taskId))
				continue;
			$task = $this->_reserveRedisTask($taskId);
			if ($task)
				return ($task);
			// the task was reserved by another process (xinetd), or doesn't exist anymore
			$this->_redis->lRem(self::REDIS_PROCESSING_QUEUE_NAME, 1, $taskId);
		}
	}
	/**
	 * Execute a task fetched with getNextTask() or reserveTask().
	 * If the processing fails, a WARN message is logged and the task is invalidated (see invalidateFetchedTask()).
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
				throw new \Exception("Unable to instantiate object '{$task['target']}'.");
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
			// the error is logged before the invalidation (which could fail)
			$error = mb_scrub(get_class($t) . ': ' . $t->getMessage());
			$taskName = ($task['target'] ?? '') . '::' . ($task['action'] ?? '') . '()';
			TµLog::log('Temma/Asynk', 'WARN', "Task '$taskName' failed. $error");
			try {
				$delay = $this->invalidateFetchedTask($task, $error, $retry);
			} catch (\Exception $e) {
				TµLog::log('Temma/Asynk', 'ERROR', "Unable to invalidate the task '$taskName': " . $e->getMessage());
				return (false);
			}
			if ($delay !== null)
				TµLog::log('Temma/Asynk', 'WARN', "Task '$taskName': new attempt in $delay seconds.");
			else if ($this->_transport instanceof \Temma\Datasources\Sqs)
				TµLog::log('Temma/Asynk', 'WARN', "Task '$taskName': the message will be delivered again after its visibility timeout.");
			else
				TµLog::log('Temma/Asynk', 'WARN', "Task '$taskName': no new attempt planned.");
			return (false);
		}
		return (true);
	}
	/**
	 * Remove a task fetched with getNextTask() or reserveTask(), after a successful processing.
	 * @param	array	$task	Task informations.
	 * @throws	\Exception	If the storage or the message queue can't be updated.
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
		// Redis storage
		if (!$this->_redis || !isset($task['id']))
			return;
		$this->_redis->hDel(self::REDIS_TASKS_HASH, (string)$task['id']);
		$this->_endRedisProcessing($task);
	}
	/**
	 * Invalidate a task, after a processing failure (task fetched with getNextTask() or reserveTask()).
	 *
	 * If retries are enabled (see the "retries" configuration) and if the maximum number of attempts is not reached,
	 * a new attempt is planned:
	 * - MySQL storage: the task gets back the "waiting" status, with the date of the next attempt;
	 * - Redis storage: the task is added to the sorted set of delayed tasks;
	 * - Beanstalkd transport: the job is released with a delay;
	 * - SQS transport: the visibility timeout of the message is changed (SQS limits it to 12 hours after the reception
	 *   of the message).
	 *
	 * Otherwise, the task is put aside:
	 * - MySQL storage: the task gets the "error" status;
	 * - Redis storage: the task is added to the list of failed tasks;
	 * - Beanstalkd transport: the job is buried (it is not delivered anymore, but it can be put back in the queue);
	 * - SQS transport: nothing is done; the message is delivered again after its visibility timeout (configure
	 *   a dead-letter queue to put aside the failed messages).
	 * @param	array	$task	Task data.
	 * @param	?string	$error	(optional) Error message, stored with the task (MySQL storage with retries enabled, Redis storage).
	 * @param	bool	$retry	(optional) False to put aside the task without new attempt (true by default).
	 * @return	?int	Number of seconds before the next attempt, or null if no new attempt is planned.
	 * @throws	\Exception	If the storage can't be updated (MySQL and Redis storages).
	 */
	public function invalidateFetchedTask(array $task, ?string $error=null, bool $retry=true) : ?int {
		// the error message is cleaned (it may contain invalid UTF-8 characters) and truncated
		if ($error !== null)
			$error = mb_strcut(mb_scrub($error), 0, self::MAX_ERROR_SIZE);
		$attempts = (int)($task['attempts'] ?? 1);
		$delay = ($retry && $this->_retries) ? $this->_getRetryDelay($attempts) : null;
		// Beanstalkd transport
		if ($this->_transport instanceof \Temma\Datasources\Beanstalk) {
			if (!isset($task['id']))
				return (null);
			try {
				// new attempt: the job is released with a delay
				if ($delay !== null) {
					$this->_transport->release($task['id'], $delay);
					return ($delay);
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
			if (!isset($task['id']) || ($delay === null))
				return (null);
			try {
				// the delay may be reduced (SQS limits the visibility timeout to 12 hours after the reception)
				$delay = $this->_transport->changeVisibility($task['id'], $delay);
			} catch (\Exception $e) {
				TµLog::log('Temma/Asynk', 'WARN', "Unable to change the visibility timeout of the message '{$task['id']}': " . $e->getMessage());
				return (null);
			}
			return ($delay);
		}
		// MySQL storage
		if ($this->_dao) {
			$data = [
				'token'  => null,
				'status' => 'error',
			];
			if ($this->_retries !== null) {
				$data['error'] = $error;
				if ($delay !== null) {
					$data['status'] = 'waiting';
					$data['dateNextAttempt'] = gmdate('Y-m-d H:i:s', time() + $delay);
				}
			}
			$this->_dao->update($task['id'], $data);
			return ($delay);
		}
		// Redis storage (the identifier is added to its new list before the update of the task, so the task can't be
		// lost; the processing list is updated at the end)
		if (!$this->_redis || !isset($task['id']))
			return (null);
		$taskId = (string)$task['id'];
		$newTask = $task;
		unset($newTask['dateStart'], $newTask['_queued']);
		$newTask['error'] = $error;
		if ($delay !== null) {
			// new attempt: the identifier is added to the sorted set of delayed tasks
			$newTask['status'] = 'waiting';
			$newTask['dateNextAttempt'] = date('c', time() + $delay);
			$this->_redis->zAdd(self::REDIS_DELAYED_QUEUE_NAME, time() + $delay, $taskId);
		} else {
			// the identifier is added to the list of failed tasks
			$newTask['status'] = 'error';
			$newTask['dateFailure'] = date('c');
			$this->_redis->lPush(self::REDIS_FAILED_QUEUE_NAME, $taskId);
		}
		$this->_redis->hSet(self::REDIS_TASKS_HASH, $taskId, $newTask);
		$this->_endRedisProcessing($task);
		return ($delay);
	}

	/* ********** TASK MANAGEMENT ********** */
	/**
	 * Return the number of tasks in each state.
	 * With SQS, the numbers are approximate, the tasks waiting for a new attempt are counted as "processing" (their
	 * visibility timeout is not reached), and the number of failed tasks is not available.
	 * @return	array	Associative array whose keys are the states ("waiting", "delayed", "processing", "failed") and the values
	 *			are the numbers of tasks (null if the number is not available).
	 * @throws	\Exception	If the storage or the message queue can't be read.
	 */
	public function getStats() : array {
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
		// Redis storage
		return ([
			'waiting'    => $this->_redis->lLen(self::REDIS_QUEUE_NAME),
			'delayed'    => $this->_redis->zCard(self::REDIS_DELAYED_QUEUE_NAME),
			'processing' => $this->_redis->lLen(self::REDIS_PROCESSING_QUEUE_NAME),
			'failed'     => $this->_redis->lLen(self::REDIS_FAILED_QUEUE_NAME),
		]);
	}
	/**
	 * Return a list of tasks. MySQL storage: sorted by identifier. Redis storage: the oldest first in each state (delayed
	 * tasks: sorted by date of next attempt).
	 * With a Beanstalkd transport, only the first failed task can be returned (it is returned if no state is given).
	 * @param	?string	$state	(optional) State of the tasks ("waiting", "delayed", "processing" or "failed"). All tasks if null.
	 * @param	int	$limit	(optional) Maximum number of tasks (for each state if no state is given). 20 by default.
	 * @return	?array	List of tasks (see getTask() for their content), or null if the configuration doesn't allow
	 *			to list the tasks (SQS transport, Beanstalkd transport with a state other than "failed").
	 * @throws	\Temma\Exceptions\Application	If the state is not valid.
	 * @throws	\Exception	If the storage or the message queue can't be read.
	 */
	public function listTasks(?string $state=null, int $limit=20) : ?array {
		$this->_checkState($state);
		$limit = max(1, $limit);
		// Beanstalkd transport: only the first buried job
		if ($this->_transport instanceof \Temma\Datasources\Beanstalk) {
			if (($state !== null) && ($state != 'failed'))
				return (null);
			$job = $this->_transport->peekBuried();
			$stats = $job ? $this->_transport->stats($job['id']) : null;
			return ($stats ? [$this->_normalizeBeanstalkTask($job, $stats)] : []);
		}
		// SQS transport
		if ($this->_transport instanceof \Temma\Datasources\Sqs)
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
		// Redis storage (the newest identifiers are at the beginning of the lists)
		foreach ($states as $currentState) {
			if ($currentState == 'delayed')
				$taskIds = $this->_redis->zRangeByScore(self::REDIS_DELAYED_QUEUE_NAME, '-inf', '+inf', 0, $limit);
			else
				$taskIds = array_reverse($this->_redis->lRange(self::_getRedisListName($currentState), -$limit, -1));
			foreach (array_unique(array_filter($taskIds, 'is_string')) as $taskId) {
				$task = $this->_redis->hGet(self::REDIS_TASKS_HASH, $taskId);
				if (is_array($task))
					$tasks[] = $this->_normalizeRedisTask($task);
			}
		}
		return ($tasks);
	}
	/**
	 * Return a task.
	 * @param	int|string	$taskId	Task identifier.
	 * @return	?array	Associative array with the keys "id", "state" ("waiting", "delayed", "processing" or "failed"),
	 *			"dateCreation", "dateStart" (processing start date), "target", "action", "data" (parameters),
	 *			"attempts" (number of started attempts), "dateNextAttempt" and "error" (last error message).
	 *			Dates are in the "YYYY-MM-DD HH:MM:SS" format, in the local time zone. Unknown values are null.
	 *			Returns null if the task doesn't exist.
	 * @throws	\Temma\Exceptions\Application	If the configuration doesn't allow to fetch a task (SQS transport).
	 * @throws	\Exception	If the storage or the message queue can't be read.
	 */
	public function getTask(int|string $taskId) : ?array {
		if (!$this->_isValidTaskId($taskId))
			return (null);
		// Beanstalkd transport
		if ($this->_transport instanceof \Temma\Datasources\Beanstalk) {
			$stats = $this->_getBeanstalkJobStats($taskId);
			$job = $stats ? $this->_transport->peek((string)$taskId) : null;
			return ($job ? $this->_normalizeBeanstalkTask($job, $stats) : null);
		}
		$this->_checkTaskManagement();
		// MySQL storage
		if ($this->_dao) {
			$task = $this->_dao->get($taskId);
			return ($task ? $this->_normalizeSqlTask($task) : null);
		}
		// Redis storage
		$task = $this->_redis->hGet(self::REDIS_TASKS_HASH, (string)$taskId);
		return (is_array($task) ? $this->_normalizeRedisTask($task) : null);
	}
	/**
	 * Put back in the queue a failed or delayed task, to process it as soon as possible.
	 * The number of attempts is reset (with a Beanstalkd transport, the job is created again, with a new identifier).
	 * With an xinetd transport, the task is notified to xinetd (if xinetd is not reachable, a WARN message is logged,
	 * and the task will be processed by the crontab).
	 * @param	int|string	$taskId	Task identifier.
	 * @return	bool	True if the task was put back in the queue, false if it doesn't exist or is not failed nor delayed.
	 * @throws	\Temma\Exceptions\Application	If the configuration doesn't allow to retry a task (SQS transport).
	 * @throws	\Exception	If the storage or the message queue can't be updated.
	 */
	public function retryTask(int|string $taskId) : bool {
		if (!$this->_isValidTaskId($taskId))
			return (false);
		// Beanstalkd transport
		if ($this->_transport instanceof \Temma\Datasources\Beanstalk) {
			$stats = $this->_getBeanstalkJobStats($taskId);
			if (!in_array(($stats['state'] ?? null), ['buried', 'delayed']))
				return (false);
			return ($this->_recreateBeanstalkJob((string)$taskId) !== null);
		}
		$this->_checkTaskManagement();
		// MySQL storage
		if ($this->_dao) {
			$stateCriteria = $this->_dao->criteria('or')->or($this->_getSqlStateCriteria('failed'));
			if (($delayedCriteria = $this->_getSqlStateCriteria('delayed')))
				$stateCriteria->or($delayedCriteria);
			$criteria = $this->_dao->criteria()->equal('id', $taskId)->and($stateCriteria);
			if (!$this->_dao->update($criteria, $this->_getSqlRetryData()))
				return (false);
			$this->_notifyTask($taskId, false);
			return (true);
		}
		// Redis storage
		if (!$this->_requeueRedisTask((string)$taskId, true))
			return (false);
		$this->_notifyTask($taskId, false);
		return (true);
	}
	/**
	 * Put back in the queue all the failed tasks. Their number of attempts is reset (with a Beanstalkd transport,
	 * the jobs are created again, with new identifiers). With an xinetd transport, the tasks are not notified to xinetd
	 * (they are processed by the crontab).
	 * @return	int	The number of tasks put back in the queue.
	 * @throws	\Temma\Exceptions\Application	If the configuration doesn't allow to retry tasks (SQS transport).
	 * @throws	\Exception	If the storage or the message queue can't be updated.
	 */
	public function retryFailedTasks() : int {
		// Beanstalkd transport (the number of jobs is limited to the number of buried jobs at the beginning, because
		// a recreated job may be buried again by a worker during the loop)
		if ($this->_transport instanceof \Temma\Datasources\Beanstalk) {
			$count = 0;
			$max = $this->_transport->stats()['buried'] ?? 0;
			for ($i = 0; $i < $max; $i++) {
				$job = $this->_transport->peekBuried();
				if (!$job)
					break;
				if ($this->_recreateBeanstalkJob($job['id']) !== null)
					$count++;
			}
			return ($count);
		}
		$this->_checkTaskManagement();
		// MySQL storage
		if ($this->_dao)
			return ($this->_dao->update($this->_getSqlStateCriteria('failed'), $this->_getSqlRetryData()));
		// Redis storage (the oldest tasks first, they are at the end of the list)
		$count = 0;
		$taskIds = array_unique(array_reverse($this->_redis->lRange(self::REDIS_FAILED_QUEUE_NAME)));
		foreach (array_filter($taskIds, 'is_string') as $taskId) {
			if ($this->_requeueRedisTask($taskId, false))
				$count++;
		}
		return ($count);
	}
	/**
	 * Remove a task.
	 * With a Beanstalkd transport, a task being processed can't be removed.
	 * @param	int|string	$taskId	Task identifier.
	 * @return	bool	True if the task was removed, false if it doesn't exist.
	 * @throws	\Temma\Exceptions\Application	If the configuration doesn't allow to remove a task (SQS transport).
	 * @throws	\Exception	If the storage or the message queue can't be updated.
	 */
	public function removeTask(int|string $taskId) : bool {
		if (!$this->_isValidTaskId($taskId))
			return (false);
		// Beanstalkd transport
		if ($this->_transport instanceof \Temma\Datasources\Beanstalk) {
			if (!$this->_getBeanstalkJobStats($taskId))
				return (false);
			try {
				$this->_transport->remove((string)$taskId);
			} catch (\Pheanstalk\Exception\JobNotFoundException $e) {
				// job removed by another process, or reserved by a worker
				return (false);
			}
			return (true);
		}
		$this->_checkTaskManagement();
		// MySQL storage
		if ($this->_dao)
			return ($this->_dao->remove($this->_dao->criteria()->equal('id', $taskId)) > 0);
		// Redis storage
		$taskId = (string)$taskId;
		if (!$this->_redis->hDel(self::REDIS_TASKS_HASH, $taskId))
			return (false);
		$this->_redis->zRem(self::REDIS_DELAYED_QUEUE_NAME, $taskId);
		foreach ([self::REDIS_QUEUE_NAME, self::REDIS_PROCESSING_QUEUE_NAME, self::REDIS_FAILED_QUEUE_NAME] as $listName)
			$this->_redis->lRem($listName, 0, $taskId);
		$this->_redis->hDel(self::REDIS_CLAIMS_HASH, $taskId);
		return (true);
	}
	/**
	 * Remove all the failed tasks.
	 * @return	int	The number of removed tasks.
	 * @throws	\Temma\Exceptions\Application	If the configuration doesn't allow to remove the failed tasks (SQS transport).
	 * @throws	\Exception	If the storage or the message queue can't be updated.
	 */
	public function purgeFailedTasks() : int {
		// Beanstalkd transport (limited to the number of buried jobs at the beginning)
		if ($this->_transport instanceof \Temma\Datasources\Beanstalk) {
			$count = 0;
			$max = $this->_transport->stats()['buried'] ?? 0;
			for ($i = 0; $i < $max; $i++) {
				$job = $this->_transport->peekBuried();
				if (!$job)
					break;
				try {
					$this->_transport->remove($job['id']);
					$count++;
				} catch (\Pheanstalk\Exception\JobNotFoundException $e) {
					// already removed by another process
				}
			}
			return ($count);
		}
		$this->_checkTaskManagement();
		// MySQL storage
		if ($this->_dao)
			return ($this->_dao->remove($this->_getSqlStateCriteria('failed')));
		// Redis storage
		$count = 0;
		foreach (array_unique($this->_redis->lRange(self::REDIS_FAILED_QUEUE_NAME)) as $taskId) {
			if (!is_string($taskId) || !$this->_redis->lRem(self::REDIS_FAILED_QUEUE_NAME, 0, $taskId))
				continue;
			// the task is removed only if it is still failed (it may have been retried)
			$task = $this->_redis->hGet(self::REDIS_TASKS_HASH, $taskId);
			if (($task['status'] ?? null) == 'error') {
				$this->_redis->hDel(self::REDIS_TASKS_HASH, $taskId);
				$count++;
			}
		}
		return ($count);
	}
	/**
	 * Recover the tasks whose processing has started for a long time (their worker has probably crashed).
	 * If retries are enabled, the interrupted processing counts as an attempt: a new attempt is planned (with the
	 * configured delay), or the task is put aside if the maximum number of attempts is reached (the error message is
	 * INTERRUPTED_ERROR). Otherwise, the tasks are put back in the queue. The tasks reserved but not started (MySQL
	 * storage) are put back in the queue without counting an attempt.
	 * With Beanstalkd and SQS transports, this method is not available: tasks are automatically delivered again after
	 * their reservation or visibility timeout.
	 * @param	int	$minutes	Number of minutes since the processing start (1 at least). It must be greater than the
	 *					maximum processing duration of a task, otherwise a task could be processed twice.
	 * @return	int	The number of recovered tasks.
	 * @throws	\Temma\Exceptions\Application	If the configuration doesn't allow to recover tasks (Beanstalkd and SQS transports).
	 * @throws	\Exception	If the storage can't be updated.
	 */
	public function recoverTasks(int $minutes) : int {
		if (in_array($this->_transportClass, self::TRANSPORT_QUEUES))
			throw new TµApplicationException('Tasks are automatically recovered by the message queue.', TµApplicationException::API);
		$minutes = max(1, $minutes);
		// MySQL storage
		if ($this->_dao)
			return ($this->_recoverSqlTasks($minutes));
		// Redis storage
		$limitTime = time() - ($minutes * 60);
		$count = 0;
		foreach (array_unique($this->_redis->lRange(self::REDIS_PROCESSING_QUEUE_NAME)) as $taskId) {
			if (!is_string($taskId))
				continue;
			$task = $this->_redis->hGet(self::REDIS_TASKS_HASH, $taskId);
			// task removed: the identifier is removed from the processing list
			if (!is_array($task)) {
				$this->_redis->lRem(self::REDIS_PROCESSING_QUEUE_NAME, 0, $taskId);
				continue;
			}
			if ((($task['status'] ?? null) != 'processing') ||
			    (isset($task['dateStart']) && (strtotime($task['dateStart']) >= $limitTime)))
				continue;
			// the identifier is removed from the processing list (if another process did it, the task is ignored)
			if (!$this->_redis->lRem(self::REDIS_PROCESSING_QUEUE_NAME, 0, $taskId))
				continue;
			$newTask = $task;
			unset($newTask['dateStart']);
			$delay = ($this->_retries === null) ? 0 : $this->_getRecoveryDelay((int)($task['attempts'] ?? 0));
			if ($this->_retries !== null)
				$newTask['error'] = self::INTERRUPTED_ERROR;
			if ($delay === null) {
				// failed task
				$newTask['status'] = 'error';
				$newTask['dateFailure'] = date('c');
				$this->_redis->lPush(self::REDIS_FAILED_QUEUE_NAME, $taskId);
			} else if (!$delay) {
				// task put back in the queue
				$newTask['status'] = 'waiting';
				$this->_redis->lPush(self::REDIS_QUEUE_NAME, $taskId);
			} else {
				// new attempt planned
				$newTask['status'] = 'waiting';
				$newTask['dateNextAttempt'] = date('c', time() + $delay);
				$this->_redis->zAdd(self::REDIS_DELAYED_QUEUE_NAME, time() + $delay, $taskId);
			}
			$this->_redis->hSet(self::REDIS_TASKS_HASH, $taskId, $newTask);
			$this->_redis->hDel(self::REDIS_CLAIMS_HASH, $taskId);
			$count++;
		}
		return ($count);
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Return the delay before the next attempt, after a failure.
	 * @param	int	$attempts	Number of started attempts (the failed attempt included).
	 * @return	?int	Number of seconds, or null if no new attempt must be done.
	 */
	private function _getRetryDelay(int $attempts) : ?int {
		return ($this->_retries[$attempts - 1] ?? null);
	}
	/**
	 * Return the delay before the next attempt, after an interrupted processing.
	 * @param	int	$attempts	Number of started attempts (the interrupted attempt included). Zero if the
	 *					attempts were not counted.
	 * @return	?int	Number of seconds (0 to process the task immediately), or null if no new attempt must be done.
	 */
	private function _getRecoveryDelay(int $attempts) : ?int {
		if ($attempts < 1)
			return (0);
		return ($this->_getRetryDelay($attempts));
	}
	/**
	 * Send a task identifier to the notification transport (xinetd), if there is one.
	 * @param	null|int|string	$taskId		Task identifier.
	 * @param	bool		$throwError	(optional) False to log a WARN message instead of throwing an exception
	 *						if xinetd can't be reached (true by default).
	 * @throws	\Exception	If xinetd can't be reached (and if $throwError is true).
	 */
	private function _notifyTask(null|int|string $taskId, bool $throwError=true) : void {
		if (!in_array($this->_transportClass, self::TRANSPORT_NOTIFY))
			return;
		try {
			$this->_transport->set('', $taskId);
			if (method_exists($this->_transport, 'disconnect'))
				$this->_transport->disconnect();
		} catch (\Exception $e) {
			if ($throwError)
				throw $e;
			TµLog::log('Temma/Asynk', 'WARN', "Unable to notify the task '$taskId' to xinetd (it will be processed by the crontab): " . $e->getMessage());
		}
	}
	/**
	 * Check that the configuration allows to manage tasks.
	 * @throws	\Temma\Exceptions\Application	If the tasks can't be managed (SQS transport).
	 */
	private function _checkTaskManagement() : void {
		if ($this->_transport instanceof \Temma\Datasources\Sqs)
			throw new TµApplicationException("SQS messages can't be managed by Asynk.", TµApplicationException::API);
	}
	/**
	 * Check a task state.
	 * @param	?string	$state	The state to check (null is accepted).
	 * @throws	\Temma\Exceptions\Application	If the state is not valid.
	 */
	private function _checkState(?string $state) : void {
		if (($state !== null) && !in_array($state, self::STATES))
			throw new TµApplicationException("Unknown task state '$state'.", TµApplicationException::BAD_PARAM);
	}
	/**
	 * Check the format of a task identifier.
	 * @param	int|string	$taskId	Task identifier.
	 * @return	bool	True if the identifier has a valid format for the configuration.
	 */
	private function _isValidTaskId(int|string $taskId) : bool {
		$taskId = (string)$taskId;
		if ($this->_transport instanceof \Temma\Datasources\Beanstalk)
			return (\Temma\Datasources\Beanstalk::isValidId($taskId));
		if ($this->_dao)
			return (ctype_digit($taskId) && (strlen($taskId) <= 20));
		if ($this->_redis)
			return ((bool)preg_match('/^\d+\.\d{6}-[0-9A-Za-z]+$/', $taskId));
		return (true);
	}
	/**
	 * Fetch the next message of a queue (Beanstalkd or SQS transport).
	 * Invalid messages, and messages received too many times when retries are enabled, are put aside: Beanstalkd jobs
	 * are buried; SQS messages are hidden during 12 hours (the dead-letter queue, if any, gets them after their maximum
	 * number of receptions).
	 * @return	?array	Task data, or null.
	 */
	private function _getNextQueueTask() : ?array {
		while (true) {
			$message = $this->_transport[''];
			if (!($message['id'] ?? null))
				return (null);
			$task = $message['data'] ?? null;
			if (!is_array($task) || !is_string($task['target'] ?? null) || !is_string($task['action'] ?? null)) {
				TµLog::log('Temma/Asynk', 'WARN', "Invalid message '{$message['id']}', put aside without processing.");
				$this->_putAsideQueueMessage($message['id']);
				continue;
			}
			$task['id'] = $message['id'];
			// number of attempts: number of receptions (SQS) or of reservations (Beanstalkd)
			if ($this->_transport instanceof \Temma\Datasources\Sqs)
				$task['attempts'] = $message['receiveCount'] ?? 1;
			else if ($this->_retries !== null)
				$task['attempts'] = $this->_getBeanstalkJobStats($message['id'])['reserves'] ?? 1;
			if (($this->_retries === null) || ($task['attempts'] <= (count($this->_retries) + 1)))
				return ($task);
			// too many attempts (the previous processings were interrupted, or the SQS message comes back after its
			// last failure): the message is put aside without processing
			TµLog::log('Temma/Asynk', 'WARN', "Message '{$message['id']}' received {$task['attempts']} times, put aside without processing.");
			$this->_putAsideQueueMessage($message['id']);
		}
	}
	/**
	 * Put aside a message received from a queue, without processing it: a Beanstalkd job is buried, an SQS message is
	 * hidden during 12 hours.
	 * @param	string	$messageId	Message identifier.
	 */
	private function _putAsideQueueMessage(string $messageId) : void {
		try {
			if ($this->_transport instanceof \Temma\Datasources\Beanstalk)
				$this->_transport->bury($messageId);
			else if ($this->_transport instanceof \Temma\Datasources\Sqs)
				$this->_transport->changeVisibility($messageId, \Temma\Datasources\Sqs::MAX_VISIBILITY_TIMEOUT);
		} catch (\Exception $e) {
			TµLog::log('Temma/Asynk', 'WARN', "Unable to put aside the message '$messageId': " . $e->getMessage());
		}
	}
	/**
	 * Recover the tasks of the MySQL storage whose processing has started for a long time (see recoverTasks()).
	 * @param	int	$minutes	Number of minutes since the processing start.
	 * @return	int	The number of recovered tasks.
	 */
	private function _recoverSqlTasks(int $minutes) : int {
		// the date of the database server is used, because the update date is set by the database server (the
		// computation is done in UTC, to avoid the effects of daylight saving time changes)
		$now = $this->_dao->getDataBase()->queryOne('SELECT CURRENT_TIMESTAMP AS now', 'now');
		$limitDate = gmdate('Y-m-d H:i:s', strtotime("$now UTC") - ($minutes * 60));
		$processingCriteria = fn() => $this->_getSqlStateCriteria('processing')->lessThan('dateUpdate', $limitDate);
		// without retries, the tasks are put back in the queue
		if ($this->_retries === null) {
			return ($this->_dao->update($processingCriteria(), [
				'token'  => null,
				'status' => 'waiting',
			]));
		}
		// with retries, a new attempt is planned, or the task is put aside
		$count = 0;
		foreach ($this->_dao->search($processingCriteria()) as $task) {
			// reserved but not started tasks: the attempt was not counted
			$delay = (($task['status'] ?? null) == 'reserved') ? 0 : $this->_getRecoveryDelay((int)($task['attempts'] ?? 0));
			$data = [
				'token'  => null,
				'status' => 'error',
				'error'  => self::INTERRUPTED_ERROR,
			];
			if ($delay !== null) {
				$data['status'] = 'waiting';
				$data['dateNextAttempt'] = $delay ? gmdate('Y-m-d H:i:s', time() + $delay) : null;
			}
			// the task is updated only if it was not recovered by another process
			$count += $this->_dao->update($processingCriteria()->equal('id', $task['id']), $data);
		}
		return ($count);
	}
	/**
	 * Reserve a task of the Redis storage (atomic operation), and start its processing: its status, processing start
	 * date and number of attempts are updated.
	 * @param	string	$taskId	Task identifier.
	 * @return	?array	The task data, or null if the task was already reserved, doesn't exist or is not ready.
	 */
	private function _reserveRedisTask(string $taskId) : ?array {
		if (!$this->_redis->hSetNx(self::REDIS_CLAIMS_HASH, $taskId, time()))
			return (null);
		$task = $this->_redis->hGet(self::REDIS_TASKS_HASH, $taskId);
		if (!is_array($task) || !$this->isTaskReady($task)) {
			$this->_redis->hDel(self::REDIS_CLAIMS_HASH, $taskId);
			return (null);
		}
		unset($task['dateNextAttempt']);
		$task['status'] = 'processing';
		$task['dateStart'] = date('c');
		$task['attempts'] = (int)($task['attempts'] ?? 0) + 1;
		$this->_redis->hSet(self::REDIS_TASKS_HASH, $taskId, $task);
		return ($task);
	}
	/**
	 * End the processing of a Redis task: its identifier is removed from the processing list (and from the waiting list
	 * if it was reserved with reserveTask()), and its reservation is removed.
	 * @param	array	$task	Task data.
	 */
	private function _endRedisProcessing(array $task) : void {
		$taskId = (string)$task['id'];
		$this->_redis->lRem(self::REDIS_PROCESSING_QUEUE_NAME, 1, $taskId);
		// task reserved by xinetd: its identifier is near the beginning of the waiting list (it was just added)
		if ($task['_queued'] ?? false)
			$this->_redis->lRem(self::REDIS_QUEUE_NAME, 1, $taskId);
		$this->_redis->hDel(self::REDIS_CLAIMS_HASH, $taskId);
	}
	/**
	 * Put back a failed or delayed Redis task in the queue (at the end of the list, to be processed as soon as possible).
	 * The number of attempts is reset.
	 * @param	string	$taskId		Task identifier.
	 * @param	bool	$acceptDelayed	True to accept delayed tasks (only failed tasks otherwise).
	 * @return	bool	True if the task was put back in the queue.
	 */
	private function _requeueRedisTask(string $taskId, bool $acceptDelayed) : bool {
		$task = $this->_redis->hGet(self::REDIS_TASKS_HASH, $taskId);
		$status = $task['status'] ?? null;
		$delayed = (($status == 'waiting') && $acceptDelayed && $this->_redis->zRem(self::REDIS_DELAYED_QUEUE_NAME, $taskId));
		if (($status != 'error') && !$delayed) {
			// not a failed task: its identifier is removed from the failed list (it was retried, or doesn't exist)
			$this->_redis->lRem(self::REDIS_FAILED_QUEUE_NAME, 0, $taskId);
			return (false);
		}
		unset($task['dateNextAttempt'], $task['dateFailure'], $task['error']);
		$task['status'] = 'waiting';
		$task['attempts'] = 0;
		$this->_redis->hSet(self::REDIS_TASKS_HASH, $taskId, $task);
		$this->_redis->rPush(self::REDIS_QUEUE_NAME, $taskId);
		$this->_redis->lRem(self::REDIS_FAILED_QUEUE_NAME, 0, $taskId);
		return (true);
	}
	/**
	 * Put back in the Redis queue the delayed tasks whose new attempt date is reached.
	 * The delayed tasks are checked at most every REDIS_DELAYED_CHECK_INTERVAL seconds (REDIS_DELAYED_CHECK_LIMIT tasks
	 * at most). If two processes put back the same task in the queue, it is processed only once (see the reservation).
	 */
	private function _promoteRedisDelayedTasks() : void {
		$now = time();
		if ($now < $this->_nextDelayedCheck)
			return;
		$this->_nextDelayedCheck = $now + self::REDIS_DELAYED_CHECK_INTERVAL;
		$taskIds = $this->_redis->zRangeByScore(self::REDIS_DELAYED_QUEUE_NAME, '-inf', $now, 0, self::REDIS_DELAYED_CHECK_LIMIT);
		foreach ($taskIds as $taskId) {
			if (!is_string($taskId))
				continue;
			// the identifier is added to the queue before its removal from the sorted set, so it can't be lost
			$this->_redis->lPush(self::REDIS_QUEUE_NAME, $taskId);
			$this->_redis->zRem(self::REDIS_DELAYED_QUEUE_NAME, $taskId);
		}
	}
	/**
	 * Return the statistics of a Beanstalkd job, if it belongs to the tube of the transport.
	 * @param	int|string	$taskId	Job identifier.
	 * @return	?array	The statistics (see \Temma\Datasources\Beanstalk::stats()), or null if the job doesn't exist
	 *			or belongs to another tube.
	 */
	private function _getBeanstalkJobStats(int|string $taskId) : ?array {
		if (!($this->_transport instanceof \Temma\Datasources\Beanstalk))
			return (null);
		$stats = $this->_transport->stats((string)$taskId);
		if (!$stats || (($stats['tube'] ?? null) !== $this->_transport->getTube()))
			return (null);
		return ($stats);
	}
	/**
	 * Create again a Beanstalkd job (to reset its counters), and remove the original job.
	 * If the original job can't be removed (it was reserved by a worker in the meantime), the new job is removed.
	 * @param	string	$jobId	Job identifier.
	 * @return	?string	Identifier of the new job, or null if the job was not created again.
	 */
	private function _recreateBeanstalkJob(string $jobId) : ?string {
		if (!($this->_transport instanceof \Temma\Datasources\Beanstalk) || !($job = $this->_transport->peek($jobId)))
			return (null);
		// the new job is created before the removal of the original one, so it can't be lost
		$newJobId = $this->_transport->write('', $job['data'], ['ttr' => self::BEANSTALK_TTR]);
		try {
			$this->_transport->remove($jobId);
		} catch (\Pheanstalk\Exception\JobNotFoundException $e) {
			// the original job was reserved or removed by another process: the new job is removed
			try {
				$this->_transport->remove($newJobId);
			} catch (\Pheanstalk\Exception\JobNotFoundException $e) {
				TµLog::log('Temma/Asynk', 'WARN', "Unable to remove the job '$newJobId'.");
			}
			return (null);
		}
		return ($newJobId);
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
		// dates of next attempt are stored in UTC
		$now = gmdate('Y-m-d H:i:s');
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
		// dates of next attempt are stored in UTC
		$dateNextAttempt = $task['dateNextAttempt'] ?? null;
		$delayed = (($this->_retries !== null) && $dateNextAttempt && ($dateNextAttempt > gmdate('Y-m-d H:i:s')));
		$state = ($status == 'error') ? 'failed' :
		         (in_array($status, ['reserved', 'processing']) ? 'processing' :
		          ($delayed ? 'delayed' : 'waiting'));
		$data = $task['data'] ?? null;
		return ([
			'id'              => $task['id'] ?? null,
			'state'           => $state,
			'dateCreation'    => self::_formatDate($task['dateCreation'] ?? null),
			'dateStart'       => ($state == 'processing') ? self::_formatDate($task['dateUpdate'] ?? null) : null,
			'target'          => $task['target'] ?? null,
			'action'          => $task['action'] ?? null,
			'data'            => is_string($data) ? json_decode($data, true) : $data,
			'attempts'        => isset($task['attempts']) ? (int)$task['attempts'] : null,
			'dateNextAttempt' => ($state == 'delayed') ? self::_formatDate($dateNextAttempt, true) : null,
			'error'           => $task['error'] ?? null,
		]);
	}
	/**
	 * Normalize a task read from the Redis storage.
	 * @param	array	$task	Task data.
	 * @return	array	Normalized task (see getTask()).
	 */
	private function _normalizeRedisTask(array $task) : array {
		$status = $task['status'] ?? null;
		$state = ($status == 'error') ? 'failed' :
		         (($status == 'processing') ? 'processing' :
		          ($this->isTaskReady($task) ? 'waiting' : 'delayed'));
		return ([
			'id'              => $task['id'] ?? null,
			'state'           => $state,
			'dateCreation'    => self::_formatDate($task['creation'] ?? null),
			'dateStart'       => ($state == 'processing') ? self::_formatDate($task['dateStart'] ?? null) : null,
			'target'          => $task['target'] ?? null,
			'action'          => $task['action'] ?? null,
			'data'            => $task['data'] ?? null,
			'attempts'        => (int)($task['attempts'] ?? 0),
			'dateNextAttempt' => ($state == 'delayed') ? self::_formatDate($task['dateNextAttempt'] ?? null) : null,
			'error'           => $task['error'] ?? null,
		]);
	}
	/**
	 * Normalize a Beanstalkd job.
	 * @param	array	$job	Job data (keys "id" and "data").
	 * @param	array	$stats	Job statistics.
	 * @return	array	Normalized task (see getTask()).
	 */
	private function _normalizeBeanstalkTask(array $job, array $stats) : array {
		$task = json_decode($job['data'], true);
		$task = is_array($task) ? $task : [];
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
			'dateCreation'    => self::_formatDate($task['creation'] ?? null),
			'dateStart'       => null,
			'target'          => $task['target'] ?? null,
			'action'          => $task['action'] ?? null,
			'data'            => $task['data'] ?? null,
			'attempts'        => $stats['reserves'] ?? null,
			'dateNextAttempt' => ($state == 'delayed') ? date('Y-m-d H:i:s', time() + ($stats['timeLeft'] ?? 0)) : null,
			'error'           => null,
		]);
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
	 * Store a task in Redis: the task is added to the hash of tasks, and its identifier to the waiting list.
	 * @param	string	$target	Name of the object to execute.
	 * @param	string	$action	Name of the method to execute.
	 * @param	array	$params	Parameters list.
	 * @return	string	Task identifier.
	 */
	private function _storeTaskRedis(string $target, string $action, array $params) : string {
		$taskId = self::_generateTaskId();
		$this->_redis->hSet(self::REDIS_TASKS_HASH, $taskId, [
			'id'       => $taskId,
			'creation' => date('c'),
			'target'   => $target,
			'action'   => $action,
			'status'   => 'waiting',
			'attempts' => 0,
			'data'     => $params,
		]);
		$this->_redis->lPush(self::REDIS_QUEUE_NAME, $taskId);
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
		], ['ttr' => self::BEANSTALK_TTR]);
	}
	/**
	 * Format a date to the "YYYY-MM-DD HH:MM:SS" format, in the local time zone.
	 * @param	?string	$date	The date (any format accepted by strtotime()).
	 * @param	bool	$utc	(optional) True if the date is in UTC and has no time zone information.
	 * @return	?string	The formatted date, or null.
	 */
	static private function _formatDate(?string $date, bool $utc=false) : ?string {
		if (!$date || (($time = strtotime($utc ? "$date UTC" : $date)) === false))
			return ($date ?: null);
		return (date('Y-m-d H:i:s', $time));
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
	 * Return the name of the Redis list (or sorted set) containing the tasks in a given state.
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
	 * @param	mixed	$retries	Configuration value: true for the default delays, false or null to disable retries,
	 *					or a list of delays (integers in seconds, or strings with an optional unit:
	 *					"30s", "15m", "4h", "2d"; case-insensitive).
	 * @return	?array	List of delays in seconds, or null if retries are not enabled.
	 * @throws	\Temma\Exceptions\Framework	If the configuration is not valid.
	 */
	static private function _parseRetries(mixed $retries) : ?array {
		if (($retries === null) || ($retries === false))
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
			if (is_int($delay) && ($delay >= 0)) {
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
