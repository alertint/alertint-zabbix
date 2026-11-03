<?php
/**
 * AlertINT Status: is the agent reachable, and what it did in the last 24 h.
 * Rendered client-side from zabbix.php?action=alertint.status.data, every 30 s.
 *
 * @var CView $this
 * @var array $data
 */

(new CHtmlPage())
	->setTitle(_('AlertINT · Status'))
	->addItem((new CDiv())->setId('alertint-status'))
	->show();
?>
<style>
#alertint-status { --ai-border: #dfe4e7; --ai-muted: #768d99; --ai-text: #1f2c33; --ai-bg: #fff; --ai-soft: #f4f6f7;
	color: var(--ai-text); font-size: 12px; max-width: 1080px; }
#alertint-status * { box-sizing: border-box; }
.ai-state { display: flex; align-items: center; gap: 10px; background: var(--ai-bg); border: 1px solid var(--ai-border);
	padding: 12px 16px; margin: 4px 0 14px; flex-wrap: wrap; }
.ai-state i { width: 10px; height: 10px; border-radius: 50%; display: inline-block; background: #59db8f; }
.ai-state.ai-down i { background: #e45959; }
.ai-state.ai-unset i { background: #97aab3; }
.ai-state b { font-size: 14px; }
.ai-state .ai-sub { color: var(--ai-muted); }
.ai-state .ai-when { margin-left: auto; color: var(--ai-muted); }
.ai-tiles { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
.ai-tile { background: var(--ai-bg); border: 1px solid var(--ai-border); padding: 10px 16px; min-width: 170px; }
.ai-tile b { display: block; font-size: 22px; line-height: 1.2; }
.ai-tile span { color: var(--ai-muted); }
.ai-panel { background: var(--ai-bg); border: 1px solid var(--ai-border); padding: 12px 16px; margin-bottom: 14px; }
.ai-panel h4 { margin: 0 0 8px; font-size: 11px; text-transform: uppercase; color: var(--ai-muted); letter-spacing: .04em; }
.ai-panel table { border-collapse: collapse; width: 100%; }
.ai-panel th { text-align: left; color: var(--ai-muted); font-weight: normal; padding: 4px 8px 4px 0; border-bottom: 1px solid var(--ai-border); }
.ai-panel td { padding: 6px 8px 6px 0; border-bottom: 1px solid var(--ai-soft); }
.ai-panel td.num, .ai-panel th.num { text-align: right; }
.ai-panel .ai-muted { color: var(--ai-muted); }
.ai-kv { display: grid; grid-template-columns: 160px 1fr; gap: 6px 12px; }
.ai-kv span:nth-child(odd) { color: var(--ai-muted); }
.ai-kv code { font-size: 12px; word-break: break-all; }
</style>
<script>
(() => {
	const FEED = 'zabbix.php?action=alertint.status.data';
	const IS_SUPER_ADMIN = <?= json_encode($data['is_super_admin']) ?>;
	const root = document.getElementById('alertint-status');

	const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) =>
		({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
	const n = (v) => Number(v || 0).toLocaleString();
	const ago = (iso) => {
		if (!iso) return '';
		const s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
		if (s < 90) return Math.round(s) + ' s ago';
		if (s < 5400) return Math.round(s / 60) + ' min ago';
		if (s < 129600) return Math.round(s / 3600) + ' h ago';
		return Math.round(s / 86400) + ' d ago';
	};
	const settingsLink = IS_SUPER_ADMIN
		? ' <a href="zabbix.php?action=alertint.settings">Open Settings</a>.'
		: ' Ask a Zabbix Super admin to open AlertINT → Settings.';

	const render = (d) => {
		const when = '<span class="ai-when">checked ' + new Date(d.fetched_at).toLocaleTimeString() + '</span>';

		if (!d.configured) {
			root.innerHTML = '<div class="ai-state ai-unset"><i></i><b>Not set up</b>'
				+ '<span class="ai-sub">No agent address saved yet.' + settingsLink + '</span>' + when + '</div>';
			return;
		}

		if (!d.ok) {
			root.innerHTML = '<div class="ai-state ai-down"><i></i><b>Agent not reachable</b>'
				+ '<span class="ai-sub">' + esc(d.error) + '.' + (IS_SUPER_ADMIN ? settingsLink : '') + '</span>' + when + '</div>'
				+ addresses(d);
			return;
		}

		const models = d.by_model || [];
		const tokens = Number(d.input_tokens || 0) + Number(d.output_tokens || 0);
		const latest = d.latest_situation;

		root.innerHTML = '<div class="ai-state"><i></i><b>Agent reachable</b>'
			+ '<span class="ai-sub">'
			+ (latest ? 'Latest Situation opened ' + esc(ago(latest.opened_at)) + ' (' + esc(latest.lifecycle) + ').'
				: 'No Situations yet. They appear when Zabbix sends its first problem.')
			+ '</span>' + when + '</div>'
			+ '<div class="ai-tiles">'
			+ tile(n(d.alerts_received), 'alerts received, 24 h')
			+ tile(n(d.incidents_analyzed), 'incidents analysed, 24 h')
			+ tile(n(d.llm_calls), 'LLM calls, 24 h')
			+ tile(n(tokens), 'LLM tokens in + out, 24 h')
			+ '</div>'
			+ '<div class="ai-panel"><h4>Model</h4>'
			+ (models.length === 0
				? '<span class="ai-muted">No analyses in the last 24 h, so no model has been used yet. The model shows here after the first one.</span>'
				: '<table><tr><th>Provider</th><th>Model</th><th class="num">Calls</th><th class="num">Input tokens</th>'
					+ '<th class="num">Output tokens</th><th class="num">Cache read</th></tr>'
					+ models.map((m) => '<tr><td>' + esc(m.provider) + '</td><td>' + esc(m.model) + '</td><td class="num">'
						+ n(m.calls) + '</td><td class="num">' + n(m.input_tokens) + '</td><td class="num">'
						+ n(m.output_tokens) + '</td><td class="num">' + n(m.cache_read_tokens) + '</td></tr>').join('')
					+ '</table>')
			+ (d.triage_exhausted > 0
				? '<p class="ai-muted">' + n(d.triage_exhausted) + ' incident(s) stopped early: the LLM budget or retries ran out.</p>'
				: '')
			+ '</div>'
			+ addresses(d);
	};

	const tile = (value, label) => '<div class="ai-tile"><b>' + value + '</b><span>' + esc(label) + '</span></div>';

	const addresses = (d) => '<div class="ai-panel"><h4>Addresses</h4><div class="ai-kv">'
		+ '<span>MCP (web frontend)</span><code>' + esc(d.mcp_url) + '</code>'
		+ '<span>Webhook (Zabbix server)</span><code>' + esc(d.webhook_url || '') + '</code>'
		+ '</div></div>';

	const load = async () => {
		try {
			const r = await fetch(FEED, {credentials: 'same-origin'});
			render(await r.json());
		}
		catch (e) {
			render({configured: true, ok: false, error: 'Zabbix did not answer the status request', fetched_at: new Date().toISOString()});
		}
	};

	load();
	setInterval(load, 30000);
})();
</script>
