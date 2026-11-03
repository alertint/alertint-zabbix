<?php

namespace Modules\AlertINT\Actions;

use CController;
use CControllerResponseData;
use Exception;
use Modules\AlertINT\Includes\Connector;
use Modules\AlertINT\Includes\McpClient;
use Modules\AlertINT\Includes\Settings;

/**
 * "Check" for read access: asks the agent, over MCP, for the open problems on
 * the AlertINT test host. That makes the agent call the Zabbix API with its own
 * token, from its own host: the same path a real investigation uses.
 */
class PullCheck extends CController {

	protected function checkInput(): bool {
		return true;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function doAction(): void {
		$config = Settings::get();

		try {
			$problems = (new McpClient($config['mcp_url'], $config['mcp_token']))
				->callTool('zabbix_host_problems', ['host' => Connector::TEST_HOST]);
			$out = ['ok' => true, 'state' => 'on',
				'detail' => _('AlertINT reads Zabbix.').' '.sprintf(_('Open problems on "%1$s": %2$s.'),
					Connector::TEST_HOST_NAME, count($problems))
			];
		}
		catch (Exception $e) {
			$msg = $e->getMessage();

			// Without a Zabbix API the agent does not register its zabbix_* tools at all.
			if (strpos($msg, 'not configured') !== false || strpos($msg, "'zabbix_host_problems' not found") !== false) {
				$out = ['ok' => false, 'state' => 'off',
					'detail' => _('The agent does not read Zabbix yet. Run the command above on the agent host.')
				];
			}
			elseif (strpos($msg, 'no host matching') !== false) {
				$out = ['ok' => true, 'state' => 'on',
					'detail' => _('AlertINT reads Zabbix. (The test host is missing: press Connect again to bring it back.)')
				];
			}
			else {
				$out = ['ok' => false, 'state' => 'failed', 'detail' => $msg];
			}
		}

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode($out)]));
	}
}
