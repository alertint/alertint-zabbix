<?php

namespace Modules\AlertINT\Actions;

use CController;
use CControllerResponseData;
use Modules\AlertINT\Includes\Connector;
use Modules\AlertINT\Includes\Settings;

/**
 * Connect button: creates or repairs everything Zabbix needs to send problems
 * to AlertINT. The webhook token is stored only in the media type.
 */
class ConnectRun extends CController {

	protected function checkInput(): bool {
		$ok = $this->validateInput([
			'webhook_token' => 'string',
			'zabbix_url' => 'string'
		]);

		if (!$ok) {
			$this->respond(['ok' => false, 'steps' => [
				['step' => 'Stopped', 'state' => 'failed', 'detail' => _('Invalid request.')]
			]]);
		}

		return $ok;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function doAction(): void {
		$steps = (new Connector())->run(
			Settings::webhookUrl(Settings::get()),
			trim($this->getInput('webhook_token', '')),
			trim($this->getInput('zabbix_url', ''))
		);

		$this->respond([
			'ok' => !in_array('failed', array_column($steps, 'state'), true),
			'steps' => $steps,
			'state' => Connector::state()
		]);
	}

	private function respond(array $data): void {
		$this->setResponse(new CControllerResponseData(['main_block' => json_encode($data)]));
	}
}
