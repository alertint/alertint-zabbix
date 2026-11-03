<?php
/**
 * AlertINT Settings (Super admin): where the agent is, and the MCP token.
 * Saved into the module's own config. The token is write-only from here.
 *
 * @var CView $this
 * @var array $data
 */

$mcp_url = (new CTextBox('mcp_url', $data['mcp_url'], false, 2048))
	->setId('alertint-mcp-url')
	->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
	->setAttribute('placeholder', 'http://agent-host:9912/mcp')
	->setAriaRequired();

$token = (new CPassBox('mcp_token', '', 1024))
	->setId('alertint-mcp-token')
	->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
	->setAttribute('autocomplete', 'new-password')
	->setAttribute('placeholder', $data['token_set']
		? _('saved; leave empty to keep it')
		: _('ALERTINT_MCP_TOKEN from the agent'));

$webhook_url = (new CTextBox('webhook_url', $data['webhook_url'], false, 2048))
	->setId('alertint-webhook-url')
	->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
	->setAttribute('placeholder', $data['webhook_url_derived'] !== ''
		? $data['webhook_url_derived']
		: 'http://agent-host:9911/webhook/zabbix');

$form = (new CForm())
	->setId('alertint-settings')
	->addItem(
		(new CFormGrid())
			->addItem([
				(new CLabel(_('Agent MCP address'), 'alertint-mcp-url'))->setAsteriskMark(),
				new CFormField([
					$mcp_url,
					(new CDiv(_('Where the Zabbix web frontend reaches the agent. install.sh prints it; the default port is 9912.')))
						->addClass('ai-hint')
				])
			])
			->addItem([
				new CLabel(_('MCP token'), 'alertint-mcp-token'),
				new CFormField([
					$token,
					(new CDiv([
						(new CSpan($data['token_set'] ? _('A token is saved.') : _('No token saved yet.')))
							->setId('alertint-token-state'),
						' ',
						_('On the agent box it is ALERTINT_MCP_TOKEN in /etc/alertint/alertint.env.')
					]))->addClass('ai-hint')
				])
			])
			->addItem([
				new CLabel(_('Webhook address'), 'alertint-webhook-url'),
				new CFormField([
					$webhook_url,
					(new CDiv(_('Where the Zabbix server sends problems. Empty means: same host as above, port 9911. Set it only when the server reaches the agent by another address.')))
						->addClass('ai-hint')
				])
			])
			->addItem(
				new CFormActions(
					(new CSubmit('save', _('Save')))->setId('alertint-save'),
					[(new CSimpleButton(_('Test connection')))->setId('alertint-test')->addClass(ZBX_STYLE_BTN_ALT)]
				)
			)
			->addItem([
				null,
				new CFormField((new CDiv())->setId('alertint-result'))
			])
	);

$connect = $data['connect'];

$connect_form = (new CForm())
	->setId('alertint-connect')
	->addItem(
		(new CFormGrid())
			->addItem([
				new CLabel(_('Webhook token'), 'alertint-webhook-token'),
				new CFormField([
					(new CPassBox('webhook_token', '', 1024))
						->setId('alertint-webhook-token')
						->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
						->setAttribute('autocomplete', 'new-password')
						->setAttribute('placeholder', $connect['webhook_token_set']
							? _('saved in the media type; leave empty to keep it')
							: _('ALERTINT_ZABBIX_WEBHOOK_TOKEN from the agent')),
					(new CDiv(_('On the agent box it is ALERTINT_ZABBIX_WEBHOOK_TOKEN in /etc/alertint/alertint.env. Stored only in the AlertINT media type.')))
						->addClass('ai-hint')
				])
			])
			->addItem([
				new CLabel(_('This Zabbix address'), 'alertint-zabbix-url'),
				new CFormField([
					(new CTextBox('zabbix_url', $connect['zabbix_url'], $connect['zabbix_url'] !== '', 2048))
						->setId('alertint-zabbix-url')
						->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
						->setAttribute('data-saved', $connect['zabbix_url'] !== '' ? '1' : ''),
					(new CDiv($connect['zabbix_url'] !== ''
						? _('Already set as the global macro {$ZABBIX_URL}. Change it in Administration → Macros.')
						: _('Saved as the global macro {$ZABBIX_URL}, so AlertINT can link back to each problem.')
					))->addClass('ai-hint')
				])
			])
			->addItem(
				new CFormActions((new CSubmit('connect', $connect['connected'] ? _('Connect again') : _('Connect')))
					->setId('alertint-connect-run')
				)
			)
			->addItem([null, new CFormField((new CDiv())->setId('alertint-connect-result'))])
	);

