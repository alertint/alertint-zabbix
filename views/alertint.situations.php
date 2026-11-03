<?php
/**
 * AlertINT Situations page: a live feed of Situations, each with its finding,
 * the Zabbix problems it absorbed, and its journal. Rendered client-side from
 * zabbix.php?action=alertint.situations.data, refreshed every 15 s.
 *
 * @var CView $this
 */

(new CHtmlPage())
	->setTitle(_('AlertINT · Situations'))
	->addItem((new CDiv())->setId('alertint-root'))
	->show();
?>
<style>
#alertint-root { --ai-border: #dfe4e7; --ai-muted: #768d99; --ai-text: #1f2c33; --ai-bg: #fff; --ai-soft: #f4f6f7;
	--sev-0: #97aab3; --sev-1: #7499ff; --sev-2: #ffc859; --sev-3: #ffa059; --sev-4: #e97659; --sev-5: #e45959;
	color: var(--ai-text); font-size: 12px; max-width: 1280px; }
#alertint-root * { box-sizing: border-box; }
.ai-bar { display: flex; align-items: center; gap: 12px; margin: 4px 0 14px; flex-wrap: wrap; }
.ai-tile { background: var(--ai-bg); border: 1px solid var(--ai-border); padding: 10px 16px; min-width: 150px; }
.ai-tile b { display: block; font-size: 22px; font-weight: bold; line-height: 1.2; }
.ai-tile span { color: var(--ai-muted); }
.ai-collapse { font-size: 15px; padding: 10px 16px; background: var(--ai-bg); border: 1px solid var(--ai-border); }
.ai-collapse b { font-size: 18px; }
.ai-live { margin-left: auto; color: var(--ai-muted); display: flex; align-items: center; gap: 6px; }
.ai-live i { width: 8px; height: 8px; border-radius: 50%; background: #59db8f; display: inline-block; }
.ai-live.ai-stale i { background: var(--sev-4); }
.ai-error { background: #fbe2e2; border: 1px solid var(--sev-5); padding: 10px 14px; margin-bottom: 12px; }
.ai-empty { background: var(--ai-bg); border: 1px solid var(--ai-border); padding: 24px; color: var(--ai-muted); text-align: center; }
.ai-card { background: var(--ai-bg); border: 1px solid var(--ai-border); border-left: 5px solid var(--sev-0); margin-bottom: 12px; }
.ai-card.ai-recovered { opacity: .78; }
.ai-head { padding: 12px 16px 4px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; color: var(--ai-muted); }
.ai-pill { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: .03em; }
.ai-pill.active { background: #fbe2e2; color: #b33a3a; }
.ai-pill.recovered, .ai-pill.closed { background: #dff5e7; color: #2a7d4a; }
.ai-pill.recovery_pending { background: #e6f4ff; color: #1f65a6; }
.ai-pill.unverified { background: #fff3d6; color: #8a6100; }
.ai-pill.verified { background: #dff5e7; color: #2a7d4a; }
.ai-pill.pending { background: var(--ai-soft); color: var(--ai-muted); }
.ai-chip { background: var(--ai-soft); border: 1px solid var(--ai-border); padding: 1px 7px; border-radius: 3px; font-family: monospace; color: var(--ai-text); }
.ai-title { padding: 2px 16px 0; font-size: 16px; font-weight: bold; }
.ai-sub { padding: 4px 16px 0; color: var(--ai-muted); }
.ai-cause { margin: 10px 16px 0; padding: 10px 12px; background: var(--ai-soft); border-left: 3px solid #7499ff; line-height: 1.5; }
.ai-cause h4 { margin: 0 0 4px; font-size: 11px; text-transform: uppercase; color: var(--ai-muted); letter-spacing: .04em; }
.ai-next { margin: 8px 16px 0; padding: 10px 12px; background: var(--ai-soft); border-left: 3px solid #59db8f; line-height: 1.5; }
.ai-next h4 { margin: 0 0 4px; font-size: 11px; text-transform: uppercase; color: var(--ai-muted); letter-spacing: .04em; }
.ai-next h4 span { text-transform: none; letter-spacing: 0; font-weight: normal; }
.ai-conf { display: inline-flex; align-items: center; gap: 6px; }
.ai-conf .ai-meter { width: 70px; height: 6px; background: var(--ai-border); border-radius: 3px; overflow: hidden; }
.ai-conf .ai-meter i { display: block; height: 100%; background: #7499ff; }
.ai-sections { padding: 8px 16px 12px; }
.ai-sections details { border-top: 1px solid var(--ai-border); }
.ai-sections summary { cursor: pointer; padding: 8px 0; font-weight: bold; list-style: none; }
.ai-sections summary::-webkit-details-marker { display: none; }
.ai-sections summary::before { content: '▸'; display: inline-block; width: 14px; color: var(--ai-muted); }
.ai-sections details[open] > summary::before { content: '▾'; }
.ai-sections summary span { color: var(--ai-muted); font-weight: normal; }
table.ai-problems { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
table.ai-problems th { text-align: left; color: var(--ai-muted); font-weight: normal; padding: 4px 6px; border-bottom: 1px solid var(--ai-border); }
table.ai-problems td { padding: 5px 6px; border-bottom: 1px solid var(--ai-soft); vertical-align: top; }
td.ai-sev { width: 90px; font-weight: bold; text-align: center; }
.ai-status-firing { color: #d64e4e; font-weight: bold; }
.ai-status-resolved { color: #2a7d4a; font-weight: bold; }
.ai-role { color: var(--ai-muted); }
.ai-role.primary { color: var(--ai-text); font-weight: bold; }
ul.ai-reasoning { margin: 0 0 8px; padding-left: 18px; line-height: 1.5; }
ul.ai-reasoning li { margin-bottom: 4px; }
ol.ai-timeline { list-style: none; margin: 0 0 8px; padding: 0; }
ol.ai-timeline li { display: flex; gap: 10px; padding: 4px 0; border-left: 2px solid var(--ai-border); margin-left: 6px; padding-left: 12px; position: relative; }
ol.ai-timeline li::before { content: ''; position: absolute; left: -5px; top: 9px; width: 8px; height: 8px; border-radius: 50%; background: var(--ai-muted); }
ol.ai-timeline li.k-finding::before { background: #7499ff; }
ol.ai-timeline li.k-problems::before { background: var(--sev-4); }
ol.ai-timeline li.k-recovered::before { background: #59db8f; }
ol.ai-timeline time { color: var(--ai-muted); min-width: 64px; font-family: monospace; }
</style>
<script>
(function () {
	const root = document.getElementById('alertint-root');
	const FEED = 'zabbix.php?action=alertint.situations.data';
	const REFRESH_MS = 15000;
	const SEVERITIES = {'not classified': 0, 'information': 1, 'warning': 2, 'average': 3, 'high': 4, 'disaster': 5};
	const open = new Set();
	let lastOk = null;
	let firstRender = true;

	const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
	const sevRank = (s) => SEVERITIES[String(s || '').toLowerCase()] ?? 0;
	const clock = (iso) => iso ? new Date(iso).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit', second: '2-digit'}) : '';
	const ago = (iso) => {
		if (!iso) return '';
		const s = Math.max(0, Math.round((Date.now() - new Date(iso)) / 1000));
		if (s < 60) return s + 's';
		if (s < 3600) return Math.floor(s / 60) + 'm';
		return Math.floor(s / 3600) + 'h ' + Math.floor((s % 3600) / 60) + 'm';
	};
	const human = (code) => String(code || '').replace(/_/g, ' ');
	const hasFinding = (inc) => !!(inc && inc.title && (inc.status === 'analyzed' || inc.status === 'resolved'));

	function allAlerts(sit) {
		const seen = new Map();
		for (const inc of sit.incidents) for (const a of inc.alerts) seen.set(a.url || (a.host + a.trigger), a);
		return [...seen.values()];
	}

	function details(id, key, label, count, body, defaultOpen) {
		const k = id + ':' + key;
		const isOpen = open.has(k) || (firstRender && defaultOpen && open.add(k));
		return `<details data-k="${esc(k)}"${isOpen ? ' open' : ''}><summary>${esc(label)} <span>${esc(count)}</span></summary>${body}</details>`;
	}

	function problemsTable(alerts) {
		const rows = alerts
			.sort((a, b) => sevRank(b.severity) - sevRank(a.severity) || String(a.starts_at).localeCompare(String(b.starts_at)))
			.map((a) => {
				const rank = sevRank(a.severity);
				const status = a.status === 'resolved' ? 'resolved' : 'firing';
				const trigger = a.url ? `<a href="${esc(a.url)}" target="_blank" rel="noopener">${esc(a.trigger)}</a>` : esc(a.trigger);
				return `<tr>
					<td class="ai-sev" style="background: var(--sev-${rank})">${esc(a.severity)}</td>
					<td>${clock(a.starts_at)}</td>
					<td class="ai-status-${status}">${status === 'resolved' ? 'RESOLVED' : 'PROBLEM'}</td>
					<td>${esc(a.host)}</td>
					<td>${trigger}</td>
					<td>${esc(a.value)}</td>
					<td class="ai-role ${esc(a.role)}">${esc(a.role)}</td>
				</tr>`;
			}).join('');
		return `<table class="ai-problems"><thead><tr><th>Severity</th><th>Time</th><th>Status</th><th>Host</th><th>Problem</th><th>Value</th><th>Role</th></tr></thead><tbody>${rows}</tbody></table>`;
	}

	function timeline(sit) {
		const events = [];
		for (const j of sit.journal) {
			const kind = j.lifecycle === 'recovered' || j.lifecycle === 'closed' ? 'recovered' : 'state';
			events.push({at: j.at, kind, text: `${j.headline} · ${human(j.lifecycle)}, ${human(j.attention)}`});
		}
		for (const inc of sit.incidents) {
			events.push({at: inc.created_at, kind: 'problems', text: `${inc.alerts.length} Zabbix problem(s) grouped into one incident`});
			// updated_at is the finding time only while analyzed; after resolve it moves, so skip it then.
			if (hasFinding(inc) && inc.status === 'analyzed') {
				events.push({at: inc.updated_at, kind: 'finding', text: `Finding: ${inc.title}`});
			}
		}
		events.sort((a, b) => String(b.at).localeCompare(String(a.at)));
		// Collapse runs of the same entry (e.g. repeated contract refreshes) into one line with a count.
		const runs = [];
		for (const e of events) {
			const prev = runs[runs.length - 1];
			if (prev && prev.text === e.text) prev.n++;
			else runs.push({...e, n: 1});
		}
		return '<ol class="ai-timeline">' + runs.map((e) =>
			`<li class="k-${e.kind}"><time>${clock(e.at)}</time><span>${esc(e.text)}${e.n > 1 ? ` <span class="ai-role">×${e.n}</span>` : ''}</span></li>`).join('') + '</ol>';
	}

	function card(sit, all) {
		const prev = sit.previous_id ? all.find((s) => s.id === sit.previous_id) : null;
		const lineage = prev ? `<span class="ai-chip">recurs · previous ${esc(clock(prev.started_at))}</span>` : '';
		// Lead with the finding that covers the most problems (the storm), not the latest straggler.
		// An incident has a finding once triage wrote one; it keeps it after resolving.
		const analyzedIncs = sit.incidents.filter(hasFinding);
		const inc = analyzedIncs.sort((a, b) => b.alerts.length - a.alerts.length)[0] || sit.incidents[0] || null;
		const alerts = allAlerts(sit);
		const firing = alerts.filter((a) => a.status !== 'resolved').length;
		const hosts = new Set(alerts.map((a) => a.host)).size;
		const top = alerts.reduce((m, a) => Math.max(m, sevRank(a.severity)), 0);
		const recovered = sit.lifecycle !== 'active';
		const analyzed = !!inc && hasFinding(inc);
		const unverified = analyzed && /^unconfirmed/i.test(inc.root_cause || inc.summary || '');
		const conf = analyzed && inc.confidence != null ? Math.round(inc.confidence * 100) : null;

		const title = analyzed ? inc.title : 'Investigating: AlertINT is grouping problems and checking evidence…';
		const verdict = !analyzed
			? '<span class="ai-pill pending">investigating</span>'
			: (unverified ? '<span class="ai-pill unverified">unverified</span>' : '<span class="ai-pill verified">verified</span>');
		const confHtml = conf == null ? '' :
			`<span class="ai-conf">confidence <span class="ai-meter"><i style="width:${conf}%"></i></span> ${conf}%</span>`;
		// The pill already says "unverified"; drop the agent's equivalent text prefix.
		const causeText = String(inc && (inc.root_cause || inc.overall_issue) || '').replace(/^unconfirmed\s*(\([^)]*\))?\s*:\s*/i, '');
		const cause = analyzed ? `<div class="ai-cause"><h4>Likely cause</h4>${esc(causeText)}</div>` : '';
		// next_step carries its source: a runbook step is the operator's own; "general" is the model's
		// knowledge and the card says so. A check (unconfirmed cause) reads "Check first".
		const ns = analyzed && inc.next_step && inc.next_step.text ? inc.next_step : null;
		const nextStep = !ns ? '' : `<div class="ai-next"><h4>${ns.kind === 'check' ? 'Check first' : 'Next step'} <span>(${
			esc({runbook: 'from runbook', verdict: 'from operator verdict'}[ns.source] || 'general advice, not from your runbook')
		})</span></h4>${esc(String(ns.text).replace(/^check first:\s*/i, ''))}</div>`;

		const sections = [
			details(sit.id, 'problems', 'Zabbix problems', `${alerts.length} (${firing} open)`, problemsTable(alerts), !recovered),
			analyzed && inc.reasoning.length
				? details(sit.id, 'reasoning', 'How AlertINT got there', inc.reasoning.length + ' checks',
					'<ul class="ai-reasoning">' + inc.reasoning.map((r) => `<li>${esc(r)}</li>`).join('') + '</ul>', false)
				: '',
			details(sit.id, 'timeline', 'Timeline', '', timeline(sit), false),
			sit.limitations.length
				? details(sit.id, 'limits', 'What AlertINT could not check', sit.limitations.length,
					'<ul class="ai-reasoning">' + sit.limitations.map((r) => `<li>${esc(r)}</li>`).join('') + '</ul>', false)
				: ''
		].join('');

		return `<div class="ai-card${recovered ? ' ai-recovered' : ''}" style="border-left-color: var(--sev-${top})">
			<div class="ai-head">
				<span class="ai-pill ${esc(sit.lifecycle)}">${esc(human(sit.lifecycle))}</span>
				<span class="ai-chip">${esc(sit.group_key)}</span>${lineage}
				<span>${alerts.length} problems on ${hosts} host${hosts === 1 ? '' : 's'}</span>
				<span>· started ${clock(sit.started_at)} (${ago(sit.started_at)} ago)</span>
				${verdict} ${confHtml}
			</div>
			<div class="ai-title">${esc(title)}</div>
			${cause}
			${nextStep}
			<div class="ai-sections">${sections}</div>
		</div>`;
	}

	function render(data) {
		if (!data.ok) {
			root.innerHTML = `<div class="ai-error">AlertINT agent not reachable: ${esc(data.error)}</div>`;
			return;
		}
		const sits = data.situations;
		const active = sits.filter((s) => s.lifecycle === 'active');
		const problems = sits.reduce((n, s) => n + allAlerts(s).length, 0);
		const activeProblems = active.reduce((n, s) => n + allAlerts(s).filter((a) => a.status !== 'resolved').length, 0);
		const findings = sits.filter((s) => s.incidents.some(hasFinding)).length;

		const bar = `<div class="ai-bar">
			<div class="ai-collapse"><b>${activeProblems}</b> open Zabbix problems → <b>${active.length}</b> active Situation${active.length === 1 ? '' : 's'}</div>
			<div class="ai-tile"><b>${problems}</b><span>problems received</span></div>
			<div class="ai-tile"><b>${sits.length}</b><span>Situations</span></div>
			<div class="ai-tile"><b>${findings}</b><span>with a finding</span></div>
			<div class="ai-live" id="alertint-live"><i></i><span>live</span></div>
		</div>`;

		const feed = sits.length ? sits.map((s) => card(s, sits)).join('') :
			'<div class="ai-empty">No Situations yet. When Zabbix sends problems, AlertINT groups them here.</div>';
		root.innerHTML = bar + feed;
		firstRender = false;
		tick();
	}

	function tick() {
		const live = document.getElementById('alertint-live');
		if (!live) return;
		const stale = !lastOk || Date.now() - lastOk > REFRESH_MS * 3;
		live.classList.toggle('ai-stale', stale);
		live.querySelector('span').textContent = lastOk ? `live · updated ${ago(new Date(lastOk).toISOString())} ago` : 'connecting…';
	}

	root.addEventListener('toggle', (e) => {
		const k = e.target.dataset && e.target.dataset.k;
		if (!k) return;
		e.target.open ? open.add(k) : open.delete(k);
	}, true);

	async function refresh() {
		try {
			const r = await fetch(FEED, {credentials: 'same-origin'});
			const data = await r.json();
			if (data.ok) lastOk = Date.now();
			render(data);
		}
		catch (e) {
			tick();
		}
	}

	refresh();
	setInterval(refresh, REFRESH_MS);
	setInterval(tick, 1000);
})();
</script>
