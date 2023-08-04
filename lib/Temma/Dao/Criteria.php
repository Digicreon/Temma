<?php

/**
 * Criteria
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2012-2023, Amaury Bouchard
 */

namespace Temma\Dao;

use \Temma\Exceptions\Dao as TµDaoException;

/**
 * Object used to generate SQL search criteria.
 *
 * @see	\Temma\Dao\Dao
 */
class Criteria {
	/** DAO object initiating the criteria. */
	private ?\Temma\Dao\Dao $_dao = null;
	/** Database connection. */
	protected ?\Temma\Base\Database $_db = null;
	/** Type of criteria boolean combination. */
	protected string $_type = 'and';
	/** List of request elements. */
	protected array $_elements = [];

	/**
	 * Constructor.
	 * @param	\Temma\Base\Database	$db	Connection to the database.
	 * @param	\Temma\Dao\Dao		$dao	DAO object.
	 * @param	string			$type	(optional) 'and', 'or'. (default: 'and')
	 * @throws	\Temma\Exceptions\Dao	If there is a bad criteria combination.
	 */
	final public function __construct(\Temma\Base\Database $db, \Temma\Dao\Dao $dao, string $type='and') {
		$this->_db = $db;
		$this->_dao = $dao;
		if (strcasecmp($type, 'and') && strcasecmp($type, 'or'))
			throw new TµDaoException("Bad criteria combination type '$type'.", TµDaoException::CRITERIA);
		$this->_type = strtolower($type);
	}
	/**
	 * Generate the SQL request.
	 * @return	string	SQL code.
	 */
	public function generate() : string {
		$s = '';
		foreach ($this->_elements as $elem) {
			list($type, $condition) = $elem;
			if ($s)
				$s .= " $type ";
			$s .= $condition;
		}
		return ($s);
	}
	/**
	 * Return a clone of the current object.
	 * @param	string		$type	(optional) 'and', 'or' (default: 'and')
	 * @return	\Temma\Dao\Criteria	Un clone de l'objet courant.
	 */
	public function subCriteria(string $type='and') : \Temma\Dao\Criteria {
		return (new static($this->_db, $this->_dao, $type));
	}

	/* ********************** BOOLEAN OPERATORS ******************** */
	/**
	 * "OR" operator.
	 * @param	\Temma\Dao\Criteria	$criteria	Search criteria.
	 * @return	\Temma\Dao\Criteria	The current instance.
	 */
	public function or(\Temma\Dao\Criteria $criteria) : \Temma\Dao\Criteria {
		$this->_addTypedCriteria('or', '(' . $criteria->generate() . ')');
		return ($this);
	}
	/**
	 * "AND" operator.
	 * @param	\Temma\Dao\Criteria	$criteria	Search criteria.
	 * @return	\Temma\Dao\Criteria	The current instance.
	 */
	public function and(\Temma\Dao\Criteria $criteria) : \Temma\Dao\Criteria {
		$this->_addTypedCriteria('and', '(' . $criteria->generate() . ')');
		return ($this);
	}

