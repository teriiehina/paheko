<?php

// Copier ce fichier dans un nouveau répertoire vide
// Et s'y rendre avec un navigateur web :-)


namespace KD2 {
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

		static public function checkCaptcha(string $secret, string $hash, string $user_value): bool
		{
			$parts = explode(':', $hash);

			if (count($parts) !== 4) {
				return false;
			}

			if ($parts[0] < time()) {
				return false;
			}

			$number = preg_replace('/\s+/', '', $user_value);
			$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
			$value = sha1($secret . $number . $ua . $parts[1]);

			$check = hash_hmac('sha1', $value, $secret);
			return hash_equals($check, $parts[3]);
		}

		static public function createCaptcha(string $secret, string $locale = 'en_US'): array
		{
			$number = random_int(1000, 9999);
			$spellout = numfmt_create($locale, \NumberFormatter::SPELLOUT)->format((int) $number);

			$expiry = time() + 60*30;
			$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
			$random = sha1(random_bytes(10));
			$value = sha1($secret . $number . $ua . $random);

			$hash = sprintf('%d:%s:%s:%s', $expiry, $random, $value, hash_hmac('sha1', $value, $secret));
			return compact('hash', 'spellout');
		}

		static public function getUserAgentScore(?string $language = null, ?string $ua = null, ?array $headers = null): int
		{
			$score = 0;
			$ua ??= $_SERVER['HTTP_USER_AGENT'] ?? '';

			// Old user agents that are commonly used by bots
			$bad_agents = '/BonEcho|Gecko\/200|MSIE\s+[2-9]|Trident|Camino|Amiga|Arora|Cheshire|python|gpt|curl|wget/i';

			$is_modern_browser = false;

			$headers ??= apache_request_headers();
			$headers = array_change_key_case($headers);

			if (preg_match($bad_agents, $ua)) {
				$score -= 50;
			}
			elseif (preg_match('!Firefox/(\d+)!', $ua, $match)) {
				// Firefox 151 was released on May 19, 2026
				$weeks_count = (time() - strtotime('2026-05-19')) / 3600 / 24 / 7;
				// There is a new version every 4 weeks
				$last_firefox_version = floor(151 + ($weeks_count / 4));

				// Recent version
				if ($match[1] >= ($last_firefox_version - 2)) {
					$score += 10;
				}
				// A little bit less recent version
				elseif ($match[1] >= ($last_firefox_version - 5)) {
					$score += 5;
				}
				// More than a year-old is unusual
				elseif ($match[1] >= ($last_firefox_version - 12)) {
					$score -= 2;
				}
				else {
					$score -= 10;
				}

				$is_modern_browser = true;
			}
			elseif (preg_match('!Chrome/(\d+)!', $ua, $match)) {
				$weeks_count = (time() - strtotime('2026-08-12')) / 3600 / 24 / 7;

				// Chrome has a new version every 4 weeks until 152 then every 2 weeks (from August 12, 2026)
				$release_cycle = time() >= strtotime('2026-08-20') ? 2 : 4;

				$last_chrome_version = floor(162 + ($weeks_count / $release_cycle));

				// Recent version
				if ($match[1] >= ($last_chrome_version - $release_cycle/2)) {
					$score += 10;
				}
				// A little bit less recent version
				elseif ($match[1] >= ($last_chrome_version - $release_cycle)) {
					$score += 5;
				}
				elseif ($match[1] >= ($last_chrome_version - $release_cycle*2)) {
					$score -= 2;
				}
				else {
					$score -= 10;
				}

				// Version doesn't match between Sec-Ch-Ua and User-Agent: this is a bot
				if (!strpos($headers['sec-ch-ua'] ?? '', 'v="' . $match[1] . '"')) {
					$score -= 100;
				}

				$is_modern_browser = true;
			}
			elseif (preg_match('!Version/(\d+)\.\d+ Safari/!', $ua, $match)) {
				// Safari version is the year following the release since 2025
				if ($match[1] == date('y') - 1) {
					$score += 10;
				}
				elseif ($match[1] == date('y') - 2) {
					$score += 5;
				}
				// Old versions from 2023 and 2024
				elseif (date('Y') < 2028 && ($match[1] == 17 || $match[1] == 18)) {
					$score += 2;
				}
				else {
					$score -= 10;
				}
			}

			if (($headers['sec-fetch-mode'] ?? '') !== 'navigate') {
				if ($is_modern_browser) {
					$score -= 20;
				}
				else {
					$score -= 5;
				}
			}

			// If platform is not in user-agent, it probably means this is a bot
			if (isset($headers['sec-ch-ua-platform'])) {
				if ($headers['sec-ch-ua-platform'] === '"Linux"'
					&& false === strpos($ua, 'Linux')) {
					$score -= 20;
				}
				elseif ($headers['sec-ch-ua-platform'] === '"Windows"'
					&& false === strpos($ua, 'Windows')) {
					$score -= 20;
				}
			}

			$good_headers = [
				'accept-encoding',
				'priority',
				'origin',
				'accept',
				'accept-language',
				'sec-fetch-dest',
				'sec-fetch-mode',
				'sec-fetch-site',
				'sec-ch-ua',
				'upgrade-insecure-requests',
			];

			foreach ($good_headers as $name) {
				if (array_key_exists($name, $headers)) {
					$score++;
				}
			}

			if (!isset($headers['accept-language'])) {
				$score -= 10;
			}
			elseif ($language && strtolower(substr($headers['accept-language'], 0, 2)) !== $language) {
				$score -= 10;
			}

			return $score;
		}

