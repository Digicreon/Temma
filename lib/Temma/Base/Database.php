<?php

/**
 * Database
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2023, Amaury Bouchard
 */

namespace Temma\Base;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Database as TµDatabaseException;

/**
 * Database connexion object.
 *
 * Connection setting is done using a DSN string:
 * <pre>type://login:password@host/base</pre>
 * Examples:
 * <ul>
 *   <li><tt>mysql://user:pwd@localhost/database</tt></li>
 *   <li><tt>pgsql://user:pwd@localhost/database</tt></li>
 *   <li><tt>sqlite:/path/to/file.sq3</tt></li>
 * </ul>
 *
 * For a MySQL connection using Unix socket:
 * <pre>mysql://login:password@localhost/database#path.sock</pre>
 * Example: <tt>mysql://user:pwd@localhost/database#/var/run/mysqld/mysqld.sock</tt>
 *
 * Simple example:
 * <code>
 * try {
 *     // object creation, database connection
 *     $db = new \Temma\Base\Database("mysql://user:pwd@localhost/database");
 *     // simple request
 *     $db->exec("DELETE FROM Bar");
 *     // request which fetch one line of data
 *     $result = $db->queryOne("SELECT COUNT(*) AS nbr FROM Foo");
 *     print($result['nbr']);
 *     // request which fetch many lines of data
 *     $result = $db->queryAll("SELECT id, name FROM Foo");
 *     // display results
 *     foreach ($result as $line)
 *         print($line['id'] . " -> " . $line['name'] . "\n");
 * } catch (Exception $e) {
 *     print("Erreur base de données: " . $e->getMessage());
 * }
 * </code>
 *
 * Prepared queries:
 * <code>
 * // values given in a list
 * $stmt = $db->prepare("UPDATE toto SET color = ? WHERE id = ?");
 * $stmt->execute(['blue', 12]);
 * // values given in an associative array
 * $stmt = $db->prepare("UPDATE toto SET color = :color WHERE id = :id");
 * $stmt->execute([':color' => 'blue', ':id' => 12]);
 * // fetch values
 * $stmt = $db->prepare("SELECT * from toto WHERE color = :color");
 * $stmt->execute([':color' => 'blue']);
 * $result = $stmt->fetchAll();
 * $stmt->execute([':color' => 'red']);
 * $result = $stmt->fetchAll();
 * </code>
 *
 * Transactional example:
 * <code>
 * // object creation, database connection
 * try { $db = new \Temma\Base\Database::factory("mysql://user:pwd@localhost/database"); }
 * catch (Exception $e) { }
 * // transactional requests, automatically committed or rolled-back
 * $db->transaction(function($db) use ($userId) {
 *     // insertion request
 *     $db->exec("INSERT INTO Foo SET name = 'pouet'");
 *     // bad request, will raise an exception => roll-back
 *     $db->exec("INSERT foobar");
 * });
 * // other kind of transactional requests
 * try {
 *     // start the transaction
 *     $db->startTransaction();
 *     // insertion request
 *     $db->exec("INSERT INTO Foo SET name = 'pouet'");
 *     // bad request, will raise an exception
 *     $db->exec("INSERT foobar");
 *     // commit of the transaction if everything is fine
 *     $db->commit();
 * } catch (Exception $e) {
 *     // rollback of the transaction
 *     $db->rollback();
 * }
 * </code>
 */
class Database extends \Temma\Base\Datasource {
	/** Database connection objet. */
	protected ?\PDO $_db = null;
	/** Database connection parameters. */
	protected null|string|array $_params = null;
	/** Connection user (PDO compatibility). */
	protected ?string $_login = null;
	/** Connection password (PDO compatibility). */
	protected ?string $_password = null;

	/* ********** CONSTRUCTION ********** */
	/**
	 * Factory. Creates an instance of the object, using the given parameters.
	 * @param	string	$dsn		Database connection string.
	 * @return	\Temma\Base\Database	The created object.
	 * @throws	\Exception	If something went wrong.
	 */
	static public function factory(string $dsn) : \Temma\Base\Database {
		// instance creation
		$instance = new self($dsn);
		return ($instance);
	}
	/**
	 * Constructor. Opens a connection to the database server.
	 * @param	string	$dsn		Database connection string.
	 * @param	string	$login		(optional) User login (for PDO compatibility).
	 * @param	string	$password	(optional) User password (for PDO compatibility).
	 */
	protected function __construct(string $dsn, ?string $login=null, ?string $password=null) {
		TµLog::log('Temma/Base', 'DEBUG', "Database object creation with DSN: '$dsn'.");
		// parameters extraction
		$params = null;
		if (preg_match("/^([^:]+):\/\/([^:@]+):?([^@]+)?@([^\/:]+):?(\d+)?\/([^#]*)#?(.*)$/", $dsn, $matches)) {
			$this->_params = [
				'type'     => $matches[1],
				'login'    => $matches[2],
				'password' => $matches[3],
				'host'     => $matches[4],
				'port'     => $matches[5],
				'base'     => $matches[6],
				'sock'     => $matches[7],
			];
			if ($this->_params['type'] == 'mysqli')
				$this->_params['type'] = 'mysql';
			$login = $matches[2] ?: $login;
			$password = $matches[3] ?: $password;
		} else {
			$this->_params = $dsn;
		}
		$this->_login = $login;
		$this->_password = $password;
	}
	/** Destructor. */
	public function __destruct() {
		//if (isset($this->_db))
		//	$this->_db->close();
	}

