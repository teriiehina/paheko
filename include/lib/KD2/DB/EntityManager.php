<?php

namespace KD2\DB;

use KD2\DB\DB;
use KD2\DB\SQLite3;
use KD2\DB\AbstractEntity;
use PDO;

class EntityManager
{
	protected $class;
	protected $db;

	static protected $_instances = [];
	static protected $_global_db;

	/**
	 * Returns an EntityManager instance linked to a specific Entity class
	 * @param  string $class Entity class name
	 * @return EntityManager
	 */
	static public function getInstance(string $class): self
	{
		// Create a new entity manager for this entity if it does not exist
		if (!array_key_exists($class, self::$_instances)) {
			// Check that the class is a child of AbstractEntity
			if (!is_a($class, AbstractEntity::class, true)) {
				throw new \InvalidArgumentException(sprintf('Class "%s" does not extend "%s"', $class, AbstractEntity::class));
			}

			// The entity manager works with SQL tables, so the entity needs to specify a table
			if (!defined($class . '::TABLE')) {
				throw new \InvalidArgumentException(sprintf('Class "%s" does not define a TABLE constant', $class));
			}

			self::$_instances[$class] = new EntityManager($class);
		}

		return self::$_instances[$class];
	}

	/**
	 * Sets the database manager used for all entity managers, unless they have a specific one
	 * @param DB $db
	 */
	static public function setGlobalDB(DB $db): void
	{
		self::$_global_db = $db;
	}

	/**
	 * Set local database object used for this entity manager
	 * @param DB|null $db Set to NULL to use the global manager
	 */
	public function setDB(?DB $db = null): void
	{
		$this->db = $db;
	}

	/**
	 * Returns the correct database object for this entity manager
	 */
	public function DB(): DB
	{
		if (null !== $this->db) {
			$db = $this->db;
		}
		else {
			$db = self::$_global_db;
		}

		if (null === $db) {
			throw new \LogicException('No DB object has been set');
		}

		return $db;
	}

	protected function __construct(string $class)
	{
		$this->class = $class;
	}

	/**
	 * Returns an Entity according to a query
	 * @param  string $class  Entity class name
	 * @param  string $query  SQL query
	 * @param  mixed ...$params Optional parameters to be used in the query
	 * @return null|AbstractEntity
	 */
	static public function findOne(string $class, string $query, ...$params)
	{
		return self::getInstance($class)->one($query, ...$params);
	}

	/**
	 * Returns an Entity from its ID
	 * @param  string $class  Entity class name
	 * @param  int $id  Entity ID
	 * @return null|AbstractEntity
	 */
	static public function findOneById(string $class, int $id, ?string $table = null)
	{
		$query = sprintf('SELECT * FROM %s WHERE id = ?;', $table ?? $class::TABLE);
		return self::findOne($class, $query, $id);
	}

	/**
	 * Formats a SQL query by replacing the table name with the entity table name
	 * @param  string $query SQL query
	 * @return string
	 */
	public function formatQuery(string $query): string
	{
		$class = $this->class;
		$query = str_replace('@TABLE', $class::TABLE, $query);
		return $query;
	}

	public function all(string $query, ...$params): array
	{
		$res = $this->iterate($query, ...$params);
		$out = [];

		foreach ($res as $row) {
			$out[] = $row;
		}

		return $out;
	}

	public function allAssoc(string $query, string $key, ...$params): array
	{
		$res = $this->iterate($query, ...$params);
		$out = [];

		foreach ($res as $row) {
			$out[$row->$key] = $row;
		}

		return $out;
	}

	public function iterate(string $query, ...$params): iterable
	{
		$db = $this->DB();
		$query = $this->formatQuery($query);
		$res = $db->preparedQuery($query, ...$params);

		if ($db instanceof SQLite3) {
			while ($row = $res->fetchArray(\SQLITE3_ASSOC)) {
				// If you are getting a row containing only NULL values
				// it probably means you are deleting rows before the iteration
				// has a chance to fetch it!
				$obj = new $this->class;
				$obj->exists(true);
				$obj->load($row);
				yield $obj;
			}

			$res->finalize();
		}
		else {
			while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
				$obj = new $this->class;
				$obj->exists(true);
				$obj->load($row);
				yield $obj;
			}
		}
	}

	public function one(string $query, ...$params)
	{
		$db = $this->DB();

		$query = $this->formatQuery($query);
		$res = $db->preparedQuery($query, $params);

		if ($db instanceof SQLite3) {
			$row = $res->fetchArray(\SQLITE3_ASSOC);
			$res->finalize();
		}
		else {
			$row = $res->fetch(PDO::FETCH_ASSOC);
		}

		if (false === $row) {
			return null;
		}

		$obj = new $this->class;
		$obj->exists(true);
		$obj->load($row);
		return $obj;
	}

	public function col(string $query, ...$params)
	{
		$query = $this->formatQuery($query);
		$db = $this->DB();
		return $db->firstColumn($query, ...$params);
	}

	public function count(string $where = '1', ...$params)
	{
		$where = $this->formatQuery($where);
		$db = $this->DB();
		return $db->count($this->class::TABLE, $where, ...$params);
	}

	public function save(AbstractEntity $entity, bool $selfcheck = true): bool
	{
		if ($selfcheck) {
			$entity->selfCheck();
		}

		$db = $this->DB();

		if ($entity->exists()) {
			if ($entity->isModified()) {
				$data = array_intersect_key($entity->asArray(true), $entity->getModifiedProperties());
				$return = $db->update($entity::TABLE, $data, $db->where('id', $entity->id()));
			}
			else {
				$return = true;
			}
		}
		else {
			$data = $entity->asArray(true);
			$data = array_filter($data, static function($v) { return $v !== null; });
			$return = $db->insert($entity::TABLE, $data);

			if ($return) {
				$id = (int) $db->lastInsertId();

				if ($id < 1) {
					throw new \LogicException('Error inserting entity in DB: invalid ID = ' . $id);
				}

				$entity->exists(true);
				$entity->id($id);
			}
		}

		$entity->clearModifiedProperties();
		return $return;
	}

	public function delete(AbstractEntity $entity): bool
	{
		$db = $this->DB();
		$return = $db->delete($entity::TABLE, $db->where('id', $entity->id()));

		if ($return) {
			$entity->exists(false);
		}

		return $return;
	}
}
