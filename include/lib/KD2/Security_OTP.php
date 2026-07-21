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

/**
 * Lightweight HOTP/TOTP library
 *
 * Compatible with Google Authenticator and based on RFCs
 */
class Security_OTP
{
	/**
	 * Default number of digits produced by HOTP and TOTP
	 */
	const DIGITS = 6;

	/**
	 * Default digest algo used in HMAC for OTP
	 */
	const DIGEST = 'sha1';

	/**
	 * Default interval for TOTP (in seconds)
	 */
	const INTERVAL = 30;

	/**
	 * Default interval drift for TOTP (1 means will check 1 code in the past and 1 in the future,
	 * this is to help users with incorrect clocks)
	 */
	const DRIFT = 1;

	/**
	 * Creates or checks a HOTP code
	 *
	 * Compatible with Google Authenticator
	 *
	 * Note that you should store $count yourself, as well as store codes that
	 * have already been used successfully, and reject any used code to protect
	 * against replay attacks.
	 * 
	 * @link https://gist.github.com/oott123/5479614
	 * @link https://github.com/ChristianRiesen/otp/blob/master/src/Otp.php
	 * @link https://github.com/lelag/otphp
	 * 
	 * @param string $secret Secret key (Base32 encoded)
	 * @param integer $count Counter or timestamp for TOTP
	 * @param integer $code  Code to check against, if null is passed, then a code will be generated
	 * @param integer $digits Number of digits in the generated code (default is 6)
	 * @param string $digest Digest algo to use (sha1, sha256 or sha512) default is sha1
	 */
	static public function HOTP($secret, $count, $code = null,
		$digits = self::DIGITS, $digest = self::DIGEST)
	{
		if (strlen($secret) < 8)
		{
			throw new \InvalidArgumentException('Secret key is too short, must be at least 8 characters.');
		}

		if (is_null($digits))
		{
			$digits = self::DIGITS;
		}

		if (is_null($digest))
		{
			$digest = self::DIGEST;
		}

		if (!is_null($code))
		{
			return hash_equals(self::HOTP($secret, $count, null, $digits, $digest), (string) $code);
		}

		// Decodes the secret to binary
		$byteSecret = self::base32_decode($secret);

		// Counter must be 64-bit int
		$count = pack('N*', 0) . pack('N*', $count);

		// Creates the HMAC
		$hmac = hash_hmac($digest, $count, $byteSecret, true);

		// Extract the OTP from the SHA1 hash.
		$offset = ord($hmac[19]) & 0xf;

		$code = (ord($hmac[$offset+0]) & 0x7F) << 24 |
			(ord($hmac[$offset + 1]) & 0xFF) << 16 |
			(ord($hmac[$offset + 2]) & 0xFF) << 8 |
			(ord($hmac[$offset + 3]) & 0xFF);

		$pattern = sprintf('%%%02dd', $digits); // eg. %06d
		return (string) sprintf($pattern, ($code % pow(10, $digits)));
	}

	/**
	 * Time based One-time password (RFC 6238)
	 *
	 * Compatible with Google Authenticator
	 *
	 * Note that you should store codes that have already been used successfully,
	 * and reject any previously used code to protect against replay attacks.
	 *
	 * @param string $secret    Secret key
	 * @param integer $code     One-time code to check against, if NULL a new code will be returned
	 * @param integer $timestamp UNIX timestamp (in seconds) or NULL to use the system time
	 * @param integer $digits 	Number of digits in the generated code (default is 6)
	 * @param string $digest 	Digest algo to use (sha1, sha256 or sha512) default is sha1
	 * @param integer $interval Time interval to round the timestamp (default is 30 seconds)
	 * @param integer $drift    Number of intervals in the past and future to try
	 * This is useful for clients who use an invalid time to generate the OTP.
	 */
	static public function TOTP($secret, $code = null, $timestamp = null,
		$digits = self::DIGITS, $digest = self::DIGEST,
		$interval = self::INTERVAL, $drift = self::DRIFT)
	{
		if (is_null($timestamp))
		{
			$timestamp = time();
		}

		if (is_null($interval))
		{
			$interval = self::INTERVAL;
		}

		if (is_null($drift))
		{
			$drift = self::DRIFT;
		}

		// Time counter: timestamp divided by time interval
		$counter = floor($timestamp / $interval);

		// Check supplied code
		if (!is_null($code))
		{
			$check = hash_equals(self::HOTP($secret, $counter, null, $digits, $digest), (string) $code);

			if ($check || empty($drift))
			{
				return true;
			}

			// Will check previous and following codes, in case of time drift
			$start = $counter - $drift;
			$end = $counter + $drift;

			for ($i = $start; $i <= $end; $i++)
			{
				if (hash_equals(self::HOTP($secret, $i, null, $digits, $digest), (string) $code))
				{
					return true;
				}
			}

			return false;
		}

		return self::HOTP($secret, $counter, $code, $digits, $digest);
	}

