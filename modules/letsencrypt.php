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
	 * Let's Encrypt integration utilities
	 *
	 * @author Matt Saladna <matt@apisnetworks.com>
	 */
	class Letsencrypt_Module extends Module_Support_Letsencrypt
	{
		const DEPENDENCY_MAP = [
			'ssl'
		];
		// production
		const LETSENCRYPT_SERVER = 'acme-v01.api.letsencrypt.org/directory';
		// staging
		const LETSENCRYPT_TESTING_SERVER = 'acme-staging.api.letsencrypt.org/directory';
		protected const LE_AUTHORITY_FINGERPRINT = LETSENCRYPT_KEYID;
		// include complementary hostname variant, e.g. foo.com + www.foo.com or www.foo.com + foo.com
		const INCLUDE_ALT_FORM = LETSENCRYPT_ALTERNATIVE_FORM;

		protected $activeServer;

		/**
		 * {{{ void __construct(void)
		 *
		 * @ignore
		 */

		public function __construct()
		{
			parent::__construct();

			if (defined('LETSENCRYPT_DEBUG') && constant('LETSENCRYPT_DEBUG')) {
				$this->activeServer = self::LETSENCRYPT_TESTING_SERVER;
			} else {
				$this->activeServer = self::LETSENCRYPT_SERVER;
			}

			if ($this->supported()) {
				$fns = array(
					'*' => PRIVILEGE_SITE,
				);
			} else {
				$fns = array(
					'supported' => PRIVILEGE_SITE,
					'permitted' => PRIVILEGE_SITE,
					'*'         => PRIVILEGE_NONE
				);
			}
			$this->exportedFunctions = $fns;
		}

		/**
		 * Let's Encrypt is supported on this platform
		 *
		 * @return bool
		 */
		public function supported()
		{
			return version_compare((string)platform_version(), '5', '>=');
		}

		/**
		 * Client may generate a LE certificate
		 *
		 * @return bool
		 */
		public function permitted()
		{
			return $this->supported() && $this->ssl_permitted();
		}

		public function renew(bool $verifyip = true)
		{
			if ($this->auth_is_inactive()) {
				return error("account `%s' is inactive - not renewing SSL", $this->domain);
			}
			$cert = $this->ssl_get_certificates();
			if (!$cert) {
				return error("no certificates installed on account");
			}
			// get_certificates() returns array of certs
			$cert = array_pop($cert);
			$cert = $this->ssl_get_certificate($cert['crt']);
			$crt = $this->ssl_parse_certificate($cert);
			if (!$this->is_ca($crt)) {
				return warn("certificate for `%s' is not provided by LE", $this->domain);
			}
			// LE certificates will always contain the CN in its SAN list
			// not sure if true for all certificates...
			$cns = $this->ssl_get_alternative_names($crt);
			$ret = $this->request($cns, $verifyip);
			if (null === $ret) {
				// case in which a request is processed OK, but
				// there are no valid hostnames on the account to renew
				return warn("request failed, lack of valid hostnames to renew");
			}
			if (!$ret) {
				Mail::send(
					Crm_Module::COPY_ADMIN,
					"renewal failed",
					SERVER_NAME_SHORT . ': ' . $this->site . "\r\n\r\n" .
					var_export($cns, true) . "\r\n\r\n" .
					"Run 'cmd -d " . $this->site . " letsencrypt_renew' from cli"
				);

				return error("failed to renew certificate");
			}

			return info("successfully renewed certificate for 90 days");
		}

		/**
		 * Certificate is generated by LE
		 *
		 * @param string $crt certificate data
		 * @return bool
		 */
		public function is_ca($crt)
		{
			$cert = $this->ssl_parse_certificate($crt);
			if (!$cert) {
				return error("invalid ssl certificate");
			}

			if (!isset($cert['extensions']) ||
				!isset($cert['extensions']['authorityKeyIdentifier'])
			) {
				return false;
			}
			$authority = $cert['extensions']['authorityKeyIdentifier'];
			$prefix = 'keyid:';
			if (!strncmp($authority, $prefix, strlen($prefix))) {
				$authority = substr($authority, strlen($prefix));
			}
			$authority = trim($authority);

			return $authority === self::LE_AUTHORITY_FINGERPRINT;
		}

		/**
		 * Request a Let's Encrypt certificate for the given common names
		 *
		 * Because there is no unreasonable limit on SANs, a www and non-www
		 * variant for each CN will be generated
		 *
		 * @param array|string $cnames   list of hosts
		 * @param string       $email    email address to use
		 * @param bool         $verifyip verify IP matches account before issuing
		 * @return bool
		 */
		public function request($cnames, bool $verifyip = true)
		{
			if (!IS_CLI) {
				return $this->query('letsencrypt_request', $cnames, $verifyip);
			}

			$cnreq = array();
			$myip = $this->common_get_ip_address();
			foreach ((array)$cnames as $c) {
				if (!is_array($c)) {
					$c = $this->web_split_host($c);
				}
				$domain = $c['domain'];
				$subdomain = $c['subdomain'];

				if (!$this->web_domain_exists($domain)) {
					error("cannot register lets encrypt: domain `%s' not a valid domain on this account",
						$domain);
					continue;
				}

				$host = ltrim($subdomain . '.' . $domain, '.');
				if (!preg_match(Regex::HTTP_HOST, $host)) {
					error("invalid server name `%s' specified", $c);
					continue;
				}

				if (LETSENCRYPT_VERIFY_IP && $verifyip && self::INCLUDE_ALT_FORM) {
					$altform = null;
					if (0 === strpos($host, 'www.')) {
						// add www.example.com if example.com given
						$altform = 'www.' . $host;
					} else {
						// add example.com if www.example.com given
						$altform = substr($host, 4);
					}
					if ($this->_verifyIP($altform, $myip)) {
						$cnreq[] = $altform;
					} else {
						info("skipping alternative hostname form `%s', IP does not resolve to `%s'",
							$altform, $myip);
					}
				}

				if (LETSENCRYPT_VERIFY_IP && $verifyip && !$this->_verifyIP($host, $myip)) {
					warn("hostname `%s' IP `%s' doesn't match hosting IP `%s', "
						. "skipping request",
						$host,
						$this->dns_gethostbyname_t($host, 1500),
						$myip
					);
					continue;
				}
				$cnreq[] = $host;
			}
			if (!$cnreq) {
				error("no hostnames to register");

				return null;
			}
			if (!$this->requestReal($cnreq, $this->site)) {
				return false;
			}
			info("reminder: only 5 certificates may be issued per week");

			return $this->_moveCertificates($this->site);
		}

		/**
		 * Invalidate issued certificate
		 *
		 * @return bool
		 */
		public function revoke()
		{
			if (!IS_CLI) {
				return $this->query('letsencrypt_revoke');
			}

			if (!$this->certificateIssued()) {
				return error("no certificate issued to revoke");
			}

			$ret = $this->_exec('revoke', array(
				'--name'    => $this->site,
				'-s'        => $this->activeServer,
				'--storage' => $this->acmeDataDirectory()
			));

			if (!$ret) {
				return error("revocation failed");
			}
			$this->_deleteAcmeCertificate($this->site);

			return true;
		}

		public function exists()
		{
			$path = parent::acmeSiteStorageDirectory($this->site);

			return file_exists($path);
		}

		/**
		 * Retrieve absolute storage path for site certificate
		 *
		 * @param string $site
		 * @return string
		 */
		public function storage_path(string $site): string
		{
			return $this->acmeSiteStorageDirectory($site);
		}

		protected function _exec($cmd, array $args)
		{
			static $tries = 0;

			if (version_compare(platform_version(), '6.5', '>=')) {
				$php = 'php';
			} else {
				// Sol, et al
				$php = 'php7';
			}
			$acmeClient = $php . ' ' . $this->getAcmeClientDirectory() . '/bin/acme %s';
			$newargs = array();
			foreach ($args as $k => $v) {
				if ($k[0] == '-') {
					$newargs[] = $k;
					$acmeClient .= ' %s';
				}
				$newargs[] = $v;

				$acmeClient .= ' %s';

			}
			array_unshift($newargs, $cmd);
			$ret = Util_Process_Safe::exec($acmeClient, $newargs);
			if ($ret['success']) {
				$tries = 0;

				return true;
			}
			debug("LE output: (stdout) %s\n(stderr) %s", $ret['stdout'], $ret['stderr']);

			$tries++;

			$matches = array();
			$fn = null;
			preg_match_all(Regex::LETSENCRYPT_ACME_CMD_MESSAGE, coalesce($ret['stdout'], $ret['stderr']), $matches,
				PREG_SET_ORDER);

			if (!count($matches)) {
				return $ret['success'] ?:
					error("letsencrypt failed, exit code: %d, stdout: %s, stderr: %s",
						$ret['return'], $ret['stdout'], $ret['stderr']
					);
			}

			foreach ($matches as $m) {
				$cls = $m['cls'];
				$msg = $m['msg'];
				switch (strtolower($cls)) {
					case 'info':
					case 'debug':
						$fn = null;
						break;
					case 'error':
						// request failed the first time, retry
						if (false !== strpos($msg, "invalid anti-replay nonce")) {
							if ($tries < 3) {
								sleep(1);

								return $this->_exec($cmd, $args);
							}
							error("attempted to renew 3 times, but got nonce error!");
						}
						$fn = 'error';
						$ret['success'] = false;
						break;
					default:
						$fn = 'warn';
						Error_Reporter::report("unknown acme output - $cls", var_export($ret, true));
						break;
				}
				if ($fn) {
					$fn($msg);
				}
			}

			return $ret['success'];
		}

		private function _deleteAcmeCertificate($account)
		{
			$acmeDir = $this->acmeSiteStorageDirectory($account);
			if (!file_exists($acmeDir)) {
				return;
			}
			$dir = opendir($acmeDir);
			while (false !== ($f = readdir($dir))) {
				if ($f === ".." || $f === ".") {
					continue;
				}
				unlink($acmeDir . '/' . $f);

			}
			closedir($dir);
			rmdir($acmeDir);

			return;

		}

		/**
		 * Verify hostname matches IP
		 *
		 * LE will fail issuance if request fails,
		 * verify the challenge points to this server
		 *
		 * @param $hostname
		 * @param $myip
		 * @return bool
		 */
		private function _verifyIP($hostname, $myip)
		{
			$ip = $this->dns_gethostbyname_t($hostname, 1500);

			return $ip && $ip == $myip;
		}

		private function _moveCertificates($site)
		{
			$files = $this->getCertificateComponentData($site);
			if (!$files) {
				return false;
			}

			return $this->ssl_install($files['key'], $files['crt'], $files['chain']);
		}

		public function _housekeeping()
		{
			// Let's Encrypt supported on Luna + Sol only
			if (!$this->supported()) {
				return;
			}
			if (!$this->_registered() && !$this->_register()) {
				return error("failed to register with Let's Encrypt");
			}
			if (!$this->certificateIssued(self::SYSCERT_NAME)) {
				// make a dummy cert that has already expired to bootstrap
				$cns = array(SERVER_NAME);
				if (defined('LETSENCRYPT_ADDITIONAL_CERTS')) {
					$cns = array_merge($cns, preg_split('/[, ]+/', constant('LETSENCRYPT_ADDITIONAL_CERTS')));
				}
				if ($this->requestReal($cns, self::SYSCERT_NAME)) {
					$this->installSystemCertificate();
				}
			} else if (!is_debug()) {
				$this->renewExpiringCertificates();
			}
		}

		private function _register($email = null)
		{
			$acctdir = $this->acmeDataDirectory();
			if (!file_exists($acctdir)) {
				mkdir($acctdir, 0700, true);
			}

			if (!$email) {
				$email = $this->admin_get_email() ?? \Crm_Module::FROM_ADDRESS;
			}

			$ret = $this->_exec('setup',
				['-s' => $this->activeServer, '--email' => $email, '--storage' => $acctdir]);
			if (!$ret) {
				return error("Let's Encrypt registration failed");
			}

			return true;
		}

		private function _registered()
		{
			$key = $this->canonicalizeServer($this->activeServer) . '.pem';
			$storageDir = $this->acmeDataDirectory();

			return file_exists($storageDir . '/accounts/' . $key);
		}

		public function _edit()
		{
			$conf_new = $this->getAuthContext()->getAccount()->new;
			$conf_cur = $this->getAuthContext()->getAccount()->old;
			$ssl = \Opcenter\SiteConfiguration::getModuleRemap('openssl');
			if (!$conf_new[$ssl]['enabled']) {
				$this->_delete();
			}
		}

		public function _delete()
		{
			$this->_deleteAcmeCertificate($this->site);
		}
	}