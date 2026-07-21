<?php

namespace KD2\I18N;

class TimeZones
{
	const REPLACE_LABEL = [
		'Pacific/Gambier'           => 'Îles Gambier',
		'Pacific/Marquesas'         => 'Îles Marquises',
		'America/Toronto'           => 'Toronto, Montréal, Québec, Ottawa',
		'Europe/Brussels'           => 'Bruxelles (Brussel)',
		'Antarctica/DumontDUrville' => 'Dumont d\'Urville',
		'Indian/Mauritius'          => 'Maurice',
	];

	const DEFAULTS = [
		'PF' => 'Pacific/Tahiti',
		'CA' => 'America/Toronto',
		'US' => 'America/New_York',
		'AU' => 'Australia/Sydney',
		'NZ' => 'Pacific/Auckland',
		'RU' => 'Europe/Moscow',
	];

	static public function check(string $name): bool
	{
		try {
			new \DateTimeZone($name);
			return true;
		}
		catch (\Exception $e) {
			return false;
		}
	}

	static public function listGroupedByContinent(string $locale = 'fr_FR'): array
	{
		$list = \DateTimeZone::listIdentifiers();

		$out = [];

		foreach ($list as $name) {
			$continent = strtok($name, '/');
			$label = strtok('');

			if (array_key_exists($name, self::REPLACE_LABEL)) {
				$label = self::REPLACE_LABEL[$name];
			}
			else {
				$label = strtr($label, ['/' => ' / ', '_' => ' ']) ?: $name;
			}

			$out[$continent] ??= [];
			$out[$continent][$name] = $label;
		}

		unset($list);
		ksort($out);
		$collator = new \Collator($locale);

		foreach ($out as &$list) {
			$collator->asort($list);
		}

		unset($collator);
		unset($list);

		return $out;
	}

	static public function listForCountry(string $code, string $locale = 'fr_FR'): array
	{
		$list = \DateTimeZone::listIdentifiers(\DateTimeZone::PER_COUNTRY, $code);

		$out = [];

		foreach ($list as $name) {
			if (array_key_exists($name, self::REPLACE_LABEL)) {
				$label = self::REPLACE_LABEL[$name];
			}
			else {
				$continent = strtok($name, '/');
				$label = strtok('');
				$label = strtr($label, ['/' => ' / ', '_' => ' ']);
			}

			$out[$name] = $label;
		}

		$collator = new \Collator($locale);
		$collator->asort($out);
		unset($collator);

		return $out;
	}

	static public function getDefaultForCountry(string $country): ?string
	{
		return self::DEFAULTS[$country] ?? null;
	}

	static public function getLocalTime(string $country, string $tz): string
	{
		$dt = new \DateTime('now', new \DateTimeZone($tz));

		if ($country === 'US' || $country === 'UK') {
			return $dt->format('g:i a');
		}

		return $dt->format('H:i');
	}

	static public function getOffset(string $name): int
	{
		$tz = new \DateTimeZone($name);
		return $tz->getOffset(new  \DateTime('now', $name));
	}
}
