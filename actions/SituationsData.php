<?php

namespace Modules\AlertINT\Actions;

use CController;
use CControllerResponseData;
use Exception;
use Modules\AlertINT\Includes\McpClient;
use Modules\AlertINT\Includes\Settings;

/**
 * JSON feed for the Situations page: the latest Situations, each with its
 * Incidents (finding + member Zabbix problems) and Transition journal, read
 * from AlertINT over MCP and reduced to what the page renders.
 */
class SituationsData extends CController {

	private const SITUATIONS = 12;
	private const INCIDENTS_PER_SITUATION = 3;

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
		$output = ['ok' => true, 'fetched_at' => gmdate('c'), 'situations' => []];

		try {
			$mcp = new McpClient($config['mcp_url'], $config['mcp_token']);
			$list = $mcp->callTool('alertint_list_situations', ['limit' => self::SITUATIONS]);

			foreach ($list['situations'] ?? [] as $row) {
				$output['situations'][] = $this->situation($mcp, $row);
			}
		}
		catch (Exception $e) {
			$output = ['ok' => false, 'error' => $e->getMessage(), 'fetched_at' => gmdate('c')];
		}

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode($output)]));
	}

	private function situation(McpClient $mcp, array $row): array {
		$detail = $mcp->callTool('alertint_get_situation', ['id' => $row['id']]);
		$transitions = $mcp->callTool('alertint_list_situation_transitions', ['id' => $row['id'], 'limit' => 100]);

		$incidents = [];
		$members = array_slice($detail['incidents'] ?? [], -self::INCIDENTS_PER_SITUATION);

		foreach (array_reverse($members) as $member) {
			$incidents[] = $this->incident($mcp->callTool('alertint_get_incident', ['incident_id' => $member['id']]));
		}

		$journal = [];

		foreach ($transitions['transitions'] ?? [] as $t) {
			$journal[] = [
				'sequence' => $t['sequence'],
				'headline' => $t['journal']['headline'] ?? $t['reason'],
				'reason' => $t['reason'],
				'lifecycle' => $t['lifecycle'],
				'attention' => $t['attention'],
				'at' => $t['journal']['occurred_at'] ?? $t['created_at'],
				'actor' => $t['actor'] ?? ''
			];
		}

		$assessment = $detail['assessment'] ?? [];

		return [
			'id' => $row['id'],
			'group_key' => $row['group_key'],
			'previous_id' => $row['previous_situation_id'] ?? null,
			'lifecycle' => $row['lifecycle'],
			'attention' => $row['attention'],
			'opened_at' => $row['opened_at'],
			'started_at' => $row['effective_started_at'] ?? $row['opened_at'],
			'next_update_at' => $assessment['action_contract']['next_update_at'] ?? null,
			'limitations' => array_column($assessment['limitations'] ?? [], 'detail'),
			'incidents' => $incidents,
			'journal' => $journal
		];
	}

	private function incident(array $inc): array {
		$roles = [];

		foreach ($inc['finding']['alerts'] ?? [] as $a) {
			$roles[$a['alert_id']] = $a['role_in_incident'] ?? '';
		}

		$alerts = [];

		foreach ($inc['alerts'] ?? [] as $a) {
			$labels = $a['labels'] ?? [];
			$ann = $a['annotations'] ?? [];
			$alerts[] = [
				'status' => $a['status'] ?? '',
				'role' => $roles[$a['id']] ?? ($a['role'] ?? ''),
				'severity' => $labels['severity'] ?? '',
				'host' => $ann['host_visible'] ?? ($labels['host'] ?? ''),
				'trigger' => $ann['trigger_name'] ?? ($labels['alertname'] ?? ''),
				'value' => $ann['item_value'] ?? '',
				'url' => $ann['generator_url'] ?? '',
				'starts_at' => $a['starts_at'] ?? null,
				'ends_at' => $a['ends_at'] ?? null
			];
		}

		return [
			'id' => $inc['id'],
			'status' => $inc['status'],
			'title' => $inc['finding']['analysis_name'] ?? ($inc['summary'] ?? ''),
			'summary' => $inc['summary'] ?? '',
			'root_cause' => $inc['root_cause'] ?? '',
			'overall_issue' => $inc['finding']['overall_issue'] ?? '',
			'confidence' => $inc['confidence'] ?? null,
			'severity' => $inc['finding']['severity'] ?? '',
			'reasoning' => $inc['finding']['correlation_findings'] ?? [],
			'next_step' => $inc['finding']['next_step'] ?? null,
			'created_at' => $inc['created_at'] ?? null,
			'updated_at' => $inc['updated_at'] ?? null,
			'recovery' => $inc['recovery'] ?? null,
			'alerts' => $alerts
		];
	}
}
