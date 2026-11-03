<?php

namespace Modules\AlertINT\Includes;

use API;
use CSessionHelper;
use CSettingsHelper;
use CZabbixServer;
use Exception;

/**
 * Connect: wires this Zabbix to the AlertINT agent through the Zabbix API, as
 * the logged-in Super admin. Every step looks its object up by name first, so
 * running Connect again changes nothing that is already right.
 *
 *   1. Test host "AlertINT test" (trapper item + trigger) in host group "AlertINT"
 *   2. Media type "AlertINT" (webhook URL + token)
 *   3. Global macro {$ZABBIX_URL} (links back from AlertINT to the problem)
 *   4. User group "AlertINT service" (no frontend login, read on all host groups)
 *   5. User "alertint-svc" with the AlertINT media
 *   6. Trigger action "Send to AlertINT"
 *   7. Webhook check from the Zabbix server (no alert is created)
 *
 * pull() is the separate, optional "read access" button: role + API token for
 * the same service user, so the agent can read Zabbix.
 */
class Connector {

	public const HOST_GROUP = 'AlertINT';
	public const TEST_HOST = 'alertint-test';
	public const TEST_HOST_NAME = 'AlertINT test';
	public const TEST_ITEM_KEY = 'alertint.test';
	public const TEST_TRIGGER = 'AlertINT test problem';
	public const MEDIA_TYPE = 'AlertINT';
	public const USER_GROUP = 'AlertINT service';
	public const USER = 'alertint-svc';
	public const ACTION = 'Send to AlertINT';
	public const ZABBIX_URL_MACRO = '{$ZABBIX_URL}';

	public const ROLE = 'AlertINT read-only API';
	public const PULL_TOKEN = 'AlertINT read-only API';
	public const INSTALLER_URL = 'https://alertint.com/zabbix/install.sh';

	/** Every Zabbix API method the agent calls (apiinfo.version needs no auth). */
	public const PULL_METHODS = ['event.get', 'history.get', 'host.get', 'hostgroup.get', 'item.get',
		'problem.get', 'trend.get', 'trigger.get'];

	private const TOKEN_PLACEHOLDER = 'REPLACE-WITH-YOUR-ALERTINT_ZABBIX_WEBHOOK_TOKEN';

	/** @var array[] [{step, state: created|updated|unchanged|ok|failed, detail}] */
	private array $steps = [];

	/**
	 * What the Settings page shows before Connect is pressed.
	 */
	public static function state(): array {
		$mt = self::mediaType();
		$token = $mt ? self::param($mt, 'token') : '';

		return [
			'connected' => $mt !== null && self::one(API::Action()->get([
				'output' => ['actionid'], 'filter' => ['name' => self::ACTION]
			])) !== null,
			'webhook_token_set' => $token !== '' && $token !== self::TOKEN_PLACEHOLDER,
			'zabbix_url' => self::macroValue()
		];
	}

	public function run(string $webhook_url, string $webhook_token, string $zabbix_url): array {
		try {
			$this->testHost();
			$mediatypeid = $this->mediaTypeStep($webhook_url, $webhook_token);
			$this->macro($zabbix_url);
			$usrgrpid = $this->userGroup();
			$userid = $this->user($usrgrpid, $mediatypeid);
			$this->action($mediatypeid, $userid);
			$this->checkWebhook($mediatypeid);
		}
		catch (Exception $e) {
			$this->steps[] = ['step' => 'Stopped', 'state' => 'failed', 'detail' => $e->getMessage()];
		}

		return $this->steps;
	}

