<?php

/**
 * AsynkDao
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 */

namespace Temma\Asynk;

use \Temma\Base\Log as TµLog;
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
	/** Transport data source. */
	protected ?\Temma\Base\Datasource $_transport = null;
	/** Storage data source. */
	protected ?\Temma\Base\Datasource $_storage = null;
	/** Transport class name. */
	protected ?string $_transportClass = null;
	/** Storage class name. */
	protected ?string $_storageClass = null;
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
		$storageClass = $storageObject ? get_class($storageObject) : null;
		$transportClass = $transportObject ? get_class($transportObject) : null;
		// checks
		if ((!$transport && in_array($storageClass, self::STORAGE_NO_TRANSPORT)) ||
		    (in_array($transportClass, self::TRANSPORT_NOTIFY) && in_array($storageClass, self::STORAGE_NOTIFIED_TRANSPORT)) ||
		    (in_array($transportClass, self::TRANSPORT_QUEUES) && !$storage)) {
			$this->_storage = $storageObject;
			$this->_transport = $transportObject;
			$this->_storageClass = $storageClass;
			$this->_transportClass = $transportClass;
		} else {
			throw new TµFrameworkException("Invalid storage '$storage' with '$transport' transport.", TµFrameworkException::CONFIG);
		}
		if ($storageClass == 'Temma\Datasources\Sql') {
			$daoClass = $loader->config->xtra('asynk', 'dao', '\Temma\Dao\Dao');
			$dbName = $loader->config->xtra('asynk', 'base');
			$tableName = $loader->config->xtra('asynk', 'table', 'Task');
			$idField = $loader->config->xtra('asynk', 'id', 'id');
			$statusField = $loader->config->xtra('asynk', 'status');
			$tokenField = $loader->config->xtra('asynk', 'token');
			$targetField = $loader->config->xtra('asynk', 'target');
			$actionField = $loader->config->xtra('asynk', 'action');
			$dataField = $loader->config->xtra('asynk', 'data');
			$fields = [];
			if ($statusField)
				$fields[$statusField] = 'status';
			if ($tokenField)
				$fields[$tokenField] = 'token';
			if ($targetField)
				$fields[$targetField] = 'target';
			if ($actionField)
				$fields[$actionField] = 'action';
			if ($dataField)
				$fields[$dataField] = 'data';
			$this->_dao = new $daoClass($storageObject, null, $tableName, $idField, $dbName, ($fields ?: null));
		}
	}
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
		} else {
			throw new TµFrameworkException("Unknown Asynk storage '{$this->_storageClass}'.", TµFrameworkException::CONFIG);
		}
		// transport
		if (!$this->_transport) {
			// crontab
			return;
		}
		if (in_array($this->_transportClass, self::TRANSPORT_NOTIFY)) {
			// (x)inetd
			$this->_transport->set('', $taskId);
			if (method_exists($this->_transport, 'disconnect'))
				$this->_transport->disconnect();
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
	 * For a Beanstalkd transport, to function will block until a message is available.
	 * @return	?array	Associative array or null.
	 */
	public function getNextTask() : ?array {
		// Beanstalkd or SQS transport
		if (in_array($this->_transportClass, self::TRANSPORT_QUEUES)) {
			$task = $this->_transport[''];
			if (!($task['id'] ?? null) || !($task['data'] ?? null))
				return (null);
			$task['data']['id'] = $task['id'];
			return ($task['data']);
		}
		// MySQL storage
		if ($this->_dao) {
			// reserve a task
			$token = bin2hex(random_bytes(8));
			$nbr = $this->_dao->update(
				$this->_dao->criteria()->equal('token', null)
				                       ->equal('status', 'waiting'),
				[
					'token'  => $token,
					'status' => 'reserved',
				],
				sort: 'id',
				limit: 1,
			);
			if (!$nbr)
				return (null);
			// get the task
			$result = $this->_dao->search(
				$this->_dao->criteria()->equal('token', $token),
				sort: 'id',
				limitOffset: 0,
				nbrLimit: 1,
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
		// Redis queue
		$task = $this->_storage->rPopLPush(self::REDIS_QUEUE_NAME, self::REDIS_PROCESSING_QUEUE_NAME);
		return ($task);
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
		$this->_storage->lRem(self::REDIS_PROCESSING_QUEUE_NAME, 1, $task);
	}
	/**
	 * Invalidate a task fetched with getNextTask().
	 * @param	array	$task	Task data.
	 */
	public function invalidateFetchedTask(array $task) : void {
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

