/*
 * Monitoring > Topology page controller.
 */
(function (global) {
	'use strict';

	const TYPE_LABEL = {
		link_added: 'Neighbor added',
		link_removed: 'Neighbor lost',
		mac_moved: 'Device moved',
		mac_new: 'New device',
		ip_changed: 'IP changed MAC'
	};
	const SEV_NAME = ['Not classified', 'Information', 'Warning', 'Average', 'High', 'Disaster'];

	function h(tag, attrs, ...kids) {
		const el = document.createElement(tag);
		Object.entries(attrs || {}).forEach(([k, v]) => {
			if (v === null || v === undefined || v === false) {
				return;
			}
			if (k === 'class') {
				el.className = v;
			}
			else if (k.startsWith('on')) {
				el.addEventListener(k.slice(2), v);
			}
			else {
				el.setAttribute(k, v === true ? '' : v);
			}
		});
		kids.flat().forEach(c => {
			if (c === null || c === undefined || c === false) {
				return;
			}
			el.appendChild(typeof c === 'string' || typeof c === 'number' ? document.createTextNode(String(c)) : c);
		});
		return el;
	}

	function fmtMac(m) {
		return m && m.length === 12 ? m.match(/../g).join(':') : (m || '');
	}

	function ago(sec) {
		if (sec < 60) {
			return sec + 's';
		}
		if (sec < 3600) {
			return Math.round(sec / 60) + 'm';
		}
		if (sec < 86400) {
			return Math.round(sec / 3600) + 'h';
		}
		return Math.round(sec / 86400) + 'd';
	}

	function store(k, v) {
		try {
			if (v === undefined) {
				return localStorage.getItem('topomap.' + k);
			}
			localStorage.setItem('topomap.' + k, v);
		}
		catch (e) {
			return null;
		}
	}

	const page = {

		init(cfg) {
			this.cfg = cfg;
			this.f = cfg.filter;
			this.data = null;
			this.sel = null;
			this.timer = null;
			this.tab = 'map';
			this.changesHost = null;
			this.layout = store('layout') || 'layered';
			this.refreshSec = parseInt(store('refresh') || '300', 10);

			this.el = {
				root: document.getElementById('topomap-root'),
				toolbar: document.getElementById('topomap-toolbar'),
				canvas: document.getElementById('topomap-canvas'),
				legend: document.getElementById('topomap-legend'),
				status: document.getElementById('topomap-status'),
				side: document.getElementById('topomap-side'),
				mapPane: document.getElementById('topomap-map-pane'),
				changes: document.getElementById('topomap-changes-pane')
			};

			this.graph = new TopoGraph(this.el.canvas, {
				layout: this.layout,
				util: cfg.util,
				onSelect: s => this.select(s),
				onDouble: n => {
					if (n && n.hostid && n.kind !== 'unmanaged') {
						window.open(this.cfg.urls.latest + encodeURIComponent(n.hostid), '_blank');
					}
				}
			});

			this.toolbar();
			this.legend();
			this.size();
			window.addEventListener('resize', () => this.size());

			if (!this.f.groupids.length) {
				this.side(h('div', {class: 'topomap-empty'},
					h('p', null, 'Choose one or more host groups and select Apply.'),
					h('p', {class: 'topomap-muted'},
						'Hosts need the "Topology by SNMP" template. Link "Topology CDP by SNMP" to Cisco devices and "Topology ARP by SNMP" to layer 3 gateways.')
				));
			}
			this.load(false);
			this.schedule();
		},

		params(extra) {
			const p = new URLSearchParams();
			this.f.groupids.forEach(g => p.append('groupids[]', g));
			p.set('endpoints', this.f.endpoints);
			p.set('unmanaged', this.f.unmanaged);
			p.set('util', this.f.util);
			Object.entries(extra || {}).forEach(([k, v]) => p.set(k, v));
			return p.toString();
		},

		async get(url) {
			const r = await fetch(url, {credentials: 'same-origin', headers: {'Accept': 'application/json'}});
			const text = await r.text();
			let d;
			try {
				d = JSON.parse(text);
			}
			catch (e) {
				throw new Error(r.ok ? 'The server returned something other than JSON. Check that the module is enabled.' : 'HTTP ' + r.status);
			}
			if (d && d.error) {
				throw new Error(typeof d.error === 'string' ? d.error : (d.error.messages || []).join(' '));
			}
			return d;
		},

		size() {
			const top = this.el.root.getBoundingClientRect().top + window.scrollY;
			const hgt = Math.max(420, window.innerHeight - top - 24);
			this.el.root.style.height = hgt + 'px';
			this.graph.redraw();
		},

		schedule() {
			clearInterval(this.timer);
			if (this.refreshSec > 0) {
				this.timer = setInterval(() => {
					if (!document.hidden && this.tab === 'map') {
						this.load(false);
					}
				}, this.refreshSec * 1000);
			}
		},

		async load(force) {
			this.status('Loading…');
			try {
				const d = await this.get(this.cfg.urls.data + '&' + this.params({mode: 'graph', refresh: force ? 1 : 0}));
				this.data = d;
				this.graph.setData(d);
				const s = d.stats;
				const parts = [
					`${s.hosts} switches`,
					s.external ? `${s.external} outside filter` : '',
					s.unmanaged ? `${s.unmanaged} unmanaged` : '',
					`${s.links} links`,
					`${s.endpoints.toLocaleString()} endpoints`
				].filter(Boolean);
				this.status(parts.join(', ') + `. Built in ${(d.build_ms / 1000).toFixed(1)}s` +
					(d.cached ? `, cached ${ago(d.cached)} ago.` : '.'));
				if (!this.sel) {
					this.overview();
				}
				else if (this.sel.type === 'node') {
					const n = d.nodes.find(x => x.id === this.sel.id);
					if (n) {
						this.select({type: 'node', id: n.id, node: n}, true);
					}
				}
				if (!d.nodes.length && this.f.groupids.length) {
					this.side(h('div', {class: 'topomap-empty'},
						h('p', null, 'No topology data in the selected host groups.'),
						h('p', {class: 'topomap-muted'},
							'Link "Topology by SNMP" to the switches, then allow one LLDP poll interval (1 hour by default) for data to arrive.')));
				}
			}
			catch (e) {
				this.status('');
				this.side(h('div', {class: 'topomap-error'}, h('strong', null, 'Topology could not be loaded. '), e.message));
			}
		},

		status(t) {
			this.el.status.textContent = t;
		},

		side(...kids) {
			this.el.side.replaceChildren(...kids.flat().filter(k => k !== null && k !== undefined && k !== false));
		},

		// ------------------------------------------------------------ toolbar

		toolbar() {
			const seg = (name, opts, cur, fn) => h('div', {class: 'topomap-seg', role: 'group', 'aria-label': name},
				opts.map(([v, label]) => h('button', {
					type: 'button', class: 'btn-alt' + (v === cur ? ' topomap-on' : ''), 'aria-pressed': v === cur ? 'true' : 'false',
					onclick: ev => {
						ev.currentTarget.parentNode.querySelectorAll('button').forEach(b => {
							b.classList.remove('topomap-on');
							b.setAttribute('aria-pressed', 'false');
						});
						ev.currentTarget.classList.add('topomap-on');
						ev.currentTarget.setAttribute('aria-pressed', 'true');
						fn(v);
					}
				}, label)));

			const search = h('input', {type: 'text', class: 'topomap-search', placeholder: 'Find switch, MAC or IP', 'aria-label': 'Find switch, MAC or IP'});
			const results = h('div', {class: 'topomap-results', hidden: true});
			let t = null;
			search.addEventListener('input', () => {
				clearTimeout(t);
				t = setTimeout(() => this.find(search.value, results), 300);
			});
			search.addEventListener('keydown', e => {
				if (e.key === 'Escape') {
					results.hidden = true;
				}
			});
			document.addEventListener('click', e => {
				if (!results.contains(e.target) && e.target !== search) {
					results.hidden = true;
				}
			});

			const refresh = h('select', {class: 'topomap-select', 'aria-label': 'Auto refresh', onchange: e => {
				this.refreshSec = parseInt(e.target.value, 10);
				store('refresh', String(this.refreshSec));
				this.schedule();
			}}, [[0, 'Refresh off'], [60, 'Every minute'], [300, 'Every 5 minutes'], [900, 'Every 15 minutes']].map(([v, l]) =>
				h('option', {value: v, selected: v === this.refreshSec}, l)));

			this.tabs = seg('View', [['map', 'Map'], ['changes', 'Changes']], 'map', v => this.showTab(v));

			this.el.toolbar.replaceChildren(
				this.tabs,
				h('div', {class: 'topomap-search-wrap'}, search, results),
				h('div', {class: 'topomap-spacer'}),
				seg('Layout', [['layered', 'Layered'], ['organic', 'Organic']], this.layout, v => {
					this.layout = v;
					store('layout', v);
					this.graph.setLayout(v);
				}),
				h('button', {type: 'button', class: 'btn-alt', 'aria-pressed': 'false', onclick: ev => {
					const on = ev.currentTarget.classList.toggle('topomap-on');
					ev.currentTarget.setAttribute('aria-pressed', on ? 'true' : 'false');
					on ? this.graph.collapseSites() : this.graph.expandSites();
				}}, 'Group by site'),
				h('button', {type: 'button', class: 'btn-alt', onclick: () => this.graph.fit()}, 'Fit'),
				refresh,
				h('button', {type: 'button', class: 'btn-alt', title: 'Rebuild from the latest stored values', onclick: () => this.load(true)}, 'Reload'),
				h('button', {type: 'button', class: 'btn-alt', onclick: () => this.exportCsv()}, 'Export endpoints'),
				this.cfg.urls.settings ? h('a', {class: 'btn-alt topomap-btn-link', href: this.cfg.urls.settings}, 'Settings') : null
			);
		},

		legend() {
			const sw = (cls, style, label) => h('span', {class: 'topomap-key'}, h('i', {class: cls, style: style}), label);
			this.el.legend.replaceChildren(
				sw('topomap-k-box', '', 'Switch'),
				sw('topomap-k-box topomap-k-dash', '', 'Outside filter'),
				sw('topomap-k-un', '', 'Not in Zabbix'),
				sw('topomap-k-line topomap-k-dash', '', 'Seen from one side'),
				sw('topomap-k-line', 'border-color:#FFA059', this.cfg.util.warn + '% used'),
				sw('topomap-k-line', 'border-color:#E45959', this.cfg.util.high + '% used')
			);
		},

		showTab(v) {
			this.tab = v;
			this.el.mapPane.style.display = v === 'map' ? '' : 'none';
			this.el.changes.style.display = v === 'changes' ? '' : 'none';
			if (v === 'changes') {
				this.changes();
			}
			else {
				this.graph.redraw();
			}
		},

		// ------------------------------------------------------------ side panel

		overview() {
			if (!this.data) {
				return;
			}
			const d = this.data;
			const problems = d.nodes.filter(n => n.kind === 'host' && n.sev >= 0).sort((a, b) => b.sev - a.sev);
			const oneSided = d.edges.filter(e => e.one_sided);
			const kids = [h('h4', null, 'Overview')];

			if (d.warnings.length) {
				kids.push(h('ul', {class: 'topomap-warn'}, d.warnings.map(w => h('li', null, w))));
			}
			if (d.issues && d.issues.length) {
				kids.push(h('h5', null, 'Collection problems'));
				kids.push(h('ul', {class: 'topomap-list'}, d.issues.slice(0, 30).map(i => h('li', null,
					h('a', {href: '#', onclick: e => {
						e.preventDefault();
						const n = this.data.nodes.find(x => x.id === 'h' + i.hostid);
						if (n) {
							this.graph.focus(n.id);
							this.select({type: 'node', id: n.id, node: n});
						}
					}}, i.name), ' ', h('span', {class: 'topomap-muted'},
						i.source + ': ' + (i.state === 'stale' ? 'no new data for ' + ago(i.age || 0)
							: i.state === 'blocked' ? (i.error || 'not polled')
							: i.state === 'unreachable' ? 'Zabbix cannot reach this device' + (i.error ? ' (' + i.error + ')' : '')
							: (i.error || 'error')))))));
			}
			kids.push(h('p', {class: 'topomap-muted'}, 'Select a switch, link or device group for details. Double-click a switch to open its latest data.'));

			if (problems.length) {
				kids.push(h('h5', null, 'Switches with problems'));
				kids.push(h('ul', {class: 'topomap-list'}, problems.slice(0, 25).map(n =>
					h('li', null, h('a', {href: '#', onclick: e => {
						e.preventDefault();
						this.graph.focus(n.id);
						this.select({type: 'node', id: n.id, node: n});
					}}, n.label), h('span', {class: 'topomap-sev topomap-sev-' + n.sev}, SEV_NAME[n.sev])))));
			}
			if (oneSided.length) {
				kids.push(h('h5', null, 'Links seen from one side'));
				kids.push(h('p', {class: 'topomap-muted'}, 'One switch reports the other, but not the reverse. Usually LLDP or CDP is off on the far end, or it is missing the template.'));
				kids.push(h('ul', {class: 'topomap-list'}, oneSided.slice(0, 25).map(e => {
					const a = d.nodes.find(n => n.id === e.from);
					const b = d.nodes.find(n => n.id === e.to);
					return h('li', null, `${a ? a.label : e.from} and ${b ? b.label : e.to}`);
				})));
			}
			this.side(...kids);
		},

		select(s, quiet) {
			this.sel = s;
			if (!s) {
				this.overview();
				return;
			}
			if (s.type === 'edge') {
				return this.edgePanel(s.edge);
			}
			if (s.type === 'cluster') {
				return this.side(h('h4', null, 'Site group'), h('p', {class: 'topomap-muted'}, 'Double-click to expand this site.'));
			}
			const n = s.node;
			if (!n) {
				return;
			}
			if (n.kind === 'unmanaged') {
				return this.unmanagedPanel(n);
			}
			if (n.kind === 'guest') {
				return this.side(this.hostHeader(n), h('p', {class: 'topomap-muted'},
					'Found behind ' + n.via + ': its MAC was learned on the switch port facing ' + n.via +
					', and its IP from ARP matches this Zabbix host.'));
			}
			if (n.kind === 'external') {
				return this.side(this.hostHeader(n), h('p', {class: 'topomap-muted'},
					'This host is in Zabbix but outside the selected host groups. Add its group to the filter to see its ports.'));
			}
			this.hostPanel(n.hostid, n.kind === 'port' ? n.port : null, quiet);
		},

		hostHeader(n) {
			const links = [
				h('a', {href: this.cfg.urls.latest + encodeURIComponent(n.hostid), target: '_blank'}, 'Latest data'),
				h('a', {href: this.cfg.urls.problems + encodeURIComponent(n.hostid), target: '_blank'}, 'Problems')
			];
			return h('div', {class: 'topomap-head'},
				h('h4', null, n.label),
				h('div', {class: 'topomap-meta'}, [n.ip, n.site ? 'Site ' + n.site : '', n.sev >= 0 ? SEV_NAME[n.sev] : ''].filter(Boolean).join(', ')),
				h('div', {class: 'topomap-links'}, links));
		},

		async hostPanel(hostid, onlyPort, quiet) {
			const node = this.data.nodes.find(x => x.id === 'h' + hostid);
			const head = this.hostHeader(node || {label: '', hostid: hostid, sev: -1});
			if (!quiet) {
				this.side(head, h('p', {class: 'topomap-muted'}, 'Loading ports…'));
			}
			let d;
			try {
				d = await this.get(this.cfg.urls.data + '&' + this.params({mode: 'host', hostid: hostid}));
			}
			catch (e) {
				return this.side(head, h('div', {class: 'topomap-error'}, e.message));
			}
			if (this.sel && this.sel.node && this.sel.node.hostid !== String(hostid)) {
				return;
			}
			const hd = d.host;
			const STATE = {
				ok: ['topomap-st-ok', 'OK'], waiting: ['topomap-st-wait', hd.blocker ? 'Not polled' : 'Waiting for first poll'],
				error: ['topomap-st-err', 'Error'], stale: ['topomap-st-err', 'Stopped arriving'],
				off: ['topomap-st-off', 'Not collected (template not linked or items disabled)'],
				unsupported: ['topomap-st-off', 'Not supported by this device']
			};
			const srcRows = Object.entries(hd.status || {}).filter(([k, v]) => !((v.state === 'off' || v.state === 'unsupported') && v.optional))
				.map(([k, v]) => h('tr', null,
					h('td', null, v.label),
					h('td', null, h('span', {class: 'topomap-st ' + STATE[v.state][0]}), ' ', STATE[v.state][1],
						v.error ? h('div', {class: 'topomap-muted topomap-small'}, v.error) : null),
					h('td', {class: 'topomap-nowrap topomap-muted'}, v.age !== null && v.age !== undefined
						? (v.state === 'stale' ? 'last value ' : 'stored ') + ago(v.age) + ' ago' : '')));
			const notes = [];
			const stale = Object.values(hd.status || {}).filter(v => v.state === 'stale');
			if (hd.blocker) {
				notes.push(hd.blocker.text);
			}
			else if (hd.unreachable) {
				notes.push('Zabbix cannot reach this device over SNMP right now' + (hd.avail_error ? ': ' + hd.avail_error : '.') +
					' While that lasts, none of its items are polled.');
			}
			else if (stale.length) {
				notes.push('Data that used to arrive has stopped. The items show no error, which usually means the polls time out: ' +
					'check the SNMP availability of this host, and try the walk by hand with snmpbulkwalk. ' +
					'If only wide requests fail, lower "Max repetition count" on the host\'s SNMP interface.');
			}
			if (!Object.keys(hd.status || {}).length) {
				notes.push('This host has no topology template; its links come from tags or from what its neighbors report.');
			}
			else if (!hd.names && !hd.blocker) {
				notes.push('Port names are not collected yet, so some ports show interface numbers. They fill in after the next "Interfaces: Name walk" (every 6 hours by default, or use Execute now on it).');
			}
			if (hd.guessed) {
				notes.push('Until port names arrive, LLDP port numbers are assumed to be interface numbers.');
			}
			if (hd.fdb_trimmed) {
				notes.push('The MAC list was trimmed to fit the size cap; busy ports show counts only.');
			}
			const noteList = notes.length ? h('ul', {class: 'topomap-notes'}, notes.map(n => h('li', null, n))) : null;
			const facts = h('div', null,
				hd.blocker ? noteList : null,
				srcRows.length ? h('details', {class: 'topomap-sources', open: hd.blocker || Object.values(hd.status || {}).some(v => v.state === 'error' || v.state === 'stale' || v.state === 'waiting') ? true : null},
					h('summary', null, 'Data collection'),
					hd.via ? h('div', {class: 'topomap-muted topomap-small'}, 'Polled by ' + hd.via) : null,
					h('table', {class: 'topomap-src'}, h('tbody', null, srcRows))) : null,
				hd.blocker ? null : noteList);

			let ports = hd.ports.filter(p => p.nb.length || p.macs.length || p.upl || p.hidden);
			if (onlyPort !== null && onlyPort !== undefined) {
				ports = ports.filter(p => String(p.k) === String(onlyPort));
			}

			const rows = [];
			ports.forEach(p => {
				const devs = p.macs.length;
				const toggle = devs ? h('button', {type: 'button', class: 'btn-link', 'aria-expanded': 'false', onclick: ev => {
					const open = sub.hidden;
					sub.hidden = !open;
					ev.currentTarget.setAttribute('aria-expanded', open ? 'true' : 'false');
				}}, devs === 1 ? (p.macs[0].zn || p.macs[0].n || p.macs[0].ip || p.macs[0].v || fmtMac(p.macs[0].m)) : devs + ' devices') : null;
				const sp = p.speed ? (p.speed >= 1000 ? (p.speed / 1000) + 'G' : p.speed + 'M') : '';
				rows.push(h('tr', null,
					h('td', {class: 'topomap-port', 'data-name': p.name},
						p.oper ? h('span', {class: 'topomap-st ' + (p.oper === 1 ? 'topomap-st-ok' : 'topomap-st-off'),
							title: p.oper === 1 ? 'Up' : 'Down'}) : null,
						p.oper ? ' ' : null, p.name,
						sp ? h('span', {class: 'topomap-muted topomap-small'}, ' ' + sp) : null,
						p.alias ? h('div', {class: 'topomap-muted topomap-small topomap-alias'}, p.alias) : null),
					h('td', null, p.nb.length ? p.nb.join(', ')
						: (p.hidden ? h('span', {class: 'topomap-muted'}, p.hidden + ' MACs behind this host; the switch port carries more than {$TOPO.FDB.EDGE.MAX}, so only the count is stored. Raise that macro on the switch to list them.')
						: (p.upl ? h('span', {class: 'topomap-muted'}, p.upl + ' MACs, no neighbor reported') : ''))),
					h('td', null, toggle)));
				const sub = h('tr', {class: 'topomap-sub', hidden: onlyPort === null || onlyPort === undefined},
					h('td', {colspan: 3}, h('table', {class: 'topomap-macs'},
						h('tbody', null, p.macs.map(m => h('tr', null,
							h('td', {class: 'topomap-mono'}, fmtMac(m.m)),
							h('td', null, m.ip),
							h('td', null, m.zh ? h('a', {href: this.cfg.urls.latest + encodeURIComponent(m.zh), target: '_blank'}, m.zn) : null,
								m.zh ? ' ' : null,
								[m.n, m.v, m.t === 'phone' ? '(phone)' : '', m.r ? '(random MAC)' : ''].filter(Boolean).join(' '))
						))))));
				rows.push(sub);
			});

			this.side(head, facts,
				onlyPort !== null && onlyPort !== undefined ? null : h('p', null, h('a', {href: '#', onclick: e => {
					e.preventDefault();
					this.changesHost = {id: String(hostid), name: hd.name};
					this.tabs.querySelectorAll('button')[1].click();
				}}, 'Show changes on this switch')),
				ports.length
					? h('table', {class: 'list-table topomap-ports'},
						h('thead', null, h('tr', null, h('th', null, 'Port'), h('th', null, 'Neighbor'), h('th', null, 'Devices'))),
						h('tbody', null, rows))
					: h('p', {class: 'topomap-muted'}, 'No neighbors or endpoints on this switch yet.'));
		},

		unmanagedPanel(n) {
			const seen = this.data.edges.filter(e => e.from === n.id || e.to === n.id).map(e => {
				const other = this.data.nodes.find(x => x.id === (e.from === n.id ? e.to : e.from));
				return e.links.map(l => {
					const mine = e.from === n.id ? l.a : l.b;
					const theirs = e.from === n.id ? l.b : l.a;
					return `${other ? other.label : '?'} ${theirs} to ${mine} (${TopoGraph.proto(l.proto)})`;
				});
			}).flat();
			this.side(
				h('h4', null, String(n.label).split('\n')[0]),
				h('div', {class: 'topomap-meta'}, 'Not in Zabbix'),
				h('dl', {class: 'topomap-dl'},
					n.ip ? [h('dt', null, 'Management IP'), h('dd', null, n.ip)] : null,
					n.dns ? [h('dt', null, 'DNS name'), h('dd', null, n.dns)] : null,
					n.vendor ? [h('dt', null, 'Vendor'), h('dd', null, n.vendor)] : null,
					n.plat ? [h('dt', null, 'Platform'), h('dd', null, n.plat)] : null,
					n.cid ? [h('dt', null, 'Chassis ID'), h('dd', {class: 'topomap-mono'}, n.cid.length === 12 ? fmtMac(n.cid) : n.cid)] : null,
					n.cap ? [h('dt', null, 'Capabilities'), h('dd', null, n.cap.split('').map(c => ({B: 'Bridge', R: 'Router', W: 'Wireless AP', T: 'Phone', S: 'Station'}[c] || c)).join(', '))] : null),
				h('h5', null, 'Seen from'),
				h('ul', {class: 'topomap-list'}, seen.map(s => h('li', null, s))));
		},

		edgePanel(e) {
			if (!e) {
				return;
			}
			const a = this.data.nodes.find(n => n.id === e.from);
			const b = this.data.nodes.find(n => n.id === e.to);
			if (e.ep) {
				return this.select({type: 'node', id: e.to, node: b});
			}
			this.side(
				h('h4', null, `${a ? a.label : e.from} and ${b ? b.label : e.to}`),
				h('div', {class: 'topomap-meta'}, e.links.length === 1 ? '1 link' : e.links.length + ' links'),
				h('table', {class: 'list-table topomap-ports'},
					h('thead', null, h('tr', null, h('th', null, a ? a.label : ''), h('th', null, b ? b.label : ''), h('th', null, 'Source'), h('th', null, 'Used'))),
					h('tbody', null, e.links.map(l => h('tr', null,
						h('td', {class: 'topomap-port'}, l.a), h('td', {class: 'topomap-port'}, l.b),
						h('td', null, TopoGraph.proto(l.proto) + (l.one_sided ? ', one side' : '')),
						h('td', null, l.util !== null && l.util !== undefined ? l.util + '%' : ''))))));
		},

		// ------------------------------------------------------------ search

		async find(q, box) {
			q = q.trim();
			if (q.length < 2) {
				box.hidden = true;
				return;
			}
			let d;
			try {
				d = await this.get(this.cfg.urls.data + '&' + this.params({mode: 'find', q: q}));
			}
			catch (e) {
				box.replaceChildren(h('div', {class: 'topomap-error'}, e.message));
				box.hidden = false;
				return;
			}
			box.replaceChildren(d.matches.length
				? h('ul', null, d.matches.map(m => h('li', null, h('button', {type: 'button', class: 'btn-link', onclick: () => {
					box.hidden = true;
					this.graph.focus(m.node);
					const n = this.data.nodes.find(x => x.id === m.node);
					if (n) {
						this.sel = {type: 'node', id: n.id, node: n};
						if (m.port && n.hostid) {
							const pnode = this.data.nodes.find(x => x.kind === 'port' && x.hostid === n.hostid && x.label.split('\n')[0] === m.port);
							this.hostPanel(n.hostid, pnode ? pnode.port : null);
							if (!pnode) {
								setTimeout(() => this.openPort(m.port), 400);
							}
						}
						else {
							this.select(this.sel);
						}
					}
				}}, h('strong', null, m.label), ' ', h('span', {class: 'topomap-muted'}, m.what)))))
				: h('div', {class: 'topomap-muted topomap-pad'}, 'No matches.'));
			box.hidden = false;
		},

		openPort(name) {
			this.el.side.querySelectorAll('.topomap-ports tbody > tr').forEach(tr => {
				const td = tr.querySelector('.topomap-port');
				if (td && td.dataset.name === name) {
					tr.classList.add('topomap-hit');
					const btn = tr.querySelector('button');
					if (btn && btn.getAttribute('aria-expanded') === 'false') {
						btn.click();
					}
					tr.scrollIntoView({block: 'center'});
				}
			});
		},

		// ------------------------------------------------------------ changes

		async changes() {
			const period = parseInt(store('period') || '86400', 10);
			const filters = JSON.parse(store('types') || 'null') || Object.keys(TYPE_LABEL);
			const head = h('div', {class: 'topomap-changes-head'},
				h('select', {class: 'topomap-select', 'aria-label': 'Period', onchange: e => {
					store('period', e.target.value);
					this.changes();
				}}, [[3600, 'Last hour'], [21600, 'Last 6 hours'], [86400, 'Last 24 hours'], [259200, 'Last 3 days'], [604800, 'Last 7 days']]
					.map(([v, l]) => h('option', {value: v, selected: v === period}, l))),
				Object.entries(TYPE_LABEL).map(([k, l]) => h('label', {class: 'topomap-chip'},
					h('input', {type: 'checkbox', checked: filters.includes(k), onchange: e => {
						const cur = new Set(JSON.parse(store('types') || 'null') || Object.keys(TYPE_LABEL));
						e.target.checked ? cur.add(k) : cur.delete(k);
						store('types', JSON.stringify([...cur]));
						this.renderChanges();
					}}), ' ' + l, h('span', {class: 'topomap-count', 'data-type': k}))),
				this.changesHost ? h('span', {class: 'topomap-chip topomap-on'}, this.changesHost.name + ' ',
					h('button', {type: 'button', class: 'btn-link', 'aria-label': 'Show all switches', onclick: () => {
						this.changesHost = null;
						this.changes();
					}}, 'Show all')) : null);
			this.el.changes.replaceChildren(head, h('p', {class: 'topomap-muted topomap-pad'}, 'Reading history…'));

			const p = new URLSearchParams();
			this.f.groupids.forEach(g => p.append('groupids[]', g));
			if (this.changesHost) {
				p.append('hostids[]', this.changesHost.id);
			}
			p.set('period', String(period));
			try {
				this.ch = await this.get(this.cfg.urls.changes + '&' + p.toString());
			}
			catch (e) {
				this.el.changes.replaceChildren(head, h('div', {class: 'topomap-error'}, e.message));
				return;
			}
			this.chHead = head;
			this.renderChanges();
		},

		renderChanges() {
			if (!this.ch) {
				return;
			}
			const types = new Set(JSON.parse(store('types') || 'null') || Object.keys(TYPE_LABEL));
			this.chHead.querySelectorAll('.topomap-count').forEach(s => {
				const c = this.ch.counts[s.dataset.type] || 0;
				s.textContent = c ? ' ' + c : '';
			});
			const ev = this.ch.events.filter(e => types.has(e.type));
			const body = ev.length
				? h('table', {class: 'list-table topomap-ch-table'},
					h('thead', null, h('tr', null, ['Time', 'Change', 'Switch', 'Port', 'Detail'].map(t => h('th', null, t)))),
					h('tbody', null, ev.map(e => h('tr', {class: 'topomap-ch-' + e.type},
						h('td', {class: 'topomap-nowrap'}, new Date(e.clock * 1000).toLocaleString()),
						h('td', {class: 'topomap-nowrap'}, TYPE_LABEL[e.type] || e.type),
						h('td', null, h('a', {href: '#', onclick: x => {
							x.preventDefault();
							this.tabs.querySelectorAll('button')[0].click();
							const n = this.data && this.data.nodes.find(y => y.id === 'h' + e.hostid);
							if (n) {
								this.graph.focus(n.id);
								this.select({type: 'node', id: n.id, node: n});
								if (e.port) {
									setTimeout(() => this.openPort(e.port), 500);
								}
							}
						}}, e.host)),
						h('td', {class: 'topomap-port'}, e.port),
						h('td', null, e.detail)))))
				: h('p', {class: 'topomap-muted topomap-pad'}, 'No changes in this period.');
			const kids = [this.chHead, body];
			if (this.ch.warnings.length) {
				kids.splice(1, 0, h('ul', {class: 'topomap-warn'}, this.ch.warnings.map(w => h('li', null, w))));
			}
			this.el.changes.replaceChildren(...kids);
		},

		// ------------------------------------------------------------ export

		async exportCsv() {
			this.status('Preparing export…');
			let d;
			try {
				d = await this.get(this.cfg.urls.data + '&' + this.params({mode: 'endpoints'}));
			}
			catch (e) {
				this.status(e.message);
				return;
			}
			const esc = v => {
				v = String(v === null || v === undefined ? '' : v);
				return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
			};
			const lines = [['Switch', 'Site', 'Port', 'Port description', 'MAC', 'IP', 'Name', 'Vendor', 'Type', 'Random MAC']].concat(d.rows)
				.map(r => r.map(esc).join(','));
			const blob = new Blob([lines.join('\r\n')], {type: 'text/csv'});
			const a = h('a', {href: URL.createObjectURL(blob), download: 'topology-endpoints-' + new Date().toISOString().slice(0, 10) + '.csv'});
			document.body.appendChild(a);
			a.click();
			setTimeout(() => {
				URL.revokeObjectURL(a.href);
				a.remove();
			}, 1000);
			this.status(`${d.rows.length.toLocaleString()} endpoints exported.`);
		}
	};

	global.topomapPage = page;
})(window);