		static public function generateMarkovText(string $text, int $max_words): string
		{
			$text = str_replace('‘', '\'', $text);
			preg_match_all('/[\p{L}\d]+(?:[\'⋅—-][\p{L}\d]+)*[\.,]?/u', $text, $match);
			$text = $match[0];
			unset($match);

			array_walk($text, function(string &$word) {
				if (mb_strtoupper($word) !== $word) {
					$word = mb_strtolower($word);
				}
			});

			$chain = [];

			foreach ($text as $i => $word) {
				$chain[$word] ??= [];

				$next = $text[$i + 1] ?? null;

				if (null === $next) {
					break;
				}

				$chain[$word][$next] ??= 0;
				$chain[$word][$next]++;
			}

			$weighAndSelect = function (array $block): ?string {
				if (!count($block)) {
					return null;
				}

				$tmp = [];

				foreach ($block as $key => $weight) {
					for($i = 1; $i <= $weight; $i++) {
						$tmp[] = $key;
					}
				}

				$rand = array_rand($tmp);
				return $tmp[$rand];
			};

			$out = '';
			$count = 0;
			$closed = true;
			$word = array_rand($chain);

			while (($word = $weighAndSelect($chain[$word]))
				&& $count++ <= $max_words) {
				$w = $word;

				if ($closed) {
					$w = mb_strtoupper(mb_substr($w, 0, 1)) . mb_substr($w, 1);
				}

				$out .= $w;
				$closed = false;

				if (substr($w, -1) === '.') {
					$closed = true;
				}

				if ($closed && $count % random_int(3, 5) == 0) {
					$out .= "\n\n";
					$closed = true;
				}
				else {
					$out .= ' ';
				}
			}

			return $out;
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



	class HTTP
	{
		const FORM = 'application/x-www-form-urlencoded';
		const JSON = 'application/json';
		const XML = 'text/xml';

		const CLIENT_DEFAULT = 'default';
		const CLIENT_CURL = 'curl';

		const CODES = [
			100 => 'Continue',
			101 => 'Switching Protocols',
			102 => 'Processing',
			200 => 'OK',
			201 => 'Created',
			202 => 'Accepted',
			203 => 'Non-Authoritative Information',
			204 => 'No Content',
			205 => 'Reset Content',
			206 => 'Partial Content',
			207 => 'Multi-Status',
			300 => 'Multiple Choices',
			301 => 'Moved Permanently',
			302 => 'Found',
			303 => 'See Other',
			304 => 'Not Modified',
			305 => 'Use Proxy',
			306 => 'Switch Proxy',
			307 => 'Temporary Redirect',
			400 => 'Bad Request',
			401 => 'Unauthorized',
			402 => 'Payment Required',
			403 => 'Forbidden',
			404 => 'Not Found',
			405 => 'Method Not Allowed',
			406 => 'Not Acceptable',
			407 => 'Proxy Authentication Required',
			408 => 'Request Timeout',
			409 => 'Conflict',
			410 => 'Gone',
			411 => 'Length Required',
			412 => 'Precondition Failed',
			413 => 'Request Entity Too Large',
			414 => 'Request-URI Too Long',
			415 => 'Unsupported Media Type',
			416 => 'Requested Range Not Satisfiable',
			417 => 'Expectation Failed',
			418 => 'I\'m a teapot',
			422 => 'Unprocessable Entity',
			423 => 'Locked',
			424 => 'Failed Dependency',
			425 => 'Unordered Collection',
			426 => 'Upgrade Required',
			449 => 'Retry With',
			450 => 'Blocked by Windows Parental Controls',
			500 => 'Internal Server Error',
			501 => 'Not Implemented',
			502 => 'Bad Gateway',
			503 => 'Service Unavailable',
			504 => 'Gateway Timeout',
			505 => 'HTTP Version Not Supported',
			506 => 'Variant Also Negotiates',
			507 => 'Insufficient Storage',
			509 => 'Bandwidth Limit Exceeded',
			510 => 'Not Extended',
		];

		public ?string $client = null;

		/**
		 * A list of common User-Agent strings, one of them is used
		 * randomly every time an object has a new instance.
		 * @var array
		 */
		public array $uas = [
			'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Ubuntu Chromium/48.0.2564.116 Chrome/48.0.2564.116 Safari/537.36',
			'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:45.0) Gecko/20100101 Firefox/45.0',
			'Mozilla/5.0 (X11; Linux x86_64; rv:38.9) Gecko/20100101 Goanna/2.0 Firefox/38.9 PaleMoon/26.1.1',
		];

		/**
		 * User agent
		 * @var string
		 */
		public ?string $user_agent = null;

		/**
		 * Default HTTP headers sent with every request
		 * @var array
		 */
		public array $headers = [
		];

		/**
		 * Options for the SSL stream wrapper
		 * Be warned that by default we allow self signed certificates
		 * See http://php.net/manual/en/context.ssl.php
		 * @var array
		 */
		public array $ssl_options = [
			'verify_peer'		=>	true,
			'verify_peer_name'	=>	true,
			'allow_self_signed'	=>	true,
			'SNI_enabled'		=>	true,
		];

		/**
		 * Options for the HTTP stream wrapper
		 * See http://php.net/manual/en/context.http.php
		 * @var array
		 */
		public array $http_options = [
			'max_redirects'		=>	10,
			'timeout'			=>	10,
			'ignore_errors'		=>	true,
		];

		/**
		 * List of cookies sent to the server, will contain the cookies
		 * set by the server after a request.
		 * @var array
		 */
		public array $cookies = [];

		/**
		 * Prepend this string to every request URL
		 * (helpful for API calls)
		 * @var string
		 */
		public string $url_prefix = '';

		/**
		 * Class construct
		 */
		public function __construct()
		{
			// Use faster client by default
			$this->client = function_exists('curl_exec') ? self::CLIENT_CURL : self::CLIENT_DEFAULT;
			//$this->client = self::CLIENT_DEFAULT;

			// Random user agent
			$this->user_agent = $this->uas[array_rand($this->uas)];
		}

		/**
		 * Enable or disable SSL security,
		 * this includes disabling or enabling self signed certificates
		 * which are allowed by default
		 * @param boolean $enable TRUE to enable certificate check, FALSE to disable
		 */
		public function setSecure(bool $enable = true): void
		{
			$this->ssl_options['verify_peer'] = $enable;
			$this->ssl_options['verify_peer_name'] = $enable;
			$this->ssl_options['allow_self_signed'] = !$enable;
		}

		/**
		 * Make a GET request
		 * @param  string $url                URL to request
		 * @param  array  $additional_headers Optional headers to send with request
		 */
		public function GET($url, ?array $additional_headers = null): HTTP_Response
		{
			return $this->request('GET', $url, null, $additional_headers);
		}

		/**
		 * Make a GET request
		 * @param  string $url                URL to request
		 * @param  array  $data 			  Data to send with POST request
		 * @param  string $type 			  Type of data
		 * @param  array  $additional_headers Optional headers to send with request
		 * @return HTTP_Response
		 */
		public function POST(string $url, array $data = [], string $type = self::FORM, ?array $additional_headers = null): HTTP_Response
		{
			$additional_headers ??= [];
			$additional_headers['Content-Type'] = $type;
			return $this->request('POST', $url, $data, $additional_headers);
		}

		/**
		 * Make a GET request
		 * @param  string $url                URL to request
		 * @param  resource|string $file 			  File to send with POST request
		 * @param  array  $additional_headers Optional headers to send with request
		 * @return HTTP_Response
		 */
		public function PUT(string $url, $file, ?array $additional_headers = null): HTTP_Response
		{
			if (is_string($file)) {
				$file = fopen($file, 'rb');
			}

			if (!isset($additional_headers['Content-Type'])) {
				$additional_headers['Content-Type'] = 'application/custom';
			}

			return $this->request('PUT', $url, $file, $additional_headers);
		}

		public function download(string $url, string $destination, string $method = 'GET', $data = null, ?array $additional_headers = null): HTTP_Response
		{
			$fp = fopen($destination, 'wb');
			$r = $this->request($method, $url, $data, $additional_headers, $fp);
			fclose($fp);
			return $r;
		}

		/**
		 * Make a custom request
		 * @param  string $method             HTTP verb (GET, POST, PUT, etc.)
		 * @param  string $url                URL to request
		 * @param  string|resource $content            Data to send with request
		 * @param  array $additional_headers
		 * @param  resource $write_pointer Pointer to write body to (body will not be returned then)
		 * @return HTTP_Response
		 */
		public function request(string $method, string $url, $data = null, ?array $additional_headers = null, $write_pointer = null): HTTP_Response
		{
			static $redirect_codes = [301, 302, 303, 307, 308];

			$url = $this->url_prefix . $url;

			if (0 !== strpos($url, 'http://') && 0 !== strpos($url, 'https://')) {
				throw new \InvalidArgumentException('Invalid URL: ' . $url);
			}

			$headers = $this->headers;

			if (!is_null($additional_headers)) {
				$headers = array_merge($headers, $additional_headers);
			}

			if ($this->user_agent && !isset($headers['User-Agent'])) {
				$headers['User-Agent'] = $this->user_agent;
			}

			$type = $headers['Content-Type'] ?? null;

			// Convert object/array to string for JSON/XML
			// (for FORM, this is done in specific clients)
			if ((is_object($data) || is_array($data)) && $type && $type !== self::FORM) {
				if ($type === self::JSON) {
					$data = json_encode($data);
				}
				elseif ($type === self::XML) {
					if ($data instanceof \SimpleXMLElement) {
						$data = $data->asXML();
					}
					elseif ($data instanceof \DOMDocument) {
						$data = $data->saveXML();
					}
					elseif (!is_string($data)) {
						throw new \InvalidArgumentException('Data is not a valid XML object or string.');
					}
				}
				else {
					throw new \InvalidArgumentException('Data is not a valid string, and no valid Content-Type was passed.');
				}
			}

			// Manual management of redirects
			if (isset($this->http_options['max_redirects'])) {
				$max_redirects = (int) $this->http_options['max_redirects'];
			}
			else {
				$max_redirects = 10;
			}

			$previous = null;
			$response = null;

			// Follow redirect until we reach maximum
			for ($i = 0; $i <= $max_redirects; $i++) {
				// Make request
				$client = $this->client . 'ClientRequest';
				$response = $this->$client($method, $url, $data, $headers, $write_pointer);
				$response->previous = $previous;

				// Apply cookies to current client for next request
				$this->cookies = array_merge($this->cookies, $response->cookies);

				// Request failed, or not a redirect, stop here
				if (!$response->status || !in_array($response->status, $redirect_codes) || empty($response->headers['location']))
				{
					break;
				}

				// Change method to GET
				if ($response->status == 303)
				{
					$method = 'GET';
				}

				// Get new URL
				$location = $response->headers['location'];

				if (is_array($location))
				{
					$location = end($location);
				}

				if (!parse_url($location))
				{
					throw new \RuntimeException('Invalid HTTP redirect: Location is not a valid URL.');
				}

				$url = self::mergeURLs($url, $location, true);
				$previous = $response;
			}

			return $response;
		}

		/**
		 * Transforms a parse_url array back into a string
		 * @param  array  $url
		 * @return string
		 */
		static public function glueURL(array $url): string
		{
			static $parts = [
				'scheme'   => '%s:',
				'host'     => '//%s',
				'port'     => ':%d',
				'user'     => '%s',
				'pass'     => ':%s',
				'path'     => '%s',
				'query'    => '?%s',
				'fragment' => '#%s',
			];

			$out = [];

			foreach ($parts as $name => $str)
			{
				if (isset($url[$name]))
				{
					$out[] = sprintf($str, $url[$name]);
				}

				if ($name == 'pass' && isset($url['user']) || isset($url['pass']))
				{
					$out[] = '@';
				}
			}

			return implode('', $out);
		}

		/**
		 * Merge two URLs, managing relative $b URL
		 * @param  string $a Primary URL
		 * @param  string $b New URL
		 * @param  boolean $dismiss_query Set to TRUE to dismiss query part of the primary URL
		 * @return string
		 */
		static public function mergeURLs(string $a, string $b, bool $dismiss_query = false): string
		{
			$a = parse_url($a);
			$b = parse_url($b);

			if ($dismiss_query)
			{
				// Don't propagate query params between redirects
				unset($a['query']);
			}
			else {
				parse_str($a['query'] ?? '', $a_query);
				parse_str($b['query'] ?? '', $b_query);
				$b['query'] = http_build_query(array_merge($a_query, $b_query));

				if ($b['query'] == '') {
					unset($b['query']);
				}
			}

			// Relative URL
			if (!isset($b['host']) && isset($b['path']) && substr(trim($b['path']), 0, 1) != '/')
			{
				$path = preg_replace('![^/]*$!', '', $a['path']);
				$path.= preg_replace('!^\./!', '', $b['path']);
				unset($a['path']);

				// replace // or  '/./' or '/foo/../' with '/'
				$b['path'] = preg_replace('#/(?!\.\.)[^/]+/\.\./|/\.?/#', '/', $path);
			}

			$url = array_merge($a, $b);
			return self::glueURL($url);
		}

		/**
		 * Return root application URI (absolute, but no host or scheme)
		 * @param  string $app_root Directory root of current application (eg. __DIR__ of the public/www directory)
		 * @return string
		 */
		static public function getRootURI(string $app_root): string
		{
			// Convert from Windows paths to UNIX paths
			$document_root = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
			$app_root = str_replace('\\', '/', $app_root);

			$document_root = rtrim($document_root, '/');
			$app_root = rtrim($app_root, '/');

			if ('' === trim($app_root)) {
				throw new \UnexpectedValueException('Invalid document root: empty app root');
			}

			if ('' === trim($document_root)) {
				throw new \UnexpectedValueException('Invalid document root: empty document root');
			}

			// Find the relative path inside the server document root
			if (0 === strpos($document_root, $app_root)) {
				// document root is below the app root: great!
				// eg. app_root = /home/user/www/app/www
				// and document_root = /home/user/www/app/www
				// or document_root = /home/user/www/app/www/admin
				$path = substr($document_root, strlen($app_root));
			}
			elseif (0 === strpos($app_root, $document_root)) {
				// document root is ABOVE the app root: not great, but should still work
				// eg. app_root = /home/user/www/app/www
				// and document_root = /home/user/www
				$path = substr($app_root, strlen($document_root));
			}
			else {
				throw new \UnexpectedValueException('Invalid document root: cannot find app root');
			}

			$path = trim($path, '/') . '/';

			if ($path[0] != '/') {
				$path = '/' . $path;
			}

			return $path;
		}

		/**
		 * Return current HTTP host / server name
		 * @return string Host, will be 'host.invalid' if the supplied host (via 'Host' HTTP header or SERVER_NAME is invalid)
		 */
		static public function getHost(): string
		{
			if (!isset($_SERVER['HTTP_HOST']) && !isset($_SERVER['SERVER_NAME']) && !isset($_SERVER['SERVER_ADDR'])) {
				return 'host.unknown';
			}

			$host = isset($_SERVER['HTTP_HOST'])
				? $_SERVER['HTTP_HOST']
				: (isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : $_SERVER['SERVER_ADDR']);

			// Host name must be lowercase
			$host = strtolower(trim($host));

			// Host name MUST be less than 255 bytes
			if (strlen($host) > 255) {
				return 'host.invalid';
			}
			// The host can come from the user
			// check that it does not contain forbidden characters (see RFC 952 and RFC 2181)

			// Delete allowed special characters for check
			$valid_host = str_replace(['_', '-', ':', '[', ']', '.'], '', $host);

			if (!ctype_alnum($valid_host)) {
				return 'host.invalid';
			}

			return $host;
		}

		/**
		 * Return current HTTP scheme
		 * @return string http or https
		 */
		static public function getScheme(): string
		{
			return empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off' ? 'http' : 'https';
		}

		/**
		 * Return complete app URL
		 * @return string
		 */
		static public function getAppURL(string $app_root): string
		{
			return self::getScheme() . '://' . self::getHost() . self::getRootURI($app_root);
		}

		/**
		 * Return current complete request URL
		 * @param bool $with_query_string Use FALSE to return the request URL without the query string (?param=a...)
		 * @return string
		 */
		static public function getRequestURL(bool $with_query_string = true): string
		{
			$path = $_SERVER['REQUEST_URI'];

			if (!$with_query_string && false !== ($pos = strpos($path, '?'))) {
				$path = substr($path, 0, $pos);
			}

			return self::getScheme() . '://' . self::getHost() . $path;
		}

		/**
		 * RFC 6570 URI template replacement, supports level 1 and level 2
		 * @param string $uri    URI with placeholders
		 * @param array  $params Parameters (placeholders)
		 * @link  https://www.rfc-editor.org/rfc/rfc6570.txt
		 * @return string
		 */
		static public function URITemplate(string $uri, Array $params = []): string
		{
			static $var_name = '(?:[0-9a-zA-Z_]|%[0-9A-F]{2})+';

			// Delimiters
			static $delims = [
				'%3A' => ':', '%2F' => '/', '%3F' => '?', '%23' => '#',
				'%5B' => '[', '%5D' => ']', '%40' => '@', '%21' => '!',
				'%24' => '$', '%26' => '&', '%27' => '\'', '%28' => '(',
				'%29' => ')', '%2A' => '*', '%2B' => '+', '%2C' => ',',
				'%3B' => ';', '%3D' => '=',
			];

			// Level 2: {#variable} => #/foo/bar
			$uri = preg_replace_callback('/\{#(' . $var_name . ')\}/i', function ($match) use ($params, $delims) {
				if (!isset($params[$match[1]]))
				{
					return '';
				}

				return '#' . strtr(rawurlencode($params[$match[1]]), $delims);
			}, $uri);

			// Level 2: {+variable} => /foo/bar
			$uri = preg_replace_callback('/\{\+(' . $var_name . ')\}/i', function ($match) use ($params, $delims) {
				if (!isset($params[$match[1]]))
				{
					return '';
				}

				return strtr(rawurlencode($params[$match[1]]), $delims);
			}, $uri);

			// Level 1: {variable} => %2Ffoo%2Fbar
			$uri = preg_replace_callback('/\{(' . $var_name . ')\}/i', function ($match) use ($params) {
				if (!isset($params[$match[1]]))
				{
					return '';
				}

				return rawurlencode($params[$match[1]]);
			}, $uri);

			return $uri;
		}

		protected function buildRequestBody($data, array &$headers): string
		{
			if (is_resource($data)) {
				$body = '';

				while (!feof($data)) {
					$body .= fread($data, 8192);
				}

				$data = $body;
			}

			if (!is_object($data) && !is_array($data) && !is_null($data)) {
				$headers['Content-Type'] ??= self::FORM;
				$headers['Content-Length'] = strlen($data);
				return $data;
			}

			$type = self::FORM;

			foreach ($data as $key => &$item) {
				if (is_resource($item)) {
					$type = 'multipart/form-data';
					$item = [
						'type' => @mime_content_type($item) ?: 'application/octet-stream',
						'name' => basename(stream_get_meta_data($item)['uri'] ?? 'file.ext'),
						'body' => stream_get_contents($item),
					];
				}
			}

			unset($item);

			if ($type === self::FORM) {
				return http_build_query((array) $data, '', '&');
			}

			$boundary = '----------==--' . sha1(random_bytes(5));
			$body = '';

			foreach ($data as $key => $item) {
				$body .= '--' . $boundary . "\r\n";
				$body .= 'Content-Disposition: form-data; name="' . $key  .'"';

				if (is_array($item)) {
					$body .= '; filename="' . $item['name'] . '"'
						. "\r\n"
						. 'Content-Type: ' . $item['type'] . "\r\n\r\n"
						. $item['body'] . "\r\n";
					$item = null;
				}
				else {
					$body .= "\r\n\r\n" . $item . "\r\n";
				}
			}

			unset($data);

			$body .= "\r\n\r\n--" . $boundary . "--\r\n";
			$headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;
			$headers['Content-Length'] = strlen($body);
			return $body;
		}

		/**
		 * HTTP request using PHP stream and file_get_contents
		 * @param  string $method
		 * @param  string $url
		 * @param  string|resource|null $data
		 * @param  array $headers
		 * @return HTTP_Response
		 */
		protected function defaultClientRequest(string $method, string $url, $data, array $headers, $write_pointer = null): HTTP_Response
		{
			$request = '';

			//Add cookies
			if (count($this->cookies) > 0)
			{
				$headers['Cookie'] = '';

				foreach ($this->cookies as $key=>$value)
				{
					if (!empty($headers['Cookie'])) $headers['Cookie'] .= '; ';
					$headers['Cookie'] .= $key . '=' . $value;
				}
			}

			if (!empty($this->http_options['proxy_auth'])) {
				$headers['Proxy-Authorization'] = sprintf('Basic %s', base64_encode($this->http_options['proxy_auth']));
			}

			if (null !== $data) {
				$data = $this->buildRequestBody($data, $headers);
			}

			foreach ($headers as $key => $value) {
				$request .= $key . ': ' . $value . "\r\n";
			}

			$http_options = [
				'method'          => $method,
				'header'          => $request,
				'max_redirects'   => 0,
				'follow_location' => false,
			];

			$http_options = array_merge($this->http_options, $http_options);

			if ($data !== null) {
				$http_options['content'] = $data;
			}

			$context = stream_context_create([
				'http' => $http_options,
				'ssl'  => $this->ssl_options,
			]);

			$request = $method . ' ' . $url . "\r\n" . $request . "\r\n" . ($data ?? '');

			$r = new HTTP_Response;
			$r->url = $url;
			$r->request = $request;
			$r->body = null;

			if (\PHP_VERSION_ID >= 80400) {
				http_clear_last_response_headers();
			}

			try {
				if (null !== $write_pointer) {
					$r->pointer = fopen($url, 'rb', false, $context);
				}
				else {
					$r->body = file_get_contents($url, false, $context);
				}
			}
			catch (\Exception $e) {
				if (!empty($this->http_options['ignore_errors'])) {
					$r->error = $e->getMessage();
					return $r;
				}

				throw $e;
			}

			if ($r->body === false && empty($http_response_header)) {
				return $r;
			}

			$r->fail = false;
			$r->size = strlen($r->body ?? '');

			if (\PHP_VERSION_ID >= 80400) {
				$http_response_header = http_get_last_response_headers();
			}

			foreach ($http_response_header as $line) {
				$header = strtok($line, ':');
				$value = strtok('');

				if ($value === false)
				{
					if (preg_match('!^HTTP/1\.[01] ([0-9]{3}) !', $line, $match)) {
						$r->status = (int) $match[1];
					}
					else {
						$r->headers[] = $line;
					}
				}
				else
				{
					$header = trim($header);
					$value = trim($value);

					// Add to cookies array
					if (strtolower($header) == 'set-cookie') {
						$cookie_key = strtok($value, '=');
						$cookie_value = strtok(';');
						$r->cookies[$cookie_key] = $cookie_value;
					}

					$r->headers[$header] = $value;
				}
			}

			if (!$r->fail && $write_pointer) {
				$hash = hash_init('md5');
				$mime = null;
				$size = 0;

				while (!feof($r->pointer)) {
					$line = fread($r->pointer, 8192);
					$size += fwrite($write_pointer, $line);
					hash_update($hash, $line);

					if (is_null($mime)) {
						$finfo = new \finfo(FILEINFO_MIME);
						$mime = $finfo->buffer($line);
						unset($finfo);
					}

					if ($size <= 1) {
						break;
					}
				}

				fclose($r->pointer);

				$r->hash = hash_final($hash);
				$r->mimetype = $mime;
				$r->size = $size;
			}

			return $r;
		}

		/**
		 * HTTP request using CURL
		 * @param  string $method
		 * @param  string $url
		 * @param  string|resource|null $data
		 * @param  array $headers
		 * @return HTTP_Response
		 */
		protected function curlClientRequest(string $method, string $url, $data, array $headers, $write_pointer = null)
		{
			$c = curl_init();
			$r = new HTTP_Response;

			// Upload file
			if (is_resource($data)) {
				fseek($data, 0, SEEK_END);
				$size = ftell($data);
				fseek($data, 0);

				curl_setopt($c, CURLOPT_INFILE, $data);
				curl_setopt($c, defined('CURLOPT_INFILESIZE_LARGE') ? constant('CURLOPT_INFILESIZE_LARGE') : CURLOPT_INFILESIZE, $size);

				// **IMPORTANT** This needs to be set *AFTER* CURLOPT_POST!
				// If not, CURLOPT_POST will **reset** its internals to **GET**
				// (losing the body contents) but *still* send a PUT method o_O
				curl_setopt($c, CURLOPT_PUT, true);
			}
			// Build request body
			elseif ($data !== null) {
				$data = $this->buildRequestBody($data, $headers);
				curl_setopt($c, CURLOPT_POSTFIELDS, $data);

			}

			// Sets headers in the right format
			foreach ($headers as $key => &$header) {
				$header = $key . ': ' . $header;
			}

			unset($header);
			$headers = array_values($headers);
			$headers[] = 'Expect:';

			if ($method === 'POST') {
				curl_setopt($c, CURLOPT_POST, true);
			}

			curl_setopt_array($c, [
				CURLOPT_URL            => $url,
				CURLOPT_HTTPHEADER     => $headers,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
				CURLOPT_MAXREDIRS      => 1,
				CURLOPT_SSL_VERIFYPEER => !empty($this->ssl_options['verify_peer']),
				CURLOPT_SSL_VERIFYHOST => !empty($this->ssl_options['verify_peer_name']) ? 2 : 0,
				CURLOPT_CUSTOMREQUEST  => $method,
				CURLOPT_TIMEOUT        => !empty($this->http_options['timeout']) ? (int) $this->http_options['timeout'] : 30,
				CURLOPT_SAFE_UPLOAD    => true, // Disable file upload with values beginning with @
				CURLINFO_HEADER_OUT    => true,
			]);

			if (null === $write_pointer) {
				curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
			}
			else {
				curl_setopt($c, CURLOPT_FILE, $write_pointer);
			}

			//curl_setopt($c, CURLOPT_VERBOSE, true);
			//curl_setopt($c, CURLOPT_STDERR, fopen('php://stderr', 'w+'));

			if (!empty($this->http_options['proxy'])) {
				curl_setopt($c, CURLOPT_PROXY, str_replace('tcp://', '', $this->http_options['proxy']));
				curl_setopt($c, CURLOPT_PROXY_SSL_VERIFYHOST, !empty($this->ssl_options['verify_peer_name']) ? 2 : 0);

				if (!empty($this->http_options['proxy_auth'])) {
					curl_setopt($c, CURLOPT_PROXYUSERPWD, $this->http_options['proxy_auth']);
				}
			}

			if (!empty($this->ssl_options['cafile'])) {
				curl_setopt($c, CURLOPT_CAINFO, $this->ssl_options['cafile']);
			}

			if (!empty($this->ssl_options['capath'])) {
				curl_setopt($c, CURLOPT_CAPATH, $this->ssl_options['capath']);
			}

			if (count($this->cookies) > 0)
			{
				// Concatenates cookies
				$cookies = [];

				foreach ($this->cookies as $key=>$value)
				{
					$cookies[] = $key . '=' . $value;
				}

				$cookies = implode('; ', $cookies);

				curl_setopt($c, CURLOPT_COOKIE, $cookies);
			}

			curl_setopt($c, CURLOPT_HEADERFUNCTION, function ($c, $header) use (&$r) {
				$name = trim(strtok($header, ':'));
				$value = strtok('');

				// End of headers, stop here
				if ($name === '')
				{
					return strlen($header);
				}
				elseif ($value === false)
				{
					$r->headers[] = $name;
				}
				else
				{
					$value = trim($value);

					if (strtolower($name) == 'set-cookie')
					{
						$cookie_key = strtok($value, '=');
						$cookie_value = strtok(';');
						$r->cookies[$cookie_key] = $cookie_value;
					}

					$r->headers[$name] = $value;
				}

				return strlen($header);
			});

			$r->url = $url;

			$r->body = curl_exec($c);
			$r->request = curl_getinfo($c, CURLINFO_HEADER_OUT) . $data;

			if ($error = curl_error($c)) {
				if (!empty($this->http_options['ignore_errors'])) {
					$r->error = $error;
					return $r;
				}

				throw new \RuntimeException('cURL error: ' . $error);
			}

			$r->status = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);

			if (PHP_VERSION_ID < 80500) {
				curl_close($c);
			}

			if ($r->body === false) {
				return $r;
			}

			if (null !== $write_pointer) {
				rewind($write_pointer);
			}

			$r->fail = false;
			$r->size = strlen($r->body);

			return $r;
		}
	}

