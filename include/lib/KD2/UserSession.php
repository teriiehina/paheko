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

/**
 * UserSession
 *
 * @author  bohwaz  http://bohwaz.net/
 */

namespace KD2;

use KD2\DB\DB;
use KD2\Security_OTP;

class UserSession
{
	////////////////////////////////////////////////////////////////////////////
	// Methods and properties that should be reimplemented in the child class
	// You should implement those methods in the class that is extending this
	// one to suit your setup.

	const SID_IN_URL_HOSTS_WHITELIST = ['::1', '127.0.0.1'];

	/**
	 * This is used to secure the use of the session ID in the URL
	 * @var null|string
	 */
	protected ?string $sid_in_url_secret = null;

	/**
	 * Set to TRUE for using the non-locking mode
	 * If using this mode, the session will be opened and closed immediately.
	 * To write to the session you will need to call ->start(true), change the value
	 * $_SESSION and call ->close().
	 * If set to FALSE (default PHP behaviour), you won't be able to use the
	 * same session from two requests at the same time.
	 */
	protected bool $non_locking = false;

	/**
	 * Cookie name for the current, short-lived session (PHP session)
	 * @var string
	 */
	protected $cookie_name = 'session';

	/**
	 * Cookie name for "remember me" session
	 * @var string
	 */
	protected $remember_me_cookie_name = 'rememberme';

	/**
	 * Domain name used for cookies
	 * @var string
	 */
	protected $cookie_domain = null;

	/**
	 * URI path used for cookies
	 * @var string
	 */
	protected $cookie_path = '/';

	/**
	 * Secure bit for cookies, set to TRUE to have the cookie only returned
	 * on HTTPS.
	 * @var boolean
	 */
	protected $cookie_secure = false;

	/**
	 * Expiry of "remember me" sessions
	 * Any string supported by strtotime() is supported here
	 * @var string
	 */
	protected $remember_me_expiry = '+3 months';

	protected array $data = [];

	/**
	 * Checks a password supplied at login ($supplied_password) against a stored
	 * password ($stored_password)
	 * @param  string $supplied_password
	 * @param  string $stored_password
	 * @return boolean TRUE if password is matching, FALSE if it's not
	 */
	public function checkPassword($supplied_password, $stored_password): bool
	{
		if (empty($supplied_password)) {
			return false;
		}

		// Remove NUL bytes
		// see http://blog.ircmaxell.com/2015/03/security-issue-combining-bcrypt-with.html
		$supplied_password = str_replace("\0", '', $supplied_password);

		return password_verify($supplied_password, $stored_password);
	}

	/**
	 * Returns user details for login
	 * @param  string $login
	 * @return object|boolean An object with at least 3 public properties: login, password and otp_secret. Or FALSE if
	 * there is no user found for this login (or the user doesn't have the right to login).
	 */
	protected function getUserForLogin($login)
	{
		return $this->db->first('SELECT login, password, otp_secret, id FROM users WHERE login = ? LIMIT 1;', $login);
	}

	/**
	 * Returns user details to populate session data
	 * @param  mixed  $id User ID
	 * @return object An object that will be stored in session
	 */
	protected function getUserDataForSession($id)
	{
		return $this->db->first('SELECT * FROM users WHERE id = ? LIMIT 1;', $id);
	}

	/**
	 * Stores "remember me" selector and details
	 * @param  string $selector
	 * @param  string $hash
	 * @param  integer $expiry
	 * @param  string $user_id
	 * @return boolean
	 */
	protected function storeRememberMeSelector($selector, $hash, $expiry, $user_id)
	{
		return $this->db->insert('remember_me_selectors', [
			'selector' => $selector,
			'hash'     => $hash,
			'expiry'   => $expiry,
			'user_id'  => $user_id,
		]);
	}

	/**
	 * Deletes expired selectors
	 * @return boolean
	 */
	protected function expireRememberMeSelectors()
	{
		return $this->db->delete('remember_me_selectors', $this->db->where('expiry', '<', time()));
	}

