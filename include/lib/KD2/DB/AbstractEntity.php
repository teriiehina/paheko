<?php
declare(strict_types=1);

/*
	This file is part of KD2FW -- <http://dev.kd2.org/>

	Copyright (c) 2001-2019 BohwaZ <http://bohwaz.net/>
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
	along with Foobar.  If not, see <https://www.gnu.org/licenses/>.
*/

namespace KD2\DB;

/**
 * AbstractEntity: a generic entity that can be extended to build your entities
 * Use the EntityManager to persist entities in a database
 *
 * @author bohwaz
 * @license AGPLv3
 */

abstract class AbstractEntity
{
	protected $_exists = false;

	protected $_modified = [];
	protected $_types = [];

	static protected $_types_cache = [];

	/**
	 * Default constructor
	 */
	public function __construct()
	{
		// Generate types cache
		if (empty(self::$_types_cache[static::class]) && empty($this->_types)) {
			$r = new \ReflectionClass(static::class);

			foreach ($r->getProperties(\ReflectionProperty::IS_PROTECTED) as $p) {
				if ($p->name[0] == '_') {
					// Skip internal stuff
					continue;
				}

				if (array_key_exists($p->name, $this->_types)) {
					$type = $this->_types[$p->name];
				}
				else {
					$t = $p->getType();

					if (null === $t) {
						throw new \LogicException(sprintf('Property "%s" of entity "%s" has no type', $p->name, static::class));
					}

					$type = $t->getName();
					$type = ($t->allowsNull() ? '?' : '') . $type;
				}

				$this->_types[$p->name] = $type;
			}
		}

		self::_loadEntityTypesCache($this->_types);
	}

	static protected function _loadEntityTypesCache(array $types)
	{
		if (!empty(self::$_types_cache[static::class])) {
			return;
		}

		foreach ($types as $name => $type) {
			$nullable = false;

			if ($type[0] === '?') {
				$type = substr($type, 1);
				$nullable = true;
			}

			$prop = (object) compact('name', 'nullable', 'type');
			$prop->boolean = $type === 'bool' || $type === 'boolean';
			$prop->integer = $type === 'int' || $type === 'integer';
			$prop->float = $type === 'float' || $type === 'double';
			$prop->string = $type === 'string';
			$prop->array = $type === 'array';
			$prop->object = !$prop->boolean && !$prop->integer && !$prop->float && !$prop->string && !$prop->array;
			$prop->class = $prop->object ? $type : null;
			$prop->stdclass = $prop->class === 'stdClass';
			$prop->datetime = $prop->class === 'DateTime' || $prop->class === 'DateTimeInterface';
			$prop->date = $prop->class === Date::class || $prop->class === 'date';

			self::$_types_cache[static::class][$name] = $prop;
		}
	}

	public function __wakeup(): void
	{
		if (empty(self::$_types_cache[static::class])) {
			self::_loadEntityTypesCache($this->_types);
		}
	}

	/**
	 * Loads data from an array into the entity properties
	 * Used for example to load data from a database. This will convert string values to typed properties.
	 * @param  array  $data
	 * @return self
	 */
	public function load(array $data): self
	{
		$properties = self::$_types_cache[static::class];

		foreach ($data as $key => $value) {
			if (!array_key_exists($key, $properties)) {
				throw new \RuntimeException(sprintf('"%s" is not a property of the entity "%s"', $key, static::class));
			}
		}

		foreach ($properties as $name => $prop) {
			if (!array_key_exists($name, $data)) {
				throw new \RuntimeException('Missing key in array: ' . $name);
			}

			$value = $data[$name];

			if (is_int($value) && $prop->boolean) {
				$value = (bool) $value;
			}
			elseif (is_string($value) && !$prop->string) {
				$value = $this->transformValue($name, $value);
			}

			$this->$name = $value;
		}

		return $this;
	}

	/**
	 * Import data from an array of user-supplied values, only keys corresponding to entity properties
	 * will be used, others will be ignored.
	 * @param  array|null $source Source data array, if none is supplied $_POST will be used
	 * @return void
	 */
	public function import(?array $source = null): self
	{
		if (null === $source) {
			$source = $_POST;
		}

		unset($source['id']);

		$data = array_intersect_key($source, self::$_types_cache[static::class]);

		foreach ($data as $key => $value) {
			$prop = self::$_types_cache[static::class][$key];

			if ($prop->nullable && is_string($value) && trim($value) === '') {
				$value = null;
			}

			$value = $this->filterUserValue($prop->type, $value, $key);
			$this->setFromAnyValue($key, $value);
		}

		return $this;
	}

