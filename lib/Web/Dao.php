<?php

namespace Temma;

/**
 * Objet basique de gestion de la base de données.
 *
 * <b>Critères de recherche</b>
 * <code>
 * // recherche les entrées dont l'email vaut "tom@tom.com" et le booléen "free" est à vrai.
 * $critera = $dao->criteria()
 *            ->equal("email", "tom@tom.com")
 *            ->has("free");
 *
 * // recherche les entrées dont l'email vaut "john@..." ou "bob@bob.com", et dont l'âge est
 * // inférieur ou égal à 12 ou strictement supérieur à 24
 * $criteria = $dao::criteria()
 *             ->equal("email", array("john@john.com", "bob@bob.com"))
 *             ->and_(
 *                   $dao::criteria("or")
 *                   ->lessOrEqualTo("age", 12)
 *                   ->greaterThan("age", 24)
 *             );
 *
 * // recherches les entrées dont l'email vient de gmail ou dont le nom est "Bill Gates"
 * $criteria = $dao::criteria("or")
 *             ->like("email", "%@gmail.com")
 *             ->equal("name", "Bill Gates");
 * 
 * // recherche les entrées dont l'email contient "finemedia" et dont l'âge est supérieur à
 * // 12 et inférieur à 20
 * $criteria = $dao::criteria()
 *             ->greaterThan("age", 12)
 *             ->lessThan("age", 20);
 * </code>
 *
 * <b>Critères de tri</b>
 * <code>
 * // tri suivant la date de naissance, de manière ascendante
 * $sort = array(
 *     'birthday'
 * );
 *
 * // tri suivant la date de naissance (ascendant) et le nombre de points (descendant)
 * $sort = array(
 *     'birthday',
 *     'points' => 'desc'
 * );
 * // équivalent au précédent
 * $sort = array(
 *     'birthday' => 'asc',
 *     'points'   => 'desc'
 * );
 * </code>
 *
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @package	Temma
 */
class Dao {
	/** Nom de l'objet de critère. */
	protected $_criteriaObject = '\Temma\DaoCriteria';
	/** Connexion à la base de données. */
	protected $_db = null;
	/** Connexion au serveur de cache. */
	protected $_cache = null;
	/** Indique s'il faut désactiver le cache. */
	protected $_disableCache = false;
	/** Nom de la base de données. */
	protected $_dbName = null;
	/** Nom de la table. */
	protected $_tableName = null;
	/** Nom de la clé primaire. */
	protected $_idField = null;
	/** Table de mapping des champs de la table. */
	protected $_fields = null;
	/** Liste des champs après génération. */
	private $_fieldsString = null;

