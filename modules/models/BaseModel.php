<?php

/**
 * @package dabl
 */
abstract class BaseModel {
	const MAX_INSTANCE_POOL_SIZE = 100;

	/**
	 * Array to contain names of modified columns
	 */
	protected $_modifiedColumns = array();

	/**
	 * Whether or not to cache results in the internal object cache
	 */
	protected $_cacheResults = true;

	/**
	 * Whether or not to save dates as formatted date/time strings
	 */
	protected $_formatDates = true;

	/**
	 * Whether or not this is a new object
	 */
	protected $_isNew = true;

	/**
	 * Errors from the validate() step of saving
	 */
	protected $_validationErrors = array();

	/**
	 * Returns an array of objects of class $class from
	 * the rows of a PDOStatement(query result)
	 *
	 * @param PDOStatement $result
	 * @param string $class_name name of class to create
	 * @return BaseModel[]
	 */
	static function fromResult(PDOStatement $result, $class_name, $write_cache = false) {
		if (!$class_name)
			throw new Exception('No class name given');

		$objects = array();
		if (is_array($class_name)) {
			$class_names = $class_name;
			unset($class_name);
			$starting_column_number = 0;
			while ($values = $result->fetch(PDO::FETCH_NUM)) {
				unset($main_object);
				$startcol = 0;
				foreach ($class_names as $key => $class_name) {
					$object = new $class_name;
					if(!$object->fromNumericResultArray($values, $startcol))
							continue;

					if ($write_cache)
						$object->insertIntoPool($object);

					if (!isset($main_object)) {
						$main_object = $objects[] = $object;
					} else {
						if(method_exists($main_object, 'set'.$class_name))
							$main_object->{'set'.$class_name}($object);
						else
							$main_object->{$class_name} = $object;
					}
				}
			}
		} else {
			// PDO::FETCH_PROPS_LATE is required to call the ctor after hydrating the fields
			$flags = PDO::FETCH_CLASS;
			if (defined('PDO::FETCH_PROPS_LATE')) $flags |= PDO::FETCH_PROPS_LATE;
			$result->setFetchMode($flags, $class_name);
			while ($object = $result->fetch()) {
				$object = clone $object;
				$object->castInts();
				$object->setNew(false);
				$objects[] = $object;
				if ($write_cache)
					$object->insertIntoPool($object);
			}
		}
		return $objects;
	}

	/**
	 * Loads values from the array returned by PDOStatement::fetch(PDO::FETCH_NUM)
	 * @param array $values
	 * @param int $startcol
	 */
	function fromNumericResultArray($values, &$startcol) {
		foreach ($this->getColumnNames() as $column_name)
			$this->{$column_name} = $values[$startcol++];
		if($this->getPrimaryKeys() && !$this->hasPrimaryKeyValues())
			return false;
		$this->castInts();
		$this->setNew(false);
		return true;
	}

	/**
	 * Loads values from the array returned by PDOStatement::fetch(PDO::FETCH_ASSOC)
	 * @param array $values
	 */
	function fromAssociativeResultArray($values) {
		foreach ($this->getColumnNames() as $column_name){
			if(array_key_exists($column_name, $values))
				$this->{$column_name} = $values[$column_name];
		}
		if($this->getPrimaryKeys() && !$this->hasPrimaryKeyValues())
			return false;
		$this->castInts();
		$this->setNew(false);
		return true;
	}

	/**
	 * Creates new instance of $this and
	 * @return BaseModel
	 */
	function copy() {
		$class = get_class($this);
		$new_object = new $class;
		$new_object->fromArray($this->toArray());

		if ($this->getPrimaryKey()) {
			$pk = $this->getPrimaryKey();
			$set_pk_method = "set$pk";
			$new_object->$set_pk_method(null);
		}
		return $new_object;
	}

	/**
	 * Checks whether any of the columns have been modified from the database values.
	 * @return bool
	 */
	function isModified() {
		return (bool) $this->getModifiedColumns();
	}

	/**
	 * Checks whether the given column is in the modified array
	 * @return bool
	 */
	function isColumnModified($columnName) {
		return in_array(strtolower($columnName), array_map('strtolower', $this->_modifiedColumns));
	}

