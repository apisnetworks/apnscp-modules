<?php declare(strict_types=1);
	/**
	 *  +------------------------------------------------------------+
	 *  | apnscp                                                     |
	 *  +------------------------------------------------------------+
	 *  | Copyright (c) Apis Networks                                |
	 *  +------------------------------------------------------------+
	 *  | Licensed under Artistic License 2.0                        |
	 *  +------------------------------------------------------------+
	 *  | Author: Matt Saladna (msaladna@apisnetworks.com)           |
	 *  +------------------------------------------------------------+
	 */

	/**
	 *  File watch component
	 *
	 * @package core
	 */
	class Watch_Module extends Module_Skeleton
	{
		const CACHE_STORAGE_DURATION = 7200;
		const CACHE_PREFIX = 'watch.';

		public $exportedFunctions = ['*' => PRIVILEGE_SITE | PRIVILEGE_USER];

		/**
		 * Export a checkpoint
		 *
		 * @param string $id checkpoint ID to export @link checkpoint
		 * @return bool|string
		 */
		public function export($id)
		{
			$res = $this->fetch($id);
			if (!$id) {
				return error("export failed");
			}

			return base64_encode(serialize($res));
		}

		/**
		 * Retrieve stored checkpoint from cache
		 *
		 * @param string $id
		 * @return array
		 */
		public function fetch($id)
		{
			$cache = Cache_Account::spawn($this->getAuthContext());
			$map = $cache->get($this->_getWatchCachePrefix() . $id);
			if (!$map) {
				return array();
			}

			return $map;
		}

		private function _getWatchCachePrefix()
		{
			return self::CACHE_PREFIX;
		}

		/**
		 * Import a saved checkpoint
		 *
		 * @param string $data checkpoint data (@see export)
		 * @return bool
		 */
		public function import($data)
		{
			if (!preg_match('/^[a-zA-Z0-9\+\/=]*$/', $data)) {
				return error("data is not base64-encoded");
			}

			$data = \Util_PHP::unserialize(base64_decode($data));
			if (!$data) {
				return error("invalid data to import");
			}
			$hash = $this->_makeKeyFromResults($data);
			$key = $this->_getWatchCachePrefix() . $hash;
			$cache = Cache_Account::spawn($this->getAuthContext());
			if (!$cache->set($key, $data, self::CACHE_STORAGE_DURATION)) {
				return error("failed to import checkpoint data: (%d) %s",
					$cache->getResultCode(),
					$cache->getResultMessage()
				);
			}

			return $hash;
		}

		private function _makeKeyFromResults($results)
		{
			return base_convert($results['ts'] + $results['inode'], 10, 36);
		}

		/**
		 * Unattended file change calcuation
		 *
		 * @param        $path
		 * @param        $id1  initial reference token (@see watch)
		 * @param string $mode whether to lock or unlock changed files
		 * @return bool
		 *
		 */
		public function batch($path, $id1, $mode = 'unlock')
		{
			$id2 = $this->checkpoint($path);
			$diff = $this->compare($id1, $id2);
			if (!$diff) {
				return error("watch batch operation failed");
			}
			$report = $this->_generateChangeReport($path, $diff);
			$resp = $this->lockdown($path, $diff, $mode);
			$report .= "\r\nEnforcement results (" . $mode . " changed files): \r\n";
			if (!$resp) {
				$report .= "\tPartially succeeded. Error messages: \r\n" .
					var_export(Error_Reporter::flush_buffer(), true);
			} else {
				$report .= "\tSUCCESS!";
			}

			Mail::send(
				$this->common_get_admin_email(),
				'File Change Report (' . $this->domain . ')',
				$report
			);

			return $diff;
		}

		/**
		 * Make a filesystem checkpoint
		 *
		 * Note: this only works on publicly readable locations
		 *
		 * @param string $path path to checkpoint
		 * @return string checkpoint id
		 */
		public function checkpoint($path)
		{
			$fullpath = $this->file_make_shadow_path($path);
			if (!$fullpath) {
				return error("unknown or invalid path `%s' provided", $path);
			} else {
				if (!is_dir($fullpath)) {
					return error("path `%s' is inaccessible", $path);
				}
			}

			$ts = time();
			$inode = fileinode($fullpath);
			$struct = array(
				'ts'    => $ts,
				'path'  => $path,
				'inode' => $inode,
				'map'   => $this->_watch_generate($fullpath)
			);
			$key = $this->_makeKeyFromResults($struct);
			$key = $this->_getWatchCachePrefix() . $key;
			$cache = Cache_Account::spawn($this->getAuthContext());
			if (is_debug()) {
				$duration = null;
			} else {
				$duration = self::CACHE_STORAGE_DURATION;
			}
			if (!$cache->set($key, $struct, $duration)) {
				return error("failed to save watch data: (%d) %s",
					$cache->getResultCode(),
					$cache->getResultMessage()
				);
			}

			return substr($key, strlen($this->_getWatchCachePrefix()));
		}

		/**
		 *
		 * @param string $path resolved shadow path
		 * @return array
		 */
		private function _watch_generate($path): array
		{
			if (!is_readable($path)) {
				error("path `%s' is not readable by other", $this->file_unmake_shadow_path($path));

				return array();
			}
			$dh = opendir($path);
			if (!$dh) {
				return array();
			}
			while (false !== ($file = readdir($dh))) {
				if ($file === "..") {
					continue;
				}
				$filepath = $path . '/' . $file;
				$size = filesize($filepath);
				$mtime = filemtime($filepath);
				$ctime = filectime($filepath);
				if ($file !== "." && is_dir($filepath)) {
					$arr[$file] = $this->_watch_generate($filepath);
				} else {
					$arr[$file] = array(
						'size'  => $size,
						'mtime' => $mtime,
						'ctime' => $ctime
					);
				}
			}
			closedir($dh);

			return $arr;

		}

		/**
		 * Compare checkpoints for changes
		 *
		 * @param string $id1 initial checkpoint
		 * @param string $id2 comparison checkpoint
		 * @return array|bool differences or false on failure
		 */
		public function compare($id1, $id2)
		{
			$cache = Cache_Account::spawn($this->getAuthContext());
			$res1 = $cache->get($this->_getWatchCachePrefix() . $id1);
			if (false === $res1) {
				return error("invalid or expired watch key, `%s'", $id1);
			}

			$res2 = $cache->get($this->_getWatchCachePrefix() . $id2);
			if (false === $res2) {
				return error("invalid or expired watch key, `%s'", $id2);
			}
			if ($res1['path'] != $res2['path']) {
				return error("path `%s' does not match path `%s'",
					$res1['path'],
					$res2['path']
				);
			} else if ($res1['inode'] != $res2['inode']) {
				warn("inode mismatch on `%s' but path same, irregular results possible", $res1['path']);
			}
			if ($res1['ts'] > $res2['ts']) {
				warn("tokens passed in reverse order - items shown are original values");
			}
			// files that have changed
			$changed = Util_PHP::array_diff_assoc_recursive($res2['map'], $res1['map']);

			return $changed;
		}

		private function _generateChangeReport($path, $files)
		{
			$files = $this->_collapseChanges($path, $files);
			$msg = "Hello, " . "\r\n" .
				"The following paths were noted as changed: " . "\r\n\r\n";
			foreach ($files as $file => $modes) {
				$msg .= "\t" . $file . ": " . join(", ", array_keys($modes)) . "\r\n";
			}

			return $msg;

		}

		private function _collapseChanges($path, $files)
		{
			$p = $path;
			$changed = array();
			foreach ($files as $f => $l) {
				if (is_array($l)) {
					$changed = array_merge($changed, $this->_collapseChanges($p . DIRECTORY_SEPARATOR . $f, $l));
				} else {
					$changed[$p][$f] = $l;
				}

			}

			return $changed;
		}

		/**
		 * Change ownership to active user + open up only to $diff files
		 *
		 * @param string $path
		 * @param array  $diff calculated diff @see compare()
		 * @param string $mode lock or unlock, how to handle changed files
		 * @return bool
		 */
		public function lockdown($path, $diff, $mode = 'unlock')
		{
			if (!IS_CLI) {
				return $this->query('watch_lockdown', $path, $diff);
			}

			if (!$this->file_exists($path)) {
				return error("path `%s' does not exist", $path);
			}
			$stat = $this->file_stat($path);
			$uid = $stat['uid'];
			if ($stat['uid'] < User_Module::MIN_UID) {
				return error("uid of `%s' is a system uid `%d'", $path, $stat['uid']);
			} else if (($this->permission_level & PRIVILEGE_USER) && $uid !== $this->user_id) {
				return error("cannot lockdown docroots unowned by this user");
			}
			$username = $this->user_get_username_from_uid($uid);
			$proposed = $this->_collapseChanges($path, $diff);
			// files and directories to adjust
			$adjfiles = array();
			$adjdirs = array();
			foreach ($proposed as $f => $meta) {
				if (isset($meta['size'])) {
					// file grew
					$adjfiles[$f] = true;
				} else if (isset($meta['ctime'])) {
					// file created
					$dir = dirname($f);
					$adjdirs[$dir] = true;
				} else if (substr($f, -1) === ".") {
					// mtime
					// file removed or added
					$adjdirs[dirname($f)] = true;
				} else {
					// file modified in place
					$adjfiles[$f] = true;
				}
			}
			$filtered = array_filter(
				array_merge(array_keys($adjdirs), array_keys($adjfiles)),
				function ($d) use ($path) {
					return 0 === strpos($d, $path);
				}
			);

			if ($mode === 'lock') {
				$this->file_chown($filtered, $username);

				return $this->file_set_acls($filtered, null);
			}

			// unlocked
			$this->file_chown($path, $username, true);
			if (!$this->file_set_acls($path, null,
				array(File_Module::ACL_MODE_RECURSIVE))
			) {
				warn("failed to release apache acls on `%s'", $path);
			}
			// make sure apache-created files are turned over to the account
			$prefix = $this->domain_shadow_path();
			foreach ($filtered as $f) {
				$f = $prefix . $f;
				if (file_exists($f) && filegroup($f) === APACHE_GID) {
					chgrp($f, $this->group_id);
				}
			}

			$filteredFiles = array_filter($filtered,
				function ($f) {
					return substr($f, -2) !== '/.';
				});
			$filteredDirs = array_diff($filtered, $filteredFiles);
			$users = array(
				['apache' => 7],
				[$username => 7],
			);
			$ret = $this->file_set_acls($filteredFiles, $users);
			if ($ret && $filteredDirs) {
				// setfacl yelps if [d]efault flag applied and file is not a directory
				// "Only directories can have default ACLs" is translated, so two 2 rounds
				$users = array_merge($users, [
					['apache' => 'drwx'],
					[$username => 'drwx']
				]);
				$ret &= $this->file_set_acls($filteredDirs, $users);
			}

			return $ret;

		}
	}