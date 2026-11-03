<?php

namespace Modules\AlertINT\Actions;

use CController;
use CControllerResponseData;
use Exception;
use Modules\AlertINT\Includes\McpClient;
use Modules\AlertINT\Includes\Settings;

/**
 * Test connection: tries the address and token in the form (before saving).
 * A blank token falls back to the saved one.
 */
class SettingsTest extends CController {

	protected function checkInput(): bool {
		$ok = $this->validateInput([
			'mcp_url' => 'required|string',
			'mcp_token' => 'string'
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
		$url = trim($this->getInput('mcp_url'));
		$token = trim($this->getInput('mcp_token', ''));

		if ($token === '') {
			$token = Settings::get()['mcp_token'];
		}

		if (!Settings::isHttpUrl($url)) {
			$this->respond(['ok' => false, 'error' => _('Agent MCP address must be an http:// or https:// URL.')]);
			return;
		}

		try {
			$mcp = new McpClient($url, $token);
			$info = $mcp->serverInfo();
			$stats = $mcp->callTool('alertint_usage_stats');

			$this->respond([
				'ok' => true,
				'server' => trim(($info['name'] ?? '').' '.($info['version'] ?? '')),
				'alerts_24h' => $stats['alerts']['received'] ?? 0,
				'llm_calls_24h' => $stats['llm']['calls'] ?? 0
			]);
		}
		catch (Exception $e) {
			$this->respond(['ok' => false, 'error' => $e->getMessage()]);
		}
	}

	private function respond(array $data): void {
		$this->setResponse(new CControllerResponseData(['main_block' => json_encode($data)]));
	}
}