	/**
	 * Returns a random secret for HOTP/TOTP
	 *
	 * @param integer $length Length of the secret key (default is 16 characters)
	 */
	static public function getRandomSecret($length = 16)
	{
		$keys = array_merge(range('A', 'Z'), range(2, 7));
		$string = '';

		for ($i = 0; $i < $length; $i++)
		{
			$rand = random_int(0, 31);
			$string .= $keys[$rand];
		}

		return $string;
	}

	/**
	 * Returns a valid otpauth:// URL from a secret
	 * Useful to generate QRcodes
	 *
	 * @link  https://github.com/google/google-authenticator/wiki/Key-Uri-Format URI format
	 *
	 * @param  string $label Service label, eg 'Blog:james@alice.com'
	 * @param  string $secret secret key
	 * @param  string $type 'totp' or 'hotp'
	 * @param  string $image HTTP URL to a PNG icon that should be displayed (supported by FreeOTP and Google Authenticator)
	 * @return string
	 */
	static public function getOTPAuthURL($label, $secret, $type = 'totp', $image = null)
	{
		$image = $image ? '&image=' . rawurlencode($image) : '';
		return 'otpauth://' . $type . '/' . rawurlencode($label) . '?secret=' . rawurlencode($secret) . $image;
	}

	/**
	 * Returns UNIX timestamp from a NTP server (RFC 5905)
	 *
	 * @param  string  $host    Server host (default is pool.ntp.org)
	 * @param  integer $timeout Timeout  in seconds (default is 5 seconds)
	 * @return integer Number of seconds since January 1st 1970
	 */
	static public function getTimeFromNTP($host = 'pool.ntp.org', $timeout = 5)
	{
		$socket = stream_socket_client('udp://' . $host . ':123', $errno, $errstr, (int)$timeout);
		stream_set_timeout($socket, $timeout);

		$msg = "\010" . str_repeat("\0", 47);
		fwrite($socket, $msg);

		$response = fread($socket, 48);
		fclose($socket);

		if (strlen($response) < 1)
		{
			return false;
		}

		// unpack to unsigned long
		$data = unpack('N12', $response);

		// 9 =  Receive Timestamp (rec): Time at the server when the request arrived
   		// from the client, in NTP timestamp format.
		$timestamp = sprintf('%u', $data[9]);

		// NTP = number of seconds since January 1st, 1900
		// Unix time = seconds since January 1st, 1970
		// remove 70 years in seconds to get unix timestamp from NTP time
		$timestamp -= 2208988800;

		return $timestamp;
	}

	/**
	 * Base32 decode compatible with RFC 3548
	 * @link https://codereview.stackexchange.com/questions/5236/base32-implementation-in-php
	 * @param  string $str Base32 encoded string
	 * @return string      Binary decoded string
	 */
	static public function base32_decode($str)
	{
		static $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

		// clean up string
		$str = rtrim(preg_replace('/[^A-Z2-7]/', '', strtoupper(trim($str))), '=');

		if ($str === '')
		{
			throw new \InvalidArgumentException('Invalid value in $str.');
		}

		$tmp = '';

		foreach (str_split($str) as $char)
		{
			if (false === ($v = strpos($alphabet, $char)))
			{
				$v = 0;
			}

			$tmp .= sprintf('%05b', $v);
		}

		$args = array_map('bindec', str_split($tmp, 8));

		array_unshift($args, 'C*');

		return rtrim(call_user_func_array('pack', $args), "\0");
	}

	/**
	 * Base32 encode compatible with RFC 3548
	 * @link https://github.com/pontago/php-encodeBase32/blob/master/encodeBase32.php
	 * @param  string  $str Binary string
	 * @param  boolean $pad Enable padding? if true will pad string to the nearest multiple of 8 length with '='
	 * @return string       Base32 encoded string
	 */
	static public function base32_encode($str, $pad = true)
	{
		static $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

		$buffer = unpack('C*', $str);

		$len = count($buffer);
		$n = 1;
		$buffer2 = '';

		while ($n <= $len)
		{
			$chr = $buffer[$n];
			$buffer2 .= sprintf('%08b', $chr);
			$n++;
		}

		$output = '';
		$len2 = strlen($buffer2);

		for ($i = 0; $i < $len2; $i += 5)
		{
			$chr = bindec(sprintf('%-05s', substr($buffer2, $i, 5)));
			$output .= $alphabet[$chr];
		}

		if ($pad)
		{
			$len3 = strlen($output);

			if ($len3 > 0)
			{
				$num = $len3 > 8 ? 8 - ($len3 % 8) : 8 - $len3;
				$output .= str_repeat('=', $num);
			}
		}

		return $output;
	}
}