<?php declare(strict_types=1);
	/**
	 * Copyright (C) Apis Networks, Inc - All Rights Reserved.
	 *
	 * Unauthorized copying of this file, via any medium, is
	 * strictly prohibited without consent. Any dissemination of
	 * material herein is prohibited.
	 *
	 * For licensing inquiries email <licensing@apisnetworks.com>
	 *
	 * Written by Matt Saladna <matt@apisnetworks.com>, April 2018
	 */


	/**
	 * Class Rampart_Module
	 *
	 * Integrates into fail2ban
	 */
	class Rampart_Module extends Module_Skeleton
	{
		const FAIL2BAN_CACHE_KEY = 'f2b';
		const FAIL2BAN_IPT_PREFIX = RAMPART_PREFIX;
		const FAIL2BAN_DRIVER = RAMPART_DRIVER;

		protected $confMapping;

		protected $exportedFunctions = [
			'*'   => PRIVILEGE_ADMIN | PRIVILEGE_SITE,
			'ban' => PRIVILEGE_ADMIN
		];

		/**
		 * Authenticated client IP or $ip is banned
		 *
		 * @param string|null $ip
		 * @param string|null $jail optional jail to check
		 * @return bool
		 */
		public function is_banned(string $ip = null, string $jail = null): bool
		{
			if (!IS_CLI) {
				return $this->query('rampart_is_banned', $ip, $jail);
			}
			if (false === ($ip = $this->checkInput($ip, $jail))) {
				return false;
			}

			return count($this->getMatches($ip, $jail)) > 0;
		}

		/**
		 * Perform permission validation and IP transformation
		 *
		 * @param string|null $ip
		 * @param string|null $jail
		 * @return false|string
		 */
		protected function checkInput(string $ip = null, string $jail = null)
		{
			if ($this->permission_level & PRIVILEGE_SITE && $ip) {
				return error('IP address may not be specified if site admin');
			}
			if ($this->permission_level & PRIVILEGE_SITE && $jail) {
				return error('jail may not be specified if site admin');
			}
			if (!$ip) {
				$ip = \Auth::client_ip();
			}
			if (!$ip) {
				report("Odd?" . var_export($_ENV, true));
			}
			if (false === inet_pton($ip)) {
				return error("invalid IP address `%s'", $ip);
			}

			return $ip;
		}

		/**
		 * Get matching rules where IP is banned
		 *
		 * @param string      $ip
		 * @param string|null $jail optional jail to restrict
		 * @return array
		 */
		private function getMatches(string $ip, string $jail = null): array
		{
			$banned = [];
			if ($jail) {
				$jail = static::FAIL2BAN_IPT_PREFIX . $jail;
			}
			$matches = (new \Opcenter\Net\Firewall)->getEntriesFromChain($jail);
			if ($jail) {
				$matches = [$jail => $matches];
			}
			foreach ($matches as $chain => $records) {
				foreach ($records as $record) {
					if ($record->getHost() === $ip && $record->isBlocked()) {
						$banned[$chain] = 1;
					}
				}
			}

			return $banned;
		}

		/**
		 * Ban an IP address
		 *
		 * @param string $ip
		 * @param string $jail
		 * @return bool
		 */
		public function ban(string $ip, string $jail): bool
		{
			if (!IS_CLI) {
				return $this->query('rampart_ban', $ip, $jail);
			}
			if (!in_array($jail, $this->get_jails(), true)) {
				return error("Unknown jail `%s'", $jail);
			}
			$ret = \Util_Process_Safe::exec('fail2ban-client set %s banip %s', $jail, $ip);

			return $ret['success'];
		}

		/**
		 * Get active jails
		 *
		 * @return array
		 */
		public function get_jails(): array
		{
			static $jails;
			if ($jails === null) {
				$jails = \Opcenter\Net\Fail2ban::getJails();
			}

			return $jails ?? [];
		}

		/**
		 * Unban an IP address
		 *
		 * @param string|null $ip
		 * @param string|null $jail optional jail to remove
		 * @return bool
		 */
		public function unban(string $ip = null, string $jail = null): bool
		{
			if (!IS_CLI) {
				return $this->query('rampart_unban', $ip, $jail);
			}
			if ($this->auth_is_demo()) {
				return error('cannot unban IP address in demo mode');
			}
			if (false === ($ip = $this->checkInput($ip, $jail))) {
				return false;
			}
			foreach (array_keys($this->getMatches($ip, $jail)) as $chain) {
				if (!$jail = $this->chain2Jail($chain)) {
					warn("Address blocked in `%s' but not recognized Rampart jail - cannot unban %s", $chain, $ip);
					continue;
				}

				$ret = \Util_Process_Safe::exec('fail2ban-client set %s unbanip %s', $jail, $ip);
				if ($ret['success']) {
					info("Unbanned `%s' from jail `%s'", $ip, $jail);
				} else {
					warn("Failed to unban `%s' from jail `%s'", $ip, $jail);
				}
			}

			return true;
		}

		/**
		 * Convert an iptables rule into a fail2ban jail
		 *
		 * @param string $chain iptables chain
		 * @return null|string
		 */
		protected function chain2Jail(string $chain): ?string
		{
			if (isset($this->confMapping[$chain])) {
				return $this->confMapping[$chain];
			}
			$jails = $this->getJailConfig();
			$chain = ' ' . $chain . ' ';
			foreach ($jails as $jail => $actions) {
				foreach ($actions as $action) {
					if (false !== strpos($action, $chain)) {
						$this->confMapping[$chain] = $jail;

						return $jail;
					}
				}
			}

			return null;
		}

		protected function getJailConfig(): ?array
		{
			$cache = \Cache_Global::spawn();
			$key = static::FAIL2BAN_CACHE_KEY . '.jail-config';
			if (false === ($jails = $cache->get($key))) {
				if (null === ($jails = \Opcenter\Net\Fail2ban::map())) {
					warn("error retrieving fail2ban jail configuration");

					return [];
				}
				$cache->set($key, $jails, 86400);
			}

			return $jails;
		}

		public function _housekeeping()
		{
			$this->getJailConfig();
		}
	}