$test_form = (new CForm())
	->setId('alertint-testrun')
	->addItem(
		(new CFormGrid())
			->addItem(
				new CFormActions(
					(new CSubmit('raise', _('Send test problem')))
						->setId('alertint-test-raise')
						->setEnabled($connect['connected']),
					[(new CSimpleButton(_('Resolve test problem')))
						->setId('alertint-test-resolve')
						->addClass(ZBX_STYLE_BTN_ALT)
						->setEnabled($connect['connected'])]
				)
			)
			->addItem([null, new CFormField((new CDiv(
				$connect['connected'] ? '' : _('Press Connect first: the test problem uses the test host it creates.')
			))->setId('alertint-test-result'))])
	);

(new CHtmlPage())
	->setTitle(_('AlertINT · Settings'))
	->addItem((new CTag('h4', true, _('1. Agent')))->addClass('ai-section'))
	->addItem($form)
	->addItem((new CTag('h4', true, _('2. Connect Zabbix')))->addClass('ai-section'))
	->addItem((new CDiv(
		_('Creates what Zabbix needs to send problems to AlertINT: the AlertINT media type, a service user that cannot log in, the trigger action "Send to AlertINT", and a test host. Safe to run again: it only adds what is missing.')
	))->addClass('ai-intro'))
	->addItem($connect_form)
	->addItem((new CTag('h4', true, _('3. Send a test problem')))->addClass('ai-section'))
	->addItem((new CDiv(
		_('Raises "AlertINT test problem" on the host "AlertINT test", the same way any trigger fires, and follows it until AlertINT files it under a Situation with a finding, then resolves it again. AlertINT investigates it like a real problem, so it uses one analysis on your model. "Resolve test problem" closes it by hand if a test is interrupted.')
	))->addClass('ai-intro'))
	->addItem($test_form)
	->show();