	/**
	 * Returns an array of the names of modified columns
	 * @return array
	 */
	function getModifiedColumns() {
		return $this->_modifiedColumns ? $this->_modifiedColumns : array();
	}

	/**
	 * Clears the array of modified column names
	 */
	function resetModified() {
		$this->_modifiedColumns = array();
	}

	/**
	 * Populates $this with the values of an associative Array.
	 * Array keys must match column names to be used.
	 * @param array $array
	 */
	function fromArray($array) {
		$columns = $this->getColumnNames();
		foreach ($array as $column => &$v) {
			if (is_string($column) === false || in_array($column, $columns) === false)
				continue;
			$this->{'set' . $column}($v);
		}
	}

	/**
	 * Returns an associative Array with the values of $this.
	 * Array keys match column names.
	 * @return array of BaseTable Objects
	 */
	function toArray() {
		$array = array();
		foreach ($this->getColumnNames() as $column)
			$array[$column] = $this->{'get' . $column}();
		return $array;
	}

	/**
	 * Sets whether to use cached results for foreign keys or to execute
	 * the query each time, even if it hasn't changed.
	 * @param bool $value[optional]
	 */
	function setCacheResults($value=true) {
		$this->_cacheResults = (bool) $value;
	}

	/**
	 * Returns true if this object is set to cache results
	 * @return bool
	 */
	function getCacheResults() {
		return (bool) $this->_cacheResults;
	}

	/**
	 * Returns true if this table has primary keys and if all of the primary values are not null
	 * @return bool
	 */
	function hasPrimaryKeyValues() {
		$pks = $this->getPrimaryKeys();
		if (!$pks)
			return false;

		foreach ($pks as &$pk)
			if ($this->$pk === null)
				return false;
		return true;
	}

	/**
	 * Returns an array of all primary key values.
	 *
	 * @return mixed[]
	 */
	function getPrimaryKeyValues() {
		$arr = array();
		$pks = $this->getPrimaryKeys();

		foreach ($pks as &$pk) {
			$arr[] = $this->{"get$pk"}();
		}

		return $arr;
	}

	/**
	 * Returns true if the column values validate.
	 * @return bool
	 */
	function validate() {
		$this->_validationErrors = array();
		return true;
	}

	/**
	 * See $this->validate()
	 * @return array Array of errors that occured when validating object
	 */
	function getValidationErrors() {
		return $this->_validationErrors;
	}

	/**
	 * Creates and executess DELETE Query for this object
	 * Deletes any database rows with a primary key(s) that match $this
	 * NOTE/BUG: If you alter pre-existing primary key(s) before deleting, then you will be
	 * deleting based on the new primary key(s) and not the originals,
	 * leaving the original row unchanged(if it exists).  Also, since NULL isn't an accurate way
	 * to look up a row, I return if one of the primary keys is null.
	 * @return int number of records deleted
	 */
	function delete() {
		$conn = $this->getConnection();
		$pks = $this->getPrimaryKeys();
		if (!$pks

			)throw new Exception("This table has no primary keys");
		$q = new Query();
		foreach ($pks as &$pk) {
			if ($this->$pk === null)
				throw new Exception("Cannot delete using NULL primary key.");
			$q->addAnd($conn->quoteIdentifier($pk), $this->$pk);
		}
		$q->setLimit(1);
		$q->setTable($this->getTableName());
		$result = $this->doDelete($q, false);
		$this->removeFromPool($this);
		return $result;
	}

	/**
	 * Saves the values of $this to a row in the database.  If there is an
	 * existing row with a primary key(s) that matches $this, the row will
	 * be updated.  Otherwise a new row will be inserted.  If there is only
	 * 1 primary key, it will be set using the last_insert_id() function.
	 * NOTE/BUG: If you alter pre-existing primary key(s) before saving, then you will be
	 * updating/inserting based on the new primary key(s) and not the originals,
	 * leaving the original row unchanged(if it exists).
	 * @todo find a way to solve the above issue
	 * @return int number of records inserted or updated
	 */
	function save() {
		if (!$this->validate())
			return 0;

		if ($this->hasColumn('Created') && $this->isNew() && !$this->isColumnModified('Created')) {
			$this->setCreated(CURRENT_TIME);
		}
		if($this->hasColumn('Updated') && !$this->isColumnModified('Updated')) {
			$this->setUpdated(CURRENT_TIME);
		}

		if ($this->getPrimaryKeys()) {
			if ($this->isNew())
				return $this->insert();
			return $this->update();
		}
		return $this->replace();
	}

