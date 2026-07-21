<?php
/*
	This file is part of KD2FW -- <http://dev.kd2.org/>

	Copyright (c) 2001-2020 BohwaZ <http://bohwaz.net/>
	All rights reserved.

	KD2FW is free software: you can redistribute it and/or modify
	it under the terms of the GNU Affero General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	Foobar is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU Affero General Public License for more details.

	You should have received a copy of the GNU Affero General Public License
	along with KD2FW.  If not, see <https://www.gnu.org/licenses/>.
*/

/**
 * DB: a generic wrapper around PDO, adding easier access functions
 *
 * @author  bohwaz http://bohwaz.net/
 * @license AGPLv3
 */

namespace KD2\DB;

use PDO;
use PDOException;
use PDOStatement;

class DB_Exception extends \RuntimeException {}

class DB
{
	/**
	 * @var int
	 */
	protected $transaction = 0;

	/**
	 * Attributes for PDO instance
	 * @var array
	 */
	protected $pdo_attributes = [
		PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
		PDO::ATTR_TIMEOUT            => 5, // in seconds
		PDO::ATTR_EMULATE_PREPARES   => false,
	];

	/**
	 * Current driver
	 * @var null
	 */
	protected $driver;

	/**
	 * Store PDO object
	 * @var null
	 */
	protected $pdo;

	protected $sqlite_functions = [
		'base64_encode'      => 'base64_encode',
		'rank'               => [__CLASS__, 'sqlite_rank'],
		'haversine_distance' => [__CLASS__, 'sqlite_haversine'],
		'escape_like'        => ['$this', 'escapeLike'],
	];

	protected $sqlite_collations = [
	];

	/**
	 * Statements cache
	 * @var array
	 */
	protected $statements = [];

	protected $callback = null;

	/**
	 * Class construct, expects a driver configuration
	 * @param array $driver Driver configurtaion
	 */
	public function __construct(string $name, array $params)
	{
		$driver = (object) [
			'type'     => $name,
			'url'      => null,
			'user'     => null,
			'password' => null,
			'options'  => [],
			'tables_prefix' => '',
		];

		if ($name == 'mysql')
		{
			if (empty($params['host']))
			{
				throw new \BadMethodCallException('No host parameter passed.');
			}

			if (empty($params['user']))
			{
				throw new \BadMethodCallException('No user parameter passed.');
			}

			if (empty($params['password']))
			{
				throw new \BadMethodCallException('No password parameter passed.');
			}

			if (empty($params['charset']))
			{
				$params['charset'] = 'utf8mb4';
			}

			if (empty($params['port']))
			{
				$params['port'] = 3306;
			}

			$driver->url = sprintf('mysql:charset=%s;host=%s;port=%d', $params['charset'], $params['host'], $params['port']);

			if (!empty($params['database']))
			{
				$driver->url .= ';dbname=' . $params['database'];
			}

			$driver->user = $params['user'];
			$driver->password = $params['password'];

			if (empty($this->pdo_attributes[PDO::MYSQL_ATTR_INIT_COMMAND])) {
				$this->pdo_attributes[PDO::MYSQL_ATTR_INIT_COMMAND] = sprintf('SET NAMES %s COLLATE %s;', $params['charset'], 'utf8mb4_unicode_ci');
			}
		}
		else if ($name == 'sqlite')
		{
			if (empty($params['file']))
			{
				throw new \BadMethodCallException('No file parameter passed.');
			}

			$driver->url = 'sqlite:' . $params['file'];

			if (isset($params['flags']) && defined('PDO::SQLITE_ATTR_OPEN_FLAGS')) {
				$driver->options[PDO::SQLITE_ATTR_OPEN_FLAGS] = $params['flags'];
			}
		}
		else
		{
			throw new \BadMethodCallException('Invalid driver name.');
		}

		$this->driver = $driver;
	}

	public function __destruct()
	{
		$this->statements = [];
	}

