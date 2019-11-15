<?php

namespace Temma\Base;

use \Temma\Base\Log as TµLog;

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
 *	 // object creation, database connection
 *	 $db = new \Temma\Base\Database("mysql://user:pwd@localhost/database");
 *	 // simple request
 *	 $db->exec("DELETE FROM tToto");
 *       // request which fetch one line of data
 *       $result = $db->queryOne("SELECT COUNT(*) AS nbr FROM Foo");
 *       print($result['nbr']);
 *	 // request which fetch many lines of data
 *	 $result = $db->queryAll("SELECT id, name FROM Foo");
 *	 // display results
 *       foreach ($result as $line)
 *               print($line['id'] . " -> " . $line['name'] . "\n");
 * } catch (Exception $e) {
 *	 print("Erreur base de données: " . $e->getMessage());
 * }
 * </code>
 *
 * Transactional example:
 * <code>
 * // object creation, database connection
 * try { $db = new \Temma\Base\Database::factory("mysql://user:pwd@localhost/database"); }
 * catch (Exception $e) { }
 * // transactional requests
 * try {
 *	 // start the transaction
 *	 $db->startTransaction();
 *	 // insertion request
 *	 $db->exec("INSERT INTO Foo SET name = 'pouet'");
 *	 // bad request, will raise an exception
 *	 $db->exec("INSERT foobar");
 *	 // commit of the transaction if everything is fine
 *	 $db->commit();
 * } catch (Exception $e) {
 *	 // rollback of the transaction
 *	 $db->rollback();
 * }
 * </code>
 *
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2019, Amaury Bouchard
 * @package	Temma
 * @subpackage	Base
 */
class Database extends \Temma\Base\Datasource {
	/** Database connection objet. */
	protected $_db = null;
	/** Database connection parameters. */
	protected $_params = null;
	/** Connection user (PDO compatibility). */
	protected $_login = null;
	/** Connection password (PDO compatibility). */
	protected $_password = null;

	/* ************************ CONSTRUCTION ********************** */
	/**
	 * Factory. Creates an instance of the object, using the given parameters.
	 * @param	string	$dsn		Database connection string.
	 * @return	\Temma\Base\Database	The created object.
	 * @throws	\Exception	If something went wrong.
	 */
	static public function factory(string $dsn) : \Temma\Base\Datasource {
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
	private function __construct(string $dsn, ?string $login=null, ?string $password=null) {
		TµLog::log('Temma/Base', 'DEBUG', "Database object creation with DSN: '$dsn'.");
		// parameters extraction
		$params = null;
		if (preg_match("/^([^:]+):\/\/([^:@]+):?([^@]+)?@([^\/:]+):?(\d+)?\/([^#]*)#?(.*)$/", $dsn, $matches)) {
			$this->_params = [
				'type'		=> $matches[1],
				'login'		=> $matches[2],
				'password'	=> $matches[3],
				'host'		=> $matches[4],
				'port'		=> $matches[5],
				'base'		=> $matches[6],
				'sock'		=> $matches[7],
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

	/* ***************************** CONNECTION / DISCONNECTION **************** */
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

	/* ***************************** TRANSACTIONS ************************ */
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

	/* ********************** REQUESTS *********************** */
	/**
	 * Return the last SQL error.
	 * @return	string	The last error.
	 */
	public function getError() : string {
		$this->_connect();
		$errInfo = $this->_db->errorInfo();
		return ($errInfo[2] ?? null);
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
	 * @param	?string	$str	The string to escape.
	 * @return	string	The escaped string.
	 */
	public function quote(?string $str) : string {
		$this->_connect();
		$str = $this->_db->quote((string)$str);
		return (strlen($str) ? $str : '');
	}
	/**
	 * Executes a SQL request without fetching data.
	 * @param	string	$sql	The SQL request.
	 * @return	int	The number of modified lines. For an asynchronous request, the returned value shouldn't be used.
	 * @throws	\Exception	If something went wrong.
	 */
	public function exec(string $sql) : int {
		TµLog::log('Temma/Base', 'DEBUG', "SQL query: $sql");
		$this->_connect();
		$nbLines = $this->_db->exec($sql);
		if ($nbLines === false) {
			$errStr = 'Request error: ' . $this->getError();
			TµLog::log('Temma/Base', 'ERROR', $errStr);
			throw new \Exception('Request error: ' . $this->getError());
		}
		return ($nbLines);
	}
	/**
	 * Execute an SQL request and fetch one line of data.
	 * @param	string	$sql	The SQL request.
	 * @return	array	An associative array which contains the line of data.
	 * @throws	\Exception	If something went wrong.
	 */
	public function queryOne(string $sql) : array {
		TµLog::log('Temma/Base', 'DEBUG', "SQL query: $sql");
		$this->_connect();
		$result = $this->_db->query($sql);
		if ($result === false) {
			$errStr = 'Request error: ' . $this->getError();
			TµLog::log('Temma/Base', 'ERROR', $errStr);
			throw new \Exception($errStr);
		}
		$line = $result->fetch(\PDO::FETCH_ASSOC);
		$result = null;
		return (is_array($line) ? $line : []);
	}
	/**
	 * Execute an SQL request and fetch all lines of returned data.
	 * @param	string	$sql	The SQL request.
	 * @return	array	An array of associative arrays.
	 * @throws	\Exception	If something went wrong.
	 */
	public function queryAll(string $sql) : array {
		TµLog::log('Temma/Base', 'DEBUG', "SQL query: $sql");
		$this->_connect();
		$result = $this->_db->query($sql);
		if ($result === false) {
			$errStr = 'Request error: ' . $this->getError();
			TµLog::log('Temma/Base', 'ERROR', $errStr);
			throw new \Exception($errStr);
		}
		$lines = $result->fetchAll(\PDO::FETCH_ASSOC);
		$result = null;
		return ($lines);
	}
	/**
	 * Returns the primary key of the last inserted element.
	 * @return	int	The primary key.
	 * @throws	\Exception	If something went wrong.
	 */
	public function lastInsertId() : int {
		$this->_connect();
		return ($this->_db->lastInsertId());
	}
}