	/* ********************** SEARCH CRITERIA ****************** */
	/**
	 * Add a criterion on a boolean field.
	 * @param	string	$field	Field name.
	 * @return	\Temma\Dao\Criteria	The current object.
	 */
	public function is(string $field) : \Temma\Dao\Criteria {
		$this->_addCriteria($field, '= TRUE');
		return ($this);
	}
	/**
	 * Add a criterion on a false boolean field.
	 * @param	string	$field	Field name.
	 * @return	\Temma\Dao\Criteria	The current object.
	 */
	public function isNot(string $field) : \Temma\Dao\Criteria {
		$this->_addCriteria($field, '= FALSE');
		return ($this);
	}
	/**
	 * Add an equality criterion.
	 * @param	string				$field	Field name.
	 * @param	null|int|float|string|array	$value	Comparison value(s).
	 * @return	\Temma\Dao\Criteria	The current object.
	 */
	public function equal(string $field, null|int|float|string|array $value) : \Temma\Dao\Criteria {
		$s = '';
		if (is_array($value)) {
			$values = [];
			foreach ($value as $v)
				$values[] = $this->_db->quote($v);
			$s = 'IN (' . implode(',', $values) . ')';
			$this->_addCriteria($field, $s);
		} else if ($value === null)
			$this->_addCriteria($field, 'IS NULL');
		else
			$this->_addCriteria($field, '=', $value);
		return ($this);
	}
	/**
	 * Add a non equality criterion.
	 * @param	string				$field	Field name.
	 * @param	null|int|float|string|array	$value	Comparison value(s).
	 * @return	\Temma\Dao\Criteria	The current object.
	 */
	public function different(string $field, null|int|float|string|array $value) : \Temma\Dao\Criteria {
		$s = '';
		if (is_array($value)) {
			$values = [];
			foreach ($value as $v)
				$values[] = $this->_db->quote($v);
			$s = 'NOT IN (' . implode(',', $values) . ')';
			$this->_addCriteria($field, $s);
		} else if ($value === null)
			$this->_addCriteria($field, 'IS NOT NULL');
		else
			$this->_addCriteria($field, '!=', $value);
		return ($this);
		
	}
	/**
	 * Add a criterion on a string.
	 * @param	string	$field	Field name.
	 * @param	string	$value	Comparison value.
	 * @return	\Temma\Dao\Criteria	The current object.
	 */
	public function like(string $field, string $value) : \Temma\Dao\Criteria {
		$this->_addCriteria($field, 'LIKE', $value);
		return ($this);
	}
	/**
	 * Add a criterion on a string.
	 * @param	string	$field	Field name.
	 * @param	string	$value	Comparison value.
	 * @return	\Temma\Dao\Criteria	The current object.
	 */
	public function notLike(string $field, string $value) : \Temma\Dao\Criteria {
		$this->_addCriteria($field, 'NOT LIKE', $value);
		return ($this);
	}
	/**
	 * Add a "less than" criterion.
	 * @param	string			$field	Field name.
	 * @param	int|float|string	$value	Comparison value.
	 * @return	\Temma\Dao\Criteria	The current object.
	 */
	public function lessThan(string $field, int|float|string $value) : \Temma\Dao\Criteria {
		$this->_addCriteria($field, '<', $value);
		return ($this);
	}
	/**
	 * Add a "greater than" criterion.
	 * @param	string			$field	Field name.
	 * @param	int|float|string	$value	Comparison value.
	 * @return	\Temma\Dao\Criteria	The current object.
	 */
	public function greaterThan(string $field, int|float|string $value) : \Temma\Dao\Criteria {
		$this->_addCriteria($field, '>', $value);
		return ($this);
	}
	/**
	 * Add a "less or equal to" criterion.
	 * @param	string			$field	Field name.
	 * @param	int|float|string	$value	Comparison value.
	 * @return	\Temma\Dao\Criteria	The current object.
	 */
	public function lessOrEqualTo(string $field, int|float|string $value) : \Temma\Dao\Criteria {
		$this->_addCriteria($field, '<=', $value);
		return ($this);
	}
	/**
	 * Add a "grater or equal to" criterion.
	 * @param	string			$field	Field name.
	 * @param	int|float|string	$value	Comparison value.
	 * @return	\Temma\Dao\Criteria	L'instance de l'objet courant.
	 */
	public function greaterOrEqualTo(string $field, int|float|string $value) : \Temma\Dao\Criteria {
		$this->_addCriteria($field, '>=', $value);
		return ($this);
	}

	/* ********************** ALIAS ********************************** */
	public function has(string $field) : \Temma\Dao\Criteria {
		return ($this->is($field));
	}
	public function hasNot(string $field) : \Temma\Dao\Criteria {
		return ($this->isNot($field));
	}
	public function eq(string $field, null|int|float|string|array $value) : \Temma\Dao\Criteria {
		return ($this->equal($field, $value));
	}
	public function ne(string $field, null|int|float|string|array $value) : \Temma\Dao\Criteria {
		return ($this->different($field, $value));
	}
	public function lt(string $field, int|float|string $value) : \Temma\Dao\Criteria {
		return ($this->lessThan($field, $value));
	}
	public function gt(string $field, int|float|string $value) : \Temma\Dao\Criteria {
		return ($this->greaterThan($field, $value));
	}
	public function le(string $field, int|float|string $value) : \Temma\Dao\Criteria {
		return ($this->lessOrEqualTo($field, $value));
	}
	public function ge(string $field, int|float|string $value) : \Temma\Dao\Criteria {
		return ($this->greaterOrEqualTo($field, $value));
	}

	/* ********************** PRIVATE METHODS *********************** */
	/**
	 * Add a search criterion using the default combination type.
	 * @param	string	$field		Field name.
	 * @param	string	$operator	(optional) Search operator. (default: '')
	 * @param	mixed	$value		(optional) Search value.
	 */
	protected function _addCriteria(string $field, string $operator='', mixed $value=null) : void {
		$this->_addTypedCriteria($this->_type, $field, $operator, $value);
	}
	/**
	 * Add a search criterion to the internal list.
	 * @param	string	$type		Criterion's combination type.
	 * @param	string	$field		Field name.
	 * @param	string	$operator	(optional) Search operator. (default: '')
	 * @param	string	$value		(optional) Search value.
	 */
	protected function _addTypedCriteria(string $type, string $field, string $operator='', ?string $value=null) : void {
		$field = $this->_dao->getFieldName($field);
		if ($operator)
			$criteria = "`$field` $operator " . (isset($value) ? $this->_db->quote($value) : '');
		else
			$criteria = $field;
		$this->_elements[] = [$type, $criteria];
	}
}