	/**
	 * Returns a remember me selector and the user password
	 * @param  string $selector
	 * @return object An object with public properties: selector, hash, user_id and user_password
	 */
	protected function getRememberMeSelector($selector)
	{
		return $this->db->first('SELECT r.*, u.password AS user_password
			FROM remember_me_selectors AS r
			LEFT JOIN users AS u ON u.id = r.user_id
			WHERE r.selector = ? LIMIT 1;', $selector);
	}

	/**
	 * Deletes a specific selector
	 * @param  string $selector
	 * @return boolean
	 */
	protected function deleteRememberMeSelector($selector)
	{
		return $this->db->delete('remember_me_selectors', $this->db->where('selector', $selector));
	}

	/**
	 * Deletes all selectors for a user
	 * @param  string $user_id
	 * @return boolean
	 */
	protected function deleteAllRememberMeSelectors($user_id)
	{
		return $this->db->delete('remember_me_selectors', $this->db->where('user_id', $user_id));
	}

	/**
	 * Returns true if a password exists in the local cached list of compromised passwords
	 * @param string $hash
	 * @return bool
	 */
	protected function isPasswordCompromisedInCache($hash)
	{
		return $this->db->test('compromised_passwords_cache', $this->db->where('hash', $hash));
	}

	/**
	 * Store a list of compromised hash suffixes in cache
	 * @param  string $prefix Hash prefix
	 * @param  array  $range  List of hash suffixes
	 * @return bool
	 */
	protected function storeCompromisedPasswordsRange($prefix, array $range)
	{
		$this->db->begin();

		// Insert prefix for cache expiry
		$this->db->preparedQuery('INSERT OR REPLACE INTO compromised_passwords_cache_ranges (prefix, date) VALUES (?, ?);', [$prefix, time()]);

		foreach ($range as $suffix) {
			$this->db->preparedQuery('INSERT OR IGNORE INTO compromised_passwords_cache (hash) VALUES (?);', [$prefix . $suffix]);
		}

		return $this->db->commit();
	}

	protected function isPasswordRangeExpiredInCache($prefix)
	{
		// 7 days
		$expiry = time() - 60 * 24 * 7;

		return !$this->db->test('compromised_passwords_cache_ranges', 'prefix = ? AND date >= ?', $prefix, $expiry);
	}

	/**
	 * Return a string containing an identifier for this app setup (eg. domain name),
	 * AND an identifier for user password (eg. hashed password).
	 * This is to make sure that other sessions are killed when the user changes their password,
	 * to block any attacker that could have be already logged in.
	 */
	protected function getUserSessionVerifier(): ?string
	{
		return strval($this->user);
	}

	////////////////////////////////////////////////////////////////////////////
	// Actual code of UserSession

	const HASH_ALGO = 'sha256';
	const REQUIRE_OTP = 'otp';
	const HIBP_API_URL = 'https://api.pwnedpasswords.com/range/%s';

	protected bool $modified = false;

	protected $cookie;
	protected $user;

	protected $db;

	protected $http;

    static public function hashPassword($password)
    {
        // Remove NUL bytes
        // see http://blog.ircmaxell.com/2015/03/security-issue-combining-bcrypt-with.html
        $password = str_replace("\0", '', $password);

        return password_hash($password, \PASSWORD_DEFAULT);
    }

	public function __construct(DB $db, $config = [])
	{
		// Reset any existing session
		unset($_SESSION);
		session_name($this->cookie_name);

		$this->db = $db;

		foreach ($config as $key=>$value)
		{
			$this->$key = $value;
		}

		if (null === $this->cookie_domain && isset($_SERVER['SERVER_NAME']))
		{
			$this->cookie_domain = $_SERVER['SERVER_NAME'];
		}
	}

	public function save()
	{
		// Save data
		if ($this->modified) {
			$this->start(true);
			$_SESSION['userSessionData'] = $this->data;
			$this->close();
			$this->modified = false;
		}
	}

	public function __destruct()
	{
		if ($this->modified) {
			throw new \LogicException('Session data has been modified but not saved');
		}
	}

	protected function getSessionOptions(bool $create_cookie)
	{
		return [
			'name'            => $this->cookie_name,
			'use_cookies'     => $create_cookie,
			'cookie_path'     => $this->cookie_path,
			'cookie_domain'   => $this->cookie_domain,
			'cookie_secure'   => $this->cookie_secure,
			'cookie_httponly' => true,
			'cookie_samesite' => 'Lax',
		];
	}

	public function id(): ?string
	{
		return session_id() ?: null;
	}

	public function setId(string $id)
	{
		session_id($id);
	}

	public function start(bool $write = false)
	{
		// Don't start session if it has been already started
		if (isset($_SESSION) && !$write) {
			return true;
		}

		$init = false;
		$create_cookie = $write;
		$session_id = $_COOKIE[$this->cookie_name] ?? null;

		if (!isset($_SESSION)) {
			$session_url = null;

			// Allow to pass session ID in URL for some URLs
			if (!$session_id
				&& !empty($_GET[$this->cookie_name])
				&& preg_match('/^[a-zA-Z0-9-]{1,64}$/', $_GET[$this->cookie_name])) {
				$allowed = false;

				if (!empty(self::SID_IN_URL_HOSTS_WHITELIST)
					&& in_array($_SERVER['REMOTE_ADDR'] ?? null, self::SID_IN_URL_HOSTS_WHITELIST, true)) {
					$allowed = true;
				}
				elseif (!empty($this->sid_in_url_secret) && false !== strpos($_SERVER['QUERY_STRING'] ?? '', $this->sid_in_url_secret)) {
					$allowed = true;
				}

				if (!$allowed) {
					return false;
				}

				$session_id = $_GET[$this->cookie_name];
				$session_url = true;
				$create_cookie = false;
			}

			// Only start session if it exists
			if ($write || $session_id)
			{
				// Check session ID value, in case it is invalid/corrupted
				// see https://stackoverflow.com/questions/3185779/the-session-id-is-too-long-or-contains-illegal-characters-valid-characters-are
				if (!$session_url && isset($_COOKIE[$this->cookie_name]) && !preg_match('/^[a-zA-Z0-9-]{1,64}$/', $_COOKIE[$this->cookie_name])) {
					@session_regenerate_id();
				}

				if (headers_sent($file, $line)) {
					throw new \LogicException(sprintf('Cannot start session: headers already sent in line %d of %s', $line, $file));
				}

				if ($session_url) {
					@ini_set('session.use_cookies', false);
					@ini_set('session.use_only_cookies', false);
				}
				elseif (ini_get('session.use_cookies')) {
					session_set_cookie_params([
						'lifetime' => 0,
						'path'     => $this->cookie_path,
						'domain'   => $this->cookie_domain,
						'secure'   => $this->cookie_secure,
						'httponly' => true,
						'samesite' => 'Lax',
					]);
				}

				session_name($this->cookie_name);
				$init = true;
			}
		}

		if ($write || $session_id) {
			// Make sure the session ID belongs to the session name
			if ($session_id && 0 !== strpos($session_id, $this->cookie_name . '-')) {
				$session_id = null;
			}

			// Don't create cookie if it's already there
			if ($session_id && ($_COOKIE[$this->cookie_name] ?? null) === $session_id) {
				$create_cookie = false;
			}

			// Create session ID including cookie name to restrict context
			$session_id ??= session_create_id($this->cookie_name . '-');
			$this->setId($session_id);

			$_COOKIE[$this->cookie_name] ??= $session_id;

			$return = session_start($this->getSessionOptions($create_cookie));

			// Make sure we restrict the context of the session
			if (!isset($_SESSION['__name'])) {
				$_SESSION['__name'] = $this->cookie_name;
			}
			// Attempting to use a different session, this is suspect
			elseif ($_SESSION['__name'] !== $this->cookie_name) {
				$this->destroy();
				return false;
			}

			if ($init) {
				$this->data = array_merge($this->data, $_SESSION['userSessionData'] ?? []);
			}

			if (!$write) {
				$this->close();
			}

			return $return;
		}

		return false;
	}

	public function close(): void
	{
		// Release lock so that other processes are not blocked
		// see https://www.php.net/manual/en/function.session-start.php
		// and https://ma.ttias.be/php-session-locking-prevent-sessions-blocking-in-requests/

		if ($this->non_locking) {
			session_write_close();
		}
	}

	public function keepAlive(): void
	{
		$this->start(true);
		$this->close();
	}

	public function refresh(): bool
	{
		if (!$this->isLogged())
		{
			throw new \LogicException('User is not logged in.');
		}

		try {
			return $this->create($this->getUser()->id);
		}
		catch (\LogicException $e) {
			$this->logout();
			return false;
		}
	}

	public function isLogged()
	{
		if (null !== $this->user)
		{
			return true;
		}

		// Démarrage session
		$this->start();

		if (empty($_SESSION['userSession'])) {
			$this->rememberMeAutoLogin();
		}

		if (empty($_SESSION['userSession'])) {
			return false;
		}

		$this->user = $_SESSION['userSession'];

		// Upgrade old sessions and set identifier for new sessions if it's not there
		if (empty($_SESSION['userIdentifier'])) {
			$this->start(true);
			$_SESSION['userIdentifier'] = $this->getUserSessionVerifier();
			$this->close();
		}
		// Make sure that if password has changed, the session is killed
		elseif ($_SESSION['userIdentifier'] !== $this->getUserSessionVerifier()) {
			$this->logout();
			return false;
		}

		return true;
	}

	public function clearSessionVerifier(): void
	{
		$this->start(true);
		$_SESSION['userIdentifier'] = null;
		$this->close();
	}

	public function getUser()
	{
		if (!$this->isLogged())
		{
			throw new \LogicException('User is not logged in.');
		}

		return $this->user;
	}

	public function set($key, $value)
	{
		if (array_key_exists($key, $this->data) && $this->data[$key] === $value) {
			return;
		}

		if ($value === null) {
			unset($this->data[$key]);
		}
		else {
			$this->data[$key] = $value;
		}

		$this->modified = true;
	}

	public function get($key)
	{
		$this->start();
		return $this->data[$key] ?? null;
	}

	public function login($login, $password, $remember_me = false)
	{
		assert(is_bool($remember_me));
		assert(is_string($login));
		assert(is_string($password));

		// Prevent DoS attacks
		if (strlen($login) > 256 || strlen($password) > 512) {
			return false;
		}

		$user = $this->getUserForLogin(trim($login));

		if (!$user || empty($user->password))
		{
			return false;
		}

		if (!$this->checkPassword(trim($password), $user->password))
		{
			return false;
		}

		if (!empty($user->otp_secret))
		{
			$this->start(true);

			$_SESSION = [];

			$_SESSION['userSessionRequireOTP'] = (object) [
				'user'        => $user,
				'remember_me' => $remember_me,
			];

			$this->close();

			return $this::REQUIRE_OTP;
		}
		else
		{
			$this->create($user->id);

			if ($remember_me)
			{
				$this->createRememberMeSelector($user->id, $user->password);
			}

			return true;
		}
	}

	protected function create($user_id): bool
	{
		$user = $this->getUserDataForSession($user_id);

		if (!$user)
		{
			throw new \LogicException('Cannot create a session for a user that does not exists.');
		}

		$this->start(true);
		$this->user = $_SESSION['userSession'] = $user;

		// Make sure the password is in the session, and the session should be closed if it changes
		$_SESSION['userIdentifier'] = $this->getUserSessionVerifier();

		$this->close();
		return true;
	}

	public function logout(bool $all = false)
	{
		if ($all && $this->isLogged()) {
			$this->deleteAllRememberMeSelectors($this->getUser()->id);
		}

		if ($cookie = $this->getRememberMeCookie())
		{
			if (!$all) {
				$this->deleteRememberMeSelector($cookie->selector);
			}

			setcookie($this->remember_me_cookie_name, '', -1, $this->cookie_path,
				$this->cookie_domain, $this->cookie_secure, true);
			unset($_COOKIE[$this->remember_me_cookie_name]);
		}

		$this->start(true);
		$this->destroy();

		return true;
	}

	protected function destroy(): void
	{
		session_destroy();
		$_SESSION = null;

		setcookie($this->cookie_name, '', -1, $this->cookie_path,
			$this->cookie_domain, $this->cookie_secure, true);

		unset($_COOKIE[$this->cookie_name]);

		$this->user = null;
	}

	/**
	 * Returns true if a password is compromised according to Have I Been Pwned
	 * @param  string  $password Password
	 * @return boolean
	 */
	public function isPasswordCompromised(string $password): bool
	{
		if (null === $this->http) {
			throw new \LogicException(self::class . '->http property is not set, must be an instance of \KD2\HTTP class');
		}

		$hash = strtoupper(sha1($password));
		$prefix = substr($hash, 0, 5);
		$suffix = substr($hash, 5);


		if ($this->isPasswordCompromisedInCache($hash)) {
			return true;
		}

		if ($this->isPasswordRangeExpiredInCache($prefix)) {
			$response = $this->http->GET(sprintf($this::HIBP_API_URL, $prefix));

			if (200 != $response->status) {
				// Still store the fact that we have requested this range when the request failed
				// so that we don't re-request this range too soon
				$this->storeCompromisedPasswordsRange($prefix, []);
				return false;
			}

			$range = (string) $response;
			$range = explode("\n", $range);
			$list = [];

			foreach ($range as $row) {
				$row = trim($row);

				if ('' === $row) {
					continue;
				}

				$row = strtok($row, ':');
				strtok('');
				$list[] = strtoupper($row);
			}

			$this->storeCompromisedPasswordsRange($prefix, $list);

			if (in_array($suffix, $list)) {
				return true;
			}
		}

		return false;
	}

	//////////////////////////////////////////////////////////////////////
	// "Remember me" feature

	protected function createSelectorValues($user_id, string $user_password, ?string $expiry = null, ?string $selector = null): \stdClass
	{
		if (null !== $selector && (!ctype_alnum(str_replace('_', '', $selector)) || strlen($selector) > 64 || strlen($selector) < 10)) {
			throw new \InvalidArgumentException('Invalid selector');
		}

		$selector = $selector ?? hash($this::HASH_ALGO, random_bytes(10));
		$verifier = hash($this::HASH_ALGO, random_bytes(10));
		$expiry = (new \DateTime)->modify($expiry ?? $this->remember_me_expiry);
		$expiry = $expiry->getTimestamp();

		$hash = hash($this::HASH_ALGO, $selector . $verifier . $user_password . $expiry);
		return (object) compact('hash', 'selector', 'expiry', 'verifier');
	}

	/**
	 * Creates a permanent "remember me" session
	 * @link   https://www.databasesandlife.com/persistent-login/
	 * @link   https://paragonie.com/blog/2015/04/secure-authentication-php-with-long-term-persistence
	 * @link   https://paragonie.com/blog/2017/02/split-tokens-token-based-authentication-protocols-without-side-channels
	 * @link   http://jaspan.com/improved_persistent_login_cookie_best_practice
	 * @param  object $user
	 * @return boolean
	 */
	protected function createRememberMeSelector($user_id, $user_password)
	{
		$s = $this->createSelectorValues($user_id, $user_password);

		$this->storeRememberMeSelector($s->selector, $s->hash, $s->expiry, $user_id);

		$cookie = $s->selector . '|' . $s->verifier;

		setcookie($this->remember_me_cookie_name, $cookie, $s->expiry,
			$this->cookie_path, $this->cookie_domain, $this->cookie_secure, true);

		return true;
	}

	protected function checkRememberMeSelector(\stdClass $selector, string $verifier): bool
	{
		// Here we are using the user password. If the user changes his password,
		// any previously opened session will be invalid.
		$hash = hash($this::HASH_ALGO, $selector->selector . $verifier . $selector->user_password . $selector->expiry);

		// Check the token hash
		return hash_equals($selector->hash, $hash);
	}

	/**
	 * Connexion automatique en utilisant un cookie permanent
	 * (fonction "remember me")
	 *
	 * @link   https://www.databasesandlife.com/persistent-login/
	 * @link   https://paragonie.com/blog/2015/04/secure-authentication-php-with-long-term-persistence
	 * @link   https://paragonie.com/blog/2017/02/split-tokens-token-based-authentication-protocols-without-side-channels
	 * @link   http://jaspan.com/improved_persistent_login_cookie_best_practice
	 * @return boolean
	 */
	protected function rememberMeAutoLogin(): bool
	{
		$cookie = $this->getRememberMeCookie();

		if (!$cookie)
		{
			return false;
		}

		// Delete expired selectors first thing
		$this->expireRememberMeSelectors();

		// Fetch the selector
		$selector = $this->getRememberMeSelector($cookie->selector);

		// Invalid selector: delete any cookie (clean up)
		if (!$selector)
		{
			$this->logout();
			return false;
		}

		// The selector is useless now, delete it so that it can't be reused
		$this->deleteRememberMeSelector($cookie->selector);

		// Check the token hash
		if (!$this->checkRememberMeSelector($selector, $cookie->verifier))
		{
			// If we get there it means that the selector is valid, but not its verifier token hash
			// Either the cookie has been stolen, then the attacker has obtained a
			// new token, and the user is coming back with an old token that is now
			// invalid. In that case let's delete all remember me selectors to force
			// the user to re-login

			$this->deleteAllRememberMeSelectors($selector->user_id);
			$this->logout();
			return false;
		}

		// Create short lived session
		$this->create($selector->user_id);

		// Re-generate a new verifier/selector and update the cookie
		// as each selector is single use
		$this->createRememberMeSelector($selector->user_id, $selector->user_password);

		return true;
	}


	protected function getRememberMeCookie()
	{
		if (empty($_COOKIE[$this->remember_me_cookie_name]))
		{
			return false;
		}

		$cookie = $_COOKIE[$this->remember_me_cookie_name];

		$data = explode('|', $cookie);

		if (count($data) !== 2)
		{
			return false;
		}

		return (object) [
			'selector' => $data[0],
			'verifier' => $data[1],
		];
	}

	public function hasRememberMeCookie(): bool
	{
		return !empty($_COOKIE[$this->remember_me_cookie_name]);
	}

	//////////////////////////////////////////////////////////////////////
	// Second factor OTP feature

	public function isOTPRequired()
	{
		$this->start();

		return !empty($_SESSION['userSessionRequireOTP']);
	}

	public function markRecoveryCodeAsUsed(string $code, \stdClass $user): void
	{
		// Define your behaviour
	}

	public function loginOTP(string $code): bool
	{
		$this->start();

		if (empty($_SESSION['userSessionRequireOTP'])) {
			return false;
		}

		$user = $_SESSION['userSessionRequireOTP']->user;

		if (empty($user->otp_secret) || empty($user->id)) {
			return false;
		}

		$ok = false;

		if ($this->checkOTP($user->otp_secret, $code)) {
			$ok = true;
		}
		elseif (!empty($user->otp_recovery_codes) && in_array($code, $user->otp_recovery_codes, true)) {
			$ok = true;
			$this->markRecoveryCodeAsUsed($code, $user);
		}

		if (!$ok) {
			return false;
		}

		if (!empty($_SESSION['userSessionRequireOTP']->remember_me)) {
			$this->createRememberMeSelector($user->id, $user->password);
		}

		$this->create($user->id);
		return true;
	}

	public function checkOTP($secret, $code)
	{
		return Security_OTP::TOTP($secret, $code);
	}

	public function getPGPFingerprint($key, $display = false)
	{
		if (!Security::canUseEncryption())
		{
			return false;
		}

		$fingerprint = Security::getEncryptionKeyFingerprint($key);

		if ($display && $fingerprint)
		{
			$fingerprint = str_split($fingerprint, 4);
			$fingerprint = implode(' ', $fingerprint);
		}

		return $fingerprint;
	}
}