	/* ********** CONNECTION / DISCONNECTION ********** */
	/**
	 * Connection.
	 * @throws	\Exception	If the connection failed.
	 */
	protected function _connect() : void {
		if ($this->_db)
			return;
		try {
			$pdoDsn = null;
			$pdoLogin = $this->_login;
			$pdoPassword = $this->_password;
			if (is_array($this->_params)) {
				$pdoDsn = $this->_params['type'] . ':';
				// special process for Oracle
				if ($this->_params['type'] == 'oci')
					$pdoDsn .= 'dbname=//' . $this->_params['host'] . ':' . $this->_params['port'] . '/' . $this->_params['base'];
				else {
					// other databases
					$pdoDsn .= 'dbname=' . $this->_params['base'];
					// host
					if ($this->_params['sock'])
						$pdoDsn .= ';unix_socket=' . $this->_params['sock'];
					else {
						$pdoDsn .= ';host=' . $this->_params['host'];
						if ($this->_params['port'])
							$pdoDsn .= ';port=' . $this->_params['port'];
					}
				}
				$pdoLogin = $this->_params['login'] ?: $pdoLogin;
				$podPassword = $this->_params['password'] ?: $pdoPassword;
			} else if (is_string($this->_params)) {
				$pdoDsn = $this->_params;
			} else
				throw new \Exception("Bad configuration.");
			if ($pdoLogin && $pdoPassword) {
				$this->_db = new \PDO($pdoDsn, $pdoLogin, $pdoPassword);
			} else {
				$this->_db = new \PDO($pdoDsn);
			}
		} catch (\Exception $e) {
			TµLog::log('Temma/Base', 'WARN', "Database connection error: " . $e->getMessage());
			throw $e;
		}
	}
	/** Disconnection. */
	public function close() : void {
		$this->_db = null;
	}
	/**
	 * Definition of the character set.
	 * @param	string	$charset	(optional) Character set to use. "utf8" by default.
	 */
	/*
	public function charset(string $charset='utf8') {
		$this->_connect();
		$this->_db->set_charset($charset);
	}
	*/

	/* ********** TRANSACTIONS ********** */
	/**
	 * Manage a transaction automatically.
	 * @param	callable	$callback	Anonymous function.
	 */
	public function transaction(callable $callback) : void {
		TµLog::log('Temma/Base', 'DEBUG', "Starting a transaction.");
		$this->startTransaction();
		try {
			$callback($this);
		} catch (\Exception $e) {
			$this->rollback();
			return;
		}
		$this->commit();
	}
	/**
	 * Start a transaction.
	 * @throws      \Exception	If it's not possible to start the transaction.
	 */
	public function startTransaction() : void {
		TµLog::log('Temma/Base', 'DEBUG', "Beginning transaction.");
		$this->_connect();
		if ($this->_db->beginTransaction() === false) {
			TµLog::log('Temma/Base', 'ERROR', "Unable to start a new transaction.");
			throw new \Exception("Unable to start a new transaction.");
		}
	}
	/**
	 * Commit a transaction.
	 * @throws      \Exception	If the commit failed.
	 */
	public function commit() : void {
		TµLog::log('Temma/Base', 'DEBUG', "Committing transaction.");
		$this->_connect();
		if ($this->_db->commit() === false) {
			TµLog::log('Temma/Base', 'ERROR', "Error during transaction commit.");
			throw new \Exception("Error during transaction commit.");
		}
	}
	/**
	 * Rollback a transaction.
	 * @throws      \Exception	If the rollback failed.
	 */
	public function rollback() : void {
		TµLog::log('Temma/Base', 'DEBUG', "Rollbacking transaction.");
		$this->_connect();
		if ($this->_db->rollback() === false) {
			TµLog::log('Temma/Base', 'ERROR', "Error during transaction rollback.");
			throw new \Exception("Error during transaction rollback.");
		}
	}