	class HTTP_Response
	{
		public $url = null;
		public $headers = [];
		public $body = null;
		public $pointer = null;

		/**
		 * Set only if write_pointer is set
		 * @var null
		 */
		public ?string $mimetype = null;
		public ?string $hash = null;

		public $fail = true;
		public $cookies = [];

		/**
		 * Status code
		 * @var null|int
		 */
		public ?int $status = null;
		public $request = null;
		public $size = 0;
		public $error = null;
		public $previous = null;

		public function __construct()
		{
			$this->headers = new HTTP_Headers;
		}

		public function __toString()
		{
			return (string)$this->body;
		}
	}

	class HTTP_Headers implements \ArrayAccess
	{
		protected $headers = [];

		public function __get($key)
		{
			$key = strtolower($key);

			if (array_key_exists($key, $this->headers))
			{
				return $this->headers[$key][1];
			}

			return null;
		}

		public function __set($key, $value)
		{
			if (is_null($key))
			{
				$this->headers[] = [null, $value];
			}
			else
			{
				$key = trim($key);
				$this->headers[strtolower($key)] = [$key, $value];
			}
		}

		#[\ReturnTypeWillChange]
		public function offsetGet($offset)
		{
			return $this->__get($offset);
		}

		public function offsetExists($offset): bool
		{
			return array_key_exists(strtolower($offset), $this->headers);
		}