?>
<style>
.ai-hint { color: #768d99; margin-top: 4px; max-width: 640px; line-height: 1.4; }
.ai-section { margin: 18px 0 6px; font-size: 14px; }
.ai-intro { color: #768d99; max-width: 760px; line-height: 1.5; margin-bottom: 8px; }
#alertint-connect-result:empty, #alertint-test-result:empty { display: none; }
#alertint-test-result { max-width: 760px; }
.ai-state.done { background: #dff5e7; color: #2a7d4a; }
.ai-state.wait { background: #f4f6f7; color: #768d99; }
#alertint-connect-result { max-width: 760px; }
.ai-steps { border-collapse: collapse; width: 100%; }
.ai-steps td { padding: 5px 8px 5px 0; border-bottom: 1px solid #ebeef0; vertical-align: top; line-height: 1.4; }
.ai-steps td:first-child { white-space: nowrap; font-weight: bold; }
.ai-state { display: inline-block; padding: 1px 7px; border-radius: 9px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
.ai-state.created, .ai-state.ok { background: #dff5e7; color: #2a7d4a; }
.ai-state.updated { background: #e6f4ff; color: #1f65a6; }
.ai-state.unchanged { background: #f4f6f7; color: #768d99; }
.ai-state.failed { background: #fbe2e2; color: #b33a3a; }
#alertint-result:empty { display: none; }
#alertint-result { padding: 8px 12px; max-width: 640px; line-height: 1.5; }
#alertint-result.ai-ok { background: #dff5e7; border: 1px solid #59db8f; }
#alertint-result.ai-bad { background: #fbe2e2; border: 1px solid #e45959; }
#alertint-result.ai-busy { background: #f4f6f7; border: 1px solid #dfe4e7; color: #768d99; }
</style>
<script>
(() => {
	const CSRF_NAME = <?= json_encode(CSRF_TOKEN_NAME) ?>;
	const CSRF = {
		'alertint.settings.update': <?= json_encode(CCsrfTokenHelper::get('alertint.settings.update')) ?>,
		'alertint.settings.test': <?= json_encode(CCsrfTokenHelper::get('alertint.settings.test')) ?>,
		'alertint.connect.run': <?= json_encode(CCsrfTokenHelper::get('alertint.connect.run')) ?>,
		'alertint.test.run': <?= json_encode(CCsrfTokenHelper::get('alertint.test.run')) ?>
	};
	const form = document.getElementById('alertint-settings');
	const result = document.getElementById('alertint-result');
	const mcpUrl = document.getElementById('alertint-mcp-url');
	const token = document.getElementById('alertint-mcp-token');
	const webhook = document.getElementById('alertint-webhook-url');

	const show = (cls, text) => { result.className = cls; result.textContent = text; };

	const post = async (action, fields) => {
		const body = new URLSearchParams(fields);
		body.set(CSRF_NAME, CSRF[action]);
		const r = await fetch('zabbix.php?action=' + action, {
			method: 'POST', credentials: 'same-origin', body
		});
		const text = await r.text();
		try { return JSON.parse(text); }
		catch (e) { return {ok: false, error: 'Zabbix refused the request (HTTP ' + r.status + '). Reload the page and try again.'}; }
	};

	// Suggest the webhook address from the MCP address while the field is empty.
	mcpUrl.addEventListener('input', () => {
		try {
			const u = new URL(mcpUrl.value.trim());
			webhook.placeholder = u.protocol + '//' + u.hostname + ':9911/webhook/zabbix';
		}
		catch (e) { webhook.placeholder = 'http://agent-host:9911/webhook/zabbix'; }
	});

	document.getElementById('alertint-test').addEventListener('click', async () => {
		show('ai-busy', 'Connecting to the agent…');
		const res = await post('alertint.settings.test', {mcp_url: mcpUrl.value.trim(), mcp_token: token.value.trim()});
		if (res.ok) {
			show('ai-ok', 'Connected: ' + (res.server || 'AlertINT MCP') + '. Last 24 h: '
				+ res.alerts_24h + ' alerts received, ' + res.llm_calls_24h + ' LLM calls.'
				+ ' Save to keep these settings.');
		}
		else {
			show('ai-bad', 'Not connected: ' + res.error);
		}
	});

	form.addEventListener('submit', async (e) => {
		e.preventDefault();
		show('ai-busy', 'Saving…');
		const res = await post('alertint.settings.update', {
			mcp_url: mcpUrl.value.trim(), mcp_token: token.value.trim(), webhook_url: webhook.value.trim()
		});
		if (res.ok) {
			token.value = '';
			token.placeholder = 'saved; leave empty to keep it';
			document.getElementById('alertint-token-state').textContent = res.token_set
				? 'A token is saved.' : 'No token saved yet.';
			show('ai-ok', 'Saved. AlertINT → Situations and Status now use this agent.');
		}
		else {
			show('ai-bad', 'Not saved: ' + res.error);
		}
	});
	// Connect: default the Zabbix address to the one this page is served from.
	const zabbixUrl = document.getElementById('alertint-zabbix-url');
	if (!zabbixUrl.value) {
		zabbixUrl.value = location.origin + location.pathname.replace(/\/zabbix\.php$/, '');
	}

	const connectForm = document.getElementById('alertint-connect');
	const connectResult = document.getElementById('alertint-connect-result');
	const webhookToken = document.getElementById('alertint-webhook-token');
	const connectButton = document.getElementById('alertint-connect-run');
	const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) =>
		({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));

	connectForm.addEventListener('submit', async (e) => {
		e.preventDefault();
		connectButton.disabled = true;
		connectResult.innerHTML = '<div class="ai-hint">Connecting Zabbix to AlertINT…</div>';
		const res = await post('alertint.connect.run', {
			webhook_token: webhookToken.value.trim(), zabbix_url: zabbixUrl.value.trim()
		});
		connectButton.disabled = false;
		const steps = res.steps || [{step: 'Stopped', state: 'failed', detail: res.error || 'No answer.'}];
		connectResult.innerHTML = '<table class="ai-steps">' + steps.map((s) => '<tr><td>' + esc(s.step)
			+ '</td><td><span class="ai-state ' + esc(s.state) + '">' + esc(s.state) + '</span></td><td>'
			+ esc(s.detail) + '</td></tr>').join('') + '</table>'
			+ (res.ok ? '<div class="ai-hint">Connected. Zabbix now sends every problem to AlertINT.</div>' : '');
		if (res.ok) {
			webhookToken.value = '';
			webhookToken.placeholder = 'saved in the media type; leave empty to keep it';
			connectButton.value = 'Connect again';
			raiseButton.disabled = false;
			resolveButton.disabled = false;
			if (testResult.textContent.startsWith('Press Connect first')) testResult.innerHTML = '';
		}
	});

	// Test: raise or resolve the test problem, then follow it every 3 s.
	const testForm = document.getElementById('alertint-testrun');
	const testResult = document.getElementById('alertint-test-result');
	const raiseButton = document.getElementById('alertint-test-raise');
	const resolveButton = document.getElementById('alertint-test-resolve');
	const LIMIT_MS = 5 * 60 * 1000;
	let timer = null;

	const stepsTable = (steps, note) => (note ? '<div class="ai-hint">' + esc(note) + '</div>' : '')
		+ '<table class="ai-steps">' + steps.map((s) => '<tr><td>' + esc(s.step)
		+ '</td><td><span class="ai-state ' + esc(s.state) + '">' + esc(s.state) + '</span></td><td>'
		+ esc(s.detail) + '</td></tr>').join('') + '</table>';

	const follow = (run, resolving, prefix = '') => {
		const started = Date.now();
		let eventid = run.eventid || '';
		const tick = async () => {
			const q = new URLSearchParams({action: 'alertint.test.status', triggerid: run.triggerid,
				since: run.since, eventid, resolving: resolving ? '1' : '0'});
			let res;
			try { res = await (await fetch('zabbix.php?' + q, {credentials: 'same-origin'})).json(); }
			catch (e) { res = {ok: false, error: 'Zabbix did not answer. Reload the page.'}; }
			if (!res.ok) {
				testResult.innerHTML = prefix + stepsTable([{step: 'Stopped', state: 'failed', detail: res.error}], run.note);
				return;
			}
			eventid = res.eventid || eventid;
			let tail = '';
			if (res.done && !res.stages.some((s) => s.state === 'failed')) {
				if (!resolving) {
					// The test is through: close the test problem again and follow the recovery.
					const raised = stepsTable(res.stages, run.note)
						+ '<div class="ai-hint">Done. Resolving the test problem…</div>';
					testResult.innerHTML = raised;
					runTest(0, raised);
					return;
				}
				tail = '<div class="ai-hint">Test complete: the problem is resolved in Zabbix and in AlertINT. See it in <a href="zabbix.php?action=alertint.situations">AlertINT → Situations</a>.</div>';
			}
			else if (!res.done && Date.now() - started > LIMIT_MS) {
				tail = '<div class="ai-hint">Still waiting after 5 minutes. Check AlertINT → Status and the agent log.</div>';
			}
			testResult.innerHTML = prefix + stepsTable(res.stages, run.note) + tail;
			if (!res.done && Date.now() - started <= LIMIT_MS) timer = setTimeout(tick, 3000);
		};
		tick();
	};

	const runTest = async (value, prefix = '') => {
		clearTimeout(timer);
		testResult.innerHTML = prefix + '<div class="ai-hint">' + (value ? 'Sending the test value…' : 'Resolving…') + '</div>';
		const res = await post('alertint.test.run', {value: String(value)});
		if (!res.ok) {
			testResult.innerHTML = prefix + stepsTable([{step: 'Stopped', state: 'failed', detail: res.error || 'No answer.'}]);
			return;
		}
		if (value === 0 && !res.eventid) {
			testResult.innerHTML = prefix + '<div class="ai-hint">' + esc(res.note) + '</div>';
			return;
		}
		follow(res, value === 0, prefix);
	};

	testForm.addEventListener('submit', (e) => { e.preventDefault(); runTest(1); });
	resolveButton.addEventListener('click', () => runTest(0));
})();
</script>
