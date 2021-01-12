<?php

/**
 * DatabaseStatement
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2020, Amaury Bouchard
 */

namespace Temma\Base;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Database as TµDatabaseException;

/**
 * Database statement object.
 *
 * Object used to manage prepared queries.
 * It is a wrapper on PDOStatement object.
 */
class DatabaseStatement {
	/** \Temma\Base\Database object. */
	protected $_db = null;
	/** PDOStatement object. */
	protected $_statement = null;

	/* ************************ CONSTRUCTION ********************** */
	/**
	 * Constructor. Should be used only by \Temma\Base\Database object.
	 * @param	\Temma\Base\Database	$db		The datbase object.
	 * @param	\PDOStatement 		$statement	The PDOStatement object.
	 */
	public function __construct(\Temma\Base\Database $db, \PDOStatement $statement) {
		$this->_db = $db;
		$this->_statement = $statement;
	}
	/** Destructor. */
	public function __destruct() {
		unset($this->_statement);
	}

	/* ********************** REQUESTS *********************** */
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
	 * @return	array	An associative array which contains the line of data.
	 * @throws	\Temma\Exceptions\Database	If an error occurs.
	 */
	public function queryOne() : array {
		if (!$this->_statement->execute($parameters)) {
			$err = $this->_statement->errorCode();
			throw new TµDatabaseException($err, TµDatabaseException::QUERY);
		}
		return ($this->_statement->fetchAll(PDO::FETCH_ASSOC));
	}
	/**
	 * Executes a prepared query and fetch all lines of returned data
	 * @param	?array	$parameters	(optional) Array of parameters.
	 * @return	bool	True if everything was fine, false otherwise.
	 * @throws	\Temma\Exceptions\Database	If an error occurs.
	 */
	public function queryAll(?array $parameters=null) : bool {
		if (!$this->_statement->execute($parameters)) {
			$err = $this->_statement->errorCode();
			throw new TµDatabaseException($err, TµDatabaseException::QUERY);
		}
		return ($this->_statement->fetchAll(PDO::FETCH_ASSOC));
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