		public function offsetSet($offset, $value): void
		{
			$this->__set($offset, $value);
		}

		public function offsetUnset($offset): void
		{
			unset($this->headers[strtolower($offset)]);
		}

		public function toArray()
		{
			return explode("\r\n", (string)$this);
		}

		public function __toString()
		{
			$out = '';

			foreach ($this->headers as $header)
			{
				$out .= (!is_null($header[0]) ? $header[0] . ': ' : '') . $header[1] . "\r\n";
			}

			return $out;
		}
	}

/**
	 * FossilInstaller
	 *
	 * This is useful to fetch and install .tar.gz (or .zip) updates from a Fossil repository
	 * using the Unversioned files feature.
	 *
	 * This also implements PGP signature verification and can display a summary of changed to the user.
	 *
	 * Copyright (C) 2021 BohwaZ <https://bohwaz.net/>
	 */

	class FossilInstaller
	{
		const DEFAULT_REGEXP = '/app-(?P<version>.*)\.tar\.gz/';

		protected array $releases;
		protected string $app_path;
		protected string $tmp_path;
		protected string $fossil_url;
		protected string $release_name_regexp;
		protected array $ignored_paths = [];
		protected array $managed_paths = [];
		protected string $gpg_pubkey_file;

		public function __construct(string $fossil_repo_url, string $app_path, string $tmp_path, ?string $release_name_regexp = null)
		{
			$this->fossil_url = $fossil_repo_url;
			$this->app_path = $app_path;
			$this->tmp_path = $tmp_path;
			$this->release_name_regexp = $release_name_regexp;
		}

