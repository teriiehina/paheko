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

class HTTP
{
	const FORM = 'application/x-www-form-urlencoded';
	const JSON = 'application/json';
	const XML = 'text/xml';

	const CLIENT_DEFAULT = 'default';
	const CLIENT_CURL = 'curl';

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

		if (0 !== strpos($url, 'http://') && 0 !== strpos($url, 'https://')) {
			throw new \InvalidArgumentException('Invalid URL: ' . $url);
		}

		$url = $this->url_prefix . $url;

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

		// Upload file
		if (is_resource($data)) {
			fseek($data, 0, SEEK_END);
			$size = ftell($data);
			fseek($data, 0);

			curl_setopt($c, CURLOPT_INFILE, $data);
			curl_setopt($c, CURLOPT_INFILESIZE, $size);
			curl_setopt($c, CURLOPT_PUT, 1);
		}
		// Build request body
		elseif ($data !== null) {
			$data = $this->buildRequestBody($data, $headers);
			curl_setopt($c, CURLOPT_POSTFIELDS, $data);
		}

		// Sets headers in the right format
		foreach ($headers as $key=>&$header)
		{
			$header = $key . ': ' . $header;
		}

		$headers[] = 'Expect:';

		unset($header);

		$r = new HTTP_Response;

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
			CURLOPT_POST           => $method == 'POST' ? true : false,
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

		if ($r->body === false) {
			return $r;
		}

		if (null !== $write_pointer) {
			rewind($write_pointer);
		}

		$r->fail = false;
		$r->size = strlen($r->body);
		$r->status = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);

		curl_close($c);

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
