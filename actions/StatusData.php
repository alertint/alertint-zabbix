<?php

namespace Modules\AlertINT\Actions;

use CController;
use CControllerResponseData;
use Exception;
use Modules\AlertINT\Includes\McpClient;
use Modules\AlertINT\Includes\Settings;

/**
 * JSON feed for the Status page: is the agent reachable, and what has it done
 * in the last 24 h (alerts in, incidents analysed, LLM calls and tokens per model).
 */
class StatusData extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return true;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$config = Settings::get();
		$out = [
			'fetched_at' => gmdate('c'),
			'configured' => $config['mcp_url'] !== '',
			'mcp_url' => $config['mcp_url'],
			'webhook_url' => Settings::webhookUrl($config)
		];

		try {
			$mcp = new McpClient($config['mcp_url'], $config['mcp_token']);
			$stats = $mcp->callTool('alertint_usage_stats');
			$latest = $mcp->callTool('alertint_list_situations', ['limit' => 1])['situations'][0] ?? null;
			$llm = $stats['llm'] ?? [];

			$out += [
				'ok' => true,
				'window' => $stats['window'] ?? null,
				'alerts_received' => $stats['alerts']['received'] ?? 0,
				'incidents_analyzed' => $stats['incidents']['analyzed'] ?? 0,
				'triage_exhausted' => $stats['incidents']['triage_exhausted'] ?? 0,
				'llm_calls' => $llm['calls'] ?? 0,
				'input_tokens' => $llm['input_tokens'] ?? 0,
				'output_tokens' => $llm['output_tokens'] ?? 0,
				'cache_read_tokens' => $llm['cache_read_tokens'] ?? 0,
				// null (not []) when there were no calls in the window.
				'by_model' => $llm['by_model'] ?? [],
				'latest_situation' => $latest === null ? null : [
					'id' => $latest['id'],
					'lifecycle' => $latest['lifecycle'] ?? '',
					'opened_at' => $latest['opened_at'] ?? null
				]
			];
		}
		catch (Exception $e) {
			$out += ['ok' => false, 'error' => $e->getMessage()];
		}

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode($out)]));
	}
}
