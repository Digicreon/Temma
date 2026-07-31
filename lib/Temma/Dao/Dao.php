<?php

/**
 * Dao
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2012-2026, Amaury Bouchard
 */

namespace Temma\Dao;

use \Temma\Exceptions\Dao as TµDaoException;

/**
 * Basic object for database access.
 *
 * <b>Search criteria</b>
 * <code>
 * // search for lines where 'email' equals "tom@tom.com" and the 'free' boolean is true.
 * $critera = $dao->criteria()
 *            ->equal('email', 'tom@tom.com')
 *            ->has('free');
 *
 * // search for lines where 'email' equals "john@john.com" or "bob@bob.com", and 'age' is
 * // less or equal to 12 or strictly greater than 24
 * $criteria = $dao::criteria()
 *             ->equal('email', ['john@john.com', 'bob@bob.com'])
 *             ->and(
 *                   $dao::criteria('or')
 *                   ->lessOrEqualTo('age', 12)
 *                   ->greaterThan('age', 24)
 *             );
 *
 * // search for lines where the email comes from Gmail or the name is "Bill Gates"
 * $criteria = $dao::criteria('or"')
 *             ->like('email', '%@gmail.com')
 *             ->equal('name', 'Bill Gates');
 * 
 * // search for lines where 'age' is greater than 12 and less than 20
 * $criteria = $dao::criteria()
 *             ->greaterThan('age', 12)
 *             ->lessThan('age', 20);
 * </code>
 *
 * <b>Sort criteria</b>
 * <code>
 * // sort on 'birthday', ascending
 * $sort = 'birthday';
 *
 * // sort on 'birthday', descending
 * $sort = '-birthday';
 *
 * // sort on 'birthday' (ascending) and 'points' (descending)
 * $sort = [
 *     'birthday',
 *     'points' => 'desc'
 * ];
 * // same as previous
 * $sort = [
 *     'birthday' => 'asc',
 *     'points'   => 'desc'
 * ];
 * // same as previous
 * $sort = ['birthday', '-points'];
 * </code>
 *
 * @see	\Temma\Web\Controller
 */
class Dao {
	/** Name of the criteria object. */
	protected string $_criteriaObject = '\Temma\Dao\Criteria';
	/** Database connection. */
	protected \Temma\Datasources\Sql $_db;
	/** Database engine type ('mysql', 'pgsql' or 'sqlite'; other engines are treated as MySQL). */
	protected string $_dbType = 'mysql';
	/** Cache connection. */
	protected ?\Temma\Base\Datasource $_cache;
	/** Tell if the cache must be disabled. */
	protected $_disableCache = false;
	/** Name of the database. */
	protected $_dbName = null;
	/** Name of the table. */
	protected $_tableName = null;
	/** Name of the table's primary key. */
	protected $_idField = null;
	/** List of the table's fields (with rename mapping if needed) */
	protected $_fields = null;
	/** List of the table's fields, indexed by their alias names. */
	private ?array $_fieldAliases = null;
	/** String with the list of fields (after generation from the list of fields). */
	private ?string $_fieldsString = null;