	/**
	 * Read access for the agent: the service user "alertint-svc" gets a role that
	 * allows only the read methods AlertINT calls, and a fresh API token. Its read
	 * rights are the service group's (every host group, kept by Connect).
	 * Returns [steps, token]; the token is shown once and never stored here.
	 */
	public function pull(): array {
		$token = null;

		try {
			$user = self::one(self::call(API::User()->get([
				'output' => ['userid', 'roleid'], 'filter' => ['username' => self::USER]
			])));

			if ($user === null) {
				throw new Exception(_('Press Connect first: it creates the service user "alertint-svc" this uses.'));
			}

			$roleid = $this->pullRole();

			if ($user['roleid'] != $roleid) {
				self::call(API::User()->update(['userid' => $user['userid'], 'roleid' => $roleid]));
				$this->add('User "'.self::USER.'"', 'updated', 'Now has the role "'.self::ROLE.'".');
			}
			else {
				$this->add('User "'.self::USER.'"', 'unchanged', 'Has the role "'.self::ROLE.'".');
			}

			$old = self::call(API::Token()->get([
				'output' => ['tokenid'], 'userids' => [$user['userid']], 'filter' => ['name' => self::PULL_TOKEN]
			]));

			if ($old) {
				self::call(API::Token()->delete(array_column($old, 'tokenid')));
			}

			$tokenid = self::call(API::Token()->create([
				'name' => self::PULL_TOKEN,
				'userid' => $user['userid'],
				'description' => 'Used by the AlertINT agent to read Zabbix. Made in AlertINT → Settings; pressing the button again replaces it.',
				'expires_at' => 0
			]))['tokenids'][0];
			$token = self::call(API::Token()->generate([$tokenid]))[0]['token'];
			$this->add('API token "'.self::PULL_TOKEN.'"', $old ? 'updated' : 'created',
				$old ? 'New token made; the old one no longer works.' : 'Token made for "'.self::USER.'". No expiry.'
			);
		}
		catch (Exception $e) {
			$this->steps[] = ['step' => 'Stopped', 'state' => 'failed', 'detail' => $e->getMessage()];
		}

		return [$this->steps, $token];
	}

	private function pullRole(): string {
		// Zabbix requires at least one frontend page per role. The service group has
		// frontend login disabled, so the dashboard is never actually reachable.
		$rules = [
			'ui' => [['name' => 'monitoring.dashboard', 'status' => 1]],
			'ui.default_access' => 0,
			'modules.default_access' => 0,
			'actions.default_access' => 0,
			'api.access' => 1,
			'api.mode' => 1,
			'api' => self::PULL_METHODS
		];

		$role = self::one(self::call(API::Role()->get([
			'output' => ['roleid', 'type'], 'filter' => ['name' => self::ROLE], 'selectRules' => ['api', 'api.access', 'api.mode']
		])));

		if ($role === null) {
			$roleid = self::call(API::Role()->create([
				'name' => self::ROLE, 'type' => USER_TYPE_ZABBIX_USER, 'rules' => $rules
			]))['roleids'][0];
			$this->add('Role "'.self::ROLE.'"', 'created', 'API only, and only: '.implode(', ', self::PULL_METHODS).'.');

			return $roleid;
		}

		$api = $role['rules']['api'] ?? [];
		sort($api);

		if ($role['type'] == USER_TYPE_ZABBIX_USER && $api === self::PULL_METHODS
				&& $role['rules']['api.access'] == 1 && $role['rules']['api.mode'] == 1) {
			$this->add('Role "'.self::ROLE.'"', 'unchanged', 'API only, read methods only.');
		}
		else {
			self::call(API::Role()->update(['roleid' => $role['roleid'], 'type' => USER_TYPE_ZABBIX_USER, 'rules' => $rules]));
			$this->add('Role "'.self::ROLE.'"', 'updated', 'Reset to API only, read methods only.');
		}

		return $role['roleid'];
	}

	/**
	 * The command that gives the token to the agent: the installer, run again on
	 * the agent host, keeps everything else and turns the Zabbix API on.
	 */
	public static function pullCommand(string $zabbix_url, string $token): string {
		return 'curl -fsSL '.self::INSTALLER_URL.' | ZABBIX_URL=\''.$zabbix_url.'\' ZABBIX_API_TOKEN=\''.$token.'\' sh';
	}