	/**
	 * Connect to the currently defined driver if needed
	 * @return void
	 */
	public function connect(): void
	{
		if ($this->pdo)
		{
			return;
		}

		if ($this->callback) {
			call_user_func($this->callback, __FUNCTION__, null, $this);
		}

		try {
			$this->pdo = new PDO($this->driver->url, $this->driver->user, $this->driver->password, $this->driver->options);

			// Set attributes
			foreach ($this->pdo_attributes as $attr => $value)
			{
				$this->pdo->setAttribute($attr, $value);
			}
		}
		catch (PDOException $e)
		{
			// Catch exception to avoid showing password in backtrace
			throw new PDOException('Unable to connect to database. Check username and password.');
		}

		if ($this->driver->type == 'sqlite')
		{
			// Enhance SQLite with default functions
			foreach ($this->sqlite_functions as $name => $callback)
			{
				if (is_array($callback) && $callback[0] === '$this') {
					$callback = [$this, $callback[1]];
				}

				$this->pdo->sqliteCreateFunction($name, $callback);
			}

			// Force to rollback any outstanding transaction
			register_shutdown_function(function () {
				if ($this->inTransaction())
				{
					$this->rollback();
				}
			});
		}

		$this->driver->password = '******';
	}

	public function close(): void
	{
		if ($this->callback) {
			call_user_func($this->callback, __FUNCTION__, null, $this);
		}

		$this->pdo = null;
	}

	protected function applyTablePrefix(string $statement): string
	{
		if (strpos('__PREFIX__', $statement) !== false)
		{
			$statement = preg_replace('/(?<=\s|^)__PREFIX__(?=\w)/', $this->driver->tables_prefix, $statement);
		}

		return $statement;
	}

	public function query(string $statement)
	{
		$this->connect();
		$statement = $this->applyTablePrefix($statement);

		if ($this->callback) {
			call_user_func($this->callback, __FUNCTION__, 'before', $this, ... func_get_args());
		}

		$out = $this->pdo->query($statement);

		if ($this->callback) {
			call_user_func($this->callback, __FUNCTION__, 'after', $this, ... func_get_args());
		}

		return $out;
	}

	/**
	 * Execute an SQL statement and return the number of affected rows
	 * returns the number of rows that were modified or deleted by the SQL statement you issued. If no rows were affected, returns 0.
	 * It may return FALSE, even if the operation completed successfully
	 *
	 * @see https://www.php.net/manual/en/pdo.exec.php
	 * @param  string $statement SQL Query
	 * @return bool|int
	 */
	public function exec(string $statement)
	{
		$this->connect();
		$statement = $this->applyTablePrefix($statement);

		if ($this->callback) {
			call_user_func($this->callback, __FUNCTION__, 'before', $this, ... func_get_args());
		}

		$out = $this->pdo->exec($statement);

		if ($this->callback) {
			call_user_func($this->callback, __FUNCTION__, 'after', $this, ... func_get_args());
		}

		return $out;
	}

	public function execMultiple(string $statement)
	{
		$this->connect();

		if ($this->callback) {
			call_user_func($this->callback, __FUNCTION__, 'before', $this, ... func_get_args());
		}

		$this->begin();

		$emulate = null;

		// Store user-set prepared emulation setting for later
		if ($this->driver->type == 'mysql') {
			$emulate = $this->pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
		}

		try
		{
			if ($this->driver->type == 'mysql')
			{
				// required to allow multiple queries in same statement
				$this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

				$st = $this->prepare($statement);
				$st->execute();

				while ($st->nextRowset())
				{
					// Iterate over rowsets, see https://bugs.php.net/bug.php?id=61613
				}

				$return = $this->commit();
			}
			else
			{
				$return = $this->pdo->exec($statement);
				$this->commit();
			}
		}
		catch (PDOException $e)
		{
			$this->rollBack();
			throw $e;
		}
		finally
		{
			// Restore prepared statement attribute
			if ($this->driver->type == 'mysql') {
				$this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulate);
			}
		}

