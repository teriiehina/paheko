<?php

namespace KD2;

use KD2\HTTP;
use KD2\Security;

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
			foreach ($this->ignored_paths as $ignored_path) {
				if (0 === strpos($file, $ignored_path)) {
					continue(2);
				}
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

			$is_ignored = false;

			// Skip ignored paths
			foreach ($this->ignored_paths as $ignored_path) {
				if (0 === strpos($relative_path, $ignored_path)) {
					$is_ignored = true;
					break;
				}
			}

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