	private function testHost(): void {
		$groupid = self::one(self::call(API::HostGroup()->get([
			'output' => ['groupid'], 'filter' => ['name' => self::HOST_GROUP]
		])))['groupid'] ?? null;
		$created = [];

		if ($groupid === null) {
			$groupid = self::call(API::HostGroup()->create(['name' => self::HOST_GROUP]))['groupids'][0];
			$created[] = 'host group';
		}

		$hostid = self::one(self::call(API::Host()->get([
			'output' => ['hostid'], 'filter' => ['host' => self::TEST_HOST]
		])))['hostid'] ?? null;

		if ($hostid === null) {
			$hostid = self::call(API::Host()->create([
				'host' => self::TEST_HOST,
				'name' => self::TEST_HOST_NAME,
				'groups' => [['groupid' => $groupid]],
				'description' => 'Used by the Test button in AlertINT → Settings. Safe to delete; Connect recreates it.'
			]))['hostids'][0];
			$created[] = 'host';
		}

		$item = self::one(self::call(API::Item()->get([
			'output' => ['itemid'], 'hostids' => [$hostid], 'filter' => ['key_' => self::TEST_ITEM_KEY]
		])));

		if ($item === null) {
			self::call(API::Item()->create([
				'hostid' => $hostid,
				'name' => 'AlertINT test value',
				'key_' => self::TEST_ITEM_KEY,
				'type' => ITEM_TYPE_TRAPPER,
				'value_type' => ITEM_VALUE_TYPE_UINT64,
				'history' => '7d',
				'trends' => '0',
				'description' => '1 = test problem, 0 = resolved. Pushed by the Test button.'
			]));
			$created[] = 'trapper item';
		}

		$trigger = self::one(self::call(API::Trigger()->get([
			'output' => ['triggerid'], 'hostids' => [$hostid], 'filter' => ['description' => self::TEST_TRIGGER]
		])));

		if ($trigger === null) {
			self::call(API::Trigger()->create([
				'description' => self::TEST_TRIGGER,
				'expression' => 'last(/'.self::TEST_HOST.'/'.self::TEST_ITEM_KEY.')=1',
				'priority' => TRIGGER_SEVERITY_AVERAGE,
				'manual_close' => ZBX_TRIGGER_MANUAL_CLOSE_ALLOWED,
				'comments' => 'Raised on purpose by the Test button in AlertINT → Settings, to check that '
					.'problems reach AlertINT. Resolves when the item goes back to 0.',
				'tags' => [['tag' => 'source', 'value' => 'alertint-test']]
			]));
			$created[] = 'trigger';
		}

		$this->add('Test host "'.self::TEST_HOST_NAME.'"', $created ? 'created' : 'unchanged',
			$created ? 'Created: '.implode(', ', $created).'.' : 'Host, trapper item and trigger already there.'
		);
	}

	private function mediaTypeStep(string $webhook_url, string $webhook_token): string {
		$existing = self::mediaType();
		$token = $webhook_token !== '' ? $webhook_token : ($existing ? self::param($existing, 'token') : '');

		if ($token === '' || $token === self::TOKEN_PLACEHOLDER) {
			throw new Exception(_('Enter the webhook token: ALERTINT_ZABBIX_WEBHOOK_TOKEN from /etc/alertint/alertint.env on the agent box.'));
		}

		if (!Settings::isHttpUrl($webhook_url)) {
			throw new Exception(_('Save an agent address first: the webhook address is built from it.'));
		}

		// Import the media type definition shipped with the module (script, parameters,
		// message templates). updateExisting refreshes the script on an older install.
		$source = file_get_contents(__DIR__.'/alertint-media-type.yaml');

		self::call(API::Configuration()->import([
			'format' => 'yaml',
			'source' => $source,
			'rules' => ['mediaTypes' => ['createMissing' => true, 'updateExisting' => true]]
		]));

		$mt = self::mediaType();
		$params = [];

		foreach ($mt['parameters'] as $p) {
			$value = $p['value'];

			if ($p['name'] === 'url') {
				$value = $webhook_url;
			}
			elseif ($p['name'] === 'token') {
				$value = $token;
			}

			$params[] = ['name' => $p['name'], 'value' => $value];
		}

		self::call(API::MediaType()->update([
			'mediatypeid' => $mt['mediatypeid'],
			'parameters' => $params,
			'status' => MEDIA_TYPE_STATUS_ACTIVE
		]));

		$same = $existing && self::param($existing, 'url') === $webhook_url
			&& self::param($existing, 'token') === $token && $existing['status'] == MEDIA_TYPE_STATUS_ACTIVE;

		$this->add('Media type "'.self::MEDIA_TYPE.'"', $existing ? ($same ? 'unchanged' : 'updated') : 'created',
			'Sends problems to '.$webhook_url.'.'
		);

		return $mt['mediatypeid'];
	}