	/* ********** REQUESTS ********** */
	/**
	 * Return the last SQL error.
	 * @return	string	The last error.
	 */
	public function getError() : string {
		$this->_connect();
		$errInfo = $this->_db->errorInfo();
		return ($errInfo[2] ?? '');
	}
	/**
	 * Check if the connection to the database is still working.
	 * @return	bool	True if the connection is working.
	 */
	public function ping() : bool {
		$this->_connect();
		try {
			$this->_db->query('DO 1');
		} catch (\PDOException $e) {
			return (false);
		}
		return (true);
	}
	/**
	 * Escape a character string.
	 * @param	mixed	$str	The string to escape.
	 * @return	string	The escaped string.
	 */
	public function quote(mixed $str) : string {
		$this->_connect();
		if ($str === false)
			return ('\'0\'');
		$str = $this->_db->quote((string)$str);
		return ($str ?: '');
	}
	/**
	 * Escape a character string. If the input is empty, return a 'NULL' string.
	 * @param	mixed	$str	The string to escape.
	 * @return	string	The escaped string, or 'NULL' if the string is empty.
	 */
	public function quoteNull(mixed $str) : string {
		if (!$str)
			return ('NULL');
		$this->_connect();
		$str = $this->_db->quote((string)$str);
		return ($str ?: '');
	}
	/**
	 * Creates a prepared query.
	 * @param	string	$sql	The SQL request.
	 * @return	\Temma\Base\DatabaseStatement	The statement object.
	 * @throws	\Temma\Exceptions\Database	If an error occurs.
	 */
	public function prepare(string $sql) : \Temma\Base\DatabaseStatement {
		TµLog::log('Temma/Base', 'DEBUG', "SQL prepare: $sql");
		$this->_connect();
		try {
			$dbStatement = $this->_db->prepare($sql);
		} catch (\PDOException $pe) {
			TµLog::log('Temma/Base', 'ERROR', 'Database prepare error: ' . $pe->getMessage());
			throw new TµDatabaseException($pe->getMessage(), TµDatabaseException::QUERY);
		}
		if ($dbStatement === false) {
			$errStr = 'Database prepare error: ' . $this->getError();
			TµLog::log('Temma/Base', 'ERROR', $errStr);
			throw new TµDatabaseException($errStr, TµDatabaseException::QUERY);
		}
		return (new \Temma\Base\DatabaseStatement($this, $dbStatement));
	}
	/**
	 * Executes a SQL request without fetching data.
	 * @param	string	$sql	The SQL request.
	 * @return	int	The number of modified lines.
	 * @throws	\Exception	If something went wrong.
	 */
	public function exec(string $sql) : int {
		TµLog::log('Temma/Base', 'DEBUG', "SQL query: $sql");
		$this->_connect();
		$nbLines = $this->_db->exec($sql);
		if ($nbLines === false) {
			$errStr = 'Database request error: ' . $this->getError();
			TµLog::log('Temma/Base', 'ERROR', $errStr);
			throw new \Exception($errStr);
		}
		return ($nbLines);
	}
	/**
	 * Execute an SQL request and fetch one line of data.
	 * @param	string	$sql		The SQL request.
	 * @param	?string	$valueField	(optional) Name of the field whose value will be returned.
	 * @return	mixed	An associative array which contains the line of data, or the value which field's name has been given as paramete.
	 * @throws	\Exception	If something went wrong.
	 */
	public function queryOne(string $sql, ?string $valueField=null) : mixed {
		TµLog::log('Temma/Base', 'DEBUG', "SQL query: $sql");
		$this->_connect();
		$result = $this->_db->query($sql);
		if ($result === false) {
			$errStr = 'Database request error: ' . $this->getError();
			TµLog::log('Temma/Base', 'ERROR', $errStr);
			throw new \Exception($errStr);
		}
		$line = $result->fetch(\PDO::FETCH_ASSOC);
		$result = null;
		$line = is_array($line) ? $line : [];
		if ($valueField)
			return ($line[$valueField] ?? null);
		return ($line);
	}
	/**
	 * Execute an SQL request and fetch all lines of returned data.
	 * @param	string	$sql		The SQL request.
	 * @param	?string	$keyField	(optional) Name of the field that must be used as the key for each record.
	 * @param	?string	$valueField	(optional) Name of the field that will be used as value for each record.
	 * @return	array	An array of associative arrays, or an array of values.
	 * @throws	\Exception	If something went wrong.
	 */
	public function queryAll(string $sql, ?string $keyField=null, ?string $valueField=null) : array {
		TµLog::log('Temma/Base', 'DEBUG', "SQL query: $sql");
		$this->_connect();
		$result = $this->_db->query($sql);
		if ($result === false) {
			$errStr = 'Database request error: ' . $this->getError();
			TµLog::log('Temma/Base', 'ERROR', $errStr);
			throw new \Exception($errStr);
		}
		$lines = $result->fetchAll(\PDO::FETCH_ASSOC);
		if ($keyField || $valueField)
			$lines = array_column($lines, $valueField, $keyField);
		return ($lines);
	}
	/**
	 * Returns the primary key of the last inserted element.
	 * @return	int	The primary key.
	 * @throws	\Exception	If something went wrong.
	 */
	public function lastInsertId() : int {
		$this->_connect();
		return ((int)$this->_db->lastInsertId());
	}
}

