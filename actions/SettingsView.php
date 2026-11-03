<?php

namespace Modules\AlertINT\Actions;

use CController;
use CControllerResponseData;
use Exception;
use Modules\AlertINT\Includes\Connector;
use Modules\AlertINT\Includes\Settings;

class SettingsView extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return true;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function doAction(): void {
		$config = Settings::get();

		// The saved token never goes back to the browser, only whether one is set.
		$response = new CControllerResponseData([
			'mcp_url' => $config['mcp_url'],
			'webhook_url' => $config['webhook_url'],
			'webhook_url_derived' => Settings::deriveWebhookUrl($config['mcp_url']),
			'token_set' => $config['mcp_token'] !== '',
			'connect' => $this->connectState()
		]);
		$response->setTitle(_('AlertINT Settings'));
		$this->setResponse($response);
	}

	private function connectState(): array {
		try {
			return Connector::state();
		}
		catch (Exception $e) {
			return ['connected' => false, 'webhook_token_set' => false, 'zabbix_url' => ''];
		}
	}
}