		public function __destruct()
		{
			$this->prune();
		}

		public function setPublicKeyFile(string $file)
		{
			$this->gpg_pubkey_file = $file;
		}

		/**
		 * Ignore some paths during upgrade
		 * @param string $path Paths are relative to the installation directory
		 */
		public function addIgnoredPath(string $path)
		{
			$this->ignored_paths[] = $path;
		}

		public function addManagedPath(string $path)
		{
			$this->managed_paths[] = $path;
		}

		public function listReleases(): array
		{
			if (isset($this->releases)) {
				return $this->releases;
			}

			$list = (new HTTP)->GET($this->fossil_url . 'juvlist');

			if (!$list) {
				return [];
			}

			$list = json_decode($list);

			if (!$list) {
				return [];
			}

			$this->releases = [];

			foreach ($list as $item) {
				if (!isset($item->name, $item->hash, $item->size, $item->mtime)) {
					continue;
				}

				if (!preg_match($this->release_name_regexp, $item->name, $match)) {
					continue;
				}

				list(, $version) = $match;

				$item->signed = false;
				$item->stable = preg_match('/alpha|dev|rc|beta/', $version) ? false : true;
				$this->releases[$version] = $item;
			}

			// Add signed information
			foreach ($list as $item) {
				if (substr($item->name, -4) !== '.asc') {
					continue;
				}

				$name = substr($item->name, 0, -4);

				foreach ($this->releases as &$r) {
					if ($r->name == $name) {
						$r->signed = true;
					}
				}
			}

			unset($r);

			return $this->releases;
		}

