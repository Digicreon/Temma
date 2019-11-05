<?php

namespace Temma;

/**
 * Objet de génération de requêtes SQL.
 *
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @package	Temma
 */
class DaoCriteria {
	/** Objet Dao à l'origine du critère. */
	private $_dao = null;
	/** Objet de connexion à la base de données. */
	protected $_db = null;
	/** Type de combinaison des critères de recherche. */
	protected $_type = 'and';
	/** Tableau contenant les éléments de la requête. */
	protected $_elements = null;

	/**
	 * Constructeur.
	 * @param	\FineDatabase	$db	Objet de connexion à la base de données.
	 * @param	\Temma\Dao	$dao	Objet DAO.
	 * @param	string		$type	(optionnel) Type de combinaison des critères de recherche ("and", "or"). "and" par défaut.
	 */
	public function __construct(\FineDatabase $db, \Temma\Dao $dao, $type='and') {
		$this->_db = $db;
		$this->_dao = $dao;
		$this->_elements = array();
		if (!strcasecmp($type, 'and') || !strcasecmp($type, 'or'))
			$this->_type = $type;
	}
	/**
	 * Génère un fragment de requête SQL.
	 * @return	string	Le fragment SQL.
	 */
	public function generate() {
		$s = "";
		foreach ($this->_elements as $elem) {
			list($type, $condition) = $elem;
			if (!empty($s))
				$s .= " $type ";
			$s .= $condition;
		}
		return ($s);
	}
	/**
	 * Retourne un clone de l'objet courant.
	 * @param	string		$type	(optionnel) Type de combinaison des critères de recherche ("and", "or"). "and" par défaut.
	 * @return	\Temma\Dao	Un clone de l'objet courant.
	 */
	public function subCriteria($type='and') {
		return (new static($this->_db, $this->_dao, $type));
	}

	/* ********************** OPERATEURS BOOLEENS ******************** */
	/**
	 * Opérateur booléen "OU".
	 * @param	\Temma\DaoCriteria	$criteria	Critères de recherche.
	 * @return	\Temma\DaoCriteria	L'instance de l'objet courant.
	 */
	public function or_(\Temma\DaoCriteria $criteria) {
		$this->_addTypedCriteria('or', '(' . $criteria->generate() . ')');
		return ($this);
	}
	/**
	 * Opérateur booléen "ET".
	 * @param	\Temma\DaoCriteria	$criteria	Critères de recherche.
	 * @return	\Temma\DaoCriteria	L'instance de l'objet courant.
	 */
	public function and_(\Temma\DaoCriteria $criteria) {
		$this->_addTypedCriteria('and', '(' . $criteria->generate() . ')');
		return ($this);
	}