	private function macro(string $zabbix_url): void {
		$macro = self::one(self::call(API::UserMacro()->get([
			'output' => ['globalmacroid', 'value'], 'globalmacro' => true,
			'filter' => ['macro' => self::ZABBIX_URL_MACRO]
		])));

		if ($macro !== null) {
			$this->add('Global macro '.self::ZABBIX_URL_MACRO, 'unchanged',
				'Already set to '.$macro['value'].'. Change it in Administration → Macros if that is wrong.'
			);
			return;
		}

		if (!Settings::isHttpUrl($zabbix_url)) {
			throw new Exception(_('Enter this Zabbix frontend address (http:// or https://), used for links back to problems.'));
		}

		self::call(API::UserMacro()->createGlobal([
			'macro' => self::ZABBIX_URL_MACRO,
			'value' => rtrim($zabbix_url, '/'),
			'description' => 'Zabbix frontend address. AlertINT links back to problems with it.'
		]));
		$this->add('Global macro '.self::ZABBIX_URL_MACRO, 'created', 'Set to '.rtrim($zabbix_url, '/').'.');
	}

	/**
	 * Zabbix only notifies users who can read the problem's host, so the service
	 * group gets read on every host group that exists now. Run Connect again
	 * after adding host groups.
	 */
	private function userGroup(): string {
		$groupids = array_column(self::call(API::HostGroup()->get(['output' => ['groupid']])), 'groupid');
		$rights = array_map(static fn($id) => ['id' => $id, 'permission' => PERM_READ], $groupids);

		$ug = self::one(self::call(API::UserGroup()->get([
			'output' => ['usrgrpid', 'gui_access'], 'filter' => ['name' => self::USER_GROUP],
			'selectHostGroupRights' => ['id', 'permission']
		])));

		if ($ug === null) {
			$usrgrpid = self::call(API::UserGroup()->create([
				'name' => self::USER_GROUP,
				'gui_access' => GROUP_GUI_ACCESS_DISABLED,
				'hostgroup_rights' => $rights
			]))['usrgrpids'][0];
			$this->add('User group "'.self::USER_GROUP.'"', 'created',
				'No frontend login; read access on '.count($groupids).' host groups.'
			);

			return $usrgrpid;
		}

		$had = array_column($ug['hostgroup_rights'] ?? [], 'id');
		$missing = array_diff($groupids, $had);

		if ($missing || $ug['gui_access'] != GROUP_GUI_ACCESS_DISABLED) {
			// Keep any rights an admin added by hand; add read on the groups that are missing.
			$keep = array_filter($ug['hostgroup_rights'] ?? [], static fn($r) => in_array($r['id'], $groupids));
			$merged = array_merge(array_values($keep),
				array_map(static fn($id) => ['id' => $id, 'permission' => PERM_READ], array_values($missing))
			);
			self::call(API::UserGroup()->update([
				'usrgrpid' => $ug['usrgrpid'],
				'gui_access' => GROUP_GUI_ACCESS_DISABLED,
				'hostgroup_rights' => $merged
			]));
			$this->add('User group "'.self::USER_GROUP.'"', 'updated',
				'Read access added on '.count($missing).' new host group(s).'
			);
		}
		else {
			$this->add('User group "'.self::USER_GROUP.'"', 'unchanged',
				'Read access on all '.count($groupids).' host groups.'
			);
		}

		return $ug['usrgrpid'];
	}