	/**
	 * Constructor.
	 * @param	\Temma\Datasources\Sql	$db		Connection to the database.
	 * @param	?\Temma\Base\Datasource	$cache		(optional) Connection to the cache server.
	 * @param	?string			$tableName	(optional) Name of the table.
	 * @param	?string			$idField	(optional) Name of the primary key. (default: 'id')
	 * @param	?string			$dbName		(optional) Name of the database.
	 * @param	?array			$fields		(optional) List of table's fields (may be remapped 'table_field' => 'aliased_name').
	 * @param	?string			$criteriaObject	(optional) Name of the criteria object. (default: \Temma\Dao\Criteria)
	 * @throws	\Temma\Exceptions\Dao	If the criteria object is not of the right type.
	 */
	public function __construct(\Temma\Datasources\Sql $db, ?\Temma\Base\Datasource $cache=null, ?string $tableName=null,
	                            ?string $idField='id', ?string $dbName=null, ?array $fields=null, ?string $criteriaObject=null) {
		$this->_db = $db;
		$type = $db->getType();
		$this->_dbType = ($type == 'sqlite2') ? 'sqlite' : ($type ?: 'mysql');
		$this->_cache = $cache;
		if (empty($this->_tableName))
			$this->_tableName = $tableName;
		if (empty($this->_idField))
			$this->_idField = $idField;
		if (empty($this->_dbName))
			$this->_dbName = $dbName;
		$this->_fields = $fields ?? $this->_fields ?? [];
		foreach ($this->_fields as $name => $alias) {
			if (is_int($name))
				continue;
			$this->_fieldAliases ??= [];
			$this->_fieldAliases[$alias] = $name;
		}
		if (!empty($criteriaObject)) {
			if (!is_subclass_of($criteriaObject, '\Temma\Dao\Criteria'))
				throw new TµDaoException("Bad object type.", TµDaoException::CRITERIA);
			$this->_criteriaObject = $criteriaObject;
		}
	}

	/* ********** GETTERS ********** */
	/**
	 * Returns the database object.
	 * @return	\Temma\Datasources\Sql	The database object.
	 */
	public function getDataBase() : \Temma\Datasources\Sql {
		return ($this->_db);
	}
	/**
	 * Returns the cache object.
	 * @return	?\Temma\Base\Datasource	The cache object.
	 */
	public function getCache() : ?\Temma\Base\Datasource {
		return ($this->_cache);
	}
	/**
	 * Returns the name of the database.
	 * @return	string	The name of the database.
	 */
	public function getDatabaseName() : string {
		if ($this->_dbType == 'sqlite')
			return ('main');
		$sql = ($this->_dbType == 'pgsql') ? 'SELECT current_database() AS dbname' : 'SELECT DATABASE() AS dbname';
		$result = $this->_db->queryOne($sql);
		return ($result['dbname']);
	}
	/**
	 * Returns the name of the table.
	 * @return	string	Table name.
	 */
	public function getTableName() : string {
		return ($this->_tableName);
	}
	/**
	 * Returns the name of the primary key field.
	 * @return	string	The field name.
	 */
	public function getIdField() : string {
		return ($this->_idField);
	}
	/**
	 * Returns the list of fields.
	 * @return	array	List of fields.
	 */
	public function getFields() : array {
		return ($this->_fields);
	}
	/**
	 * Return the name of a field of the table, using aliases if defined.
	 * This method should be used only by \Temma\Dao\Criteria objects.
	 * @param	string	$field	Field name.
	 * @return	string	The aliased field name.
	 */
	public function getFieldName(string $field) : string {
		return ($this->_fieldAliases[$field] ?? $field);
	}
	/**
	 * Escape and quote an SQL identifier (database, table or field name), with backticks
	 * (MySQL, SQLite) or double quotes (PostgreSQL).
	 * This method may also be used by \Temma\Dao\Criteria objects.
	 * @param	string	$identifier	The identifier to quote.
	 * @return	string	The quoted identifier.
	 */
	public function quoteIdentifier(string $identifier) : string {
		if ($this->_dbType == 'pgsql')
			return ('"' . str_replace('"', '""', $identifier) . '"');
		return ('`' . str_replace('`', '``', $identifier) . '`');
	}
	/**
	 * Tell if the table exists.
	 * @param	string	$tableName	(optional) Name of the table to check. If empty,
	 *					check the DAO's table.
	 * @return	bool	True if the table exists.
	 */
	public function tableExists(?string $tableName=null) : bool {
		$tableName = $tableName ?: $this->_tableName;
		if ($this->_dbType == 'sqlite') {
			$sql = "SELECT COUNT(*) AS cnt
			        FROM sqlite_master
			        WHERE type = 'table'
			          AND name = " . $this->_db->quote($tableName);
		} else if ($this->_dbType == 'pgsql') {
			$sql = "SELECT COUNT(*) AS cnt
			        FROM information_schema.tables
			        WHERE table_catalog = current_database()
			          AND table_schema = current_schema()
			          AND table_type = 'BASE TABLE'
			          AND table_name = " . $this->_db->quote($tableName);
		} else {
			$dbName = $this->getDatabaseName();
			$sql = "SELECT COUNT(*) AS cnt
			        FROM information_schema.TABLES
			        WHERE TABLE_SCHEMA = " . $this->_db->quote($dbName) . "
			          AND TABLE_TYPE = 'BASE TABLE'
			          AND TABLE_NAME = " . $this->_db->quote($tableName);
		}
		$count = $this->_db->queryOne($sql, 'cnt');
		return ((bool)$count);
	}