		public function latest(bool $stable_only = true): ?string
		{
			$releases = $this->listReleases();

			$latest = null;

			foreach ($releases as $version => $r) {
				if ($stable_only && !$r->stable) {
					continue;
				}

				if (!$latest || version_compare($version, $latest, '>')) {
					$latest = $version;
				}
			}

			return $latest;
		}

		public function download(string $version): string
		{
			if (!isset($this->releases[$version])) {
				throw new \InvalidArgumentException('Unknown release');
			}

			$release = $this->releases[$version];

			$url = sprintf('%suv/%s', $this->fossil_url, $release->name);
			$tmpfile = $this->_getTempFilePath($version);
			$r = (new HTTP)->GET($url);

			if (!$r->fail && $r->body) {
				file_put_contents($tmpfile, $r->body);
				touch($tmpfile);
			}

			if (!file_exists($tmpfile)) {
				throw new \RuntimeException('Error while downloading file');
			}

			$can_check_hash = in_array('sha3-256', hash_algos());

			if ($can_check_hash && !hash_equals(hash_file('sha3-256', $tmpfile), $release->hash)) {
				@unlink($tmpfile);
				throw new \RuntimeException('Error while downloading file: invalid hash');
			}

			return $tmpfile;
		}

		protected function _getTempFilePath(string $version): string
		{
			return $this->tmp_path . '/tmp-release-' . sha1($version) . '.tar.gz';
		}

