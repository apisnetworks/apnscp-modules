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
	 *  Provides administrative functions
	 *
	 * @package core
	 */
	class Admin_Module extends Module_Skeleton
	{
		const ADMIN_HOME = '/etc/opcenter/webhost';
		// @var string under ADMIN_HOME
		const ADMIN_CONFIG = '.config/';
		const ADMIN_CONFIG_LEGACY = '/etc/appliance/appliance.ini';

		/**
		 * {{{ void __construct(void)
		 *
		 * @ignore
		 */
		public function __construct()
		{
			parent::__construct();
			$this->exportedFunctions =
				array('*' => PRIVILEGE_ADMIN);
		}
		/* }}} */

		/**
		 * List all domains on the server
		 *
		 * @return array
		 */
		public function get_domains()
		{

			$q = \PostgreSQL::initialize()->query("SELECT domain,site_id FROM siteinfo ORDER BY domain");
			$domains = array();
			while (null !== ($row = $q->fetch_object())) {
				$domains[$row->site_id] = $row->domain;
			}
			return $domains;
		}

		/**
		 * Get e-mail from domain
		 *
		 * @param  string $domain
		 * @return string
		 */
		public function get_address_from_domain($domain)
		{
			if (!preg_match(Regex::DOMAIN, $domain)) {
				return error("invalid domain `%s'", $domain);
			}
			$siteid = $this->get_site_id_from_domain($domain);
			if (!$siteid) {
				return false;
			}
			$pgdb = \PostgreSQL::initialize();
			$q = $pgdb->query("SELECT email FROM siteinfo WHERE site_id = " . intval($siteid));
			if ($pgdb->num_rows() > 0) {
				return $q->fetch_object()->email;
			}
			return false;
		}

		/**
		 * Translate domain to id
		 *
		 * @param  string $domain domain
		 * @return int
		 */
		public function get_site_id_from_domain($domain)
		{
			if (!preg_match(Regex::DOMAIN, $domain)) {
				return error("invalid domain `%s'", $domain);
			}
			$pgdb = \PostgreSQL::initialize();
			$q = $pgdb->query("SELECT site_id FROM siteinfo WHERE domain = '" . $domain . "'");
			if ($pgdb->num_rows() > 0) {
				return $q->fetch_object()->site_id;
			}
			$id = Auth::get_site_id_from_domain($domain);
			return $id;

		}

		public function get_meta_from_domain($domain, $service, $class = null)
		{

			if (!IS_CLI) {
				return $this->query('admin_get_meta_from_domain', $domain, $service, $class);
			}
			$site = $domain;

			// $domain passed as site
			if (substr($domain, 0, 4) != 'site' || intval($domain) != substr($domain, 4)) {
				$tmp = Auth::get_site_id_from_domain($domain);
				if (!$tmp) {
					return error("unknown domain `$domain'");
				}
				$site = 'site' . $tmp;
			} else {
				if (!Auth::site_exists($site)) {
					return error("site `%s' out of bounds", $site);
				}
			}
			$file = '/home/virtual/' . $site . '/info/current/' . $service;
			$new = '/home/virtual/' . $site . '/info/new/' . $service . '.new';
			if (file_exists($new)) {
				$file = $new;
			} else {
				if (!file_exists($file)) {
					return error("service `$service' not installed for `$domain'");
				}
			}

			$meta = Util_Conf::parse_ini($file);
			if (!$class) {
				return $meta;
			}
			if (!isset($meta[$class])) {
				// @XXX DEBUG from CRM
				Error_Reporter::report(join(" ",
						array($domain, $service, $class)) . " " . Error_Reporter::get_debug_bt());
				return error("meta `%s' does not exist for `%s'",
					$class, $service);
			}
			return $meta[$class];
		}

		/**
		 * Get appliance admin email
		 *
		 * @return string|null
		 */
		public function get_email(): ?string
		{
			$ini = $this->_get_admin_config();
			return $ini['adminemail'] ?? null;
		}

		/**
		 * Set appliance admin email
		 *
		 * @param string $email
		 * @return bool
		 */
		public function set_email($email)
		{
			if (!IS_CLI) {
				return $this->query('admin_set_email', $email);
			}
			if (!preg_match(Regex::EMAIL, $email)) {
				return error("invalid email `%s'", $email);
			}
			$ini = $this->_get_admin_config();
			$ini['adminemail'] = $email;
			$data = '[DEFAULT]' . "\n" . implode("\n", array_key_map(function ($k, $v) {
					return $k . ' = ' . $v;
				}, $ini)) . "\n";
			$prefs = \Preferences::factory($this->getAuthContext())->unlock($this->getApnscpFunctionInterceptor());
			$prefs['email'] = $email;
			return (bool)file_put_contents($this->getAdminConfigFile(), $data);
		}

		public function _housekeeping()
		{
			$configHome = static::ADMIN_HOME . '/' . self::ADMIN_CONFIG;
			if (!is_dir($configHome)) {
				mkdir($configHome) && chmod($configHome, 0700);
			}
			$defplan = \Opcenter\SiteConfiguration::planPath(\Opcenter\SiteConfiguration::DEFAULT_SVC_NAME);
			if (is_dir($defplan)) {
				return;
			}
			$base = \Opcenter\SiteConfiguration::planPath('');
			// plan name change
			$dh = opendir($base);
			if (!$dh) {
				return error("Plan path `%s' missing, account creation will fail until fixed",
					$base
				);
			}
			while (false !== ($f = readdir($dh))) {
				if ($f === '..' || $f === '.') {
					continue;
				}
				$path = $base . DIRECTORY_SEPARATOR . $f;
				if (is_link($path)) {
					unlink($path);
					break;
				}
			}
			if ($f !== false) {
				info("old default plan `%s' renamed to `%s'",
					$f, \Opcenter\SiteConfiguration::DEFAULT_SVC_NAME
				);
			}
			symlink(dirname($defplan) . '/.skeleton', $defplan);
		}

		protected function _get_admin_config()
		{
			$file = $this->getAdminConfigFile();
			if (!file_exists($file)) {
				return [];
			}
			return parse_ini_file($file);
		}

		private function getAdminConfigFile(): string {
			if (version_compare(platform_version(), '7.5', '<')) {
				return self::ADMIN_CONFIG_LEGACY;
			}
			return self::ADMIN_HOME . DIRECTORY_SEPARATOR . self::ADMIN_CONFIG .
				DIRECTORY_SEPARATOR . basename(self::ADMIN_CONFIG_LEGACY);
		}

		/**
		 * Force bulk update of webapps
		 *
		 * @param array $options
		 * @return bool
		 */
		public function update_webapps(array $options = []): bool {
			$launcher = \Module\Support\Webapps\Updater::launch();
			foreach ($options as $k => $v) {
				switch ($k) {
					case 'limit':
						$launcher->batch((int)$v);
						break;
					case 'type':
						$launcher->limitType($v);
						break;
					case 'assets':
						$launcher->enableAssetUpdates((bool)$v);
						break;
					case 'core':
						$launcher->enableCoreUpdates((bool)$v);
						break;
					case 'site':
						$launcher->limitSite($v);
						break;
					default:
						fatal("unknown option `%s'", $k);
				}
			}
			return (bool)$launcher->run();
		}

		/**
		 * Reset failed apps
		 *
		 * @param array $constraints [site: <anything>, version: <operator> <version>, type: <type>]
		 * @return int
		 */
		public function reset_webapp_failure(array $constraints = []): int {
			$known = ['site', 'version', 'type'];
			if ($bad = array_diff(array_keys($constraints), $known)) {
				error("unknown constraints: `%s'", implode(', ', $bad));
				return 0;
			}
			if (isset($constraints['site'])) {
				$siteid = Auth::get_site_id_from_anything($constraints['site']);
				if (!$siteid) {
					error("unknown site `%s'", $constraints['site']);
					return 0;
				}
				$sites = ['site' . $siteid];
			} else {
				$sites = \Opcenter\Account\Enumerate::active();
			}
			$versionFilter = function(array $appmeta) use ($constraints) {
				if (!isset($constraints['version'])) {
					return true;
				}
				if (!isset($appmeta['version'])) {
					return false;
				}

				$vercon = explode(' ', $constraints['version']);
				if (count($vercon) === 1) {
					$vercon = ['=', $vercon[0]];
				}
				return version_compare($appmeta['version'], ...array_reverse($vercon));
			};
			$typeFilter = function(array $appmeta) use ($constraints) {
				if (!isset($constraints['type'])) {
					return true;
				}
				return $appmeta['type'] === $constraints['type'];
			};
			$count = 0;
			foreach ($sites as $site) {
				$auth = Auth::context(null, $site);
				$finder = new \Module\Support\Webapps\Finder($auth);
				$apps = $finder->getApplications(function ($appmeta) {
					return !empty($appmeta['failed']);
				});
				foreach ($apps as $path => $app) {
					if (!$typeFilter($app)) {
						continue;
					}
					if (!$versionFilter($app)) {
						continue;
					}
					/**
					 * @var \Module\Support\Webapps\App\Type\Unknown $instance
					 */
					$instance =  \Module\Support\Webapps\App\Loader::factory(null, $path, $auth);
					$instance->clearFailed();
					info("Reset failed status on `%s/%s'", $instance->getHostname(), $instance->getPath());
					$count++;
				}
			}
			return $count;
		}

		public function locate_webapps($site = null): array {
			return \Module\Support\Webapps\Finder::find($site);
		}
	}