	/* ********** CRITERIA ********** */
	/**
	 * Creates a criteria management object.
	 * @param	string	$type	(optional) How criteria must be associated ('and', 'or'). (default: 'and')
	 * @return 	\Temma\Dao\Criteria	The criteria object.
	 */
	public function criteria(string $type='and') : \Temma\Dao\Criteria {
		return (new $this->_criteriaObject($this->_db, $this, $type));
	}

	/* ********** REQUESTS ********** */
	/**
	 * Returns the number of matching records.
	 * @param	null|array|\Temma\Dao\Criteria	$criteria	(optional) Search criteria or an associative array of fields and their search values.
	 *								Null to count all records in the table. (default: null)
	 * @return	int	The number of records.
	 */
	public function count(null|array|\Temma\Dao\Criteria $criteria=null) : int {
		$cacheVarName = '__dao:' . $this->_dbName . ':' . $this->_tableName . ':count';
		$sql = 'SELECT COUNT(*) AS nb
			FROM ' . $this->_getTableString();
		if (isset($criteria)) {
			if (is_array($criteria)) {
				$crit = $this->criteria();
				foreach ($criteria as $k => $v)
					$crit->equal($k, $v);
				$criteria = $crit;
			}
			$where = $criteria->generate();
			if ($where) {
				$sql .= ' WHERE ' . $where;
				$cacheVarName .= ':' . hash('md5', $sql);
			}
		}
		// searchfor the datain cache
		if (($nb = $this->_getCache($cacheVarName)) !== null)
			return ($nb);
		// query execution
		$data = $this->_db->queryOne($sql);
		// write result in cache
		$this->_setCache($cacheVarName, $data['nb']);
		return ($data['nb']);
	}
	/**
	 * Fetch a record from its primary key, or the first record matching a criteria.
	 * @param	int|string|array|\Temma\Dao\Criteria	$id	Primary key or criteria.
	 * @return	array	Associative array.
	 */
	public function get(int|string|array|\Temma\Dao\Criteria $id) : array {
		if (is_int($id) || is_string($id)) {
			$where = $this->quoteIdentifier($this->_idField) . ' = ' . $this->_db->quote($id);
			$idHash = md5($id);
		} else {
			if (is_array($id)) {
				$crit = $this->criteria();
				foreach ($id as $k => $v)
					$crit->equal($k, $v);
				$id = $crit;
			}
			$where = $id->generate();
			$idHash = md5($where);
		}
		// search data in cache
		$cacheVarName = '__dao:' . $this->_dbName . ':' . $this->_tableName . ":get:$idHash";
		if (($data = $this->_getCache($cacheVarName)) !== null)
			return ($data);
		// query execution
		$sql = 'SELECT ' . $this->_getFieldsString() . ' FROM ' . $this->_getTableString() .
			' WHERE ' . $where;
		$data = $this->_db->queryOne($sql);
		// write result in cache
		$this->_setCache($cacheVarName, $data);
		return ($data);
	}
	/**
	 * Insert a record in the table.
	 * @param	array	$data		Associative array which contains the data to add ('field' => 'value').
	 * @param	mixed	$safeData	(optional) Safe-mode management. (default: null)
	 * 					The safe-mode is used to avoir blocking an insertion that will generate a key duplication.
	 *					It could be:
	 *					- a list of fields that must be updated (with their associated values)
	 *					- the name of a field (if the field is listed in the first parameter, its value will be used,
	 *					  otherwise the field will keep its former value in database)
	 *					- TRUE to update all fields (using the values given as the first parameter)
	 * @return	int	The primary key of the created record.
	 * @throws	\Temma\Exceptions\Dao	If the input data are not well formed.
	 * @throws	\Exception		If there was a problem during insertion.
	 */
	public function create(array $data, mixed $safeData=null) : int {
		// Flush cache for this DAO
		$this->_flushCache();
		// boolean used for the safe data (telling if the primary key is in the given data)
		$idUpdated = false;
		// generate the lists of column names and quoted values, and the assignment string used by the safe mode
		$columns = [];
		$values = [];
		$set = [];
		foreach ($data as $key => $value) {
			// manage the key
			if (($field = array_search($key, $this->_fields)) !== false && !is_int($field))
				$key = $field;
			// manage the boolean
			if ($key == $this->_idField)
				$idUpdated = true;
			// add the data
			if (is_null($value))
				$quotedValue = 'NULL';
			else if (is_string($value) || is_numeric($value) || is_bool($value))
				$quotedValue = $this->_db->quote($value);
			else
				throw new TµDaoException("Bad field value for key '$key'.", TµDaoException::FIELD);
			$columns[] = $this->quoteIdentifier($key);
			$values[] = $quotedValue;
			$set[] = $this->quoteIdentifier($key) . ' = ' . $quotedValue;
		}
		$dataSet = implode(', ', $set);
		// create the query
		$sql = 'INSERT INTO ' . $this->_getTableString() . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';
		// management of key duplication
		if (!is_null($safeData)) {
			if ($this->_dbType == 'mysql') {
				$sql .= ' ON DUPLICATE KEY UPDATE ';
				if (!$idUpdated && $this->_idField) {
					// this instruction is used to make the subsequent call to LAST_INSERT_ID() returns the rightful value
					// see: https://stackoverflow.com/questions/778534/mysql-on-duplicate-key-last-insert-id
					$sql .= $this->quoteIdentifier($this->_idField) . ' = LAST_INSERT_ID(' . $this->quoteIdentifier($this->_idField) . '), ';
				}
			} else {
				// PostgreSQL and SQLite: the conflict target is the primary key
				$sql .= ' ON CONFLICT (' . $this->quoteIdentifier($this->_idField) . ') DO UPDATE SET ';
			}
			if ($safeData === true)
				$sql .= $dataSet;
			else if (is_string($safeData)) {
				if (isset($data[$safeData]))
					$sql .= $this->quoteIdentifier($safeData) . ' = ' . $this->_db->quote($data[$safeData]);
				else
					$sql .= $this->quoteIdentifier($safeData) . ' = ' . $this->_getExistingValueString($safeData);
			} else if (is_array($safeData)) {
				$set = [];
				foreach ($safeData as $key) {
					if (!isset($data[$key]))
						continue;
					$value = $data[$key];
					$key = (($field = array_search($key, $this->_fields)) === false || is_int($field)) ? $key : $field;
					$set[] = $this->quoteIdentifier($key) . ' = ' . $this->_db->quote($value);
				}
				$sql .= implode(', ', $set);
			} else
				$sql .= $this->quoteIdentifier($this->_idField) . ' = ' . $this->_getExistingValueString($this->_idField);
		}
		// PostgreSQL (always) and SQLite (in safe mode): use a RETURNING clause to get the
		// primary key of the created record, because lastInsertId() is not usable there
		if ($this->_idField && ($this->_dbType == 'pgsql' || ($this->_dbType == 'sqlite' && !is_null($safeData)))) {
			$sql .= ' RETURNING ' . $this->quoteIdentifier($this->_idField);
			$row = $this->_db->queryOne($sql);
			return ((int)($row[$this->_idField] ?? 0));
		}
		$this->_db->exec($sql);
		return ($this->_db->lastInsertId());
	}
	/**
	 * Search records from a search criteria.
	 * @param	null|array|\Temma\Dao\Criteria	$criteria	(optional) Search criteria or an associative array of fields and their search values. Null to take all records. (default: null)
	 * @param	null|bool|string|array		$sort		(optional) Sort data. Null for natural sort, true for descending sort
	 *								on the primary key, false for random sort. See _getSortString() for details.
	 * @param	?int				$limitOffset	(optional) Offset of the first returned record. (default: 0).
	 * @param	?int				$limit		(optional) Maximum number of records to return. Null for no limit. (default: null)
	 * @return	array	List of associative arrays, indexed by the primary key (if defined).
	 */
	public function search(null|array|\Temma\Dao\Criteria $criteria=null, null|bool|string|array $sort=null, ?int $limitOffset=null, ?int $limit=null) : array {
		$cacheVarName = '__dao:' . $this->_dbName . ':' . $this->_tableName . ':count';
		$sql = 'SELECT ' . $this->_getFieldsString() . ' FROM ' . $this->_getTableString();
		if (isset($criteria)) {
			if (is_array($criteria)) {
				$crit = $this->criteria();
				foreach ($criteria as $k => $v)
					$crit->equal($k, $v);
				$criteria = $crit;
			}
			$where = $criteria->generate();
			if (!empty($where))
				$sql .= ' WHERE ' . $where;
		}
		$sql .= $this->_getSortString($sort);
		if (!is_null($limitOffset) && !is_null($limit))
			$sql .= " LIMIT $limit OFFSET $limitOffset";
		else if (!is_null($limit))
			$sql .= " LIMIT $limit";
		else if (!is_null($limitOffset)) {
			// offset without limit: PostgreSQL accepts a bare OFFSET clause, the other engines don't
			if ($this->_dbType == 'pgsql')
				$sql .= " OFFSET $limitOffset";
			else if ($this->_dbType == 'sqlite')
				$sql .= " LIMIT -1 OFFSET $limitOffset";
			else
				$sql .= " LIMIT 18446744073709551615 OFFSET $limitOffset";
		}
		// on cherche la donnée en cache
		$cacheVarName = '__dao:' . $this->_dbName . ':' . $this->_tableName . ':search:' . hash('md5', $sql);
		if (($data = $this->_getCache($cacheVarName)) !== null)
			return ($data);
		// exécution de la requête
		$data = $this->_db->queryAll($sql, $this->_idField);
		// écriture de la donnée en cache
		$this->_setCache($cacheVarName, $data);
		return ($data);
	}
	/**
	 * Update one or more records.
	 * @param	null|int|string|array|\Temma\Dao\Criteria	$criteria	Primary key of the record that must be updated, or a search criteria.
	 *										Null to update all records. (default: null)
	 * @param	array						$fields		Associative array where the keys are the fields to update, and their
	 *										values are the new values to update. (default: empty array)
	 * @param	null|bool|string|array		$sort		(optional) Sort data. Null for natural sort, true for descending sort
	 *									on the primary key, false for random sort. See _getSortString() for details.
	 * @param	?int						$limit		(optional) Maximum number of lines to update. Null to update without limit.
	 * @return	int	The number of modified lines.
	 * @throws	\Temma\Exceptions\Dao	If the criteria or the fields array are not well formed.
	 */
	public function update(null|int|string|array|\Temma\Dao\Criteria $criteria=null, array $fields=[],
	                            null|bool|string|array $sort=null, ?int $limit=null) : int {
		if (!$fields)
			return (0);
		$this->_flushCache();
		// creation of the request
		$sql = 'UPDATE ' . $this->_getTableString() . ' SET ';
		$set = [];
		foreach ($fields as $field => $value) {
			// get the field if it is aliased
			if (($field2 = array_search($field, $this->_fields)) !== false && !is_int($field2))
				$field = $field2;
			// request generation
			if (is_string($value) || is_int($value) || is_float($value))
				$set[] = $this->quoteIdentifier($field) . ' = ' . $this->_db->quote($value);
			else if (is_bool($value))
				$set[] = $this->quoteIdentifier($field) . ' = ' . ($value ? 'TRUE' : 'FALSE');
			else if (is_null($value))
				$set[] = $this->quoteIdentifier($field) . ' = NULL';
			else
				throw new TµDaoException("Bad field '$field' value.", TµDaoException::VALUE);
		}
		$sql .= implode(',', $set);
		if (!is_null($criteria)) {
			$sql .= ' WHERE ';
			if (is_int($criteria) || is_string($criteria))
				$sql .= $this->quoteIdentifier($this->_idField) . ' = ' . $this->_db->quote($criteria);
			else {
				if (is_array($criteria)) {
					$crit = $this->criteria();
					foreach ($criteria as $k => $v)
						$crit->equal($k, $v);
					$criteria = $crit;
				}
				$sql .= $criteria->generate();
			}
		}
		// sort and limit management (only supported by MySQL in UPDATE queries)
		$sortString = $this->_getSortString($sort);
		if (($sortString || !is_null($limit)) && $this->_dbType != 'mysql')
			throw new TµDaoException("Sort and limit parameters are not supported by this database engine in update queries.", TµDaoException::CRITERIA);
		$sql .= $sortString;
		if (!is_null($limit))
			$sql .= " LIMIT $limit";
		$modified = $this->_db->exec($sql);
		return ($modified);
	}
	/**
	 * Delete one or more records.
	 * @param	null|int|string|array|\Temma\Dao\Criteria	$criteria	(optional) Primary key of the record that must be deleted, or a search criteria.
	 *										Null to remove all entries. (default: null)
	 * @return	int	The number of deleted lines.
	 */
	public function remove(null|int|string|array|\Temma\Dao\Criteria $criteria=null) : int {
		// effacement du cache pour cette DAO
		$this->_flushCache();
		// constitution et exécution de la requête
		$sql = 'DELETE FROM ' . $this->_getTableString();
		if (!is_null($criteria)) {
			$sql .= ' WHERE ';
			if (is_int($criteria) || is_string($criteria))
				$sql .= $this->quoteIdentifier($this->_idField) . ' = ' . $this->_db->quote($criteria);
			else {
				if (is_array($criteria)) {
					$crit = $this->criteria();
					foreach ($criteria as $k => $v)
						$crit->equal($k, $v);
					$criteria = $crit;
				}
				$sql .= $criteria->generate();
			}
		}
		$deleted = $this->_db->exec($sql);
		return ($deleted);
	}

