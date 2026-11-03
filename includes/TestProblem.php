<?php

namespace Modules\AlertINT\Includes;

use API;
use Exception;

/**
 * Test button: raises a real problem on the "AlertINT test" host by pushing a
 * value to its trapper item (history.push), then follows that one event from
 * Zabbix to AlertINT. Nothing is simulated: the trigger, the action, the media
 * type and the agent all run exactly as they do for any other problem.
 */
class TestProblem {

	/** Seconds the Zabbix server and agent clocks may disagree. */
	private const CLOCK_SKEW = 60;

	/**
	 * Raise (value 1) or resolve (value 0) the test problem.
	 *
	 * @return array{triggerid: string, since: int, eventid: ?string, note: string}
	 */
	public static function send(int $value): array {
		[$itemid, $triggerid] = self::target();
		$open = Connector::one(Connector::call(API::Problem()->get([
			'output' => ['eventid', 'clock'],
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'objectids' => [$triggerid],
			'sortfield' => ['eventid'],
			'sortorder' => ZBX_SORT_DOWN
		])));

		if ($value == 1 && $open !== null) {
			return ['triggerid' => $triggerid, 'since' => (int) $open['clock'], 'eventid' => $open['eventid'],
				'note' => _('The test problem was already open, so no new one was raised. Following the open one.')];
		}

		if ($value == 0 && $open === null) {
			return ['triggerid' => $triggerid, 'since' => time(), 'eventid' => null,
				'note' => _('No test problem is open, so there is nothing to resolve.')];
		}

		$since = time();
		$result = Connector::call(API::History()->push([
			['itemid' => $itemid, 'value' => (string) $value]
		]));

		foreach ($result['data'] ?? [] as $row) {
			if (array_key_exists('error', $row)) {
				// A host created moments ago (by Connect) is not in the server's
				// configuration cache yet, and history.push reports it as missing.
				throw new Exception(strpos($row['error'], 'No permissions to referred object') !== false
					? _('The Zabbix server has not loaded the test host yet. It picks up new hosts within a minute (CacheUpdateFrequency). Try again shortly.')
					: _s('Zabbix server did not accept the test value: %1$s', $row['error']));
			}
		}

		return ['triggerid' => $triggerid, 'since' => $since, 'eventid' => $value == 0 ? $open['eventid'] : null,
			'note' => ''];
	}

	/**
	 * Where the test event is on its way to a Situation. Each stage is
	 * done, wait or failed; the page stops polling when $done is true.
	 */
	public static function progress(string $triggerid, int $since, ?string $eventid, bool $resolving): array {
		$stages = [];
		$event = self::event($triggerid, $since, $eventid);

		if ($event === null) {
			$stages[] = self::stage('problem', 'wait', _('Waiting for Zabbix to raise "AlertINT test problem"…'));

			return ['eventid' => null, 'stages' => $stages, 'done' => false];
		}

		$resolved = $event['r_eventid'] != 0;
		$stages[] = self::stage('problem', 'done', $resolving
			? ($resolved ? _s('Zabbix resolved problem %1$s.', $event['eventid']) : _('Waiting for Zabbix to resolve it…'))
			: _s('Zabbix raised problem %1$s at %2$s.', $event['eventid'], date('H:i:s', (int) $event['clock'])));

		if ($resolving && !$resolved) {
			$stages[count($stages) - 1]['state'] = 'wait';

			return ['eventid' => $event['eventid'], 'stages' => $stages, 'done' => false];
		}

		$sent = self::sent($resolving ? $event['r_eventid'] : $event['eventid']);
		$stages[] = $sent;

		if ($sent['state'] !== 'done') {
			return ['eventid' => $event['eventid'], 'stages' => $stages, 'done' => $sent['state'] === 'failed'];
		}

		$config = Settings::get();
		$mcp = new McpClient($config['mcp_url'], $config['mcp_token']);
		$alert = self::received($mcp, $triggerid, (int) $event['clock'], $event['eventid']);

		if ($alert === null || ($resolving && ($alert['status'] ?? '') !== 'resolved')) {
			$stages[] = self::stage('received', 'wait', $resolving
				? _('Waiting for AlertINT to record the recovery…')
				: _('Waiting for AlertINT to record the problem…'));

			return ['eventid' => $event['eventid'], 'stages' => $stages, 'done' => false];
		}

		$stages[] = self::stage('received', 'done', $resolving
			? _('AlertINT recorded the recovery.')
			: _('AlertINT received the problem.'));

		if ($resolving) {
			return ['eventid' => $event['eventid'], 'stages' => $stages, 'done' => true];
		}

		[$situation, $incident] = self::situation($mcp, $alert['id']);

		if ($situation === null) {
			$stages[] = self::stage('situation', 'wait', _('Waiting for AlertINT to file it under a Situation…'));

			return ['eventid' => $event['eventid'], 'stages' => $stages, 'done' => false];
		}

		$stages[] = self::stage('situation', 'done', _s('Situation %1$s is %2$s.',
			$situation['public_handle'] ?? substr($situation['id'], 0, 8), str_replace('_', ' ', $situation['lifecycle'])));

		$title = $incident['finding']['analysis_name'] ?? '';
		$title = $title !== '' ? $title : ($incident['summary'] ?? '');

		if ($title === '') {
			$stages[] = self::stage('finding', 'wait', _('AlertINT is investigating. This usually takes under a minute.'));

			return ['eventid' => $event['eventid'], 'stages' => $stages, 'done' => false];
		}

		$stages[] = self::stage('finding', 'done', _s('Finding: %1$s', $title));

		return ['eventid' => $event['eventid'], 'stages' => $stages, 'done' => true];
	}

