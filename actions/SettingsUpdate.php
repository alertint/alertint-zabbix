<?php

namespace Modules\AlertINT\Actions;

use CController;
use CControllerResponseData;
use Exception;
use Modules\AlertINT\Includes\Settings;

/**
 * Saves the agent addresses and MCP token. A blank token keeps the saved one.
 */
class SettingsUpdate extends CController {

	protected function checkInput(): bool {
		$ok = $this->validateInput([
			'mcp_url' => 'required|string',
			'mcp_token' => 'string',
			'webhook_url' => 'string'
		]);

		if (!$ok) {
			$this->respond(['ok' => false, 'error' => _('Invalid request.')]);
		}

		return $ok;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function doAction(): void {
		$config = Settings::get();
		$mcp_url = trim($this->getInput('mcp_url'));
		$webhook_url = trim($this->getInput('webhook_url', ''));
		$token = trim($this->getInput('mcp_token', ''));

		if (!Settings::isHttpUrl($mcp_url)) {
			$this->respond(['ok' => false, 'error' => _('Agent MCP address must be an http:// or https:// URL.')]);
			return;
		}

		if ($webhook_url !== '' && !Settings::isHttpUrl($webhook_url)) {
			$this->respond(['ok' => false, 'error' => _('Webhook address must be an http:// or https:// URL, or empty.')]);
			return;
		}

		$config['mcp_url'] = $mcp_url;
		$config['webhook_url'] = $webhook_url;

		if ($token !== '') {
			$config['mcp_token'] = $token;
		}

		try {
			Settings::save($config);
			$this->respond(['ok' => true, 'token_set' => $config['mcp_token'] !== '']);
		}
		catch (Exception $e) {
			$this->respond(['ok' => false, 'error' => $e->getMessage()]);
		}
	}

	private function respond(array $data): void {
		$this->setResponse(new CControllerResponseData(['main_block' => json_encode($data)]));
	}
}
