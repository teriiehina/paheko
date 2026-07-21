<?php

namespace KD2\DB;

use DateTime;
use DateTimeInterface;
use DateTimeZone;

/**
 * Just a helper that tells us that the date should be stored as Y-m-d that's all
 */
class Date extends DateTime {
	// For PHP 7.4
	static public function createFromInterface(DateTimeInterface $object): Date
	{
		$n = new self;
		$n->setTimestamp($object->getTimestamp());
		$n->setTimezone($object->getTimeZone());
		return $n;
	}

	#[\ReturnTypeWillChange]
	static public function createFromFormat($format, $datetime, DateTimeZone $timezone = null)
	{
		$v = parent::createFromFormat($format, $datetime, $timezone);

		if (!$v) {
			return $v;
		}

		return self::createFromInterface($v);
	}
}