	/**
	 * @return string[] [itemid, triggerid]
	 */
	private static function target(): array {
		$item = Connector::one(Connector::call(API::Item()->get([
			'output' => ['itemid'],
			'host' => Connector::TEST_HOST,
			'filter' => ['key_' => Connector::TEST_ITEM_KEY],
			'selectTriggers' => ['triggerid']
		])));

		if ($item === null || !$item['triggers']) {
			throw new Exception(_('The test host is missing. Press Connect first.'));
		}

		return [$item['itemid'], $item['triggers'][0]['triggerid']];
	}

	private static function event(string $triggerid, int $since, ?string $eventid): ?array {
		$options = [
			'output' => ['eventid', 'clock', 'r_eventid'],
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'objectids' => [$triggerid],
			'value' => TRIGGER_VALUE_TRUE,
			'sortfield' => ['eventid'],
			'sortorder' => ZBX_SORT_DOWN,
			'limit' => 1
		];
		$options += $eventid !== null ? ['eventids' => [$eventid]] : ['time_from' => $since - 5];

		return Connector::one(Connector::call(API::Event()->get($options)));
	}

	private static function sent(string $eventid): array {
		$mt = Connector::mediaType();

		if ($mt === null) {
			return self::stage('sent', 'failed', _('The AlertINT media type is missing. Press Connect again.'));
		}

		$alert = Connector::one(Connector::call(API::Alert()->get([
			'output' => ['status', 'error', 'retries'],
			'eventids' => [$eventid],
			'mediatypeids' => [$mt['mediatypeid']],
			'sortfield' => ['alertid'],
			'sortorder' => ZBX_SORT_DOWN
		])));

		if ($alert === null) {
			return self::stage('sent', 'wait', _('Waiting for the action "Send to AlertINT" to send it…'));
		}

		switch ($alert['status']) {
			case ALERT_STATUS_SENT:
				return self::stage('sent', 'done', _('Zabbix sent it through the AlertINT media type.'));

			case ALERT_STATUS_FAILED:
				return self::stage('sent', 'failed', _s('Zabbix could not send it: %1$s', $alert['error']));

			default:
				return self::stage('sent', 'wait', $alert['error'] !== ''
					? _s('Zabbix is retrying (%1$s): %2$s', $alert['retries'], $alert['error'])
					: _('Zabbix is sending it to AlertINT…'));
		}
	}

	/**
	 * The agent's alert for this event, delivered since the problem was raised.
	 * Without zabbix.instance_id the agent keys Zabbix events by event id
	 * alone, so after a Zabbix rebuild (event ids restart) or with two Zabbix
	 * on one agent, a delivery can update an older alert row. That row still
	 * counts: the agent did record this delivery (received_at moves on).
	 */
	private static function received(McpClient $mcp, string $triggerid, int $clock, string $eventid): ?array {
		$found = $mcp->callTool('alertint_search_alerts', [
			'label_key' => 'zabbix_trigger_id',
			'label_value' => $triggerid,
			'since' => gmdate('Y-m-d\TH:i:s\Z', $clock - self::CLOCK_SKEW),
			'limit' => 20
		]);

		foreach ($found['alerts'] ?? [] as $alert) {
			if (($alert['annotations']['zabbix_event_id'] ?? '') === (string) $eventid) {
				return $alert;
			}
		}

		return null;
	}

	/**
	 * The Situation (and Incident) that owns this alert. Situations come back
	 * most recently updated first, so the owner is near the top.
	 *
	 * @return array [situation|null, incident|null]
	 */
	private static function situation(McpClient $mcp, string $alertid): array {
		$list = $mcp->callTool('alertint_list_situations', ['limit' => 10]);

		foreach (array_slice($list['situations'] ?? [], 0, 5) as $row) {
			if (strpos($row['group_key'] ?? '', Connector::TEST_HOST) === false) {
				continue;
			}

			$detail = $mcp->callTool('alertint_get_situation', ['id' => $row['id']]);

			foreach (array_reverse(array_slice($detail['incidents'] ?? [], -3)) as $member) {
				$incident = $mcp->callTool('alertint_get_incident', ['incident_id' => $member['id']]);

				if (in_array($alertid, array_column($incident['alerts'] ?? [], 'id'), true)) {
					return [$row, $incident];
				}
			}
		}

		return [null, null];
	}

	private static function stage(string $key, string $state, string $detail): array {
		static $labels = null;

		$labels ??= [
			'problem' => _('Problem in Zabbix'),
			'sent' => _('Sent by Zabbix'),
			'received' => _('Received by AlertINT'),
			'situation' => _('Situation'),
			'finding' => _('Finding')
		];

		return ['step' => $labels[$key], 'state' => $state, 'detail' => $detail];
	}
}
