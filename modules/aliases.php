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

	use Module\Support\Aliases;

	/**
	 * Aliases and shared domains
	 *
	 * @package core
	 */
	class Aliases_Module extends Aliases
	{
		const DEPENDENCY_MAP = [
			'siteinfo',
			'apache',
			'users', // addon domain ownership
		];

		/** addon domain dns verification record */
		const DNS_VERIFICATION_RECORD = 'newacct';

		/**
		 * void __construct(void)
		 *
		 * @ignore
		 */
		public function __construct()
		{
			$this->exportedFunctions = array(
				'*'                  => PRIVILEGE_SITE,
				'add_domain_backend' => PRIVILEGE_SERVER_EXEC | PRIVILEGE_SITE,
				'map_domain'         => PRIVILEGE_SERVER_EXEC,
			);
			parent::__construct();
		}

		/**
		 * Post-verification add_domain()
		 *
		 * @param string $domain
		 * @param string $path
		 * @return bool
		 */
		public function add_domain_backend($domain, $path)
		{
			$parent = dirname($path);

			if (!file_exists($this->domain_fs_path() . $parent)) {
				warn("%s: parent directory does not exist", $parent);
				if (!$this->file_create_directory($parent, 0755, true)) {
					return error("failed to create parent directory");
				}
			}
			if (!$this->createDocumentRoot($path)) {
				return error("failed to create document root `%s'", $path);
			}
			$stat = $this->file_stat($path);
			$user = null;
			if (isset($stat['owner'])) {
				$user = $stat['owner'];
				if (ctype_digit($user)) {
					warn("no such user found for domain `%s' uid `%d'", $domain, $user);
					$user = null;
				}
			} else {
				Error_Reporter::report("Bad stat response: " . var_export($stat, true));
			}

			if (!$user && $stat['uid'] < \User_Module::MIN_UID) {
				return error("unable to determine ownership of docroot `%s' for `%s'",
					$path, $domain);
			} else if (!$user) {
				warn("invalid uid `%d' detected on `%s', squashed to account uid `%d'",
					$stat['uid'],
					$domain,
					$this->user_id
				);
				$this->file_chown($path, $this->user_id, true);
				$user = $this->user_id;
			}

			$ret = $this->add_alias($domain);
			if (!$ret) {
				file_exists($path) && unlink($path);

				return error("failed to add domain alias configuration `%s'", $domain);
			}

			$this->notify_admin($domain, $path);

			if (!$this->map_domain('add', $domain, $path, $user)) {
				return error("failed to map domain `%s' in http configuration", $domain);
			}
			$ip = $this->dns_get_public_ip();
			$this->removeBypass($domain);

			return $this->dns_add_zone_backend($domain, $ip);
		}

		/**
		 * Add hostname to account configuration
		 *
		 * add_alias() implies that prereq checks have been made,
		 * including duplication checks
		 *
		 * @param string $alias
		 * @return bool
		 */
		protected function add_alias($alias)
		{
			if (!IS_CLI) {
				return error(__METHOD__ . ' should be called from backend');
			}

			$alias = strtolower($alias);
			if (!preg_match(Regex::DOMAIN, $alias)) {
				return error($alias . ": invalid domain");
			}

			$aliases = (array)$this->getServiceValue('aliases', 'aliases');
			$aliases[] = $alias;
			$limit = $this->getServiceValue('aliases', 'max', null);
			if (null !== $limit && count($aliases) + 1 > $limit) {
				return error("account has reached max amount of addon domains, `%d'", $limit);
			}

			return $this->setConfigJournal('aliases', 'enabled', 1) &&
				$this->setConfigJournal('aliases', 'aliases', $aliases);
		}

		/**
		 * Notify appliance admin domain has been added
		 *
		 * @param string $domain
		 * @param string $path
		 * @return bool
		 */
		protected function notify_admin(string $domain, string $path): bool
		{
			if (!DOMAINS_NOTIFY) {
				return false;
			}

			$template = \BladeLite::factory('views/email');
			$html = $template->make('aliases.domain-add',
				[
					'domain'     => $domain,
					'path'       => $path,
					'authdomain' => $this->domain,
					'authuser'   => $this->username,
					'siteid'     => $this->site_id,
				]
			)->render();

			$opts = array(
				'html_charset' => 'utf-8',
				'text_charset' => 'utf-8'
			);
			$from = \Crm_Module::FROM_NAME . ' <' . \Crm_Module::FROM_ADDRESS . '>';
			$headers = array(
				'Sender' => $from,
				'From'   => $from
			);
			$mime = new Mail_Mime($opts);

			$mime->setHTMLBody($html);
			$mime->setTXTBody(strip_tags($html));
			$headers = $mime->txtHeaders($headers);
			$msg = $mime->get();

			return Mail::send(
				\Crm_Module::COPY_ADMIN,
				"Domain Added",
				$msg,
				$headers
			);

		}

		/**
		 * Manage domain symlink mapping
		 *
		 * @todo   merge into web module
		 *
		 * @param  string $mode   add/delete
		 * @param  string $domain domain to add/remove
		 * @param  string $path   domain path
		 * @param  string $user   user to assign mapping
		 * @return bool
		 */
		public function map_domain($mode, $domain, $path = null, $user = null)
		{
			if (!IS_CLI) {
				return $this->query('aliases_map_domain',
					$mode,
					$domain,
					$path,
					$user);
			}

			$mode = substr($mode, 0, 3);
			if (!preg_match(Regex::DOMAIN, $domain)) {
				return error($domain . ": invalid domain");
			}
			if ($mode != 'add' && $mode != 'del') {
				return error($mode . ": invalid map operation");
			}
			if ($mode == 'del') {
				return $this->removeMap($domain) &&
					$this->file_delete('/home/*/all_domains/' . $domain);
			} else {
				if ($mode == 'add') {
					if (!$user) {
						$stat = $this->file_stat($path);
						if ($stat instanceof Exception) {
							return $stat;
						}

						$user = $this->user_get_username_from_uid($stat['uid']);
					}
					if ($user) {
						if ($user == $this->tomcat_system_user()) {
							$user = $this->username;
							$uid = $this->user_get_uid_from_username($user);
						} else {
							$uid = $this->user_get_uid_from_username($user);
							if ($uid < User_Module::MIN_UID) {
								$user = $this->username;
							}
						}

						$user_home = '/home/' . $user;
						$user_home_abs = $this->domain_fs_path() . $user_home;

						if (!file_exists($this->domain_fs_path() . $path)) {
							warn($path . ": path does not exist, creating link");
						}
						if (!file_exists($user_home_abs . '/all_domains')) {
							$this->file_create_directory($user_home . '/all_domains');
							$this->file_chown($user_home . '/all_domains', $user);
						}
						// remove symlink if domain previously added
						$fullpath = $this->domain_fs_path() . $user_home . '/all_domains/' . $domain;
						// sometimes clients do dumb things, like remove the symlink and recreate
						// as an empty directory
						clearstatcache(true, $fullpath);
						if (is_link($fullpath)) {
							unlink($fullpath);
						} else {
							if (is_dir($fullpath)) {
								Error_Reporter::mute_warning(true);
								if (!rmdir($fullpath)) {
									warn("not creating symlink all_domains/%s; a directory was found within " .
										"that contains files", $domain);
								}
								Error_Reporter::unmute_warning();
							}
						}
						// and sometimes clients can do really dumb things like
						// assign a doc root under all_domains/
						$localpath = $user_home . '/all_domains/' . $domain;
						if (!file_exists($fullpath)) {
							$this->file_symlink($path, $localpath);
						} else {
							warn("cannot make symlink %s - file exists, possibly misplaced docroot?",
								$localpath
							);
						}
					} else {
						warn($domain . ": cannot determine user for domain mapping");
					}
				}
			}
			if ($mode == 'add') {
				return $this->addMap($domain, $path);
			}

			return $this->removeMap($domain);
		}

		/**
		 * Domain is exempt from DNS verification requirements
		 *
		 * @param $domain
		 * @return bool
		 */
		public function bypass_exists($domain)
		{
			return $this->isBypass($domain);
		}

		/**
		 * Modify shared domain settings
		 *
		 * @param  string $domain
		 * @param  array  $newparams
		 * @return bool
		 */
		public function modify_domain(string $domain, array $newparams): bool
		{
			if (!IS_CLI) {
				$ret = $this->query('aliases_modify_domain', $domain, $newparams);
				if (!$this->inContext()) {
					\Preferences::reload();
				}
				$this->web_purge();

				return $ret;
			}
			if (!$this->domain_exists($domain)) {
				return error("domain `$domain' is not attached to account");
			}
			if ($this->shared_domain_hosted($domain)) {
				return error("domain `$domain' is hosted by another account");
			}
			if ($domain === $this->getConfig('siteinfo', 'domain')) {
				return error("cannot modify primary domain");
			}

			$params = array('type', 'domain', 'location', 'owner');
			if (isset($newparams['owner'])) {
				$newowner = $newparams['owner'];
				if (!$this->_change_owner($domain, $newowner)) {
					return false;
				}
			}

			if (isset($newparams['path'])) {
				$path = $newparams['path'];
				if (!$this->_change_path($domain, $path)) {
					return false;
				}
			}

			if (isset($newparams['domain'])) {
				$newdomain = $newparams['domain'];
				if (!$this->_change_domain($domain, $newdomain)) {
					return false;
				}
			}
			$this->web_purge();

			return true;
		}

		/**
		 * Verify domain hosted on account
		 *
		 * @param string $domain
		 * @return bool
		 */
		public function domain_exists($domain): bool
		{
			return $domain === $this->getConfig('siteinfo', 'domain') ||
				array_key_exists($domain, $this->list_shared_domains());
		}

		/**
		 * array list_shared_domains()
		 *
		 * @return array
		 */
		public function list_shared_domains()
		{
			if (!IS_CLI) {
				return $this->query('aliases_list_shared_domains');
			}
			$map = $this->transformMap();
			if (isset($map[$this->domain])) {
				unset($map[$this->domain]);
			}

			return $map;
		}

		/**
		 * Shared domain is hosted by another account
		 *
		 * @param string $domain
		 * @return bool
		 */
		public function shared_domain_hosted($domain)
		{
			$domain = strtolower($domain);
			if ($this->dns_domain_hosted($domain, true)) {
				return true;
			}
			$id = Auth::get_site_id_from_domain($domain);
			if ($id && $id != $this->site_id) {
				return true;
			}

			return false;
		}

		private function _change_owner($domain, $user)
		{
			$users = $this->user_get_users();
			if (!isset($users[$user])) {
				return error("user `$user' not found");
			}
			$map = $this->transformMap();
			if (!array_key_exists($domain, $map)) {
				return error("domain `$domain' not found in domain map");
			}

			$path = $map[$domain];

			return $this->file_chown($path, $user, true);
		}

		private function _change_path($domain, $newpath)
		{
			$map = $this->transformMap();
			if (!array_key_exists($domain, $map)) {
				return error("domain `$domain' not found in domain map");
			} else if (!preg_match(Regex::ADDON_DOMAIN_PATH, $newpath)) {
				return error($newpath . ": invalid path");
			}
			$oldpath = $map[$domain];
			if (!$this->removeMap($domain)) {
				return false;
			}
			if (!file_exists($this->domain_fs_path() . $newpath)) {
				$this->createDocumentRoot($newpath);
			}
			if (!$this->addMap($domain, $newpath)) {
				// domain addition failed - revert
				$this->addMap($domain, $oldpath);

				return error("domain `$domain' path change failure - reverting");
			}

			if ($oldpath === $newpath) {
				return true;
			}

			return true;
		}

		private function _change_domain($domain, $newdomain)
		{
			$map = $this->transformMap();
			if (!array_key_exists($domain, $map)) {
				return error("domain `$domain' not found in domain map");
			}
			$map = $this->transformMap();
			$path = $map[$domain];
			\Module\Support\Webapps\MetaManager::instantiateContexted($this->getAuthContext())
				->merge($path, ['host' => $newdomain])->sync();
			$ret = $this->remove_domain($domain)
				&& $this->_synchronize_changes() &&
				$this->add_domain($newdomain, $path);
			if ($ret) {
				warn("activate configuration changes for new domain to take effect");
			}

			return $ret;
		}

		/**
		 * bool remove_domain(string)
		 *
		 * @param string $domain domain name to remove
		 * @return bool
		 */
		public function remove_domain($domain)
		{
			if (!IS_CLI) {
				$docroot = $this->web_get_docroot($domain);
				$status = $this->query('aliases_remove_domain', $domain);
				if ($status && $docroot) {
					\Module\Support\Webapps\MetaManager::factory($this->getAuthContext())
						->forget($docroot)->sync();
				}

				return $status;
			}
			$domain = strtolower($domain);
			if (!preg_match(Regex::DOMAIN, $domain)) {
				return error("Invalid domain `$domain'");
			}
			$this->map_domain('delete', $domain);
			if (!$this->remove_alias($domain)) {
				return false;
			}

			/**
			 * NB: don't call dns_remove_zone, the domain may be added back at a later date,
			 * in which case the DNS will get clobbered
			 */
			return true;
		}

		public function remove_alias($alias)
		{
			if (!IS_CLI) {
				$status = $this->query('aliases_remove_alias', $alias);

				return $status;
			}
			$alias = strtolower(trim($alias));
			if (!preg_match(Regex::DOMAIN, $alias)) {
				return error("Invalid domain");
			}

			$aliases = (array)array_get($this->getNewServices('aliases'), 'aliases', $this->getServiceValue('aliases', 'aliases'));

			$key = array_search($alias, $aliases, true);
			if ($key === false) {
				return error("domain `$alias' not found");
			}

			unset($aliases[$key]);
			if (!$aliases && version_compare(platform_version(), '7.5', '<')) {
				$this->setConfigJournal('aliases', 'enabled', 0);
			}

			return $this->setConfigJournal('aliases', 'aliases', $aliases);
		}

		private function _synchronize_changes()
		{
			if ($this->auth_is_inactive()) {
				return error('account is suspended, will not resync');
			}
			$cmd = new Util_Account_Editor($this->getAuthContext()->getAccount());
			// pull in latest, unsynchronized config from new/
			$cmd->importConfig();
			$status = $cmd->edit();
			if (!$status) {
				return error("failed to activate domain changes");
			}
			info("Hang tight! Domain changes will be active within a few minutes, but may take up to 24 hours to work properly.");
			$this->getAuthContext()->getAccount()->reset($this->getAuthContext());

			return true;
		}

		public function add_domain($domain, $path)
		{
			$domain = preg_replace('/^www./', '', strtolower($domain));
			$path = rtrim(str_replace('..', '.', $path), '/') . '/';

			if (!preg_match(Regex::DOMAIN, $domain)) {
				return error($domain . ": invalid domain");
			} else if (!preg_match(Regex::ADDON_DOMAIN_PATH, $path)) {
				return error($path . ": invalid path");
			} else if ($domain === $this->getServiceValue('siteinfo', 'domain')) {
				return error("Primary domain may not be replicated as a shared domain");
			}

			if (!$this->_verify($domain)) {
				return false;
			}

			return $this->query('aliases_add_domain_backend', $domain, $path);
		}

		protected function _verify($domain)
		{
			if ($this->domain_exists($domain)) {
				return error("domain `$domain' exists");
			}

			if ($this->shared_domain_hosted($domain)) {
				return error("`%s': domain is already hosted by another account", $domain);
			}

			if (!DOMAINS_DNS_CHECK) {
				return true;
			}

			if (!$this->dns_domain_on_account($domain) /** domain under same invoice */ &&
				!$this->_verify_dns($domain) && !$this->_verify_url($domain)
			) {
				$nameservers = $this->dns_get_authns_from_host($domain);
				$cpnameservers = $this->dns_get_hosting_nameservers($domain);
				$hash = $this->challenge_token($domain);
				$script = $hash . '.html';

				return error("`%s': domain has DNS records delegated to nameservers %s. " .
					"Domain cannot be added to this account for security. Complete one of the following options to " .
					"verify ownership:" . "\r\n\r\n" .
					"(1) Change nameservers to %s within the domain registrar" . "\r\n" .
					"(2) Upload a html file to your old hosting provider accessible via http://%s/%s with the content:\r\n\t%s" . "\r\n" .
					"(3) Create a temporary DNS record named %s.%s with an `A' resource record that points to %s" . "\r\n\r\n" .
					"Please contact your previous hosting provider for assistance with performing any of " .
					"these verification options.",
					$domain,
					join(", ", $nameservers),
					join(", ", $cpnameservers),
					$domain,
					$script,
					$hash,
					self::DNS_VERIFICATION_RECORD,
					$domain,
					$this->dns_get_public_ip()
				);
			}

			return true;
		}

		/**
		 * Ensure a domain is not already hosted through Apis
		 *
		 * @param $domain
		 * @return bool domain can be hosted
		 */
		protected function _verify_dns($domain)
		{
			/*
			 * workaround for account migrations which
			 * duplicate domains across multiple servers
			 * that no longer have DNS properly delegated
			 *
			 * @XXX DNS checks can be bypassed via API: BAD
			 */
			if ($this->isBypass($domain)) {
				return true;
			}
			// domain not hosted, 5 second timeout
			$ip = silence(function () use ($domain) {
				return parent::__call('dns_gethostbyname_t', [$domain, 5000]);
			});
			if (!$ip) {
				return true;
			}
			$myip = $this->dns_get_public_ip();

			if ($ip === $myip) {
				// domain is on this server and would appear in db lookup check
				return true;
			}
			if ($this->domain_is_delegated($domain)) {
				return true;
			}
			$record = self::DNS_VERIFICATION_RECORD . '.' . $domain;
			$tmp = $this->dns_gethostbyname_t($record, 1500);
			if ($tmp && $tmp == $myip) {
				return true;
			}

			return false;
		}

		/**
		 * Verify that a domain is delegated to hosting nameservers
		 *
		 * @param $domain
		 * @return int
		 */
		protected function domain_is_delegated($domain)
		{
			if ($this->dns_domain_uses_nameservers($domain)) {
				return true;
			}
			$ns = $this->dns_get_authns_from_host($domain);
			// no nameservers set, treat this as addable
			// some nameservers return records, some fail if the
			// target domain is not registered... may need workaround in future
			// query WHOIS?
			if (is_null($ns)) {
				return -1;
			}
			$hostingns = $this->dns_get_hosting_nameservers($domain);
			// uses at least 1 of the required nameservers, we're good

			foreach ($ns as $n) {
				if (in_array($n, $hostingns)) {
					return 1;
				}
			}

			return 0;
		}

		protected function _verify_url($domain)
		{
			$hash = $this->challenge_token($domain);
			$url = 'http://' . $domain . '/' . $hash . '.html';
			if (extension_loaded('curl')) {
				$adapter = new HTTP_Request2_Adapter_Curl();
			} else {
				$adapter = new HTTP_Request2_Adapter_Socket();
			}

			$http = new HTTP_Request2(
				$url,
				HTTP_Request2::METHOD_GET,
				array(
					'adapter' => $adapter,
				)
			);

			try {
				$response = $http->send();
				$code = $response->getStatus();
				switch ($code) {
					case 200:
						break;
					case 403:
						return error("Verification URL request forbidden by server");
					case 404:
						return false;
					case 302:
						return error("Verification URL request moved to different location, ",
							$response->getDefaultReasonPhrase());
					default:
						return error("Verification URL request failed, code `%d': %s",
							$code, $response->getReasonPhrase());
				}
				$content = $response->getBody();
			} catch (HTTP_Request2_Exception $e) {
				return error("Fatal error retrieving verification URL: `%s'", $e->getMessage());
			}

			return trim(strip_tags($content)) == $hash;
		}

		/**
		 * Get challenge token to verify ownership of domain
		 *
		 * @param string $domain
		 * @return string
		 */
		public function challenge_token(): string
		{
			if (!IS_CLI) {
				return $this->query('aliases_challenge_token');
			}
			$str = (string)fileinode($this->domain_info_path('users'));

			return sha1($str);
		}

		public function remove_shared_domain(string $domain)
		{
			deprecated_func('Use remove_domain');

			return $this->remove_domain($domain);
		}

		public function add_shared_domain(string $domain, string $path)
		{
			deprecated_func('Use add_domain');

			return $this->add_domain($domain, $path);
		}

		public function shared_domain_exists($domain): bool
		{
			deprecated_func('use domain_exists');

			return $this->domain_exists($domain);
		}

		/**
		 * Compare domain configuration journal
		 *
		 * @return bool
		 */
		public function list_unsynchronized_domains()
		{
			$active = parent::getActiveServices('aliases');
			$active = $active['aliases'];
			$pending = (array)parent::getNewServices('aliases');
			if ($pending) {
				$pending = $pending['aliases'];
			}
			$domains = array_keys($this->list_shared_domains());
			$changes = array(
				'add'    => array_diff($pending, $active),
				'remove' => array_diff($active, $domains)
			);

			return $changes;
		}

		public function synchronize_changes()
		{
			if (!IS_CLI) {
				$ret = $this->query('aliases_synchronize_changes');
				$this->getAuthContext()->reset();
				return $ret;
			}

			$cache = Cache_Account::spawn($this->getAuthContext());
			$time = $cache->get('aliases.sync');
			$aliases = array_keys($this->list_shared_domains());
			if (version_compare(platform_version(), '7.5', '<')) {
				$this->setConfigJournal('aliases', 'enabled', (int)(count($aliases) > 0));
			}
			$this->setConfigJournal('aliases', 'aliases', $aliases);

			return $this->_synchronize_changes() && ($cache->set('aliases.sync', $time) || true);
		}

		/**
		 * array list_aliases()
		 *
		 * @return array aliases associated to the domain
		 */
		public function list_aliases()
		{
			$values = $this->getServiceValue('aliases', 'aliases');

			return (array)$values;
		}

		public function _reset(Util_Account_Editor &$editor = null)
		{
			$module = 'aliases';
			$params = array('aliases' => array());
			if (version_compare(platform_version(), '7.5', '<')) {
				$params['enabled'] = 0;
			}
			if ($editor) {
				foreach ($params as $k => $v) {
					$editor->setConfig($module, $k, $v);
				}
			}

			return array($module => $params);
		}

		public function _edit()
		{
			$conf_old = $this->getAuthContext()->conf('siteinfo', 'old');
			$conf_new = $this->getAuthContext()->conf('siteinfo', 'new');
			$domainold = $conf_old['domain'];
			$domainnew = $conf_new['domain'];

			// domain name change via auth_change_domain()
			if ($domainold !== $domainnew && $this->isBypass($domainnew)) {
				$this->removeBypass($domainnew);
			}
			$aliasesnew = array_get($this->getAuthContext()->conf('aliases', 'new'), 'aliases', []);
			$aliasesold = array_get($this->getAuthContext()->conf('aliases', 'old'), 'aliases', []);
			$add = array_diff($aliasesnew, $aliasesold);
			$rem = array_diff($aliasesold, $aliasesnew);
			$db = \Opcenter\Map::load(\Opcenter\Map::DOMAIN_MAP, 'wd');
			foreach ($add as $a) {
				$db->insert($a, $this->site);
			}
			foreach ($rem as $r) {
				$db->delete($r);
			}
			$db->close();

			return;
		}

		public function _create()
		{
			$db = \Opcenter\Map::write(\Opcenter\Map::DOMAIN_MAP);
			$conf = array_get($this->getAuthContext()->conf('aliases'), 'aliases', []);
			foreach ($conf as $domain) {
				$db->insert($domain, $this->site);
			}
			$db->close();
		}

		public function _delete()
		{
			$db = \Opcenter\Map::write(\Opcenter\Map::DOMAIN_MAP);
			$conf = array_get($this->getAuthContext()->conf('aliases'), 'aliases', []);
			foreach ($conf as $domain) {
				$db->delete($domain);
			}
			$db->close();
		}

		public function _edit_user(string $user, string $usernew, array $oldpwd)
		{
			if ($user === $usernew) {
				return;
			}

			$domains = $this->list_shared_domains();
			$home = $oldpwd['home'];
			$newhome = preg_replace('!' . DIRECTORY_SEPARATOR . $user . '!', DIRECTORY_SEPARATOR . $usernew, $home, 1);
			foreach ($domains as $domain => $path) {
				if (0 !== strpos($path, $home)) {
					continue;
				}
				$newpath = preg_replace('!^' . $home . '!', $newhome, $path);
				if (!$this->_change_path($domain, $newpath)) {
					warn("failed to update domain `%s'", $domain);
				}
			}
			$this->web_purge();

			return true;
		}

		public function _verify_conf(\Opcenter\Service\ConfigurationContext $ctx): bool
		{
			return true;
		}

		public function _create_user(string $user)
		{
			// TODO: Implement _create_user() method.
		}

		public function _delete_user(string $user)
		{
			// TODO: Implement _delete_user() method.
		}


	}