	protected function filterUserValue(string $type, $value, string $key)
	{
		if (is_null($value)) {
			return $value;
		}

		if (is_object($value)) {
			return $value;
		}

		switch ($type)
		{
			case 'date':
			case Date::class:
				$d = new Date($value);
				$d->setTime(0, 0, 0);
				return $d;
			case 'DateTime':
				return new \DateTime($value);
			case 'int':
				return (int) $value;
			case 'float':
				return (float) $value;
			case 'bool':
				return (bool) $value;
			case 'string':
				return trim((string) $value);
		}

		return $value;
	}

	protected function assert($test, ?string $message = null): void
	{
		if ($test) {
			return;
		}

		if (null === $message) {
			$backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
			$caller_class = array_pop($backtrace);
			$caller = array_pop($backtrace);
			$message = sprintf('Entity assertion fail from class %s on line %d', $caller_class['class'], $caller['line']);
		}

		throw new \UnexpectedValueException($message);
	}

	public function selfCheck(): void
	{
		$this->assert(!isset($this->id) || (is_numeric($this->id) && $this->id > 0));

		foreach (self::$_types_cache[static::class] as $prop_name => $prop) {
			// Skip ID
			if ($prop_name == 'id') {
				continue;
			}

			if (!isset($this->$prop_name) && !$prop->nullable) {
				throw new \UnexpectedValueException(sprintf('Entity property "%s" cannot be left NULL', $prop_name));
			}
		}
	}

	public function asArray(bool $for_database = false): array
	{
		$vars = get_object_vars($this);

		// Remove internal stuff
		foreach ($vars as $key => &$value) {
			if ($key[0] == '_') {
				unset($vars[$key]);
				continue;
			}

			if (!$for_database) {
				continue;
			}

			$value = $this->getAsString($key);
		}

		return $vars;
	}

	public function getAsString(string $key, $value = null)
	{
		if (!isset($this->$key)) {
			return null;
		}

		$value ??= $this->$key;

		switch (gettype($value)) {
			case 'object':
				if ($value instanceof \stdClass) {
					return json_encode($value);
				}
				elseif ($value instanceof Date) {
					return $value->format('Y-m-d');
				}
				elseif ($value instanceof \DateTimeInterface) {
					return $value->format('Y-m-d H:i:s');
				}

				return (string) $value;
			case 'bool':
			case 'boolean':
				return (int) $value;
			case 'array':
				return json_encode($value);
			case 'int':
			case 'integer':
			case 'double':
			case 'float':
				return $value;
			default:
				return (string) $value;
		}
	}

	/**
	 * Returns an array containing *OLD* values of modified properties
	 * (*NEW* value is stored in object)
	 *
	 * Note that modified properties are cleared after save()
	 */
	public function getModifiedProperties(): array
	{
		return $this->_modified;
	}

	/**
	 * @deprecated
	 */
	public function modifiedProperties(bool $for_database = false): array
	{
		return array_intersect_key($this->asArray($for_database), $this->_modified);
	}

	/**
	 * Returns the *OLD* value of a modified property
	 */
	public function getModifiedProperty(string $key)
	{
		return $this->_modified[$key] ?? null;
	}

	public function clearModifiedProperties(?array $properties = null): void
	{
		if (null === $properties) {
			$this->_modified = [];
			return;
		}

		foreach ($properties as $key) {
			unset($this->_modified[$key]);
		}
	}

	public function isModified(?string $property = null): bool
	{
		if ($property !== null) {
			return array_key_exists($property, $this->_modified);
		}
		else {
			return count($this->_modified) > 0;
		}
	}

	public function id(?int $id = null): int
	{
		if (null !== $id) {
			$this->id = $id;
		}

		if (!isset($this->id)) {
			throw new \LogicException('This entity does not have an ID yet');
		}

		return $this->id;
	}

	public function exists(?bool $exists = null): bool
	{
		if (null !== $exists) {
			$this->_exists = $exists;

			if ($exists === false) {
				unset($this->id);
			}
		}

		return $this->_exists;
	}

	public function setFromAnyValue(string $key, $value)
	{
		$this->set($key, $this->transformValue($key, $value));
	}

