<?php
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

namespace KD2;

class Security
{
	/**
	 * Allowed schemes/protocols in URLs
	 * @var array
	 */
	static protected array $whitelist_url_schemes = [
		'http'  =>  '://',
		'https' =>  '://',
		'ftp'   =>  '://',
		'mailto'=>  ':',
		'xmpp'  =>  ':',
		'news'  =>  ':',
		'nntp'  =>  '://',
		'tel'   =>  ':',
		'callto'=>  ':',
		'ed2k'  =>  '://',
		'irc'   =>  '://',
		'magnet'=>  ':',
		'mms'   =>  '://',
		'rtsp'  =>  '://',
		'sip'   =>  ':',
	];

	/**
	 * Returns a random password of $length characters, picked from $alphabet
	 * @param  integer $length  Length of password
	 * @param  string $alphabet Alphabet used for password generation
	 * @return string
	 */
	static public function getRandomPassword(int $length = 12, string $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ123456789=/:!?-_'): string
	{
		$password = '';

		for ($i = 0; $i < (int)$length; $i++)
		{
			$pos = random_int(0, strlen($alphabet) - 1);
			$password .= $alphabet[$pos];
		}

		return $password;
	}

	/**
	 * Returns a random passphrase of $words length
	 *
	 * You can use any dictionary from /usr/share/dict, or any text file with one word per line
	 *
	 * @param  string  $dictionary      Path to dictionary file
	 * @param  integer $words           Number of words to include
	 * @param  ?string $character_match Regexp (unicode) character class to match, eg.
	 * if you want only words in lowercase: \pL
	 * @param  boolean $add_entropy     If TRUE will replace one character from each word randomly with a number or special character
	 * @return string Passphrase
	 */
	static public function getRandomPassphrase(string $dictionary = '/usr/share/dict/words', int $words = 4, ?string $character_match = null, bool $add_entropy = false): string
	{
		if (empty($dictionary) || !is_readable($dictionary)) {
			throw new \InvalidArgumentException('Invalid dictionary file: cannot open or read from file \'' . $dictionary . '\'');
		}

		$file = file($dictionary);

		$selection = [];
		$max = 1000;
		$i = 0;

		while (count($selection) < (int) $words) {
			if ($i++ > $max) {
				throw new \Exception('Could not find a suitable combination of words.');
			}

			$rand = random_int(0, count($file) - 1);
			$w = trim($file[$rand]);

			if (!$character_match || preg_match('/^[' . $character_match . ']+$/Ui', $w)) {
				if ($add_entropy) {
					$w[random_int(0, strlen($w) - 1)] = self::getRandomPassword(1, '23456789=/:!?-._');
				}

				$selection[] = $w;
			}
		}

		return implode(' ', $selection);
	}

	/**
	 * Returns a base64 string safe for URLs
	 * @param  string $str
	 * @return string
	 */
	static public function base64_encode_url_safe(string $str): string
	{
		return rtrim(strtr(base64_encode($str), '+/', '-_'), '=');
	}

	/**
	 * Decodes a URL safe base64 string
	 * @param  string $str
	 * @return string
	 */
	static public function base64_decode_url_safe(string $str): string
	{
		return base64_decode(str_pad(strtr($str, '-_', '+/'), strlen($str) % 4, '=', STR_PAD_RIGHT));
	}

	static public function checkCaptcha(string $secret, string $hash, string $user_value)
	{
		$check = sha1(trim($secret) . preg_replace('/\s+/', '', $user_value));
		return hash_equals($check, $hash);
	}

	static public function createCaptcha(string $secret, string $locale = 'en_US'): array
	{
		$number = random_int(1000, 9999);
		$spellout = numfmt_create($locale, \NumberFormatter::SPELLOUT)->format((int) $number);
		$hash = sha1(trim($secret) . $number);
		return compact('hash', 'spellout');
	}

	/**
	 * Protects a URL/URI given as an image/link target against XSS attacks
	 * (at least it tries)
	 * @param  string 	$value 	Original URL
	 * @return string 	Filtered URL but should still be escaped, like with htmlspecialchars for HTML documents
	 */
	static public function protectURL(string $value): string
	{
		// Decode entities and encoded URIs
		$value = rawurldecode($value);
		$value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');

		// Convert unicode entities back to ASCII
		// unicode entities don't always have a semicolon ending the entity
		$value = preg_replace_callback('~&#x0*([0-9a-f]+);?~i',
			function($match) { return chr(hexdec($match[1])); },
			$value);
		$value = preg_replace_callback('~&#0*([0-9]+);?~',
			function ($match) { return chr($match[1]); },
			$value);

		// parse_url already helps against some XSS malformed URLs
		$url = parse_url($value);

		// This should not happen as parse_url can usually deal with most malformed URLs
		if (!$url)
		{
			return false;
		}

		$value = '';

		if (!empty($url['scheme']))
		{
			$url['scheme'] = strtolower($url['scheme']);

			if (!array_key_exists($url['scheme'], self::$whitelist_url_schemes))
			{
				return '';
			}

			$value .= $url['scheme'] . self::$whitelist_url_schemes[$url['scheme']];
		}

		if (!empty($url['user']))
		{
			$value .= rawurlencode($url['user']);

			if (!empty($url['pass']))
			{
				$value .= ':' . rawurlencode($url['pass']);
			}

			$value .= '@';
		}

		if (!empty($url['host']))
		{
			$value .= $url['host'];
		}

		if (!empty($url['port']) && !($url['scheme'] == 'http' && $url['port'] == 80) 
			&& !($url['scheme'] == 'https' && $url['port'] == 443))
		{
			$value .= ':' . (int) $url['port'];
		}

		if (!empty($url['path']))
		{
			// Split and re-encode path
			$url['path'] = explode('/', $url['path']);
			$url['path'] = array_map('rawurldecode', $url['path']);
			$url['path'] = array_map('rawurlencode', $url['path']);
			$url['path'] = implode('/', $url['path']);

			// Keep leading /~ un-encoded for compatibility with user accounts on some web servers
			$url['path'] = preg_replace('!^/%7E!', '/~', $url['path']);

			$value .= $url['path'];
		}

		if (!empty($url['query']))
		{
			// We can't use parse_str and build_http_string to sanitize url here
			// Or else we'll get things like ?param1&param2 transformed in ?param1=&param2=
			$query = explode('&', $url['query'], 2);

			foreach ($query as &$item)
			{
				$item = explode('=', $item);

				if (isset($item[1]))
				{
					$item = rawurlencode(rawurldecode($item[0])) . '=' . rawurlencode(rawurldecode($item[1]));
				}
				else
				{
					$item = rawurlencode(rawurldecode($item[0]));
				}
			}

			$value .= '?' . implode('&', $query);
		}

		if (!empty($url['fragment']))
		{
			$value .= '#' . rawurlencode(rawurldecode($url['fragment']));
		}

		return $value;
	}

	/**
	 * Check that GnuPG extension is installed and available to encrypt emails
	 * @return boolean
	 */
	static public function canUseEncryption(): bool
	{
		return (extension_loaded('gnupg') && function_exists('\gnupg_init') && class_exists('\gnupg', false));
	}

	/**
	 * Initializes gnupg environment and object
	 * @param  string $key     Public encryption key
	 * @param  string &$tmpdir Temporary directory used to store gnupg keys
	 * @param  array  &$info   Informations about the imported key
	 * @return \gnupg
	 */
	static protected function _initGnupgEnv(string $key, ?string &$tmpdir, ?array &$info): \gnupg
	{
		if (!self::canUseEncryption())
		{
			throw new \RuntimeException('Cannot use encryption: gnupg extension not found.');
		}

		$tmpdir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('gpg_', true);

		// Create temporary home directory as required by gnupg
		mkdir($tmpdir);

		if (!is_dir($tmpdir))
		{
			throw new \RuntimeException('Cannot create temporary directory for GnuPG');
		}

		// Before PECL gnupg 1.5.0, setting home_dir required to set environment variable
		if (version_compare(phpversion('gnupg'), '1.5.0', '<')) {
			@putenv('GNUPGHOME=' . $tmpdir);
		}

		$gpg = new \gnupg(['home_dir' => $tmpdir]);
		$gpg->seterrormode(\GNUPG_ERROR_EXCEPTION);

		$info = $gpg->import($key);

		return $gpg;
	}

	/**
	 * Cleans gnupg environment
	 * @param  string $tmpdir Temporary directory used to store gpg keys
	 * @return void
	 */
	static protected function _cleanGnupgEnv(string $tmpdir): void
	{
		// Remove files
		foreach (glob($tmpdir . DIRECTORY_SEPARATOR . '*') as $file) {
			if (is_dir($file)) {
				@rmdir($file);
			}
			else {
				@unlink($file);
			}
		}

		rmdir($tmpdir);
	}

	/**
	 * Returns pgp key fingerprint
	 * @param  string $key Public key
	 * @return null|string Fingerprint
	 */
	static public function getEncryptionKeyFingerprint(string $key): ?string
	{
		if (trim($key) === '')
		{
			return null;
		}

		self::_initGnupgEnv($key, $tmpdir, $info);
		self::_cleanGnupgEnv($tmpdir);

		return isset($info['fingerprint']) ? $info['fingerprint'] : null;
	}

	/**
	 * Encrypt clear text data with GPG public key
	 * @param  string  $key    Public key
	 * @param  string  $data   Data to encrypt
	 * @param  boolean $binary set to false to have the function return armored string instead of binary
	 * @return string
	 */
	static public function encryptWithPublicKey(string $key, string $data, bool $binary = false): string
	{
		$gpg = self::_initGnupgEnv($key, $tmpdir, $info);

		$gpg->setarmor((int)!$binary);
		$gpg->addencryptkey($info['fingerprint']);
		$data = $gpg->encrypt($data);

		self::_cleanGnupgEnv($tmpdir);

		return $data;
	}

	/**
	 * Verify signed data with a public key
	 * @param  string  $key    Public key
	 * @param  string  $data   Data to verify
	 * @param  string  $signature Signature
	 * @return boolean
	 * @see https://stackoverflow.com/questions/32787007/what-do-returned-values-of-php-gnupg-signature-verification-mean
	 */
	static public function verifyWithPublicKey(string $key, string $data, string $signature): bool
	{
		$gpg = self::_initGnupgEnv($key, $tmpdir, $info);

		$gpg->import($key);

		try {
			$return = $gpg->verify($data, $signature);
		}
		catch (\Exception $e) {
			if ($e->getMessage() == 'verify failed') {
				return false;
			}
		}
		finally {
			self::_cleanGnupgEnv($tmpdir);
		}

		if (!isset($return[0]['summary'])) {
			return false;
		}

		// @see http://git.gnupg.org/cgi-bin/gitweb.cgi?p=gpgme.git;a=blob;f=src/gpgme.h.in;h=6cea2c777e2e763f063ad88e7b2135d21ba4bd4a;hb=107bff70edb611309f627058dd4777a5da084b1a#l1506
		$summary = $return[0]['summary'];

		return ($summary === 0
			|| (($summary & 0x04) !== 0x04) // Fail if signature is bad
			|| (($summary & 0x10) !== 0x10) // Fail if key is revoked
			|| (($summary & 0x0080) !== 0x0080) // Fail if key is missing
			|| (($summary & 0x0800) !== 0x0800) // Fail if system error
		);
	}
}