	/* ********************** CRITERES DE RECHERCHE ****************** */
	/**
	 * Ajoute un critère de choix sur un booléen.
	 * @param	string	$field	Nom du champ.
	 * @return	\Temma\DaoCriteria	L'instance de l'objet courant.
	 */
	public function is($field) {
		$this->_addCriteria($field, '= TRUE');
		return ($this);
	}
	/**
	 * Ajoute un critère de refus sur un booléen.
	 * @param	string	$field	Nom du champ.
	 * @return	\Temma\DaoCriteria	L'instance de l'objet courant.
	 */
	public function isNot($field) {
		$this->_addCriteria($field, '= FALSE');
		return ($this);
	}
	/**
	 * Ajoute un critère d'égalité.
	 * @param	string	$field	Nom du champ.
	 * @param	mixed	$value	Valeur.
	 * @return	\Temma\DaoCriteria	L'instance de l'objet courant.
	 */
	public function equal($field, $value) {
		$s = "";
		if (is_array($value)) {
			$values = array();
			foreach ($value as $v)
				$values[] = "'" . $this->_db->quote($v) . "'";
			$s = 'IN (' . implode(',', $values) . ')';
			$this->_addCriteria($field, $s);
		} else if ($value === null)
			$this->_addCriteria($field, 'IS NULL');
		else
			$this->_addCriteria($field, '=', $value);
		return ($this);
	}
	/**
	 * Ajoute un critère de non-égalité.
	 * @param	string	$field	Nom du champ.
	 * @param	mixed	$value	Valeur.
	 * @return	\Temma\DaoCriteria	L'instance de l'objet courant.
	 */
	public function different($field, $value) {
		$s = "";
		if (is_array($value)) {
			$values = array();
			foreach ($value as $v)
				$values[] = "'" . $this->_db->quote($v) . "'";
			$s = 'NOT IN (' . implode(',', $values) . ')';
			$this->_addCriteria($field, $s);
		} else if ($value === null)
			$this->_addCriteria($field, 'IS NOT NULL');
		else
			$this->_addCriteria($field, '!=', $value);
		return ($this);
		
	}
	/**
	 * Ajoute un critère de recherche de chaîne de caractères.
	 * @param	string	$field	Nom du champ.
	 * @param	string	$value	Valeur.
	 * @return	\Temma\DaoCriteria	L'instance de l'objet courant.
	 */
	public function like($field, $value) {
		$this->_addCriteria($field, 'LIKE', $value);
		return ($this);
	}
	/**
	 * Ajoute un critère de non-recherche de chaîne de caractères.
	 * @param	string	$field	Nom du champ.
	 * @param	string	$value	Valeur.
	 * @return	\Temma\DaoCriteria	L'instance de l'objet courant.
	 */
	public function notLike($field, $value) {
		$this->_addCriteria($field, 'NOT LIKE', $value);
		return ($this);
	}
	/**
	 * Ajout un critère "inférieur à".
	 * @param	string		$field	Nom du champ.
	 * @param	string|int	$value	Valeur.
	 * @return	\Temma\DaoCriteria	L'instance de l'objet courant.
	 */
	public function lessThan($field, $value) {
		$this->_addCriteria($field, '<', $value);
		return ($this);
	}
	/**
	 * Ajoute un critère "supérieur à".
	 * @param	string		$field	Nom du champ.
	 * @param	sintrg|int	$value	Valeur.
	 * @return	\Temma\DaoCriteria	L'instance de l'objet courant.
	 */
	public function greaterThan($field, $value) {
		$this->_addCriteria($field, '>', $value);
		return ($this);
	}
	/**
	 * Ajout un critère "inférieur ou égale à".
	 * @param	string		$field	Nom du champ.
	 * @param	string|int	$value	Valeur.
	 * @return	\Temma\DaoCriteria	L'instance de l'objet courant.
	 */
	public function lessOrEqualTo($field, $value) {
		$this->_addCriteria($field, '<=', $value);
		return ($this);
	}
	/**
	 * Ajoute un critère "supérieur ou égale à".
	 * @param	string		$field	Nom du champ.
	 * @param	sintrg|int	$value	Valeur.
	 * @return	\Temma\DaoCriteria	L'instance de l'objet courant.
	 */
	public function greaterOrEqualTo($field, $value) {
		$this->_addCriteria($field, '>=', $value);
		return ($this);
	}

	/* ********************** ALIAS ********************************** */
	public function eq($field, $value) {
		return ($this->equal($field, $value));
	}
	public function ne($field, $value) {
		return ($this->different($field, $value));
	}
	public function lt($field, $value) {
		return ($this->lessThan($field, $value));
	}
	public function gt($field, $value) {
		return ($this->greaterThan($field, $value));
	}
	public function le($field, $value) {
		return ($this->lessOrEqualTo($field, $value));
	}
	public function ge($field, $value) {
		return ($this->greaterOrEqualTo($field, $value));
	}

	/* ********************** METHODES PRIVEES *********************** */
	/**
	 * Ajout un critère de recherche en utilisant le type de combinaison par défaut.
	 * @param	string	$field		Nom du champ.
	 * @param	string	$operator	(optionnel) Opérateur de recherche.
	 * @param	string	$value		(optionnel) Valeur de recherche.
	 */
	protected function _addCriteria($field, $operator='', $value=null) {
		$this->_addTypedCriteria($this->_type, $field, $operator, $value);
	}
	/**
	 * Ajout un critère de recherche au tableau interne.
	 * @param	string	$type		Type de combinaison du critère.
	 * @param	string	$field		Nom du champ.
	 * @param	string	$operator	(optionnel) Opérateur de recherche.
	 * @param	string	$value		(optionnel) Valeur de recherche.
	 */
	protected function _addTypedCriteria($type, $field, $operator='', $value=null) {
		$field = $this->_dao->getFieldName($field);
		if (!empty($operator))
			$criteria = "`$field` $operator" . (isset($value) ? (" '" . $this->_db->quote($value) . "'") : '');
		else
			$criteria = $field;
		$this->_elements[] = array($type, $criteria);
	}
}