	/**
	 * Transforms the value from loosely typed to be suitable to expected type of a property
	 * eg. (string)'42' => (int)42
	 */
	public function transformValue(string $key, $value)
	{
		$prop = self::$_types_cache[static::class][$key] ?? null;

		if (null === $prop) {
			throw new \InvalidArgumentException(sprintf('Unknown "%s" property: "%s"', static::class, $key));
		}

		if (is_string($value) && trim($value) === '' && $prop->nullable) {
			$value = null;
		}
		elseif (($prop->float || $prop->integer) && is_string($value) && is_numeric($value)) {
			$value = (int)$value;
		}
		elseif ($prop->datetime && is_string($value) && strlen($value) === 19 && ($d = \DateTime::createFromFormat('!Y-m-d H:i:s', $value))) {
			$value = $d;
		}
		elseif ($prop->datetime && is_string($value) && strlen($value) === 16 && ($d = \DateTime::createFromFormat('!Y-m-d H:i', $value))) {
			$value = $d;
		}
		elseif ($prop->date && is_string($value) && strlen($value) === 10 && ($d = Date::createFromFormat('!Y-m-d', $value))) {
			$value = $d;
		}
		elseif ($prop->date && is_object($value) && $value instanceof \DateTime && !($value instanceof Date)) {
			$value = Date::createFromInterface($value);
		}
		elseif ($prop->boolean && is_numeric($value) && ($value == 0 || $value == 1)) {
			$value = (bool) $value;
		}
		elseif ($prop->array && is_string($value)) {
			$value = json_decode($value, true);

			if (null === $value) {
				throw new \RuntimeException(sprintf('Cannot decode JSON string for key "%s"', $key));
			}
		}
		elseif ($prop->stdclass && is_string($value)) {
			$value = json_decode($value);

			if (null === $value) {
				throw new \RuntimeException(sprintf('Cannot decode JSON string for key "%s"', $key));
			}

			if (is_array($value)) {
				$value = (object)$value;
			}
		}

		return $value;
	}

	public function set(string $key, $value)
	{
		$prop = self::$_types_cache[static::class][$key] ?? null;

		if (null === $prop) {
			throw new \InvalidArgumentException(sprintf('Unknown "%s" property: "%s"', static::class, $key));
		}

		if (isset($this->$key) && is_object($this->$key)) {
			$original_value = clone $this->$key;
		}
		elseif (isset($this->$key)) {
			$original_value = $this->$key;
		}
		else {
			$original_value = null;
		}

		if (null === $value && !$prop->nullable) {
			throw new \UnexpectedValueException(sprintf('Unexpected NULL value for "%s"', $key));
		}

		if ($prop->date && is_object($value) && !($value instanceof Date)) {
			$value = Date::createFromInterface($value);
		}

		if (null !== $value && !$this->_checkValueType($value, $prop)) {
			$found_type = $this->_getValueType($value);

			if ('object' == $found_type) {
				$found_type = get_class($value);
			}

			throw new \UnexpectedValueException(sprintf('Value of type \'%s\' for property \'%s\' is invalid (expected \'%s\')', $found_type, $key, $prop->type));
		}

		// Normalize line breaks to \n
		if (is_string($value) && (!isset($this->$key) || $this->$key !== $value)) {
			$value = str_replace("\r\n", "\n", $value);
			$value = str_replace("\r", "\n", $value);
		}

		$this->$key = $value;

		// For storing a modified object, compare its string value, not the object, as DateTime !== DateTime
		if (is_object($value) && is_object($original_value)) {
			$compare_value = $this->getAsString($key, $original_value);
			$value = $this->getAsString($key, $value);
		}
		else {
			$compare_value = $original_value;
		}

		// Only modify entity if value has changed
		if ($value !== $compare_value) {
			$this->_modified[$key] = $original_value;
		}
	}

	public function get(string $key)
	{
		return $this->$key ?? null;
	}

	public function __set(string $key, $value)
	{
		$this->set($key, $value);
	}

	public function __get(string $key)
	{
		return $this->get($key);
	}

	public function __isset($key)
	{
		return property_exists($this, $key) && isset($this->$key);
	}

	/**
	 * Make sure the cloned object doesn't have the same ID, it's a brand new entity!
	 */
	public function __clone()
	{
		unset($this->id);
		$this->_exists = false;
	}

	protected function _checkValueType($value, \stdClass $prop): bool
	{
		$type = $this->_getValueType($value);

		if ($type !== 'object' && isset($prop->$type) && $prop->$type === true) {
			return true;
		}
		elseif ($prop->date && $value instanceof Date) {
			return true;
		}
		elseif ($prop->datetime && $value instanceof \DateTimeInterface) {
			return true;
		}
		elseif ($prop->stdclass && $value instanceof \stdClass) {
			return true;
		}
		elseif ($prop->class && $value instanceof $prop->class) {
			return true;
		}

		return false;
	}

	protected function _getValueType($value)
	{
		$type = gettype($value);

		// Type names are not consistent in PHP...
		// see https://mlocati.github.io/articles/php-type-hinting.html
		$type = $type === 'double' ? 'float': $type;

		return $type;
	}

	// Helpful helpers
	public function save(bool $selfcheck = true): bool
	{
		return EntityManager::getInstance(static::class)->save($this, $selfcheck);
	}

	public function delete(): bool
	{
		return EntityManager::getInstance(static::class)->delete($this);
	}
}