	/* ***************** CACHE MANAGEMENT ************* */
	/**
	 * Disable cache.
	 * @param	mixed	$p	(optional) Value to return. (default: null)
	 * @return	mixed	The value given as parameter, or the instance of the current object (if the parameter was null).
	 */
	public function disableCache(mixed $p=null) : mixed {
		$this->_disableCache = true;
		return ($p ?? $this);
	}
	/**
	 * Enable cache.
	 * @param	mixed	$p	(optional) Value to return. (default: null)
	 * @return	mixed	The value given as parameter, or the instance of the current object (if the parameter was null).
	 */
	public function enableCache(mixed $p=null) : mixed {
		$this->_disableCache = false;
		return ($p ?? $this);
	}

	/* ****** PRIVATE METHODS ****** */
	/**
	 * Generate the quoted table name, prefixed with the quoted database name if defined.
	 * @return	string	The generated string.
	 */
	protected function _getTableString() : string {
		return ((!$this->_dbName ? '' : ($this->quoteIdentifier($this->_dbName) . '.')) . $this->quoteIdentifier($this->_tableName));
	}
	/**
	 * Generate the SQL reference to the value already stored in the table for the given field,
	 * as used in the update clause of an upsert query ("keep the former value").
	 * @param	string	$field	The field name.
	 * @return	string	The generated string.
	 */
	protected function _getExistingValueString(string $field) : string {
		// MySQL (ON DUPLICATE KEY UPDATE): the bare field name refers to the stored value;
		// PostgreSQL and SQLite (ON CONFLICT DO UPDATE): it must be prefixed with the table name
		if ($this->_dbType == 'mysql')
			return ($this->quoteIdentifier($field));
		return ($this->quoteIdentifier($this->_tableName) . '.' . $this->quoteIdentifier($field));
	}
	/**
	 * Generates the sort string.
	 * @param	null|bool|string|array		$sort		(optional) Sort data:
	 *								- null: natural sort.
	 *								- true: descending sort on the primary key.
	 *								- false: random sort.
	 *								- string: field name (prefix '-' for descending).
	 *								- array: list of fields, see Datasource sort doc.
	 * @return	string	The generated string. Could be empty.
	 */
	protected function _getSortString(null|bool|string|array $sort=null) : string {
		if (is_null($sort))
			return ('');
		$sortList = [];
		if ($sort === false)
			$sortList[] = ($this->_dbType == 'mysql') ? 'RAND()' : 'RANDOM()';
		else if ($sort === true)
			$sortList[] = $this->quoteIdentifier($this->_idField) . ' DESC';
		else if (is_string($sort)) {
			if (str_starts_with($sort, '-'))
				$sortList[] = $this->quoteIdentifier(mb_substr($sort, 1)) . ' DESC';
			else
				$sortList[] = $this->quoteIdentifier($sort);
		} else if (is_array($sort)) {
			foreach ($sort as $key => $value) {
				$field = is_int($key) ? $value : $key;
				if (str_starts_with($field, '-')) {
					$field = mb_substr($field, 1);
					$sortType = 'DESC';
				} else
					$sortType = (!is_int($key) && !strcasecmp($value, 'desc')) ? 'DESC' : 'ASC';
				if (($field2 = array_search($field, $this->_fields)) !== false && !is_int($field2))
					$field = $field2;
				$sortList[] = $this->quoteIdentifier($field) . " $sortType";
			}
		}
		if (!$sortList)
			return ('');
		return (' ORDER BY ' . implode(', ', $sortList) . ' ');
	}
	/**
	 * Generate the string with the fields list.
	 * @return	string	The generated string.
	 */
	protected function _getFieldsString() : string {
		if ($this->_fieldsString)
			return ($this->_fieldsString);
		if (!$this->_fields)
			$this->_fieldsString = '*';
		else {
			$list = [];
			foreach ($this->_fields as $fieldName => $aliasName) {
				if (is_int($fieldName))
					$list[] = $this->quoteIdentifier($aliasName);
				else
					$list[] = $this->quoteIdentifier($fieldName) . ' AS ' . $this->quoteIdentifier($aliasName);
			}
			$this->_fieldsString = implode(', ', $list);
		}
		return ($this->_fieldsString);
	}
	/**
	 * Read a data from cache.
	 * @param	string	$cacheVarName	Name of the variable to fetch.
	 * @return	mixed	Variable value.
	 */
	protected function _getCache(string $cacheVarName) : mixed {
		if (!$this->_cache || $this->_disableCache)
			return (null);
		return ($this->_cache->get($cacheVarName));
	}
	/**
	 * Add a variable in cache.
	 * @param	string	$cacheVarName	Name of the variable to add.
	 * @param	mixed	$data		Value of the variable.
	 */
	protected function _setCache(string $cacheVarName, $data) : void {
		if (!$this->_cache || $this->_disableCache)
			return;
		$listName = '__dao:' . $this->_dbName . ':' . $this->_tableName;
		$list = $this->_cache->get($listName);
		$list[] = $cacheVarName;
		$this->_cache->set($listName, $list);
		$this->_cache->set($cacheVarName, $data);
	}
	/** Delete all cache variables linked to this DAO. */
	protected function _flushCache() : void {
		$listName = '__dao:' . $this->_dbName . ':' . $this->_tableName;
		if (!$this->_cache || $this->_disableCache || ($list = $this->_cache->get($listName)) === null || !is_array($list))
			return;
		foreach ($list as $var)
			$this->_cache->set($var, null);
		$this->_cache->set($listName, null);
	}
}