	/**
	 * Returns true if this has not yet been saved to the database
	 * @return bool
	 */
	function isNew() {
		return (bool) $this->_isNew;
	}

	/**
	 * Indicate whether this object has been saved to the database
	 * @param bool $bool
	 */
	function setNew($bool) {
		$this->_isNew = (bool) $bool;
	}

	/**
	 * Creates and executes INSERT query string for this object
	 * @return int
	 */
	protected function insert() {
		$conn = $this->getConnection();
		$pk = $this->getPrimaryKey();

		$fields = array();
		$values = array();
		$placeholders = array();
		foreach ($this->getColumnNames() as $column) {
			$value = $this->$column;
			if ($value === null && !$this->isColumnModified($column))
				continue;
			$fields[] = $conn->quoteIdentifier($column);
			$values[] = $value;
			$placeholders[] = '?';
		}

		$quotedTable = $conn->quoteIdentifier($this->getTableName());
		$queryString = "INSERT INTO $quotedTable (" . implode(", ", $fields) . ") VALUES (" . implode(', ', $placeholders) . ") ";

		$statement = new QueryStatement($conn);
		$statement->setString($queryString);
		$statement->setParams($values);

		$result = $statement->bindAndExecute();
		$count = $result->rowCount();

		if ($pk && $this->isAutoIncrement()) {
			if ($conn instanceof DBPostgres)
				$id = $conn->getId($this->getTableName(), $pk);
			elseif ($conn->isGetIdAfterInsert())
				$id = $conn->lastInsertId();
			$this->{"set$pk"}($id);
		}
		$this->resetModified();
		$this->setNew(false);

		$this->insertIntoPool($this);

		return $count;
	}

	/**
	 * Creates and executes REPLACE query string for this object.  Returns
	 * the number of affected rows.
	 * @return Int
	 */
	protected function replace() {
		$conn = $this->getConnection();
		$quotedTable = $conn->quoteIdentifier($this->getTableName());

		$fields = array();
		$values = array();
		foreach ($this->getColumnNames() as $column) {
			$fields[] = $conn->quoteIdentifier($column);
			$values[] = $this->$column;
			$placeholders[] = '?';
		}
		$queryString = "REPLACE INTO $quotedTable (" . implode(", ", $fields) . ") VALUES (" . implode(', ', $placeholders) . ") ";

		$statement = new QueryStatement($conn);
		$statement->setString($queryString);
		$statement->setParams($values);

		$result = $statement->bindAndExecute();
		$count = $result->rowCount();

		$this->resetModified();
		$this->setNew(false);

		return $count;
	}

	/**
	 * Creates and executes UPDATE query string for this object.  Returns
	 * the number of affected rows.
	 * @return Int
	 */
	protected function update() {
		$conn = $this->getConnection();
		$quotedTable = $conn->quoteIdentifier($this->getTableName());

		if (!$this->getPrimaryKeys())
			throw new Exception('This table has no primary keys');

		$fields = array();
		$values = array();
		foreach ($this->getModifiedColumns() as $column) {
			$fields[] = $conn->quoteIdentifier($column) . '=?';
			$values[] = $this->$column;
		}

		//If array is empty there is nothing to update
		if (!$fields)
			return 0;

		$pkWhere = array();
		foreach ($this->getPrimaryKeys() as $pk) {
			if ($this->$pk === null)
				throw new Exception('Cannot update with NULL primary key.');
			$pkWhere[] = $conn->quoteIdentifier($pk) . '=?';
			$values[] = $this->$pk;
		}

		$queryString = "UPDATE $quotedTable SET " . implode(", ", $fields) . " WHERE " . implode(" AND ", $pkWhere);
		$statement = new QueryStatement($conn);
		$statement->setString($queryString);
		$statement->setParams($values);
		$result = $statement->bindAndExecute();

		$this->resetModified();

		$this->removeFromPool($this);

		return $result->rowCount();
	}

	/**
	 * Cast returned values from the database into integers where appropriate.
	 */
	abstract function castInts();
}