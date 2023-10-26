<?php

/**
 * AsynkDao
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 */

namespace Temma\Asynk;

use \Temma\Exceptions\Framework as TµFrameworkException;

/**
 * DAO for asynchronous procesings.
 * Must be called only by Asynk objects and scripts.
 */
class AsynkDao implements \Temma\Base\Loadable {
	/** Constant: Name of the Redis queue. */
	const REDIS_QUEUE_NAME = 'asynk';
	/** Constant: Name of the Redis queue used for processing tasks. */
	const REDIS_PROCESSING_QUEUE_NAME = 'asynk_processing';
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
	/** Constant: message queue storage objects. */
	const STORAGE_QUEUES = [
		'Temma\Datasources\Sql',
		'Temma\Datasources\Redis',
		'Temma\Datasources\Sqs',
		'Temma\Datasources\Beanstalk',
	];
	/** Transport data source. */
	protected ?\Temma\Base\Datasource $_transport = null;
	/** Storage data source. */
	protected ?\Temma\Base\Datasource $_storage = null;
	/** SQL DAO. */
	protected ?\Temma\Dao\Dao $_dao = null;

	/**
	 * Constructor.
	 * @param	\Temma\Base\Loader	$loader	Loader.
	 * @throws	\Temma\Exceptions\Framework	If the configuration is not valid.
	 */
	public function __construct(\Temma\Base\Loader $loader) {
		// read configuration
		$storage = $loader->config->xtra('asynk', 'storage');
		$transport = $loader->config->xtra('asynk', 'transport');
		$storageObject = ($storage && isset($loader->dataSources[$storage])) ? $loader->dataSources[$storage] : null;
		$transportObject = ($transport && isset($loader->dataSources[$transport])) ? $loader->dataSources[$transport] : null;
		$storageClass = $this->_storage ? get_class($storageObject) : null;
		$transportClass = $this->_transport ? get_class($transportObject) : null;
		// checks
		if ((!$transport && in_array($storageClass, self::STORAGE_NO_TRANSPORT)) ||
		    (in_array($transport, self::TRANSPORT_NOTIFY) && in_array($storage, self::STORAGE_NOTIFIED_TRANSPORT)) ||
		    (in_array($transport, self::TRANSPORT_QUEUES) && in_array($storage, self::STORAGE_QUEUES))) {
			$this->_storage = $storageObject;
			$this->_transport = $transportObject;
		} else {
			throw new TµFrameworkException("Invalid storage '$storage' with '$transport' transport.", TµFrameworkException::CONFIG);
		}
		if ($storageClass == 'Temma\Datasources\Sql') {
			$daoClass = $loader->config->xtra('asynk', 'dao', '\Temma\Dao\Dao');
			$tableName = $loader->config->xtra('asynk', 'table', 'Task');
			$idField = $loader->config->xtra('asynk', 'id', 'id');
			$dbName = $loader->config->xtra('asynk', 'base');
			$fields = $loader->config->xtra('asynk', 'fields');
			$this->_dao = new $daoClass($storageObject, null, $tableName, $idField, $dbName, $fields);
		}
	}
	/**
	 * Process a task.
	 * @param	string	$target	Name of the object to execute.
	 * @param	string	$action	Name of the method to execute.
	 * @param	array	$params	Parameters list.
	 */
	public function createTask(string $target, string $action, array $params) : void {
		$transportClass = $this->_transport ? get_class($this->_transport) : null;
		$storageClass = get_class($this->_storage);
		$taskId = null;
		// storage
		if ($storageClass == 'Temma\Datasources\Sql') {
			// MySQL storage
			$taskId = $this->_storeTaskSql($target, $action, $params);
		} else if (!$transportClass && $storageClass == 'Temma\Datasources\Redis') {
			// Redis queue (Redis storage and no transport)
			$this->_storeTaskRedisQueue($target, $action, $params);
		} else if (!in_array($storageClass, self::TRANSPORT_QUEUES)) {
			// key-value storage (Redis)
			$taskId = $this->_storeTaskKeyValue($target, $action, $params);
		}
		// transport
		if (!$transportClass) {
			// cron
			return;
		}
		if (in_array($transportClass, self::TRANSPORT_NOTIFY)) {
			// (x)inetd
			$this->_transport->set('', $taskId);
			if (method_exists($this->_transport, 'disconnect'))
				$this->_transport->disconnect();
		} else if (in_array($transportClass, self::TRANSPORT_QUEUES)) {
			// SQS or Beanstalk
			if ($storageClass) {
				// send task ID
				$this->_transport->write('', $taskId);
			} else {
				// send full task
				$this->_transportQueue($target, $action, $params);
			}
		}
	}
	/**
	 * Get a task from its ID for processing.
	 * Should be called only by Asynk clients.
	 * @param	int|string	$taskId	Task identifier.
	 * @return	?array	Associative array.
	 */
	public function getTaskForProcessing(int|string $taskId) : ?array {
		$storageClass = get_class($this->_storage);
		if ($this->_dao) {
			/* MySQL storage */
			// reserve the task
			$token = bin2hex(random_bytes(4));
			$nbr = $this->_dao->update(
				$this->_dao->criteria()->equal('id', $taskId)
				                       ->equal('status', 'waiting'),
				[
					'token'  => $token,
					'status' => 'processing',
				],
			);
			if (!$nbr)
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
		/* Redis storage */
		// fetch the task
		$task = $this->_storage[$taskId];
		if (($task['status'] ?? null) != 'waiting')
			return (null);
		// update task's status
		$task['status'] = 'processing';
		$this->_storage[$taskId] = $task;
		return ($task);
	}
	/**
	 * Reserve tasks.
	 * @param	int	$nbr	(optional) Maximum number of tasks to reserve. (defaults to 10)
	 * @return	array	Array of two values, the reservation token and the number of reserved tasks.
	 */
	public function reserveTasks(int $nbr=10) : array {
		if (!$this->_storage)
			return ([null, null]);
		if ($this->_dao) {
			/* MySQL storage */
			$token = bin2hex(random_bytes(4));
			$nbr = $this->_dao->update(
				$this->_dao->criteria()->equal('token', null)
				                       ->equal('status', 'waiting'),
				[
					'token'  => $token,
					'status' => 'reserved',
				],
			);
			return ([$token, $nbr]);
		}
		// Redis queue (Redis storage and no transport)
		$len = $this->_storage->lLen(self::REDIS_QUEUE_NAME);
		return (['', $len]);
	}
	/**
	 * Returns the next reserved task.
	 * @param	string	$token		Reservation token.
	 * @param	?array	$exceptIds	(optional) List of unwanted identifiers. (defaults to null)
	 * @return	?array	Associative array or null.
	 */
	public function getNextReservedTask(string $token, ?array $exceptIds=null) : ?array {
		if ($this->_dao) {
			/* MySQL storage */
			// get the task
			$result = $this->_dao->search(
				$this->_dao->criteria()->equal('token', $token),
				'id',
				0,
				1,
			);
			$task = $result[0] ?? null;
			if (($task['data'] ?? null))
				$task['data'] = json_decode($task['data'], true);
			// update the task's status
			$this->_dao->update($task['id'], ['status' => 'processing']);
			return ($task);
		}
		// Redis queue
		$task = $this->_storage->rPopLPush(self::REDIS_QUEUE_NAME, self::REDIS_PROCESSING_QUEUE_NAME);
		return ($task);
	}
	/**
	 * Remove a reserved task.
	 * @param	array	$task	Task informations.
	 */
	public function removeReservedTask(array $task) : void {
		if ($this->_dao) {
			// MySQL storage
			$this->_dao->remove($task['id']);
			return;
		}
		// Redis queue
		$this->_storage->lRem(self::REDIS_PROCESSING_QUEUE_NAME, 1, $task);
	}
	/**
	 * Invalidate a reserved task.
	 * @param	array	$task	Task data.
	 */
	public function invalidateReservedTask(array $task) : void {
		if ($this->_dao) {
			// MySQL storage
			$this->_dao->update($task['id'], ['status' => 'error']);
			return;
		}
		// Redis queue => do nothing
	}
	/**
	 * Set the status of a task.
	 * @param	int|string	$taskId	Task identifier.
	 * @param	string		$status	Task status.
	 */
	public function setTaskStatus(int|string $taskId, string $status) : void {
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
	public function removeTask(int|string $taskId) : void {
		if ($this->_dao) {
			/* MySQL storage */
			$this->_dao->remove($taskId);
			return;
		}
		/* Redis storage */
		unset($this->_storage[$taskId]);
	}

	/* ********** PRIVATE METHODS ********** */
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
			'status'       => 'status',
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
		$this->_storage->lPush(self::REDIS_QUEUE_NAME, [
			'creation' => date('c'),
			'target'   => $target,
			'action'   => $action,
			'status'   => 'waiting',
			'data'     => $params,
		]);
	}
	/**
	 * Store a task in a key-value data source.
	 * @param	string	$target	Name of the object to execute.
	 * @param	string	$action	Name of the method to execute.
	 * @param	array	$params	Parameters list.
	 * @return	string	Task identifier.
	 */
	private function _storeTaskKeyValue(string $target, string $action, array $params) : string {
		$f = microtime(true);
		$s = bin2hex(random_bytes(4));
		$s = \Temma\Utils\BaseConvert::convert($s, 16, 62);
		$taskId = sprintf('%.06f-%s', $f, $s);
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
}