	private function user(string $usrgrpid, string $mediatypeid): string {
		$media = [
			'mediatypeid' => $mediatypeid,
			'sendto' => 'alertint',
			'active' => MEDIA_STATUS_ACTIVE,
			'severity' => 63,
			'period' => '1-7,00:00-24:00'
		];

		$user = self::one(self::call(API::User()->get([
			'output' => ['userid'], 'filter' => ['username' => self::USER],
			'selectMedias' => ['mediatypeid', 'sendto', 'active', 'severity', 'period'],
			'selectUsrgrps' => ['usrgrpid']
		])));

		if ($user === null) {
			$role = self::one(self::call(API::Role()->get([
				'output' => ['roleid'], 'filter' => ['type' => USER_TYPE_ZABBIX_USER], 'sortfield' => 'roleid'
			])));

			if ($role === null) {
				throw new Exception(_('No user role of type "User" found.'));
			}

			$userid = self::call(API::User()->create([
				'username' => self::USER,
				'name' => 'AlertINT',
				'surname' => 'service',
				'passwd' => bin2hex(random_bytes(24)),
				'roleid' => $role['roleid'],
				'usrgrps' => [['usrgrpid' => $usrgrpid]],
				'medias' => [$media]
			]))['userids'][0];
			$this->add('User "'.self::USER.'"', 'created', 'Receives every problem through the AlertINT media type. It cannot log in.');

			return $userid;
		}

		$medias = $user['medias'];
		$has_media = false;

		foreach ($medias as &$m) {
			if ($m['mediatypeid'] == $mediatypeid) {
				$has_media = $m['active'] == MEDIA_STATUS_ACTIVE && $m['severity'] == 63;
				$m = $media;
			}
		}
		unset($m);

		$in_group = in_array($usrgrpid, array_column($user['usrgrps'], 'usrgrpid'));

		if ($has_media && $in_group) {
			$this->add('User "'.self::USER.'"', 'unchanged', 'AlertINT media active for all severities.');
		}
		else {
			if (!in_array($mediatypeid, array_column($medias, 'mediatypeid'))) {
				$medias[] = $media;
			}

			$groups = array_map(static fn($id) => ['usrgrpid' => $id],
				array_unique(array_merge(array_column($user['usrgrps'], 'usrgrpid'), [$usrgrpid]))
			);
			self::call(API::User()->update(['userid' => $user['userid'], 'medias' => $medias, 'usrgrps' => $groups]));
			$this->add('User "'.self::USER.'"', 'updated', 'AlertINT media switched on for all severities.');
		}

		return $user['userid'];
	}

	private function action(string $mediatypeid, string $userid): void {
		$action = self::one(self::call(API::Action()->get([
			'output' => ['actionid', 'status'], 'filter' => ['name' => self::ACTION, 'eventsource' => EVENT_SOURCE_TRIGGERS]
		])));

		if ($action === null) {
			$op = [
				'operationtype' => OPERATION_TYPE_MESSAGE,
				'opmessage' => ['default_msg' => 1, 'mediatypeid' => $mediatypeid],
				'opmessage_usr' => [['userid' => $userid]]
			];
			self::call(API::Action()->create([
				'name' => self::ACTION,
				'eventsource' => EVENT_SOURCE_TRIGGERS,
				'status' => ACTION_STATUS_ENABLED,
				'esc_period' => '1h',
				'operations' => [$op],
				'recovery_operations' => [$op]
			]));
			$this->add('Trigger action "'.self::ACTION.'"', 'created', 'Every problem and its recovery go to AlertINT.');
		}
		elseif ($action['status'] != ACTION_STATUS_ENABLED) {
			self::call(API::Action()->update(['actionid' => $action['actionid'], 'status' => ACTION_STATUS_ENABLED]));
			$this->add('Trigger action "'.self::ACTION.'"', 'updated', 'Was disabled; enabled again.');
		}
		else {
			$this->add('Trigger action "'.self::ACTION.'"', 'unchanged', 'Enabled.');
		}
	}