		if ($this->callback) {
			call_user_func($this->callback, __FUNCTION__, 'after', $this, ... func_get_args());
		}

		return $return;
	}

	public function createFunction(string $name, callable $callback): bool
	{
		if ($this->driver->type != 'sqlite')
		{
			throw new \LogicException('This driver does not support functions.');
		}

		if ($this->pdo)
		{
			return $this->pdo->sqliteCreateFunction($name, $callback);
		}
		else
		{
			$this->sqlite_functions[$name] = $callback;
			return true;
		}
	}

	public function import(string $file)
	{
		if (!is_readable($file))
		{
			throw new \RuntimeException(sprintf('Cannot read file %s', $file));
		}

		return $this->execMultiple(file_get_contents($file));
	}

	public function prepare(string $statement, array $driver_options = [])
	{
		$this->connect();
		$statement = $this->applyTablePrefix($statement);

		if ($this->callback) {
			call_user_func($this->callback, __FUNCTION__, 'before', $this, ... func_get_args());
		}

		$return = $this->pdo->prepare($statement, $driver_options);

		if ($this->callback) {
			call_user_func($this->callback, __FUNCTION__, 'after', $this, ... func_get_args());
		}

		return $return;
	}

	public function begin()
	{
		$this->transaction++;

		if ($this->transaction == 1) {
			$this->connect();

			if ($this->callback) {
				call_user_func($this->callback, __FUNCTION__, null, $this, ... func_get_args());
			}

			return $this->pdo->beginTransaction();
		}

		return true;
	}

	public function inTransaction()
	{
		return $this->transaction > 0;
	}

	public function commit()
	{
		if ($this->transaction == 0) {
			throw new \LogicException('Cannot commit a transaction: no transaction is running');
		}

		$this->transaction--;

		if ($this->transaction == 0) {
			$this->connect();
			$return = $this->pdo->commit();

			if ($this->callback) {
				call_user_func($this->callback, __FUNCTION__, null, $this, ... func_get_args());
			}

			return $return;
		}

		return true;
	}

	public function rollback()
	{
		if ($this->transaction == 0) {
			throw new \LogicException('Cannot rollback a transaction: no transaction is running');
		}

		$this->transaction = 0;
		$this->connect();
		$return = $this->pdo->rollBack();

		if ($this->callback) {
			call_user_func($this->callback, __FUNCTION__, null, $this, ... func_get_args());
		}

		return $return;
	}

	public function lastInsertId(?string $name = null): string
	{
		$this->connect();
		return $this->pdo->lastInsertId($name);
	}

	public function lastInsertRowId(): string
	{
		return $this->lastInsertId();
	}

	/**
	 * Quotes a string for use in a query
	 * @see https://www.php.net/manual/en/pdo.quote.php
	 */
	public function quote(string $value, int $parameter_type = PDO::PARAM_STR): string
	{
		if ($this->driver->type == 'sqlite')
		{
			// PHP quote() is truncating strings on NUL bytes
			// https://bugs.php.net/bug.php?id=63419

			$value = str_replace("\0", '\\0', $value);
		}

		$this->connect();
		return $this->pdo->quote($value, $parameter_type);
	}

	/**
	 * Quotes an identifier (table name or column name) for use in a query
	 * @param  string $value Identifier to quote
	 * @return string Quoted identifier
	 */
	public function quoteIdentifier(string $value): string
	{
		// see https://www.codetinkerer.com/2015/07/08/escaping-column-and-table-names-in-mysql-part2.html
		if ($this->driver->type == 'mysql')
		{
			if (strlen($value) > 64)
			{
				throw new \OverflowException('MySQL column or table names cannot be longer than 64 characters.');
			}

			if (substr($value, 0, -1) == ' ')
			{
				throw new \UnexpectedValueException('MySQL column or table names cannot end with a space character');
			}

			if (preg_match('/[\0\.\/\\\\]/', $value))
			{
				throw new \UnexpectedValueException('Invalid MySQL column or table name');
			}

			return sprintf('`%s`', str_replace('`', '``', $value));
		}
		else
		{
			return sprintf('"%s"', str_replace('"', '""', $value));
		}
	}

	public function escapeLike(string $value, string $escape_character): string
	{
		return strtr($value, [
			$escape_character => $escape_character . $escape_character,
			'%' => $escape_character . '%',
			'_' => $escape_character . '_',
		]);
	}

	/**
	 * Quote identifier, eg. 'users.index' => '"users"."index"'
	 */
	public function quoteIdentifiers(string $value): string
	{
		$value = explode('.', $value);
		$value = array_map([$this, 'quoteIdentifier'], $value);
		return implode('.', $value);
	}

	public function preparedQuery(string $query, ...$args)
	{
		$key = md5($query . implode(',', array_keys($args)));

		// Use statements cache!
		if (!array_key_exists($key, $this->statements)) {
			$this->statements[$key] = $this->prepare($query);
		}

		return $this->execute($this->statements[$key], ...$args);
	}

	public function execute($statement, ...$args)
	{
        // Only one argument, which is an array: this is an associative array
        if (isset($args[0]) && is_array($args[0]))
        {
        	$args = $args[0];
        }

        if (!is_array($args) && !is_object($args)) {
        	throw new \InvalidArgumentException('Expecting an array or object as query arguments');
        }

        $args = (array) $args;

        foreach ($args as &$arg)
        {
        	if (is_object($arg) && $arg instanceof \DateTimeInterface)
        	{
        		$arg = $arg->format('Y-m-d H:i:s');
        	}
        }

        unset($arg);

		if ($this->callback) {
			call_user_func($this->callback, __FUNCTION__, 'before', $this, ... func_get_args());
		}

		$statement->execute($args);

		if ($this->callback) {
			call_user_func($this->callback, __FUNCTION__, 'after', $this, ... func_get_args());
		}

		return $statement;
	}

	public function iterate(string $query, ...$args): iterable
	{
		$st = $this->preparedQuery($query, ...$args);

		while ($row = $st->fetch())
		{
			yield $row;
		}

		unset($st);

		return;
	}

	public function get(string $query, ...$args): array
	{
		return $this->preparedQuery($query, ...$args)->fetchAll();
	}

	/**
	 * Return results from a SQL query in an associative flat array:
	 * SELECT id, name FROM table;
	 * -> [42 => "PJ Harvey", 44 => "Tori Amos",...]
	 */
	public function getAssoc(string $query, ...$args): array
	{
		$st = $this->preparedQuery($query, ...$args);
		$out = [];

		while ($row = $st->fetch(PDO::FETCH_NUM))
		{
			$out[$row[0]] = $row[1];
		}

		return $out;
	}

	/**
	 * Return results grouped by the first column:
	 * SELECT id, * FROM table;
	 * -> [42 => {id: 42, date: ..., name...}, 43 => ...]
	 */
	public function getGrouped(string $query, ...$args): array
	{
		$st = $this->preparedQuery($query, ...$args);
		$out = [];

		while ($row = $st->fetch(PDO::FETCH_ASSOC))
		{
			$out[current($row)] = (object) $row;
		}

		return $out;
	}

	/**
	 * Return results grouped by columns, multidimensional
	 * SELECT month, * FROM table;
	 * -> ['202101' => [{id: 42, month: ..., name...}, {id: 43, ...}]]
	 */
	public function getGroupedMulti(string $query, ...$args): array
	{
		$r = $this->iterate($query, ...$args);
		$out = [];

		foreach ($r as $row)
		{
			$row = (array)$row;
			$levels = count($row) - 1;
			$prev =& $out;

			for ($i = 0; $i < $levels; $i++) {
				$key = current($row);
				if (!isset($prev[$key])) {
					$prev[$key] = [];
				}

				$prev =& $prev[$key];
				next($row);
			}

			$prev = (object)$row;
		}

		return $out;
	}

	/**
	 * Return results associative by the first column:
	 * SELECT month, name, SUM(amount) FROM table;
	 * -> ['202101' => ['Tori Amos' =>  20000,...]]
	 */
	public function getAssocMulti(string $query, ...$args): array
	{
		$r = $this->iterate($query, ...$args);
		$out = [];

		foreach ($r as $row)
		{
			$row = (array)$row;
			$levels = count($row) - 1;
			$prev =& $out;

			for ($i = 0; $i < $levels; $i++) {
				$key = current($row);
				if (!isset($prev[$key])) {
					$prev[$key] = [];
				}

				$prev =& $prev[$key];
				next($row);
			}

			$prev = end($row);
		}

		return $out;
	}

	/**
	 * Runs a query and returns the first row
	 * @param  string $query SQL query
	 * @return object
	 *
	 * Accepts one or more arguments as part of bindings for the statement
	 */
	public function first(string $query, ...$args)
	{
		$st = $this->preparedQuery($query, ...$args);

		return $st->fetch();
	}

	/**
	 * Runs a query and returns the first column
	 * @param  string $query SQL query
	 * @return object
	 *
	 * Accepts one or more arguments as part of bindings for the statement
	 */
	public function firstColumn(string $query, ...$args)
	{
		$st = $this->preparedQuery($query, ...$args);

		return $st->fetchColumn();
	}

	/**
	 * Inserts a row in $table, using $fields as data to fill
	 * @param  string $table  Table name
	 * @param  array|object $fields List of columns as an associative array
	 * @param  null|string $clause INSERT clause (eg. 'OR IGNORE' etc.)
	 * @return boolean
	 */
	public function insert(string $table, $fields, ?string $clause = null)
	{
		assert(is_array($fields) || is_object($fields));

		$fields = (array) $fields;

		$fields_names = array_keys($fields);
		$fields_names = implode(', ', array_map([$this, 'quoteIdentifier'], $fields_names));

		$values = [];

		foreach ($fields as $key => $value) {
			$values[md5($key)] = $value;
		}

		$query = sprintf('INSERT %s INTO %s (%s) VALUES (:%s);', $clause, $this->quoteIdentifier($table),
			$fields_names, implode(', :', array_keys($values)));

		return (bool) $this->preparedQuery($query, $values);
	}

	/**
	 * Insert a row in $table, or if it already exists (according to primary key/unique constraint), it is ignored
	 * @param  string $table  Table name
	 * @param  array|object $fields List of columns as an associative array
	 * @return boolean
	 */
	public function insertIgnore(string $table, $fields)
	{
		if ($this->driver->type == 'mysql') {
			$clause = 'IGNORE';
		}
		elseif ($this->driver->type == 'sqlite') {
			$clause = 'OR IGNORE';
		}
		else {
			throw new \RuntimeException('Unsupported driver for INSERT IGNORE');
		}

		return $this->insert($table, $fields, $clause);
	}

	/**
	 * Updates lines in $table using $fields, selecting using $where
	 * @param  string       $table  Table name
	 * @param  array|object $fields List of fields to update
	 * @param  string       $where  Content of the WHERE clause
	 * @param  array|object $args   Arguments for the WHERE clause
	 * @return boolean
	 */
	public function update(string $table, $fields, ?string $where = null, $args = null)
	{
		assert(is_string($table));
		assert((is_string($where) && strlen($where)) || is_null($where));
		assert(is_array($fields) || is_object($fields));
		assert(is_null($args) || is_array($args) || is_object($args), 'Arguments for the WHERE clause must be an array or object');

		// Convert to array
		$fields = (array) $fields;
		$args = (array) $args;

		foreach ($args as $key => $arg) {
			if (is_int($key)) {
				throw new \LogicException('Arguments must be a named array, not an indexed array');
			}
		}

		// No fields to update? no need to do a query
		if (empty($fields))
		{
			return false;
		}

		$column_updates = [];

		foreach ($fields as $key => $value)
		{
			if (is_object($value) && $value instanceof \DateTimeInterface)
			{
				$value = $value->format('Y-m-d H:i:s');
			}

			// Append to arguments
			$args['field_' . $key] = $value;

			$column_updates[] = sprintf('%s = :field_%s', $this->quoteIdentifier($key), $key);
		}

		if (is_null($where))
		{
			$where = '1';
		}

		// Final query assembly
		$column_updates = implode(', ', $column_updates);
		$query = sprintf('UPDATE %s SET %s WHERE %s;', $this->quoteIdentifier($table), $column_updates, $where);

		return (bool) $this->preparedQuery($query, $args);
	}

	/**
	 * Deletes rows from a table
	 * @param  string $table Table name
	 * @param  string $where WHERE clause
	 * @return boolean
	 *
	 * Accepts one or more arguments as bindings for the WHERE clause.
	 * Warning! If run without a $where argument, will delete all rows from a table!
	 */
	public function delete(string $table, string $where, ...$args)
	{
		$query = sprintf('DELETE FROM %s WHERE %s;', $table, $where);
		return (bool) $this->preparedQuery($query, ...$args);
	}

	/**
	 * Returns true if the condition from the WHERE clause is valid and a row exists
	 * @param  string $table Table name
	 * @param  string $where WHERE clause
	 * @return boolean
	 */
	public function test(string $table, string $where, ...$args): bool
	{
		$query = sprintf('SELECT 1 FROM %s WHERE %s LIMIT 1;', $this->quoteIdentifier($table), $where);
		return (bool) $this->firstColumn($query, ...$args);
	}

	/**
	 * Returns the number of rows in a table according to a WHERE clause
	 * @param  string $table Table name
	 * @param  string $where WHERE clause
	 * @return integer
	 */
	public function count(string $table, string $where = '1', ...$args): int
	{
		$query = sprintf('SELECT COUNT(*) FROM %s WHERE %s LIMIT 1;', $this->quoteIdentifier($table), $where);
		return (int) $this->firstColumn($query, ...$args);
	}

	/**
	 * Generate a WHERE clause, can be called as a short notation:
	 * where('id', '42')
	 * or including the comparison operator:
	 * where('id', '>', '42')
	 * It accepts arrays or objects as the value. If no operator is specified, 'IN' is used.
	 * @param  string $name Column name
	 * @param  string $operator Operator
	 * @param  mixed $value
	 * @return string
	 */
	public function where(string $name): string
	{
		$num_args = func_num_args();

		$value = func_get_arg($num_args - 1);

		if (is_object($value) && $value instanceof \DateTimeInterface)
		{
			$value = $value->format('Y-m-d H:i:s');
		}

		if (is_object($value))
		{
			$value = (array) $value;
		}

		if ($num_args == 2)
		{
			if (is_array($value))
			{
				$operator = 'IN';
			}
			elseif (is_null($value))
			{
				$operator = 'IS';
			}
			else
			{
				$operator = '=';
			}
		}
		elseif ($num_args == 3)
		{
			$operator = strtoupper(func_get_arg(1));

			if (is_array($value))
			{
				if ($operator == 'IN' || $operator == '=')
				{
					$operator = 'IN';
				}
				elseif ($operator == 'NOT IN' || $operator == '!=')
				{
					$operator = 'NOT IN';
				}
				else
				{
					throw new \InvalidArgumentException(sprintf('Invalid operator \'%s\' for value of type array or object (only IN and NOT IN are accepted)', $operator));
				}
			}
			elseif (is_null($value))
			{
				if ($operator != '=' && $operator != '!=')
				{
					throw new \InvalidArgumentException(sprintf('Invalid operator \'%s\' for value of type null (only = and != are accepted)', $operator));
				}

				$operator = ($operator == '=') ? 'IS' : 'IS NOT';
			}
		}
		else
		{
			throw new \BadMethodCallException('Method ::where requires 2 or 3 parameters');
		}

		if (is_array($value))
		{
			$value = array_values($value);

			array_walk($value, function (&$row) {
				$row = is_int($row) || is_float($row) ? $row : $this->quote($row);
			});

			$value = sprintf('(%s)', implode(', ', $value));
		}
		elseif (is_null($value))
		{
			$value = 'NULL';
		}
		elseif (is_bool($value))
		{
			$value = $value ? 'TRUE' : 'FALSE';
		}
		elseif ($operator === 'LIKE') {
			$value = $this->quote($value) . ' ESCAPE \'\\\'';
		}
		elseif (is_string($value))
		{
			$value = $this->quote($value);
		}

		return sprintf('%s %s %s', $this->quoteIdentifier($name), $operator, $value);
	}

	/**
	 * SQLite search ranking user defined function
	 * Converted from C from SQLite manual: https://www.sqlite.org/fts3.html#appendix_a
	 * @param  string $aMatchInfo
	 * @return double Score
	 */
	static public function sqlite_rank($aMatchInfo)
	{
		$iSize = 4; // byte size
		$iPhrase = (int) 0;                 // Current phrase //
		$score = (double)0.0;               // Value to return //

		/* Check that the number of arguments passed to this function is correct.
		** If not, jump to wrong_number_args. Set aMatchinfo to point to the array
		** of unsigned integer values returned by FTS function matchinfo. Set
		** nPhrase to contain the number of reportable phrases in the users full-text
		** query, and nCol to the number of columns in the table.
		*/
		$aMatchInfo = (string) func_get_arg(0);
		$nPhrase = ord(substr($aMatchInfo, 0, $iSize));
		$nCol = ord(substr($aMatchInfo, $iSize, $iSize));

		if (func_num_args() > (1 + $nCol))
		{
			throw new \Exception("Invalid number of arguments : ".$nCol);
		}

		// Iterate through each phrase in the users query. //
		for ($iPhrase = 0; $iPhrase < $nPhrase; $iPhrase++)
		{
			$iCol = (int) 0; // Current column //

			/* Now iterate through each column in the users query. For each column,
			** increment the relevancy score by:
			**
			**   (<hit count> / <global hit count>) * <column weight>
			**
			** aPhraseinfo[] points to the start of the data for phrase iPhrase. So
			** the hit count and global hit counts for each column are found in
			** aPhraseinfo[iCol*3] and aPhraseinfo[iCol*3+1], respectively.
			*/
			$aPhraseinfo = substr($aMatchInfo, (2 + $iPhrase * $nCol * 3) * $iSize);

			for ($iCol = 0; $iCol < $nCol; $iCol++)
			{
				$nHitCount = ord(substr($aPhraseinfo, 3 * $iCol * $iSize, $iSize));
				$nGlobalHitCount = ord(substr($aPhraseinfo, (3 * $iCol + 1) * $iSize, $iSize));
				$weight = ($iCol < func_num_args() - 1) ? (double) func_get_arg($iCol + 1) : 0;

				if ($nHitCount > 0 && $nGlobalHitCount != 0)
				{
					$score += ((double)$nHitCount / (double)$nGlobalHitCount) * $weight;
				}
			}
		}

		return $score;
	}

	/**
	 * Haversine distance between two points
	 * @return double Distance in kilometres
	 */
	static public function sqlite_haversine()
	{
		if (count($geo = array_map('deg2rad', array_filter(func_get_args(), 'is_numeric'))) != 4)
		{
			throw new \InvalidArgumentException('4 arguments expected for haversine_distance');
		}

		return round(acos(sin($geo[0]) * sin($geo[2]) + cos($geo[0]) * cos($geo[2]) * cos($geo[1] - $geo[3])) * 6372.8, 3);
	}
}