	/**
	 * Constructeur.
	 * @param	FineDatabase	$db		Connexion à la base de données.
	 * @param	FineCache	$cache		(optionnel) Connexion au serveur de cache.
	 * @param	string		$tableName	(optionnel) Nom de la table concernée.
	 * @param	string		$idField	(optionnel) Nom de la clé primaire. "id" par défaut.
	 * @param	string		$dbName		(optionnel) Nom de la base de données contenant la table.
	 * @param	array		$fields		(optionnel) Hash de mapping des champs de la table ("champ dans la table" => "nom aliasé").
	 * @param	string		$criteriaObject	(optionnel) Nom de l'objet de critères. "\Temma\DaoCriteria" par défaut.
	 * @throws	\Temma\Exceptions\DaoException	Si l'objet de critère n'est pas bon.
	 */
	public function __construct(\FineDatabase $db, \FineCache $cache=null, $tableName=null, $idField='id', $dbName=null, $fields=null, $criteriaObject=null) {
		$this->_db = $db;
		$this->_cache = $cache;
		if (empty($this->_tableName))
			$this->_tableName = $tableName;
		if (empty($this->_idField))
			$this->_idField = $idField;
		if (empty($this->_dbName))
			$this->_dbName = $dbName;
		if (is_array($fields))
			$this->_fields = $fields;
		if (is_null($this->_fields))
			$this->_fields = array();
		if (!empty($criteriaObject)) {
			if (!is_subclass_of($criteriaObject, '\Temma\DaoCriteria'))
				throw new \Temma\Exceptions\DaoException("Bad object type.", \Temma\Exceptions\DaoException::CRITERIA);
			$this->_criteriaObject = $criteriaObject;
		}
	}
	/**
	 * Génère un objet gestion des critères de requête.
	 * @param	string	$type	(optionnel) Type de combinaison des critères ("and", "or"). "and" par défaut.
	 * @return 	TemmaDaoCriteria	L'objet de critère.
	 */
	public function criteria($type='and') {
		return (new $this->_criteriaObject($this->_db, $this, $type));
	}
	/**
	 * Retourne le nombre d'enregistrements dans la table.
	 * @param	TemmaDaoCriteria	$criteria	(optionnel) Critères de recherche.
	 * @return	int	Le nombre.
	 */
	public function count(\Temma\DaoCriteria $criteria=null) {
		$cacheVarName = '__dao:' . $this->_dbName . ':' . $this->_tableName . ':count';
		$sql = 'SELECT COUNT(*) AS nb
			FROM ' . (empty($this->_dbName) ? '' : ($this->_dbName . '.')) . $this->_tableName;
		if (isset($criteria)) {
			$where = $criteria->generate();
			if (!empty($where)) {
				$sql .= ' WHERE ' . $where;
				$cacheVarName .= ':' . hash('md5', $sql);
			}
		}
		// on cherche la donnée en cache
		if (($nb = $this->_getCache($cacheVarName)) !== null)
			return ($nb);
		// exécution de la requête
		$data = $this->_db->queryOne($sql);
		// écriture de la donnée en cache
		$this->_setCache($cacheVarName, $data['nb']);
		return ($data['nb']);
	}
	/**
	 * Récupère un enregistrement dans la base de données, à partir de son identifiant.
	 * @param	int|string	$id	Identifiant de l'enregistrement à récupérer.
	 * @return	array	Hash de données.
	 */
	public function get($id) {
		// on cherche la donnée en cache
		$cacheVarName = '__dao:' . $this->_dbName . ':' . $this->_tableName . ":get:$id";
		if (($data = $this->_getCache($cacheVarName)) !== null)
			return ($data);
		// exécution de la requête
		$sql = 'SELECT ' . $this->_getFieldsString() . ' FROM ' .
			(empty($this->_dbName) ? '' : ($this->_dbName . '.')) . $this->_tableName .
			' WHERE ' . $this->_idField . " = '" . $this->_db->quote($id) . "'";
		$data = $this->_db->queryOne($sql);
		// écriture de la donnée en cache
		$this->_setCache($cacheVarName, $data);
		return ($data);
	}
	/**
	 * Insère un élément dans la table.
	 * @param	array		$data		Hash contenant les informations champ => valeur à insérer.
	 * @param	array|bool	$safeData	(optionnel) Données à utiliser pour gérer le safe-mode. Null par défaut.
	 * 						Le safe-mode permet de ne pas bloquer sur une insertion qui génère une duplication de clé.
	 *						Ce paramètre peut contenir un tableau listant les champs à mettre à jour en cas de
	 *						duplication, ou un booléen valant TRUE pour mettre tous les champs à jour, ou toute autre
	 *						valeur nulle pour que l'insertion ne bloque pas et ne fasse pas de mise à jour.
	 * @return	int	La clé primaire de l'élément créé.
	 * @throws	\Temma\Exceptions\DaoException	Si les données reçues sont mal formées.
	 * @throws	Exception			Si l'insertion s'est mal déroulée.
	 */
	public function create($data, $safeData=null) {
		// effacement du cache pour cette DAO
		$this->_flushCache();
		// constitution et exécution de la requête
		$sql = 'INSERT INTO ' . (empty($this->_dbName) ? '' : ($this->_dbName . '.')) . $this->_tableName .
			' SET ';
		$set = array();
		foreach ($data as $key => $value) {
			if (is_null($value))
				$set[] = "$key = NULL";
			else {
				if (!is_string($value) && !is_numeric($value) && !is_bool($value))
					throw new \Temma\Exceptions\DaoException("Bad field value for key '$key'.", \Temma\Exceptions\DaoException::FIELD);
				$key = (($field = array_search($key, $this->_fields)) === false || is_int($field)) ? $key : $field;
				$set[] = "$key = '" . $this->_db->quote($value) . "'";
			}
		}
		$dataSet = implode(', ', $set);
		$sql .= $dataSet;
		// gestion de la duplication d'index
		if (!is_null($safeData)) {
			$sql .= ' ON DUPLICATE KEY UPDATE ';
			if ($safeData === true)
				$sql .= $dataSet;
			else if (is_string($safeData)) {
				if (isset($data[$safeData]))
					$sql .= "$safeData = '" . $this->_db->quote($data[$safeData]) . "'";
				else
					$sql .= "$safeData = $safeData";
			} else if (is_array($safeData)) {
				$set = array();
				foreach ($safeData as $key) {
					if (!isset($data[$key]))
						continue;
					$value = $data[$key];
					$key = (($field = array_search($key, $this->_fields)) === false || is_int($field)) ? $key : $field;
					$set[] = "$key = '" . $this->_db->quote($value) . "'";
				}
				$sql .= implode(', ', $set);
			} else
				$sql .= $this->_idField . ' = ' . $this->_idField;
		}
		$this->_db->exec($sql);
		return ($this->_db->lastInsertId());
	}
	/**
	 * Récupère des enregistrements à partir de critères de recherche.
	 * @param	\Temma\DaoCriteria	$criteria	(optionnel) Critères de recherche. Null par défaut, pour prendre tous les enregistrements.
	 * @param	string|array		$sort		(optionnel) Informations de tri.
	 * @param	int			$limitOffset	(optionnel) Décalage pour le premier élément retourné. 0 par défaut.
	 * @param	int			$nbrLimit	(optionnel) Nombre d'éléments maximum à retourner. -1 par défaut.
	 * @return	array	Liste de hashs.
	 */
	public function search(\Temma\DaoCriteria $criteria=null, $sort=null, $limitOffset=null, $nbrLimit=null) {
		$cacheVarName = '__dao:' . $this->_dbName . ':' . $this->_tableName . ':count';
		$sql = 'SELECT ' . $this->_getFieldsString() . ' FROM ' .
			(empty($this->_dbName) ? '' : ($this->_dbName . '.')) . $this->_tableName;
		if (isset($criteria)) {
			$where = $criteria->generate();
			if (!empty($where))
				$sql .= ' WHERE ' . $where;
		}
		if (!is_null($sort)) {
			$sortList = array();
			if (is_string($sort))
				$sortList[] = $sort;
			else if ($sort === false)
				$sortList[] = 'RAND()';
			else if (is_array($sort)) {
				foreach ($sort as $key => $value) {
					$field = is_int($key) ? $value : $key;
					if (($field2 = array_search($field, $this->_fields)) !== false && !is_int($field2))
						$field = $field2;
					$sortType = (!is_int($key) && (!strcasecmp($value, 'asc') || !strcasecmp($value, 'desc'))) ? $value : 'ASC';
					$sortList[] = "$field $sortType";
				}
			}
			if (!empty($sortList))
				$sql .= ' ORDER BY ' . implode(', ', $sortList);
		}
		if (!is_null($limitOffset) && !is_null($nbrLimit))
			$sql .= " LIMIT $limitOffset, $nbrLimit";
		else if (!is_null($nbrLimit))
			$sql .= " LIMIT $nbrLimit";
		else if (!is_null($limitOffset))
			$sql .= " OFFSET $limitOffset";
		// on cherche la donnée en cache
		$cacheVarName = '__dao:' . $this->_dbName . ':' . $this->_tableName . ':search:' . hash('md5', $sql);
		if (($data = $this->_getCache($cacheVarName)) !== null)
			return ($data);
		// exécution de la requête
		$data = $this->_db->queryAll($sql);
		// écriture de la donnée en cache
		$this->_setCache($cacheVarName, $data);
		return ($data);
	}
	/**
	 * Met à jour un ou plusieurs enregistrements.
	 * @param	mixed	$criteria	Identifiant de l'enregistrement à modifier, ou critères de recherche.
	 *					Null par défaut, pour modifier toutes les lignes.
	 * @param	array	$fields		Hash contenant des paires champ => valeur à mettre à jour.
	 * @throws	\Temma\Exceptions\DaoException	Si les critères ou les champs/valeurs sont mal formés.
	 */
	public function update($criteria=null, $fields) {
		// effacement du cache pour cette DAO
		$this->_flushCache();
		// constitution et exécution de la requête
		$sql = 'UPDATE ' . (empty($this->_dbName) ? '' : ($this->_dbName . '.')) . $this->_tableName .
			' SET ';
		$set = array();
		foreach ($fields as $field => $value) {
			// récupération du champ s'il est aliasé
			if (($field2 = array_search($field, $this->_fields)) !== false && !is_int($field2))
				$field = $field2;
			// génération de la requête
			if (is_string($value) || is_int($value) || is_float($value))
				$set[] = "$field = '" . $this->_db->quote($value) . "'";
			else if (is_bool($value))
				$set[] = "$field = '" . ($value ? 1 : 0) . "'";
			else if (is_null($value))
				$set[] = "$field = NULL";
			else
				throw new \Temma\Exceptions\DaoException("Bad field '$field' value.", \Temma\Exceptions\DaoException::VALUE);
		}
		$sql .= implode(',', $set);
		$sql .= ' WHERE ';
		if (is_int($criteria) || is_string($criteria))
			$sql .= $this->_idField . " = '" . $this->_db->quote($criteria) . "'";
		else if ($criteria instanceof \Temma\DaoCriteria)
			$sql .= $criteria->generate();
		else
			throw new \Temma\Exceptions\DaoException("Bad criteria type.", \Temma\Exceptions\DaoException::CRITERIA);
		$this->_db->exec($sql);
	}
	/**
	 * Efface des enregistrements.
	 * @param	mixed	$criteria	Identifiant de l'élément à effacer, ou critères de recherche.
	 */
	public function remove($criteria) {
		// effacement du cache pour cette DAO
		$this->_flushCache();
		// constitution et exécution de la requête
		$sql = 'DELETE FROM ' . (empty($this->_dbName) ? '' : ($this->_dbName . '.')) . $this->_tableName .
			' WHERE ';
		if (is_int($criteria) || is_string($criteria))
			$sql .= $this->_idField . " = '" . $this->_db->quote($criteria) . "'";
		else
			$sql .= $criteria->generate();
		$this->_db->exec($sql);
	}
	/**
	 * Retourne le nom d'un champ de la table, en fonction de la présence ou non d'alias.
	 * Cette méthode ne devrait être utilisée que par les objets de type \Temma\DaoCriteria.
	 * @param	string	$field	Le nom du champ.
	 * @return	string	Le nom du champ, avec traitement des alias.
	 */
	public function getFieldName($field) {
		if (empty($this->_fields))
			return ($field);
		$realName = array_search($field, $this->_fields);
		return ($realName ? $realName : $field);
	}

