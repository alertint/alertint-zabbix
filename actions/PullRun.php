<?php

namespace Modules\AlertINT\Actions;

use CController;
use CControllerResponseData;
use Modules\AlertINT\Includes\Connector;
use Modules\AlertINT\Includes\Settings;

/**
 * "Create read access" button: a read-only role and API token for alertint-svc,
 * and the one command that hands the token to the agent. The token is in this
 * response only; the module never stores it.
 */
class PullRun extends CController {

	protected function checkInput(): bool {
		$ok = $this->validateInput(['zabbix_url' => 'string']);

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
		$url = rtrim(trim($this->getInput('zabbix_url', '')), '/');
		$url = preg_replace('#/api_jsonrpc\.php$#', '', $url);

		if (!Settings::isHttpUrl($url) || preg_match('#[\s\'"\\\\]#', $url)) {
			$this->respond(['ok' => false, 'steps' => [['step' => 'Stopped', 'state' => 'failed',
				'detail' => _('Enter the Zabbix address the agent host can open, e.g. https://zabbix.example.com.')
			]]]);

			return;
		}

		[$steps, $token] = (new Connector())->pull();
		$ok = $token !== null && !in_array('failed', array_column($steps, 'state'), true);

		$this->respond([
			'ok' => $ok,
			'steps' => $steps,
			'command' => $ok ? Connector::pullCommand($url, $token) : null
		]);
	}

	private function respond(array $data): void {
		$this->setResponse(new CControllerResponseData(['main_block' => json_encode($data)]));
	}
}
