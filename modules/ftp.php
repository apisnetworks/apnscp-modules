<?php
	declare(strict_types=1);
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
	 * Provides common functionality associated with vsFTPd
	 *
	 * @package core
	 */
	class Ftp_Module extends Module_Skeleton implements \Opcenter\Contracts\Hookable
	{
		const DEPENDENCY_MAP = [
			'siteinfo',
			'users'
		];

		/**
		 * {{{ void __construct(void)
		 *
		 * @ignore
		 */
		const VSFTPD_CONF_DIR = '/etc/vsftpd';
		const VSFTPD_CHROOT_FILE = '/etc/vsftpd.chroot_list';
		const PAM_SVC_NAME = 'ftp';

		public function __construct()
		{
			parent::__construct();
			$this->exportedFunctions = array(
				'*' => PRIVILEGE_SITE
			);
		}

		public function jail_user($user, $dir = '')
		{
			if (!IS_CLI) {
				return $this->query('ftp_jail_user', $user, $dir);
			}
			if (!$this->user_exists($user)) {
				return error("user " . $user . " does not exist");
			}

			$chroot_file = $this->domain_fs_path() . self::VSFTPD_CHROOT_FILE;
			$chroot_users = array();
			if (file_exists($chroot_file)) {
				$chroot_users = preg_split("/[\r\n]+/", trim(file_get_contents($chroot_file)));
			}

			if (in_array($user, $chroot_users)) {
				if (!$dir) {
					return warn("user " . $user . " already jailed");
				}
			} else {
				$chroot_users[] = $user;
			}

			file_put_contents($this->domain_fs_path(self::VSFTPD_CHROOT_FILE),
				join("\n", $chroot_users) . "\n");
			if ($dir) {
				if (!$this->file_exists($dir)) {
					$this->file_create_directory($dir, 0755, true);
				} else {
					$stat = $this->file_stat($dir);
					if ($stat['link']) {
						info("target is symlink, converted jailed path `%s' to `%s'",
							$dir,
							$stat['referent']
						);
						$dir = $stat['referent'];
					}
				}
				$this->file_chown($dir, $user) && $this->set_option($user, 'local_root', $dir);
			}

			return true;
		}

		public function set_option($user, $c_directive, $c_val = null)
		{
			if (!IS_CLI) {
				return $this->query('ftp_set_option', $user, $c_directive, $c_val);
			}

			if (!$this->user_exists($user)) {
				return error("user " . $user . " does not exist");
			}

			return $this->_set_option_real($user, $c_directive, $c_val);
		}

		protected function _set_option_real($user, $c_directive, $c_val = null)
		{
			$user_conf = self::VSFTPD_CONF_DIR . '/' . $user;

			if (!file_exists($this->domain_fs_path() . $user_conf) &&
				($status = file_put_contents($this->domain_fs_path(self::VSFTPD_CONF_DIR . '/' . $user), "") === false)
			) {
				return $status;
			}

			$fp = fopen($this->domain_fs_path() . $user_conf, "r");
			if (!$fp) {
				return error(self::VSFTPD_CONF_DIR . '/' . $user . ": cannot access file");
			}
			$new = true;
			for ($buffer = array(); !feof($fp);) {
				$line = trim((string)fgets($fp));
				if (!$line) {
					continue;
				}

				if (false !== strpos($line, '=')) {
					list($lval, $rval) = explode("=", $line, 2);
				} else {
					$rval = '';
					$lval = $line;
				}

				if ($lval == $c_directive) {
					$new = false; // value already set
					if (!$c_val) {
						continue;
					}
					$rval = $c_val;
				}
				$buffer[] = $lval . ($rval ? '=' . $rval : '');
			}
			if ($new) {
				$buffer[] = $c_directive . ($c_val ? '=' . $c_val : '');
			}
			$path = $this->domain_fs_path() . $user_conf;
			file_put_contents($path, join("\n", $buffer) . "\n");
			// make sure configuration is owned by root on v6+ platforms
			// no more custom patches
			chown($path, 'root');

			return true;
		}

		public function deny_user($user)
		{
			return (new Util_Pam($this->getAuthContext()))->remove($user, $this->getPamServiceName());
		}

		/**
		 * Wrapper for backwards compatibility during dev
		 *
		 * @todo yank before 3.0.0 release
		 *
		 * @return string
		 */
		protected function getPamServiceName(): string
		{
			//@xxx temporary backwards compatibility
			if (version_compare(platform_version(), '7.5', '<')) {
				return 'proftpd';
			}

			return static::PAM_SVC_NAME;
		}

		public function permit_user($user)
		{
			if ($this->auth_is_demo()) {
				return error("FTP disabled for demo account");
			}

			return (new Util_Pam($this->getAuthContext()))->add($user, $this->getPamServiceName());
		}

		public function _edit_user(string $user, string $usernew, array $pwd)
		{
			if ($user === $usernew) {
				return;
			}
			if (!$this->user_enabled($user)) {
				return true;
			}
			(new Util_Pam($this->getAuthContext()))->remove($user, $this->getPamServiceName());
			(new Util_Pam($this->getAuthContext()))->add($usernew, $this->getPamServiceName());

			$home = $pwd['home'];
			if ($this->_user_jailed_real($user)) {
				$jailhome = null;
				if ($this->has_configuration($user)) {
					$jailhome = $this->get_option($user, 'local_root');
					if (!strncmp($jailhome, $home, strlen($home))) {
						$newhome = preg_replace('!' . DIRECTORY_SEPARATOR . $user . '!',
							DIRECTORY_SEPARATOR . $usernew, $jailhome, 1);
						$this->set_option($user, 'local_root', $newhome);
						$jailhome = $newhome;
					}
				}
				$jailconf = $this->domain_fs_path() . '/' . self::VSFTPD_CHROOT_FILE;
				$conf = file_get_contents($jailconf);
				$conf = preg_replace('/^' . $user . '$/m', $usernew, $conf);
				file_put_contents($jailconf, $conf);
			}
			if ($this->has_configuration($user)) {
				$ftpconfdir = $this->domain_fs_path() . self::VSFTPD_CONF_DIR;
				if (file_exists($ftpconfdir . '/' . $user)) {
					rename($ftpconfdir . '/' . $user, $ftpconfdir . '/' . $usernew);
				}
			}

			return true;
		}

		public function user_enabled($user)
		{
			return (new Util_Pam($this->getAuthContext()))->check($user, $this->getPamServiceName());
		}

		protected function _user_jailed_real($user)
		{
			$chroot_file = $this->domain_fs_path() . self::VSFTPD_CHROOT_FILE;
			if (!file_exists($chroot_file)) {
				return false;
			}

			return (bool)preg_match('/\b' . $user . '\b/', file_get_contents($chroot_file));
		}

		public function has_configuration($user)
		{
			$path = $this->domain_fs_path() . self::VSFTPD_CONF_DIR . '/' . $user;

			return file_exists($path);
		}

		public function get_option($user, $c_directive)
		{
			if (!IS_CLI) {
				return $this->query('ftp_get_option', $user, $c_directive);
			}

			if (!$this->user_exists($user)) {
				return error("user " . $user . " does not exist");
			}

			return $this->_get_option_real($user, $c_directive);
		}

		protected function _get_option_real($user, $c_directive)
		{
			$conf_file = $this->domain_fs_path() . self::VSFTPD_CONF_DIR . '/' . $user;
			if (!file_exists($conf_file)) {
				warn("no configuration set for user " . $user);

				return null;
			}
			$user_conf = file_get_contents($conf_file);
			$conf_val = null;

			if (!preg_match('/^\b' . preg_quote($c_directive) . '(?:\s*=\s*)(.+)$/m', $user_conf, $conf_val)) {
				return null;
			}

			$conf_val = $conf_val[1];

			return $conf_val;
		}

		public function _reload($what = null)
		{
			if ($what === "letsencrypt") {
				Util_Process::exec('/sbin/service vsftpd restart');
			}

			return true;
		}

		public function _delete_user(string $user)
		{
			if ($this->user_jailed($user)) {
				$this->unjail_user($user);
			}
			$ftp_conf = join(DIRECTORY_SEPARATOR,
				array(
					$this->domain_fs_path(),
					self::VSFTPD_CONF_DIR,
					$user
				)
			);
			if (file_exists($ftp_conf)) {
				unlink($ftp_conf);
			}

			return true;
		}

		public function user_jailed($user)
		{
			if (!$this->user_exists($user)) {
				return error("user " . $user . " does not exist");
			}

			return $this->_user_jailed_real($user);
		}

		public function unjail_user($user)
		{
			if (!IS_CLI) {
				return $this->query('ftp_unjail_user', $user);
			}
			if (!$this->user_exists($user)) {
				return error("user " . $user . " does not exist");
			}
			if (!file_exists($this->domain_fs_path() . self::VSFTPD_CHROOT_FILE)) {
				return warn("chroot file " . self::VSFTPD_CHROOT_FILE . " not found");
			}
			$buffer = '';
			$fp = fopen($this->domain_fs_path() . self::VSFTPD_CHROOT_FILE, 'r');

			for ($buffer = array(), $seen = false; !feof($fp);) {
				$line = trim((string)fgets($fp));
				if (!$line) {
					continue;
				} else if ($user === $line) {
					$seen = true;
					continue;
				}
				$buffer[] = $line;
			}
			fclose($fp);

			if (!$seen) {
				warn("user `%s' not found in jail conf", $user);
			}
			$prefix = $this->domain_fs_path();
			$path = $prefix . self::VSFTPD_CHROOT_FILE;
			$size = file_put_contents($path, join("\n", $buffer) . "\n", LOCK_EX);

			return $size !== false;
		}

		public function _create()
		{
			// stupid thor...
			$conf = $this->getAuthContext()->getAccount()->new;
			$admin = $conf['siteinfo']['admin_user'];
			$pam = new Util_Pam($this->getAuthContext());
			if ($this->auth_is_demo() && $pam->check($admin, $this->getPamServiceName())) {
				$pam->remove($admin, $this->getPamServiceName());
			}
		}

		public function _verify_conf(\Opcenter\Service\ConfigurationContext $ctx): bool
		{
			return true;
		}

		public function _delete()
		{

		}

		public function _edit()
		{
		}

		public function _create_user(string $user)
		{
		}

	}