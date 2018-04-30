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
	 * WordPress management
	 *
	 * An interface to wp-cli
	 *
	 * @package core
	 */
	class Wordpress_Module extends \Module\Support\Webapps
	{

		const APP_NAME = 'WordPress';

		// primary domain document root
		const WP_CLI = '/usr/share/pear/wp-cli.phar';

		// latest release
		const WP_CLI_URL = 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar';

		const VERSION_CHECK_URL = 'https://api.wordpress.org/core/version-check/1.7/';
		const PLUGIN_VERSION_CHECK_URL = 'https://api.wordpress.org/plugins/info/1.0/%plugin%.json';
		const THEME_VERSION_CHECK_URL = 'https://api.wordpress.org/themes/info/1.2/?action=theme_information&request[slug]=%theme%&request[fields][versions]=1';
		const DEFAULT_VERSION_LOCK = 'none';

		protected $_aclList = array(
			'min' => array(
				'/wp-content',
				'/.htaccess',
				'/wp-config.php'
			),
			'max' => array(
				'/wp-content/uploads',
				'/wp-content/cache',
				'/wp-content/wflogs',
				'/wp-content/updraft'
			)
		);

		/**
		 * @var array files detected by Wordpress when determining write-access
		 */
		protected $controlFiles = [
			'/wp-admin/includes/file.php'
		];

		/**
		 * Install WordPress
		 *
		 * @param string $hostname domain or subdomain to install WordPress
		 * @param string $path     optional path under hostname
		 * @param array  $opts     additional install options
		 * @return bool
		 */
		public function install(string $hostname, string $path = '', array $opts = array()): bool
		{

			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error("failed to detect document root for `%s'", $hostname);
			}

			if (!parent::checkDocroot($docroot)) {
				return false;
			}

			if (!empty($opts['ssl']) && !parent::configureSsl($hostname)) {
				return false;
			}

			$version = null;
			if (isset($opts['version'])) {
				$version = $opts['version'];
			}

			if (!isset($opts['autoupdate'])) {
				$opts['autoupdate'] = true;
			}

			$squash = array_get($opts, 'squash', false);
			if ($squash && $this->permission_level & PRIVILEGE_USER) {
				warn('must squash privileges as secondary user');
				$squash = true;
			}
			$opts['squash'] = $squash;

			if (isset($opts['email']) && !preg_match(Regex::EMAIL, $opts['email'])) {
				return error("invalid email address `%s' specified", $opts['email']);
			}
			$opts['email'] = $this->get_config('siteinfo', 'email');

			$args = array('mode' => 'download');
			if (null !== $version) {
				if (strcspn($version, '.0123456789')) {
					return error('invalid version number, %s', $version);
				}
				$args['version'] = '--version=' . $version;
			} else {
				$args['version'] = null;
			}

			if (!empty($opts['user'])) {
				if (!$this->user_exists($opts['user'])) {
					return error("user `%s' does not exist", $opts['user']);
				} else if (!$this->file_chown($docroot, $opts['user'])) {
					return error("failed to prep docroot for ownership by `%s'", $opts['user']);
				}
			} else {
				$opts['user'] = $this->username;
			}
			$args['user'] = $opts['user'];

			// ensure the docroot is owned by the target uid to permit installation
			// correct it at the end
			if (!$squash) {
				$this->file_chown($docroot, $this->user_id);
			}
			$ret = $this->_exec($docroot, 'core %(mode)s %(version)s', $args);

			if (!$ret['success']) {
				$vertmp = $version ?? 'LATEST';

				return error("failed to download WP version `%s', error: %s",
					$vertmp,
					coalesce($ret['stdout'], $ret['stderr'])
				);
			}
			$db = $this->_suggestDB($hostname);
			if (!$db) {
				return false;
			}

			$dbuser = $this->_suggestUser($db);
			if (!$dbuser) {
				return false;
			}
			$dbpass = $this->suggestPassword();
			$credentials = array(
				'db'       => $db,
				'user'     => $dbuser,
				'password' => $dbpass
			);

			if (!parent::setupDatabase($credentials)) {
				return false;
			}

			if (!$this->_generateNewConfig($hostname, $docroot, $credentials)) {
				info('removing temporary files');
				$this->file_delete($docroot, true);
				$this->sql_delete_mysql_database($db);
				$this->sql_delete_mysql_user($dbuser, 'localhost');

				return false;
			}

			if (!isset($opts['title'])) {
				$opts['title'] = 'A Random Blog for a Random Reason';
			}
			$autogenpw = false;
			if (!isset($opts['password'])) {
				$autogenpw = true;
				$opts['password'] = $this->suggestPassword(16);
				info("autogenerated password `%s'", $opts['password']);
			}

			info("setting admin user to `%s'", $this->username);
			// fix situations when installed on global subdomain
			$fqdn = $this->web_normalize_hostname($hostname);
			$opts['url'] = rtrim($fqdn . '/' . $path, '/');
			$args = array(
				'email'    => $opts['email'],
				'mode'     => 'install',
				'url'      => $opts['url'],
				'title'    => $opts['title'],
				'user'     => $opts['user'],
				'password' => $opts['password'],
				'proto'    => !empty($opts['ssl']) ? 'https://' : null
			);
			$ret = $this->_exec($docroot, 'core %(mode)s --admin_email=%(email)s --skip-email ' .
				'--url=%(proto)s%(url)s --title=%(title)s --admin_user=%(user)s ' .
				'--admin_password=%(password)s', $args);
			if (!$ret['success']) {
				return error('failed to create database structure: %s', $ret['stdout']);
			}
			// by default, let's only open up ACLs to the bare minimum

			if (!$version) {
				$version = $this->_getLastestVersion();
			}
			$params = array(
				'version'    => $version,
				'hostname'   => $hostname,
				'path'       => $path,
				'autoupdate' => (bool)$opts['autoupdate'],
				'options'    => $opts
			);
			$this->_map('add', $docroot, $params);
			$this->fortify($hostname, $path, 'max');

			if (array_get($opts, 'notify', true)) {
				\Lararia\Bootstrapper::minstrap();
				\Illuminate\Support\Facades\Mail::to($opts['email'])->
				send((new \Module\Support\Webapps\Mailer('install.wordpress', [
					'login'    => $opts['user'],
					'password' => $opts['password'],
					'uri'      => rtrim($fqdn . '/' . $path, '/'),
					'proto'    => empty($opts['ssl']) ? 'http://' : 'https://',
					'appname'  => static::APP_NAME
				]))->setAppName(static::APP_NAME));
			}

			if (!$opts['squash']) {
				parent::unsquash($docroot);
			}

			return info('WordPress installed - confirmation email with login info sent to %s', $opts['email']);
		}


		/**
		 * Enumerate plugin states
		 * @param string      $hostname
		 * @param string      $path
		 * @param string|null $plugin optional plugin
		 * @return array|bool
		 */
		public function plugin_status(string $hostname, string $path = '', string $plugin = null)
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('invalid WP location');
			}

			$ret = $this->_exec($docroot, 'plugin status');
			if (!$ret['success']) {
				return error('failed to get plugin status');
			}

			if (!preg_match_all(Regex::WORDPRESS_PLUGIN_STATUS, $ret['output'], $matches, PREG_SET_ORDER)) {
				return error('unable to parse WP plugin info');
			}
			$pluginmeta = [];
			foreach ($matches as $match) {
				$name = $match['name'];
				$version = $match['version'];
				if (!$versions = $this->pluginVersions($name)) {
					continue;
				}
				$pluginmeta[$name] = [
					'version' => $version,
					'next' => \Opcenter\Versioning::nextVersion($versions, $version),
					'max' => $this->pluginInfo($name)['version'] ?? end($versions)
				];
				// dev version may be present
				$pluginmeta[$name]['current'] = version_compare((string)array_get($pluginmeta, "${name}.max", '99999999.999'), (string)$version, '<=') ?:
					(bool)\Opcenter\Versioning::current($versions, $version);
			}
			return $plugin ? $pluginmeta[$plugin] ?? error("unknown plugin `%s'", $plugin) : $pluginmeta;
		}

		protected function pluginVersions(string $plugin): ?array {
			$info = $this->pluginInfo($plugin);
			if (!$info || empty($info['versions'])) {
				return null;
			}
			array_forget($info, 'versions.trunk');
			return array_keys($info['versions']);
		}

		/**
		 * Get information about a plugin
		 *
		 * @param string $plugin
		 * @return array
		 */
		protected function pluginInfo(string $plugin): array {
			$cache = \Cache_Super_Global::spawn();
			$key = 'wp.pinfo-' . $plugin;
			if (false !== ($data = $cache->get($key))) {
				return $data;
			}
			$url = str_replace('%plugin%', $plugin, static::PLUGIN_VERSION_CHECK_URL);
			$info = (array)json_decode(file_get_contents($url), true);
			$cache->set($key, $info, 86400);
			return $info;
		}

		/**
		 * Install and activate plugin
		 *
		 * @param string $hostname domain or subdomain of wp install
		 * @param string $path     optional path component of wp install
		 * @param string $plugin   plugin name
		 * @param string $version  optional plugin version
		 * @return bool
		 */
		public function install_plugin(
			string $hostname,
			string $path = '',
			string $plugin,
			string $version = 'stable'
		): bool {
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('invalid WP location');
			}

			$args = array(
				'plugin' => $plugin
			);
			$cmd = 'plugin install %(plugin)s --activate';
			if ($version) {
				$cmd .= ' --version=%(version)s';
				$args['version'] = $version;
			}
			$ret = $this->_exec($docroot, $cmd, $args);
			if (!$ret['success']) {
				return error("failed to install plugin `%s': %s", $plugin, coalesce($ret['stderr'], $ret['stdout']));
			}
			info("installed plugin `%s'", $plugin);

			return true;
		}

		/**
		 * Uninstall a plugin
		 *
		 * @param string $hostname
		 * @param string $path
		 * @param string $plugin plugin name
		 * @param  bool  $force  delete even if plugin activated
		 * @return bool
		 */
		public function uninstall_plugin(string $hostname, string $path = '', string $plugin, bool $force = false): bool
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('invalid WP location');
			}

			$args = array(
				'plugin' => $plugin
			);
			$cmd = 'plugin uninstall %(plugin)s';
			if ($force) {
				$cmd .= ' --deactivate';
			}
			$ret = $this->_exec($docroot, $cmd, $args);

			if (!$ret['stdout'] || !strncmp($ret['stdout'], 'Warning:', strlen('Warning:'))) {
				return error("failed to uninstall plugin `%s': %s", $plugin, coalesce($ret['stderr'], $ret['stdout']));
			}
			info("uninstalled plugin `%s'", $plugin);

			return true;
		}

		/**
		 * Remove a Wordpress theme
		 *
		 * @param string $hostname
		 * @param string $path
		 * @param string $theme
		 * @param bool $force deactive if necessary
		 * @return bool
		 */
		public function uninstall_theme(string $hostname, string $path = '', string $theme, bool $force = false): bool
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('invalid WP location');
			}

			$args = array(
				'theme' => $theme
			);
			$cmd = 'theme uninstall %(plugin)s';
			if ($force) {
				$cmd .= ' --deactivate';
			}
			$ret = $this->_exec($docroot, $cmd, $args);

			if (!$ret['stdout'] || !strncmp($ret['stdout'], 'Warning:', strlen('Warning:'))) {
				return error("failed to uninstall plugin `%s': %s", $theme, coalesce($ret['stderr'], $ret['stdout']));
			}
			info("uninstalled theme `%s'", $theme);

			return true;
		}


		/**
		 * Recovery mode to disable all plugins
		 *
		 * @param string $hostname subdomain or domain of WP
		 * @param string $path     optional path
		 * @return bool
		 */
		public function disable_all_plugins(string $hostname, string $path = ''): bool
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('failed to determine path');
			}

			$ret = $this->_exec($docroot, 'plugin deactivate --all --skip-plugins');
			if (!$ret['success']) {
				return error('failed to deactivate all plugins: %s', coalesce($ret['stderr'], $ret['stdout']));
			}

			return info('plugin deactivation successful: %s', $ret['stdout']);
		}

		/**
		 * Uninstall WP from a location
		 *
		 * @param        $hostname
		 * @param string $path
		 * @param string $delete "all", "db", or "files"
		 * @return bool
		 */
		public function uninstall(string $hostname, string $path = '', string $delete = 'all'): bool
		{
			return parent::uninstall($hostname, $path, $delete);
		}

		/**
		 * Get database configuration for a blog
		 *
		 * @param string $hostname domain or subdomain of wp blog
		 * @param string $path     optional path
		 * @return array|bool
		 */
		public function db_config(string $hostname, string $path = '')
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('failed to determine WP');
			}
			$code = 'set_error_handler(function() { global $table_prefix; print serialize(array("user" => DB_USER, "password" => DB_PASSWORD, "db" => DB_NAME, "host" => DB_HOST, "prefix" => $table_prefix)); die(); }); include("./wp-config.php"); trigger_error("");';
			$cmd = 'cd %(path)s && php -d mysqli.default_socket=%(socket)s -r %(code)s';
			$ret = $this->pman_run($cmd,
				array(
					'path'   => $docroot,
					'code'   => $code,
					'socket' => ini_get('mysqli.default_socket')
				)
			);
			if (!$ret['success']) {
				return error("failed to obtain WP configuration for `%s'", $docroot);
			}

			return \Util_PHP::unserialize(trim($ret['stdout']));
		}

		/**
		 * Check if version is latest or get latest version
		 *
		 * @param null|string $version    app version
		 * @param string|null $branchcomp optional branch to compare against
		 * @return int|string
		 */
		public function is_current(string $version = null, string $branchcomp = null)
		{
			return parent::is_current($version, $branchcomp);

		}

		/**
		 * Change WP admin credentials
		 *
		 * $fields is a hash whose indices match wp_update_user
		 * common fields include: user_pass, user_login, and user_nicename
		 *
		 * @link https://codex.wordpress.org/Function_Reference/wp_update_user
		 *
		 * @param string $hostname
		 * @param string $path
		 * @param array  $fields
		 * @return bool
		 */
		public function change_admin(string $hostname, string $path = '', array $fields): bool
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return warn('failed to change administrator information');
			}
			$admin = $this->get_admin($hostname, $path);

			if (!$admin) {
				return error('cannot determine admin of WP install');
			}

			if (isset($fields['user_login'])) {
				return error('user login field cannot be changed in WP');
			}

			$args = array(
				'user' => $admin
			);
			$cmd = 'user update %(user)s';
			foreach ($fields as $k => $v) {
				$cmd .= ' --' . $k . '=%(' . $k . ')s';
				$args[$k] = $v;
			}

			$ret = $this->_exec($docroot, $cmd, $args);
			if (!$ret['success']) {
				return error("failed to update admin `%s', error: %s",
					$admin,
					coalesce($ret['stderr'], $ret['stdout'])
				);
			}


			if (isset($fields['user_pass'])) {
				info("user `%s' password changed", $admin);
			}

			return $ret['success'];
		}

		/**
		 * Get the primary admin for a WP instance
		 *
		 * @param string      $hostname
		 * @param null|string $path
		 * @return string admin or false on failure
		 */
		public function get_admin(string $hostname, string $path = ''): ?string
		{
			$docroot = $this->getAppRoot($hostname, $path);
			$ret = $this->_exec($docroot, 'user list --role=administrator --field=user_login');
			if (!$ret['success']) {
				warn('failed to enumerate WP administrative users');

				return null;
			}

			return strtok($ret['stdout'], "\r\n");
		}

		/**
		 * Get installed version
		 *
		 * @param string $hostname
		 * @param string $path
		 * @return string version number
		 */
		public function get_version(string $hostname, string $path = ''): ?string
		{
			if (!$this->valid($hostname, $path)) {
				return null;
			}
			$docroot = $this->getAppRoot($hostname, $path);
			$ret = $this->_exec($docroot, 'core version');
			if (!$ret['success']) {
				return null;
			}

			return trim($ret['stdout']);

		}

		/**
		 * Location is a valid WP install
		 *
		 * @param string $hostname or $docroot
		 * @param string $path
		 * @return bool
		 */
		public function valid(string $hostname, string $path = ''): bool
		{
			if ($hostname[0] === '/') {
				$docroot = $hostname;
			} else {
				$docroot = $this->getAppRoot($hostname, $path);
				if (!$docroot) {
					return false;
				}
			}

			return $this->file_file_exists($docroot . '/wp-config.php') || $this->file_file_exists($docroot . '/wp-config-sample.php');
		}

		/**
		 * Update core, plugins, and themes atomically
		 *
		 * @param string $hostname subdomain or domain
		 * @param string $path     optional path under hostname
		 * @param string $version
		 * @return bool
		 */
		public function update_all(string $hostname, string $path = '', string $version = null): bool
		{
			$ret = ($this->update_themes($hostname, $path) && $this->update_plugins($hostname, $path) &&
					$this->update($hostname, $path, $version)) || error('failed to update all components');
			parent::setInfo($this->getAppRoot($hostname, $path), [
				'version' => $this->get_version($hostname, $path),
				'failed'  => !$ret
			]);

			return $ret;
		}

		/**
		 * Update WordPress to latest version
		 *
		 * @param string $hostname domain or subdomain under which WP is installed
		 * @param string $path     optional subdirectory
		 * @param string $version
		 * @return bool
		 */
		public function update(string $hostname, string $path = '', string $version = null): bool
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('update failed');
			}
			$this->assertOwnershipSystemCheck($docroot);

			$cmd = 'core update';
			$args = [];

			if ($version) {
				if (!is_scalar($version) || strcspn($version, '.0123456789')) {
					return error('invalid version number, %s', $version);
				}
				$cmd .= ' --version=%(version)s';
				$args['version'] = $version;
			}

			$oldversion = $this->get_version($hostname, $path);
			$ret = $this->_exec($docroot, $cmd, $args);

			if (!$ret['success']) {
				$output = coalesce($ret['stderr'], $ret['stdout']);
				if (0 === strpos($output, 'Error: Download failed.')) {
					return warn('Failed to fetch update - retry update later');
				}

				return error("update failed: `%s'", coalesce($ret['stderr'], $ret['stdout']));
			}

			// Sanity check as WP-CLI is known to fail while producing a 0 exit code
			if ($oldversion === $this->get_version($hostname, $path) &&
				!$this->is_current($oldversion, \Opcenter\Versioning::asMajor($oldversion))) {
				return error('Failed to update WordPress - old version is same as new version - %s! ' .
					'Diagnostics: (stderr) %s (stdout) %s', $oldversion, $ret['stderr'], $ret['stdout']);
			}

			info('updating WP database if necessary');
			$ret = $this->_exec($docroot, 'core update-db');
			$this->shareOwnershipSystemCheck($docroot);

			if (!$ret['success']) {
				return warn('failed to update WP database - ' .
					'login to WP admin panel to manually perform operation');
			}

			return $ret['success'];
		}

		/**
		 * Update WordPress plugins
		 *
		 * @param string $hostname domain or subdomain
		 * @param string $path     optional path within host
		 * @param array  $plugins
		 * @return bool
		 */
		public function update_plugins(string $hostname, string $path = '', array $plugins = array()): bool
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('update failed');
			}
			if (!$plugins) {
				$ret = $this->_exec($docroot, 'plugin update --all');
				if (!$ret['success']) {
					return error("plugin update failed: `%s'", coalesce($ret['stderr'], $ret['stdout']));
				}
				return $ret['success'];
			}
			$status = 1;
			foreach ($plugins as $plugin)  {
				$name = $plugin['name'] ?? $plugin;
				$version = null;
				$cmd = 'plugin update %(name)s';
				$args = [
					'name' => $name
				];
				if (isset($plugin['version'])) {
					$cmd .= ' --version=%(version)s';
					$args['version'] = $plugin['version'];
				}
				$ret = $this->_exec($docroot, $cmd, $args);
				if (!$ret['success']) {
					error("failed to update plugin `%s': %s", $name, coalesce($ret['stderr'], $ret['stdout']));
				}
				$status &= $ret['success'];
			}
			return (bool)$status;
		}

		/**
		 * Update WordPress themes
		 *
		 * @param string $hostname subdomain or domain
		 * @param string $path     optional path under hostname
		 * @param array  $themes
		 * @return bool
		 */
		public function update_themes(string $hostname, string $path = '', array $themes = array()): bool
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('update failed');
			}
			if (!$themes) {
				$ret = $this->_exec($docroot, 'theme update --all');
				if (!$ret['success']) {
					return error("theme update failed: `%s'", coalesce($ret['stderr'], $ret['stdout']));
				}

				return $ret['success'];
			}

			$status = 1;
			foreach ($themes as $theme) {
				$name = $theme['name'] ?? $theme;
				$version = null;
				$cmd = 'theme update %(name)s';
				$args = [
					'name' => $name
				];
				if (isset($theme['version'])) {
					$cmd .= ' --version=%(version)s';
					$args['version'] = $theme['version'];
				}
				$ret = $this->_exec($docroot, $cmd, $args);
				if (!$ret['success']) {
					error("failed to update theme `%s': %s", $name, coalesce($ret['stderr'], $ret['stdout']));
				}
				$status &= $ret['success'];
			}

			return (bool)$status;
		}

		/**
		 * Get theme status
		 *
		 * Sample response:
		 * [
		 *  hestia => [
		 *      version => 1.1.50
		 *      next => 1.1.51
		 *      current => false
		 *      max => 1.1.66
		 *  ]
		 * ]
		 *
		 * @param string      $hostname
		 * @param string      $path
		 * @param string|null $theme
		 * @return array|bool
		 */
		public function theme_status(string $hostname, string $path = '', string $theme = null)
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('invalid WP location');
			}

			$ret = $this->_exec($docroot, 'theme status');
			if (!$ret['success']) {
				return error('failed to get theme status');
			}

			if (!preg_match_all(Regex::WORDPRESS_PLUGIN_STATUS, $ret['output'], $matches, PREG_SET_ORDER)) {
				return error('unable to parse WP theme info');
			}
			$themes = [];
			foreach ($matches as $match) {
				$name = $match['name'];
				$version = $match['version'];
				if (!$versions = $this->themeVersions($name)) {
					continue;
				}

				$themes[$name] = [
					'version' => $version,
					'next'    => \Opcenter\Versioning::nextVersion($versions, $version),
					'max'     => $this->themeInfo($name)['version'] ?? end($versions)
				];
				// dev version may be present
				$themes[$name]['current'] = version_compare((string)array_get($themes, "${name}.max", '99999999.999'), (string)$version, '<=') ?:
					(bool)\Opcenter\Versioning::current($versions, $version);
			}

			return $theme ? $themes[$theme] ?? error("unknown theme `%s'", $theme) : $themes;
		}

		/**
		 * Get theme versions
		 * @param string $theme
		 * @return null|array
		 */
		protected function themeVersions($theme): ?array {
			$info = $this->themeInfo($theme);
			if (!$info || empty($info['versions'])) {
				return null;
			}
			array_forget($info, 'versions.trunk');
			return array_keys($info['versions']);
		}

		/**
		 * Get theme information
		 *
		 * @param string $theme
		 * @return array|null
		 */
		protected function themeInfo(string $theme): ?array {
			$cache = \Cache_Super_Global::spawn();
			$key = 'wp.tinfo-' . $theme;
			if (false !== ($data = $cache->get($key))) {
				return $data;
			}
			$url = str_replace('%theme%', $theme, static::THEME_VERSION_CHECK_URL);
			$info = (array)json_decode(file_get_contents($url), true);
			$cache->set($key, $info, 86400);
			return $info;
		}

		public function install_theme(string $hostname, string $path = '', string $theme, string $version = null): bool
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('invalid WP location');
			}

			$args = array(
				'theme' => $theme
			);
			$cmd = 'theme install %(theme)s --activate';
			if ($version) {
				$cmd .= ' --version=%(version)s';
				$args['version'] = $version;
			}
			$ret = $this->_exec($docroot, $cmd, $args);
			if (!$ret['success']) {
				return error("failed to install theme `%s': %s", $theme, coalesce($ret['stderr'], $ret['stdout']));
			}
			info("installed theme `%s'", $theme);

			return true;
		}

		/**
		 * Web application supports fortification
		 *
		 * @param string|null $mode optional mode (min, max)
		 * @return bool
		 */
		public function has_fortification(string $mode = null): bool
		{
			return parent::has_fortification($mode);
		}

		/**
		 * Restrict write-access by the app
		 *
		 * @param string $hostname
		 * @param string $path
		 * @param string $mode
		 * @return bool
		 */
		public function fortify(string $hostname, string $path = '', string $mode = 'max'): bool
		{
			if (!parent::fortify($hostname, $path, $mode)) {
				return false;
			}
			$docroot = $this->getAppRoot($hostname, $path);
			if ($mode === 'min') {
				// allow direct access on min to squelch FTP dialog
				$this->shareOwnershipSystemCheck($docroot);
			} else {
				// flipping from min to max, reset file check
				$this->assertOwnershipSystemCheck($docroot);
			}

			return true;
		}

		/**
		 * Relax permissions to allow write-access
		 *
		 * @param string $hostname
		 * @param string $path
		 * @return bool
		 * @internal param string $mode
		 */
		public function unfortify(string $hostname, string $path = ''): bool
		{
			return parent::unfortify($hostname, $path);
		}

		/**
		 * Install wp-cli if necessary
		 *
		 * @return bool
		 * @throws \Exception
		 */
		public function _housekeeping()
		{
			if (file_exists(self::WP_CLI) && filemtime(self::WP_CLI) < filemtime(__FILE__)) {
				unlink(self::WP_CLI);
			}
			if (!file_exists(self::WP_CLI)) {
				$url = self::WP_CLI_URL;
				$res = Util_HTTP::download($url, self::WP_CLI);
				if (!$res) {
					return error('failed to install wp-cli module');
				}
				info('downloaded wp-cli');
			}

			$local = $this->service_template_path('siteinfo') . '/' . self::WP_CLI;
			if (!file_exists($local)) {
				return copy(self::WP_CLI, $local);
			}

			return true;
		}

		private function _exec($path = null, $cmd, array $args = array())
		{
			// client may override tz, propagate to bin
			$tz = date_default_timezone_get();
			$cli = 'php -d mysqli.default_socket=' . escapeshellarg(ini_get('mysqli.default_socket')) .
				' -d date.timezone=' . $tz . ' -d memory_limit=128m ' . self::WP_CLI;
			if (!is_array($args)) {
				$args = array_slice(func_get_args(), 2);
			}
			$user = $this->username;
			if ($path) {
				$cmd = '--path=%(path)s ' . $cmd;
				$args['path'] = $path;
				$user = $this->getDocrootUser($path);
			}
			$cmd = $cli . ' ' . $cmd;
			// $from_email isn't always set, ensure WP can send via wp-includes/pluggable.php
			$ret = $this->pman_run($cmd, $args, ['SERVER_NAME' => $this->domain], ['user' => $user]);
			if (0 === strpos($ret['stdout'], 'Error:')) {
				// move stdout to stderr on error for consistency
				$ret['success'] = false;
				if (!$ret['stderr']) {
					$ret['stderr'] = $ret['stdout'];
				}

			}

			return $ret;
		}

		private function _generateNewConfig($domain, $docroot, $dbcredentials, array $ftpcredentials = array())
		{
			// generate db
			if (!isset($ftpcredentials['user'])) {
				$ftpcredentials['user'] = $this->username . '@' . $this->domain;
			}
			if (!isset($ftpcredentials['host'])) {
				$ftpcredentials['host'] = 'localhost';
			}
			if (!isset($ftpcredentials['password'])) {
				$ftpcredentials['password'] = '';
			}

			$xtraphp = '<<EOF ' . "\n" .
				'// defer updates to CP' . "\n" .
				"define('WP_AUTO_UPDATE_CORE', false); " . "\n" .
				"define('FTP_USER',%(ftpuser)s);" . "\n" .
				"define('FTP_HOST', %(ftphost)s);" . "\n" .
				($ftpcredentials['password'] ?
					"define('FTP_PASS', %(ftppass)s);" : '') . "\n" .
				'EOF';
			$args = array(
				'mode'     => 'config',
				'db'       => $dbcredentials['db'],
				'password' => $dbcredentials['password'],
				'user'     => $dbcredentials['user'],
				'ftpuser'  => $ftpcredentials['user'],
				'ftphost'  => 'localhost',
				'ftppass'  => $ftpcredentials['password'],
			);


			$ret = $this->_exec($docroot,
				'core %(mode)s --dbname=%(db)s --dbpass=%(password)s --dbuser=%(user)s --dbhost=localhost --extra-php ' . $xtraphp,
				$args);
			if (!$ret['success']) {
				return error('failed to generate configuration, error: %s', coalesce($ret['stderr'], $ret['stdout']));
			}

			return true;
		}

		/**
		 * Get latest WP release
		 *
		 * @return string
		 */
		protected function _getLastestVersion()
		{
			$versions = $this->_getVersions();
			if (!$versions) {
				return null;
			}

			return $versions[0]['version'];
		}

		/**
		 * Get all current major versions
		 *
		 * @return array
		 */
		protected function _getVersions()
		{
			$key = 'wp.versions';
			$cache = Cache_Super_Global::spawn();
			if (false !== ($ver = $cache->get($key))) {
				return $ver;
			}
			$url = self::VERSION_CHECK_URL;
			$context = stream_context_create(['http' => ['timeout' => 5]]);
			$contents = file_get_contents($url, false, $context);
			if (!$contents) {
				return array();
			}
			$versions = json_decode($contents, true);
			$versions = $versions['offers'];
			$cache->set($key, $versions, 43200);

			return $versions;
		}

		/**
		 * Share ownership of a WordPress install allowing WP write-access in min fortification
		 *
		 * @param string $docroot
		 * @return int num files changed
		 */
		protected function shareOwnershipSystemCheck(string $docroot): int
		{
			$changed = 0;
			$options = $this->getOptions($docroot);
			if (!array_get($options, 'fortify', 'min')) {
				return $changed;
			}
			$user = array_get($options, 'user', $this->getDocrootUser($docroot));
			foreach ($this->controlFiles as $file) {
				$path = $docroot . $file;
				if (!file_exists($this->domain_fs_path() . $path)) {
					continue;
				}
				$this->file_chown($path, \Web_Module::WEB_USERNAME);
				$this->file_set_acls($path, $user, 6);
				$changed++;
			}

			return $changed;
		}

		/**
		 * Change ownership over to WordPress admin
		 *
		 * @param string $docroot
		 * @return int num files changed
		 */
		protected function assertOwnershipSystemCheck(string $docroot): int
		{
			$changed = 0;
			$options = $this->getOptions($docroot);
			$user = array_get($options, 'user', $this->getDocrootUser($docroot));
			foreach ($this->controlFiles as $file) {
				$path = $docroot . $file;
				if (!file_exists($this->domain_fs_path() . $path)) {
					continue;
				}
				$this->file_chown($path, $user);
				$changed++;
			}

			return $changed;
		}

		/**
		 * Get all available WordPress versions
		 *
		 * @return array versions descending
		 */
		public function get_versions(): array
		{
			$versions = $this->_getVersions();

			return array_reverse(array_column($versions, 'version'));
		}

		public function next_version(string $version, string $maximalbranch = '99999999.99999999.99999999'): ?string
		{
			return parent::next_version($version, $maximalbranch);
		}

		/**
		 * Reconfigure a WordPress instance
		 *
		 * @param            $field
		 * @param string     $attribute
		 * @param array      $new
		 * @param array|null $old
		 */
		public function reconfigure(string $field, string $attribute, array $new, array $old = null)
		{

		}

		public function get_configuration($field)
		{

		}
	}
