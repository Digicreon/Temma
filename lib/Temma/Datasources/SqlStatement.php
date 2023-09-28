<?php

/**
 * SqlStatement
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2020-2023, Amaury Bouchard
 */

namespace Temma\Datasources;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Database as TµDatabaseException;

/**
 * Database statement object.
 *
 * Object used to manage prepared queries.
 * It is a wrapper on PDOStatement object.
 */
class SqlStatement {
	/** \Temma\Base\Database object. */
	protected \Temma\Datasources\Sql $_db;
	/** PDOStatement object. */
	protected \PDOStatement $_statement;

	/* ********** CONSTRUCTION ********** */
	/**
	 * Constructor. Should be used only by \Temma\Base\Database object.
	 * @param	\Temma\Datasources\Sql	$db		The datbase object.
	 * @param	\PDOStatement 			$statement	The PDOStatement object.
	 */
	public function __construct(\Temma\Datasources\Sql $db, \PDOStatement $statement) {
		$this->_db = $db;
		$this->_statement = $statement;
	}
	/** Destructor. */
	public function __destruct() {
		unset($this->_statement);
	}

	/* ********** REQUESTS ********** */
	/**
	 * Executes a prepared query without fetching data.
	 * @param	?array	$parameters	(optional) Array of parameters.
	 * @return	int	The number of modified lines.
	 * @throws	\Temma\Exceptions\Database	If an error occurs.
	 */
	public function exec(?array $parameters=null) : int {
		if (!$this->_statement->execute($parameters)) {
			$err = $this->_statement->errorCode();
			throw new TµDatabaseException($err, TµDatabaseException::QUERY);
		}
		return ($this->_statement->rowCount());
	}
	/**
	 * Executes a prepared SQL query and fetch one line of data.
	 * @param	?array	$parameters	(optional) Array of parameters.
	 * @param	?string	$valueField	(optional) Name of the field whose value will be returned.
	 * @return	array	An associative array which contains the line of data.
	 * @throws	\Temma\Exceptions\Database	If an error occurs.
	 */
	public function queryOne(?array $parameters=null, ?string $valueField=null) : array {
		if (!$this->_statement->execute($parameters)) {
			$err = $this->_statement->errorCode();
			throw new TµDatabaseException($err, TµDatabaseException::QUERY);
		}
		$line = $this->_statement->fetch(\PDO::FETCH_ASSOC);
		$line = is_array($line) ? $line : [];
		if ($valueField)
			return ($line[$valueField] ?? null);
		return ($line);
	}
	/**
	 * Executes a prepared query and fetch all lines of returned data
	 * @param	?array	$parameters	(optional) Array of parameters.
	 * @param	?string	$keyField	(optional) Name of the field that must be used as the key for each record.
	 * @param	?string	$valueField	(optional) Name of the field that will be used as value for each record.
	 * @return	array	An array of associative arrays, or an array of values.
	 * @throws	\Temma\Exceptions\Database	If an error occurs.
	 */
	public function queryAll(?array $parameters=null, ?string $keyField=null, ?string $valueField=null) : array {
		if (!$this->_statement->execute($parameters)) {
			$err = $this->_statement->errorCode();
			throw new TµDatabaseException($err, TµDatabaseException::QUERY);
		}
		$lines = $this->_statement->fetchAll(\PDO::FETCH_ASSOC);
		if ($keyField || $valueField)
			$lines = array_column($lines, $valueField, $keyField);
		return ($lines);
	}
	/**
	 * Return the error code of the last execution.
	 * @return	string	An SQLSTATE code.
	 */
	public function errorCode() : string {
		return ($this->_statement->errorCode());
	}
	/**
	 * Return detailed informantion about the last error.
	 * @return	array	An array with three elements: An SQLSTATE error code, a driver-specific error code,
	 *			and a driver-specific error message.
	 */
	public function errorInfo() : array {
		return ($this->_statement->errorInfo());
	}
}

