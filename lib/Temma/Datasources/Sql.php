<?php

/**
 * Sql
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2023, Amaury Bouchard
 */

namespace Temma\Datasources;

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
 *     $db = new \Temma\Base\Datasource("mysql://user:pwd@localhost/database");
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
 * try { $db = new \Temma\Base\Datasource::factory("mysql://user:pwd@localhost/database"); }
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
 *
 * For use as a regular datasource, you must create a table named 'TemmaData':
 * <code>
 * CREATE TABLE TemmaData (
 *     key    CHAR(255) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
 *     data   LONGTEXT,
 *     PRIMARY KEY (key)
 * ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
 * </code>
 */
class Sql extends \Temma\Base\Datasource {
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
	 * @return	\Temma\Datasources\Sql	The created object.
	 * @throws	\Exception	If something went wrong.
	 */
	static public function factory(string $dsn) : \Temma\Datasources\Sql {
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

	/* ********** CONNECTION / DISCONNECTION ********** */
	/**
	 * Connection.
	 * @throws	\Temma\Exceptions\Database	If the connection failed.
	 */
	public function connect() : void {
		if ($this->_db)
			return;
		$this->reconnect();
	}
	/**
	 * Reconnection.
	 * @throws	\Temma\Exceptions\Database	If the connection failed.
	 */
	public function reconnect() {
		if (!$this->_enabled)
			return;
		$this->disconnect();
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
			throw new \Temma\Exceptions\Database("Database connection error: " . $e->getMessage(), \Temma\Exceptions\Database::CONNECTION);
		}
	}
	/** Disconnection. */
	public function disconnect() : void {
		unset($this->_db);
		$this->_db = null;
	}

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
		if (!$this->_enabled)
			return;
		$this->connect();
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
		if (!$this->_enabled)
			return;
		$this->connect();
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
		if (!$this->_enabled)
			return;
		$this->connect();
		if ($this->_db->rollback() === false) {
			TµLog::log('Temma/Base', 'ERROR', "Error during transaction rollback.");
			throw new \Exception("Error during transaction rollback.");
		}
	}

	/* ********** SPECIAL REQUESTS ********** */
	/**
	 * Return the last SQL error.
	 * @return	string	The last error.
	 */
	public function getError() : string {
		if (!$this->_enabled)
			return ('');
		$this->connect();
		$errInfo = $this->_db->errorInfo();
		return ($errInfo[2] ?? '');
	}
	/**
	 * Check if the connection to the database is still working.
	 * @return	bool	True if the connection is working.
	 */
	public function ping() : bool {
		if (!$this->_enabled)
			return (true);
		$this->connect();
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
		if (!$this->_enabled)
			return ("'" . str_replace("'", "\\'", $str) . "'");
		$this->connect();
		if ($str === false)
			return ('\'0\'');
		if ($str === null)
			return ('\'\'');
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
		if (!$this->_enabled)
			return ($this->quote($str));
		$this->connect();
		$str = $this->_db->quote((string)$str);
		return ($str ?: '');
	}
	/**
	 * Creates a prepared query.
	 * @param	string	$sql	The SQL request.
	 * @return	\Temma\Datasources\SqlStatement	The statement object.
	 * @throws	\Temma\Exceptions\Database	If an error occurs.
	 */
	public function prepare(string $sql) : \Temma\Datasources\SqlStatement {
		TµLog::log('Temma/Base', 'DEBUG', "SQL prepare: $sql");
		if (!$this->_enabled)
			throw new TµDatabaseException("Unable to prepare a query while the connection is disabled.", TµDatabaseException::QUERY);
		$this->connect();
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
		return (new \Temma\Datasources\SqlStatement($this, $dbStatement));
	}
	/**
	 * Executes a SQL request without fetching data.
	 * @param	string	$sql	The SQL request.
	 * @return	int	The number of modified lines.
	 * @throws	\Temma\Exceptions\Database	If something went wrong.
	 */
	public function exec(string $sql) : int {
		TµLog::log('Temma/Base', 'DEBUG', "SQL query: $sql");
		if (!$this->_enabled)
			return (0);
		$this->connect();
		$nbLines = $this->_db->exec($sql);
		if ($nbLines === false) {
			$errStr = 'Database request error: ' . $this->getError();
			TµLog::log('Temma/Base', 'ERROR', $errStr);
			throw new TµDatabaseException($errStr, TµDatabaseException::QUERY);
		}
		return ($nbLines);
	}
	/**
	 * Execute an SQL request and fetch one line of data.
	 * @param	string	$sql		The SQL request.
	 * @param	?string	$valueField	(optional) Name of the field whose value will be returned.
	 * @return	mixed	An associative array which contains the line of data, or the value which field's name has been given as parameter.
	 * @throws	\Temma\Exceptions\Database	If something went wrong.
	 */
	public function queryOne(string $sql, ?string $valueField=null) : mixed {
		TµLog::log('Temma/Base', 'DEBUG', "SQL query: $sql");
		if (!$this->_enabled)
			return ([]);
		$this->connect();
		$result = $this->_db->query($sql);
		if ($result === false) {
			$errStr = 'Database request error: ' . $this->getError();
			TµLog::log('Temma/Base', 'ERROR', $errStr);
			throw new TµDatabaseException($errStr, TµDatabaseException::QUERY);
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
	 * @throws	\Temma\Exceptions\Database	If something went wrong.
	 */
	public function queryAll(string $sql, ?string $keyField=null, ?string $valueField=null) : array {
		TµLog::log('Temma/Base', 'DEBUG', "SQL query: $sql");
		if (!$this->_enabled)
			return ([]);
		$this->connect();
		$result = $this->_db->query($sql);
		if ($result === false) {
			$errStr = 'Database request error: ' . $this->getError();
			TµLog::log('Temma/Base', 'ERROR', $errStr);
			throw new TµDatabaseException($errStr, TµDatabaseException::QUERY);
		}
		$lines = $result->fetchAll(\PDO::FETCH_ASSOC);
		if ($keyField || $valueField)
			$lines = array_column($lines, $valueField, $keyField);
		return ($lines);
	}
	/**
	 * Returns the primary key of the last inserted element.
	 * @return	int	The primary key.
	 * @throws	\Temma\Exceptions\Database	If something went wrong.
	 */
	public function lastInsertId() : int {
		if (!$this->_enabled)
			return (0);
		$this->connect();
		return ((int)$this->_db->lastInsertId());
	}

	/* ********** ARRAY-LIKE REQUESTS ********** */
	/**
	 * Return the number of elements.
	 * @return	int	The number of elements.
	 */
	public function count() : int {
		if (!$this->_enabled)
			return (0);
		$sql = "SELECT COUNT(*) AS nbr
		        FROM TemmaData";
		$res = $this->queryOne($sql);
		return ($res['nbr']);
	}

	/* ********** STANDARD REQUESTS ********** */
	/**
	 * Tell if a key exists in database.
	 * @param	string $key	Key to check.
	 * @return	bool	True if the key exists.
	 */
	public function isSet(string $key) : bool {
		if (!$this->_enabled)
			return (false);
		$sql = "SELECT COUNT(*) AS nbr
		        FROM TemmaData
		        WHERE key = " . $this->quote($key);
		$res = $this->queryOne($sql);
		return ($res['nbr'] != 0);
	}
	/**
	 * Remove a key.
	 * @param	string	$key	The key to remove.
	 */
	public function remove(string $key) : void {
		if (!$this->_enabled)
			return;
		$sql = "DELETE FROM TemmaData
		        WHERE key = " . $this->quote($key);
		$this->exec($sql);
	}
	/**
	 * Multiple remove.
	 * @param	array	$keys	List of keys to remove.
	 */
	public function mRemove(array $keys) : void {
		if (!$this->_enabled)
			return;
		array_walk($keys, function(&$value, $key) {
			$value = $this->quote($value);
		});
		$sql = "DELETE FROM TemmaData
		        WHERE key IN (" . implode(', ', $keys) . ")";
		$this->exec($sql);
	}
	/**
	 * Remove all keys matching a given pattern.
	 * @param	string	$prefix	The key prefix.
	 */
	public function clear(string $prefix) : void {
		if (!$this->_enabled)
			return;
		$sql = "DELETE FROM TemmaData
		        WHERE key LIKE " . $this->quote("$prefix%");
		$this->exec($sql);
	}
	/**
	 * Remove all data.
	 */
	public function flush() : void {
		if (!$this->_enabled)
			return;
		$sql = "TRUNCATE TABLE TemmaData";
		$this->exec($sql);
	}

	/* ********** RAW REQUESTS ********** */
	/**
	 * Return a list of keys that match a pattern.
	 * @param	string	$prefix		The prefix to match.
	 * @param	bool	$getValues	(optional) True to fetch the associated values. False by default.
	 * @return	array	List of keys, or associative array of key-value pairs.
	 */
	public function find(string $prefix, bool $getValues=false) : array {
		if (!$this->_enabled)
			return ([]);
		$sql = "SELECT key";
		if ($getValues)
			$sql .= ", data";
		$sql .= " FROM TemmaData
		         WHERE key LIKE " . $this->quote("$prefix%");
		$data = $this->queryAll($sql);
		$result = [];
		foreach ($data as $line) {
			if ($getValues)
				$result[$line['key']] = $line['data'];
			else
				$result[] = $line['key'];
		}
		return ($result);
	}
	/**
	 * Get the value associated to a key in database.
	 * @param	string	$key			Key to fetch.
	 * @param	mixed	$defaultOrCallback	(optional) Default scalar value or function called if the data is not found.
	 *						If scalar: the value is returned.
	 *						If callback: the value returned by the function is stored in the database, and returned.
	 * @param	mixed	$options		(optional) Options used if the key is added in databse.
	 *						If a string: mime type.
	 *						If a boolean: true for public access, false for private access.
	 *						If an array: 'public' (bool) and/or 'mimetype' (string) keys.
	 * @return	?string	The data fetched from the S3 file, or null.
	 * @throws	\Exception	If an error occured.
	 */
	public function read(string $key, mixed $defaultOrCallback=null, mixed $options=null) : ?string {
		// fetch the data
		if ($this->_enabled) {
			$sql = "SELECT data
				FROM TemmaData
				WHERE key = " . $this->quote($key);
			$res = $this->queryOne($sql);
			if (($res['data'] ?? null))
				return ($res['data']);
		}
		// manage default value
		if (!$defaultOrCallback)
			return (null);
		$value = $defaultOrCallback;
		if (is_callable($defaultOrCallback)) {
			$value = $defaultOrCallback();
			$this->set($key, $value, $options);
		}
		return ($value);
	}
	/**
	 * Multiple read.
	 * @param	array	$keys	List of keys.
	 * @return	array	Associative array with the keys and their associated values.
	 */
	public function mRead(array $keys) : array {
		if (!$this->_enabled)
			return ([]);
		array_walk($keys, function(&$value, $key) {
			$value = $this->quote($value);
		});
		$sql = "SELECT key, data
			FROM TemmaData
			WHERE key IN (" . implode(', ', $keys) . ")";
		$data = $this->queryAll($sql);
		$result = [];
		foreach ($data as $line)
			$result[$line['key']] = $line['data'];
		return ($result);
	}
	/**
	 * Add or update a key in database.
	 * @param	string	$key		Key to add or update.
	 * @param	mixed	$data		(optional) Data value. The data is deleted if the value is not given or if it is null.
	 * @param	mixed	$options	Not used.
	 * @return	bool	Always true.
	 * @throws	\Exception	If an error occured.
	 */
	public function write(string $key, mixed $data=null, mixed $options=null) : bool {
		if (!$this->_enabled)
			return (false);
		// remove file
		if ($data === null) {
			$this->remove($key);
			return (true);
		}
		// create or update key
		$sql = "INSERT INTO TemmaData
			SET data = " . $this->quote($data) . "
			WHERE key = " . $this->quote($key) . "
			ON DUPLICATE KEY UPDATE data = " . $this->quote($data);
		$this->exec($sql);
		return (true);
	}
	/**
	 * Multiple write.
	 * @param	array	$data		Associative array with keys and their associated values.
	 * @param	mixed	$options	(optional) Options (like expiration duration).
	 * @return	int	The number of set data.
	 */
	public function mWrite(array $data, mixed $options=null) : int {
		if (!$this->_enabled)
			return (0);
		$values = [];
		foreach ($data as $key => $value)
			$values[] = '(' . $this->quote($key) . ', ' . $this->quote($data) . ')';
		$sql = "INSERT INTO TemmaData (key, data)
		        VALUES " . implode(', ', $values) . "
		        ON DUPLICATE KEY UPDATE data = VALUES(data)";
		return ($this->exec($sql));
	}
}

