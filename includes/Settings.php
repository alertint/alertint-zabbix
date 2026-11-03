<?php

namespace Modules\AlertINT\Includes;

use API;
use APP;
use Exception;

/**
 * The module's own config, stored by Zabbix in the module row.
 *
 * module.update REPLACES config (it does not merge), so every save writes the
 * whole array. Keys added in later versions are missing from configs saved by
 * earlier ones, so reads always go through defaults().
 */
class Settings {

	public static function defaults(): array {
		return [
			'mcp_url' => '',
			'mcp_token' => '',
			'webhook_url' => ''
		];
	}

	public static function get(): array {
		$module = APP::ModuleManager()->getActionModule();

		return array_merge(self::defaults(), $module ? $module->getConfig() : []);
	}

	/**
	 * Saves the whole config. Needs a Super admin session (module.update is Super admin only).
	 */
	public static function save(array $config): void {
		$module = APP::ModuleManager()->getActionModule();

		if ($module === null) {
			throw new Exception(_('AlertINT module is not loaded.'));
		}

		$config = array_intersect_key(array_merge(self::defaults(), $config), self::defaults());
		$result = API::Module()->update([['moduleid' => $module->getModuleId(), 'config' => $config]]);

		if ($result === false) {
			$errors = array_column(get_and_clear_messages(), 'message');
			throw new Exception($errors ? implode(' ', $errors) : _('Cannot save the module settings.'));
		}
	}

	/**
	 * The webhook address Zabbix server posts problems to, derived from the MCP
	 * address when not set: same host, port 9911, /webhook/zabbix.
	 */
	public static function webhookUrl(array $config): string {
		if ($config['webhook_url'] !== '') {
			return $config['webhook_url'];
		}

		return self::deriveWebhookUrl($config['mcp_url']);
	}

	public static function deriveWebhookUrl(string $mcp_url): string {
		$parts = parse_url($mcp_url);

		if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
			return '';
		}

		// parse_url keeps the brackets of an IPv6 host.
		return $parts['scheme'].'://'.$parts['host'].':9911/webhook/zabbix';
	}

	public static function isHttpUrl(string $url): bool {
		$parts = parse_url($url);

		return is_array($parts) && isset($parts['host'])
			&& in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true);
	}
}