	/**
	 * Asks the Zabbix server (not this web frontend) to run the webhook with an
	 * empty event id. The agent checks the token first and then rejects the empty
	 * event with HTTP 400, so nothing is stored. 400 = reachable and token right.
	 */
	private function checkWebhook(string $mediatypeid): void {
		global $ZBX_SERVER, $ZBX_SERVER_PORT;

		$mt = self::mediaType();
		$params = [];

		foreach ($mt['parameters'] as $p) {
			$params[$p['name']] = in_array($p['name'], ['url', 'token'], true) ? $p['value'] : '';
		}

		$server = new CZabbixServer($ZBX_SERVER, $ZBX_SERVER_PORT,
			timeUnitToSeconds(CSettingsHelper::get(CSettingsHelper::CONNECT_TIMEOUT)),
			timeUnitToSeconds(CSettingsHelper::get(CSettingsHelper::MEDIA_TYPE_TEST_TIMEOUT)), ZBX_SOCKET_BYTES_LIMIT
		);
		$result = $server->testMediaType(['mediatypeid' => $mediatypeid, 'parameters' => $params],
			CSessionHelper::getId()
		);
		$error = $result === false ? (string) $server->getError() : '';
		$url = self::param($mt, 'url');

		if (strpos($error, 'replied 400') !== false) {
			$this->add('Webhook check from the Zabbix server', 'ok', 'The agent at '.$url.' answered and accepted the token.');
		}
		elseif (strpos($error, 'replied 401') !== false || strpos($error, 'replied 403') !== false) {
			throw new Exception('The agent at '.$url.' refused the webhook token. Check ALERTINT_ZABBIX_WEBHOOK_TOKEN and press Connect again.');
		}
		elseif ($result !== false) {
			throw new Exception('The agent at '.$url.' accepted an empty test event. Is this really an AlertINT agent?');
		}
		else {
			throw new Exception('The Zabbix server could not reach '.$url.': '.$error
				.'. Check that the agent runs and its port 9911 is open to the Zabbix server.'
			);
		}
	}

	private function add(string $step, string $state, string $detail): void {
		$this->steps[] = ['step' => $step, 'state' => $state, 'detail' => $detail];
	}

	public static function mediaType(): ?array {
		return self::one(self::call(API::MediaType()->get([
			'output' => ['mediatypeid', 'status', 'parameters'], 'filter' => ['name' => self::MEDIA_TYPE]
		])));
	}

	private static function macroValue(): string {
		$macro = self::one(self::call(API::UserMacro()->get([
			'output' => ['value'], 'globalmacro' => true, 'filter' => ['macro' => self::ZABBIX_URL_MACRO]
		])));

		return $macro['value'] ?? '';
	}

	private static function param(array $mediatype, string $name): string {
		foreach ($mediatype['parameters'] ?? [] as $p) {
			if ($p['name'] === $name) {
				return $p['value'];
			}
		}

		return '';
	}

	public static function one($rows): ?array {
		return is_array($rows) && $rows ? reset($rows) : null;
	}

	/**
	 * The frontend API wrapper returns false and queues the error message.
	 */
	public static function call($result) {
		if ($result === false) {
			$errors = array_column(get_and_clear_messages(), 'message');
			throw new Exception($errors ? implode(' ', $errors) : _('Zabbix API call failed.'));
		}

		return $result;
	}
}