	/* ***************** GESTION DU CACHE ************* */
	/**
	 * Désactive le cache.
	 * @param	mixed	$p	(optionnel) Valeur à retourner.
	 * @return	\Temma\Dao	L'instance de l'objet courant.
	 */
	public function disableCache($p) {
		$this->_disableCache = true;
		return (is_null($p) ? $this : $p);
	}
	/**
	 * Active le cache.
	 * @param	mixed	$p	(optionnel) Valeur à retourner.
	 * @return	\Temma\Dao	L'instance de l'objet courant.
	 */
	public function enableCache($p=null) {
		$this->_disableCache = false;
		return (is_null($p) ? $this : $p);
	}

	/* ****** Méthodes privées ****** */
	/**
	 * Génère la chaîne de caractères contenant la liste des champs.
	 * @return	string	La chaîne.
	 */
	protected function _getFieldsString() {
		if (!empty($this->_fieldsString))
			return ($this->_fieldsString);
		if (empty($this->_fields))
			$this->_fieldsString = '*';
		else {
			$list = array();
			foreach ($this->_fields as $fieldName => $aliasName) {
				if (is_int($fieldName))
					$list[] = $aliasName;
				else
					$list[] = "$fieldName AS $aliasName";
			}
			$this->_fieldsString = implode(', ', $list);
		}
		return ($this->_fieldsString);
	}
	/**
	 * Lit une donnée en cache.
	 * @param	string	$cacheVarName	Nom de la variable.
	 */
	protected function _getCache($cacheVarName) {
		if (!$this->_cache || $this->_disableCache)
			return (null);
		return ($this->_cache->get($cacheVarName));
	}
	/**
	 * Ajoute une variable en cache.
	 * @param	string	$cacheVarName	Nom de la variable.
	 * @param	mixed	$data		La donnée à mettre en cache.
	 */
	protected function _setCache($cacheVarName, $data) {
		if (!$this->_cache || $this->_disableCache)
			return;
		$listName = '__dao:' . $this->_dbName . ':' . $this->_tableName;
		$list = $this->_cache->get($listName);
		$list[] = $cacheVarName;
		$this->_cache->set($listName, $list);
		$this->_cache->set($cacheVarName, $data);
	}
	/** Efface toutes les variables de cache correspondant à cette DAO. */
	protected function _flushCache() {
		$listName = '__dao:' . $this->_dbName . ':' . $this->_tableName;
		if (!$this->_cache || $this->_disableCache || ($list = $this->_cache->get($listName)) === null || !is_array($list))
			return;
		foreach ($list as $var)
			$this->_cache->set($var, null);
		$this->_cache->set($listName, null);
	}
}