		public function verify(string $version): ?bool
		{
			if (!isset($this->releases[$version])) {
				throw new \InvalidArgumentException('Unknown release');
			}

			$tmpfile = $this->_getTempFilePath($version);

			if (!file_exists($tmpfile)) {
				throw new \LogicException('This release has not been downloaded yet');
			}

			$release = $this->releases[$version];

			$can_check_hash = in_array('sha3-256', hash_algos());

			if ($can_check_hash && !hash_equals(hash_file('sha3-256', $tmpfile), $release->hash)) {
				@unlink($tmpfile);
				throw new \RuntimeException('Error while downloading file: invalid hash');
			}

			if (!$release->signed) {
				return null;
			}

			if (!Security::canUseEncryption()) {
				return null;
			}

			$url = sprintf('%suv/%s.asc', $this->fossil_url, $release->name);
			$r = (new HTTP)->GET($url);

			if ($r->fail || !$r->body) {
				return null;
			}

			$key = file_get_contents($this->gpg_pubkey_file);
			$data = file_get_contents($tmpfile);

			return Security::verifyWithPublicKey($key, $data, $r->body);
		}

		/**
		 * Remove old stale downloaded files
		 * @return void
		 */
		public function prune(int $delay = 3600 * 24): void
		{
			$files = self::recursiveList($this->tmp_path, 'tmp-release-*');
			$dirs = [];

			foreach ($files as $file) {
				if (is_dir($file)) {
					$dirs[] = $file;
					continue;
				}

				if (!$delay || filemtime($file) < (time() - $delay)) {
					@unlink($file);
				}
			}

			// Try to remove directories
			foreach ($dirs as $dir) {
				@rmdir($dir);
			}
		}

		public function clean(string $version): void
		{
			$path = $this->_getTempFilePath($version);
			self::recursiveDelete(dirname($path), basename($path) . '*');
		}

		static protected function recursiveDelete(string $path, string $pattern = '*') {
			$files = self::recursiveList($path, $pattern);

			$dirs = [];

			foreach ($files as $file) {
				if (is_dir($file)) {
					$dirs[] = $file;
					continue;
				}

				@unlink($file);
			}

			foreach ($dirs as $dir) {
				@rmdir($dir);
			}
		}

		public function diff(string $version): \stdClass
		{
			$this->listReleases();

			if (!isset($this->releases[$version])) {
				throw new \InvalidArgumentException('Unknown release');
			}

			$tmpfile = $this->_getTempFilePath($version);

			if (!file_exists($tmpfile)) {
				throw new \LogicException('This release has not been downloaded yet');
			}

			$release = $this->releases[$version];

			$phar = new \PharData($tmpfile,
				\FilesystemIterator::CURRENT_AS_FILEINFO | \FilesystemIterator::KEY_AS_PATHNAME
				| \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS);


			// List existing files
			$existing_files = [];
			$l = strlen($this->app_path);

			foreach (self::recursiveList($this->app_path) as $path) {
				if (is_dir($path)) {
					continue;
				}

				$file = substr($path, $l + 1);

				// Skip ignored paths
				if ($this->isIgnoredPath($file)) {
					continue;
				}

				$existing_files[$file] = $path;
			}

			// List files
			$release_files = [];
			$update = [];

			// We are always ignoring the first directory level
			$parent = $phar->getPathName();
			$parent_l = strlen($parent);

			foreach (new \RecursiveIteratorIterator($phar) as $path => $file) {
				if ($file->isDir()) {
					// Skip directories
					continue;
				}

				$relative_path = substr($path, $parent_l + 1);
				$release_files[$relative_path] = $path;

				$is_ignored = $this->isPublic($relative_path);
				$local_path = $this->app_path . DIRECTORY_SEPARATOR . $relative_path;

				// Skip if file doesn't exist, it will be marked as to be created
				if (!file_exists($local_path)) {
					continue;
				}

				if ($file->getSize() != filesize($local_path)
					|| sha1_file($local_path) != sha1_file($path)) {
					$update[$relative_path] = $path;
				}
				elseif ($is_ignored) {
					unset($release_files[$relative_path]);
				}
			}

			$create = array_diff_key($release_files, $existing_files);
			$delete = array_diff_key($existing_files, $release_files);

			ksort($create);
			ksort($delete);
			ksort($update);

			return (object) compact('delete', 'create', 'update');
		}

		protected function isPathIgnored(string $relative_path): bool
		{
			foreach ($this->managed_paths as $managed_path) {
				if (0 === strpos($relative_path, $managed_path)) {
					return false;
				}
			}

			foreach ($this->ignored_paths as $ignored_path) {
				if (0 === strpos($relative_path, $ignored_path)) {
					return true;
				}
			}

			return false;
		}

		public function upgrade(string $version): void
		{
			$diff = $this->diff($version);

			foreach ($diff->delete as $path) {
				@unlink($path);
			}

			// FIXME: Clean up empty directories

			foreach ($diff->create as $file => $source) {
				$this->_copy($source, $this->app_path . DIRECTORY_SEPARATOR . $file);
			}

			foreach ($diff->update as $file => $source) {
				$this->_copy($source, $this->app_path . DIRECTORY_SEPARATOR . $file);

				if (function_exists('opcache_invalidate')) {
					@opcache_invalidate($this->app_path . DIRECTORY_SEPARATOR . $file, true);
				}
			}

			$this->clean($version);
		}

		protected function _copy(string $source, string $target): bool
		{
			$dir = dirname($target);

			if (!file_exists($dir)) {
				mkdir($dir, 0777, true);
			}

			return copy($source, $target);
		}

		public function install(string $version)
		{
			if (!isset($this->releases[$version])) {
				throw new \InvalidArgumentException('Unknown release');
			}

			$tmpfile = $this->_getTempFilePath($version);
			$phar = new \PharData($tmpfile, \FilesystemIterator::CURRENT_AS_FILEINFO | \FilesystemIterator::KEY_AS_PATHNAME
				| \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS);
			// Ignore first level directory
			$root_l = strlen($phar->getPathName());

			foreach (new \RecursiveIteratorIterator($phar) as $source => $_file) {
				$file = substr($source, $root_l + 1);
				$this->_copy($source, $this->app_path . DIRECTORY_SEPARATOR . $file);
			}
		}

		public function autoinstall(?string $version = null): void
		{
			$version ??= $this->latest();

			if (!$version) {
				return;
			}

			$this->download($version);

			if (isset($this->gpg_pubkey_file)) {
				$this->verify($version);
			}

			$this->install($version);
			$this->clean($version);
		}

		static protected function recursiveList(string $path, string $pattern = '*')
		{
			$out = [];

			foreach (glob($path . DIRECTORY_SEPARATOR . $pattern, \GLOB_NOSORT) as $subpath) {
				$out[] = $subpath;

				if (is_dir($subpath)) {
					$out = array_merge($out, self::recursiveList($subpath));
				}
			}

			return $out;
		}
	}
}

namespace {
	const WEBSITE = 'https://fossil.kd2.org/paheko/';
	const INSTALL_DIR = __DIR__ . '/.install';

	echo '
	<!DOCTYPE html>
	<html>
	<head>
	<meta charset="utf-8" />
	<style type="text/css">
	body {
		font-family: sans-serif;
	}
	h2, p {
		margin: 0;
		margin-bottom: 1rem;
	}
	div {
		position: relative;
		border: 1px solid #999;
		max-width: 500px;
		padding: 1em;
		border-radius: .5em;
	}
	.spinner h2::after {
		display: block;
		content: " ";
		margin: 1rem auto;
		width: 50px;
		height: 50px;
		border: 5px solid #000;
		border-radius: 50%;
		border-top-color: #999;
		animation: spin 1s ease-in-out infinite;
	}

	@keyframes spin { to { transform: rotate(360deg); } }
	</style>';

	function exception_error_handler($severity, $message, $file, $line) {
		if (!(error_reporting() & $severity)) {
			return;
		}
		throw new ErrorException($message, 0, $severity, $file, $line);
	}

	function mini_exception_handler($e) {
		printf('
		<div style="padding: 1rem;
			background: #fee;
			border: 2px solid darkred;"><h2>%s</h2>
			<h3>in %s:%d</h3>
			<pre>%s</pre>
		</div>',
		$e->getMessage(), $e->getFile(), $e->getLine(), (string) $e);
	}

	set_error_handler("exception_error_handler");

	set_exception_handler('mini_exception_handler');

	if (!version_compare(phpversion(), '7.4', '>=')) {
		throw new \Exception('PHP 7.4 ou supérieur requis. PHP version ' . phpversion() . ' installée.');
	}

	if (!class_exists('SQLite3')) {
		throw new \Exception('Le module de base de données SQLite3 n\'est pas disponible.');
	}

	$v = \SQLite3::version();

	if (!version_compare($v['versionString'], '3.16', '>=')) {
		throw new \Exception('SQLite3 version 3.16 ou supérieur requise. Version installée : ' . $v['versionString']);
	}

	$step = $_GET['step'] ?? null;
	$error = null;

	@mkdir(INSTALL_DIR);
	$i = new KD2\FossilInstaller(WEBSITE, __DIR__, INSTALL_DIR, '!^paheko-(.*)\.tar\.gz$!');

	if ($step == 'download') {
		$latest = $i->latest();

		if (!$latest) {
			die('</head><h1>Aucune version à télécharger n\'a été trouvée.</h1>');
		}

		$i->download($latest);
		$next = 'install';
	}
	elseif ($step == 'install') {
		$latest = $i->latest();

		if (!$latest) {
			die('</head><h1>Aucune version à télécharger n\'a été trouvée.</h1>');
		}

		$i->install($latest);
		$i->clean($latest);

		if (class_exists('\OCP\AppFramework\Controller')) {
			$next = 'nc' . time();
		}
		else {
			$next = null;
		}
	}
	else {
		$next = 'download';
	}

	echo $next ? '<meta http-equiv="refresh" content="0;url=?step='.$next.'" />' : '';

	echo '
	</head>';

	if ($step == 'download') {
		echo '
		<div class="spinner">
			<h2>Décompression en cours…</h2>
		</div>';
	}
	elseif ($step == 'install') {
		echo '<div>
			<h2>Installation réussie</h2>
			<p>Configurez désormais votre sous-domaine pour pointer sur le sous-répertoire <strong>www</strong> de cette installation.</p>
			<p><a href="' . WEBSITE . '">Consultez la documentation pour plus d\'infos</a></p>
		</div>';
	}
	else {
		echo '
		<div class="spinner">
			<h2>Téléchargement en cours…</h2>
		</div>';
	}

	echo '
	</body>
	</html>
	';

	if ($step == 'install') {
		$i->prune(0);
		@rmdir(INSTALL_DIR);
		@unlink(__FILE__);
	}
}

?>