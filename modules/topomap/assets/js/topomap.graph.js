/*
 * TopoGraph: renders the Topology module graph with vis-network.
 * Shared by the Monitoring > Topology page and the dashboard widget.
 */
(function (global) {
	'use strict';

	const SEV = ['#97AAB3', '#7499FF', '#FFC859', '#FFA059', '#E97659', '#E45959'];
	const UTIL_WARN = '#FFA059';
	const UTIL_HIGH = '#E45959';

	function luminance(rgb) {
		const m = String(rgb).match(/\d+(\.\d+)?/g);
		if (!m || m.length < 3) {
			return 1;
		}
		const a = m.length > 3 ? parseFloat(m[3]) : 1;
		if (a === 0) {
			return 1;
		}
		return (0.2126 * m[0] + 0.7152 * m[1] + 0.0722 * m[2]) / 255;
	}

	function alpha(hex, a) {
		const n = parseInt(hex.slice(1), 16);
		return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${a})`;
	}

	/** Opaque blend of a colour over the node surface, so edges never show through labels. */
	function mix(hex, base, a) {
		const parse = c => {
			if (c.startsWith('#')) {
				const n = parseInt(c.slice(1), 16);
				return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
			}
			const m = c.match(/\d+/g) || [255, 255, 255];
			return [+m[0], +m[1], +m[2]];
		};
		const f = parse(hex), b = parse(base);
		return 'rgb(' + f.map((v, i) => Math.round(v * a + b[i] * (1 - a))).join(',') + ')';
	}

	function textBlock(lines) {
		const div = document.createElement('div');
		div.className = 'topomap-tip';
		lines.filter(Boolean).forEach((l, i) => {
			const p = document.createElement(i === 0 ? 'strong' : 'div');
			p.textContent = l;
			div.appendChild(p);
		});
		return div;
	}

	function surfaceOf(el) {
		let e = el;
		while (e && e !== document.documentElement) {
			const bg = getComputedStyle(e).backgroundColor;
			if (bg && !/rgba\(0, 0, 0, 0\)|transparent/.test(bg)) {
				return bg;
			}
			e = e.parentElement;
		}
		return getComputedStyle(document.body).backgroundColor || '#ffffff';
	}

	class TopoGraph {

		constructor(container, opts = {}) {
			this.el = container;
			this.opts = Object.assign({layout: 'layered', compact: false, onSelect: null, onDouble: null}, opts);
			this.opts.util = Object.assign({warn: 50, high: 80}, this.opts.util || {});
			this.nodes = new vis.DataSet();
			this.edges = new vis.DataSet();
			this.network = null;
			this.sitesCollapsed = false;
			this.raw = {nodes: [], edges: []};
			this.theme = this._theme();
		}

		_theme() {
			const cs = getComputedStyle(this.el);
			const bg = surfaceOf(this.el);
			const dark = luminance(bg) < 0.45;
			return {
				fg: cs.color || (dark ? '#f2f2f2' : '#1f2c33'),
				bg: bg,
				dark: dark,
				font: cs.fontFamily || 'Arial, sans-serif',
				muted: dark ? '#8f9ba6' : '#6d7f89',
				line: dark ? '#5d6873' : '#a9b6bf',
				box: dark ? '#383838' : '#ffffff',
				ok: dark ? '#7a8792' : '#8fa1ab'
			};
		}

		_node(n) {
			const t = this.theme;
			const base = {
				id: n.id, label: n.label, level: n.level, site: n.site || '', kind: n.kind,
				font: {color: t.fg, face: t.font, size: this.opts.compact ? 11 : 13}
			};
			const sev = typeof n.sev === 'number' ? n.sev : -1;

			if (n.kind === 'host' || n.kind === 'external' || n.kind === 'guest') {
				const border = sev >= 0 ? SEV[sev] : t.ok;
				const fill = sev >= 0 ? mix(SEV[sev], t.box, t.dark ? 0.35 : 0.22) : t.box;
				Object.assign(base, {
					shape: 'box',
					margin: this.opts.compact ? 6 : {top: 7, right: 10, bottom: 7, left: 10},
					borderWidth: sev >= 0 ? 2 : 1,
					shapeProperties: {borderRadius: n.kind === 'guest' ? 8 : 3,
						borderDashes: (n.kind === 'external' || n.avail === 2) ? [4, 3] : (n.kind === 'guest' ? [2, 2] : false)},
					color: {
						background: fill, border: n.avail === 2 ? SEV[5] : border,
						highlight: {background: fill, border: t.fg}, hover: {background: fill, border: t.fg}
					}
				});
				if (n.kind === 'external') {
					base.font.color = t.muted;
				}
				base.title = textBlock([
					n.label,
					n.ip,
					n.kind === 'external' ? 'Outside the current filter' : '',
					n.kind === 'guest' ? 'Behind ' + n.via + ' (matched by IP)' : '',
					n.site ? 'Site: ' + n.site : '',
					n.avail === 2 ? 'SNMP unreachable' : '',
					n.stale ? 'Topology data older than 2 days' : ''
				]);
			}
			else if (n.kind === 'unmanaged') {
				const cap = n.cap || '';
				Object.assign(base, {
					shape: cap.includes('R') ? 'diamond' : cap.includes('W') ? 'triangle' : 'square',
					size: 9,
					color: {background: t.box, border: t.muted, highlight: {background: t.box, border: t.fg}},
					borderWidth: 1.5,
					shapeProperties: {borderDashes: [3, 2]},
					font: {color: t.muted, face: t.font, size: this.opts.compact ? 10 : 12}
				});
				base.title = textBlock([String(n.label).split('\n')[0], n.ip, n.dns, n.vendor, n.plat, 'Not in Zabbix',
					cap ? 'Capabilities: ' + cap : '']);
			}
			else {
				const c = n.count || 1;
				Object.assign(base, {
					shape: 'dot',
					size: Math.min(22, 5 + Math.sqrt(c) * 2.2),
					color: {background: alpha(t.dark ? '#9fb0bd' : '#768d99', 0.35), border: t.muted,
						highlight: {background: alpha('#768d99', 0.5), border: t.fg}},
					borderWidth: 1,
					font: {color: t.muted, face: t.font, size: 11}
				});
			}
			return base;
		}

		_edge(e) {
			const t = this.theme;
			const n = e.links.length;
			let color = t.line;
			if (e.util !== null && e.util !== undefined) {
				color = e.util >= this.opts.util.high ? UTIL_HIGH : e.util >= this.opts.util.warn ? UTIL_WARN : t.line;
			}
			const edge = {
				id: e.id, from: e.from, to: e.to,
				width: e.ep ? 1 : Math.min(6, 1.5 + (n - 1) * 0.9),
				color: {color: e.ep ? alpha('#768d99', 0.45) : color, highlight: t.fg, hover: t.fg},
				dashes: e.ep ? [2, 4] : (e.one_sided ? [6, 4] : false),
				label: !e.ep && n > 1 ? '×' + n : undefined,
				font: {color: t.muted, size: 10, strokeWidth: 3, strokeColor: t.bg, face: t.font},
				selectionWidth: 1.5,
				hoverWidth: 0.5
			};
			if (!e.ep) {
				edge.title = textBlock(e.links.map(l => {
					const u = l.util !== null && l.util !== undefined ? `  ${l.util}%` : '';
					return `${l.a}  to  ${l.b}  ${TopoGraph.proto(l.proto)}${u}${l.one_sided ? '  (seen from one side)' : ''}`;
				}).sort().filter((x, i) => i < 16));
			}
			return edge;
		}

		_options() {
			const big = this.raw.nodes.length > 600;
			const layered = this.opts.layout === 'layered';
			return {
				autoResize: true,
				layout: {improvedLayout: !big && !layered, randomSeed: 7, hierarchical: {enabled: false}},
				physics: layered ? {enabled: false} : {
					enabled: true, solver: 'forceAtlas2Based',
					forceAtlas2Based: {gravitationalConstant: -60, springLength: 90, springConstant: 0.06, avoidOverlap: 0.4},
					stabilization: {enabled: true, iterations: big ? 150 : 400, updateInterval: 50}
				},
				edges: {
					smooth: big ? false : (layered ? {type: 'cubicBezier', forceDirection: 'vertical', roundness: 0.2} : {type: 'continuous'})
				},
				interaction: {hover: true, tooltipDelay: 150, hideEdgesOnDrag: big, hideEdgesOnZoom: big, multiselect: false},
				nodes: {chosen: true}
			};
		}

		/**
		 * Layered placement: rows from node.level, order by site then barycenter
		 * sweeps, then x positions pulled toward neighbours without overlapping.
		 */
		_place(data) {
			const sep = this.opts.compact ? 95 : 135;
			const gap = this.opts.compact ? 14 : 22;
			const charW = this.opts.compact ? 6 : 7;
			const rows = new Map();
			const info = new Map();
			const attached = data.nodes.filter(n => n.kind === 'endpoints');
			data.nodes.forEach(n => {
				if (n.kind === 'endpoints') {
					return;
				}
				const lvl = typeof n.level === 'number' ? n.level : 0;
				const label = String(n.label || '').split('\n').reduce((a, b) => a.length > b.length ? a : b, '');
				const w = (n.kind === 'host' || n.kind === 'external' || n.kind === 'guest') ? label.length * charW + 24 : Math.max(26, label.length * (charW - 1));
				info.set(n.id, {n: n, lvl: lvl, w: w, up: [], down: [], x: 0});
				if (!rows.has(lvl)) {
					rows.set(lvl, []);
				}
				rows.get(lvl).push(n.id);
			});
			data.edges.forEach(e => {
				const a = info.get(e.from);
				const b = info.get(e.to);
				if (!a || !b) {
					return;
				}
				if (a.lvl < b.lvl) {
					b.up.push(e.from);
					a.down.push(e.to);
				}
				else if (b.lvl < a.lvl) {
					a.up.push(e.to);
					b.down.push(e.from);
				}
			});
			const levels = [...rows.keys()].sort((a, b) => a - b);
			levels.forEach(l => rows.get(l).sort((a, b) => {
				const na = info.get(a).n, nb = info.get(b).n;
				return String(na.site || '~').localeCompare(String(nb.site || '~')) || String(na.label).localeCompare(String(nb.label), undefined, {numeric: true});
			}));
			const idx = new Map();
			const reindex = () => levels.forEach(l => rows.get(l).forEach((id, i) => idx.set(id, i)));
			reindex();
			const bary = (ids, key) => {
				const v = ids.map(id => {
					const nb = info.get(id)[key];
					return [id, nb.length ? nb.reduce((s, x) => s + idx.get(x), 0) / nb.length : idx.get(id)];
				});
				v.sort((a, b) => a[1] - b[1]);
				return v.map(x => x[0]);
			};
			for (let it = 0; it < 4; it++) {
				levels.slice(1).forEach(l => { rows.set(l, bary(rows.get(l), 'up')); reindex(); });
				levels.slice(0, -1).reverse().forEach(l => { rows.set(l, bary(rows.get(l), 'down')); reindex(); });
			}

			// Consecutive nodes with the same parents form a block, laid out as a grid
			// so a distribution pair with 40 access switches does not become one huge row.
			const attachOff = this.opts.compact ? 38 : 52;
			const subSep = attached.length ? (this.opts.compact ? 88 : 112) : (this.opts.compact ? 48 : 62);
			const target = this.opts.compact ? 900 : 1500;
			const blocks = new Map();
			levels.forEach(l => {
				const list = [];
				rows.get(l).forEach(id => {
					const sig = info.get(id).up.slice().sort().join(',');
					const last = list[list.length - 1];
					if (last && last.sig === sig) {
						last.ids.push(id);
					}
					else {
						list.push({sig: sig, ids: [id], x: 0});
					}
				});
				const shape = cols => {
					let width = 0;
					list.forEach(b => {
						b.c = Math.min(cols, b.ids.length);
						b.cell = Math.max(...b.ids.map(id => info.get(id).w)) + gap;
						b.w = b.c * b.cell - gap;
						b.rows = Math.ceil(b.ids.length / b.c);
						width += b.w + gap * 2;
					});
					return width;
				};
				// Fewer columns until the row fits the target width (never below 2).
				let cols = 8;
				while (shape(cols) > target && cols > 2) {
					cols--;
				}
				list.forEach(b => {
					b.up = [...new Set(b.ids.flatMap(id => info.get(id).up))];
					b.down = [...new Set(b.ids.flatMap(id => info.get(id).down))];
				});
				blocks.set(l, list);
			});
			const setMembers = b => b.ids.forEach((id, i) => {
				info.get(id).x = b.x - b.w / 2 + b.cell * ((i % b.c) + 0.5) - gap / 2;
				info.get(id).sub = Math.floor(i / b.c);
			});
			const pack = (list, want) => {
				const xs = list.map(want);
				for (let i = 1; i < list.length; i++) {
					const min = xs[i - 1] + (list[i - 1].w + list[i].w) / 2 + gap * 2;
					if (xs[i] < min) {
						xs[i] = min;
					}
				}
				for (let i = list.length - 2; i >= 0; i--) {
					const max = xs[i + 1] - (list[i + 1].w + list[i].w) / 2 - gap * 2;
					if (xs[i] > max) {
						xs[i] = max;
					}
				}
				list.forEach((b, i) => { b.x = xs[i]; setMembers(b); });
			};
			const avg = (ids, fallback) => ids.length ? ids.reduce((sum, x) => sum + info.get(x).x, 0) / ids.length : fallback;

			levels.forEach(l => {
				let x = 0;
				pack(blocks.get(l), b => { x += b.w / 2; const r = x; x += b.w / 2 + gap * 2; return r; });
			});
			for (let it = 0; it < 3; it++) {
				levels.slice(1).forEach(l => pack(blocks.get(l), b => avg(b.up, b.x)));
				levels.slice(0, -1).reverse().forEach(l => pack(blocks.get(l), b => avg(b.down, b.x)));
			}
			levels.slice(1).forEach(l => pack(blocks.get(l), b => avg(b.up, b.x)));

			const rowY = {};
			let y = 0;
			levels.forEach(l => {
				rowY[l] = y;
				const subs = Math.max(...blocks.get(l).map(b => b.rows));
				y += sep + (subs - 1) * subSep;
			});
			const pos = {};
			info.forEach((v, id) => { pos[id] = {x: Math.round(v.x), y: rowY[v.lvl] + (v.sub || 0) * subSep}; });
			// Endpoint bubbles sit below and to the right of their switch, so they never
			// land on the edge to a child drawn straight underneath.
			attached.forEach(n => {
				const p = pos[n.parent];
				const pw = info.has(n.parent) ? info.get(n.parent).w : 80;
				if (p) {
					pos[n.id] = {x: p.x + pw / 2 + (this.opts.compact ? 22 : 30), y: p.y + attachOff * 0.7};
				}
			});
			return pos;
		}

		setData(data) {
			this.raw = data;
			const vn = data.nodes.map(n => this._node(n));
			const ve = data.edges.map(e => this._edge(e));
			const layered = this.opts.layout === 'layered';
			const place = () => {
				const pos = this._place(data);
				vn.forEach(n => {
					if (pos[n.id]) {
						n.x = pos[n.id].x;
						n.y = pos[n.id].y;
					}
				});
			};

			if (!this.network) {
				if (layered) {
					place();
				}
				this.nodes.add(vn);
				this.edges.add(ve);
				this._create();
				return;
			}
			const keepN = new Set(vn.map(n => n.id));
			const keepE = new Set(ve.map(e => e.id));
			const structural = vn.some(n => !this.nodes.get(n.id)) || this.nodes.getIds().some(id => !keepN.has(id));
			this.nodes.remove(this.nodes.getIds().filter(id => !keepN.has(id)));
			this.edges.remove(this.edges.getIds().filter(id => !keepE.has(id)));
			if (structural && layered) {
				place();
			}
			this.nodes.update(vn);
			this.edges.update(ve);
			if (this.sitesCollapsed) {
				this.collapseSites();
			}
		}

		_create() {
			this.network = new vis.Network(this.el, {nodes: this.nodes, edges: this.edges}, this._options());
			this.network.on('stabilizationIterationsDone', () => {
				this.network.setOptions({physics: {enabled: false}});
				if (!this.opts.view) {
					this.network.fit({animation: false, maxZoomLevel: 1.4});
				}
			});
			this.network.once('afterDrawing', () => {
				if (this.opts.view) {
					this.network.moveTo({position: this.opts.view.position, scale: this.opts.view.scale, animation: false});
				}
				else {
					this.network.fit({animation: false, maxZoomLevel: 1.4});
				}
			});
			this.network.on('click', p => {
				if (!this.opts.onSelect) {
					return;
				}
				const id = p.nodes[0];
				if (id && this.network.isCluster(id)) {
					this.opts.onSelect({type: 'cluster', id: id});
				}
				else if (id) {
					this.opts.onSelect({type: 'node', id: id, node: this.raw.nodes.find(n => n.id === id)});
				}
				else if (p.edges[0]) {
					const eid = p.edges[0];
					this.opts.onSelect({type: 'edge', id: eid, edge: this.raw.edges.find(e => e.id === eid)});
				}
				else {
					this.opts.onSelect(null);
				}
			});
			this.network.on('doubleClick', p => {
				const id = p.nodes[0];
				if (!id) {
					return;
				}
				if (this.network.isCluster(id)) {
					this.network.openCluster(id);
					return;
				}
				if (this.opts.onDouble) {
					this.opts.onDouble(this.raw.nodes.find(n => n.id === id));
				}
			});
		}

		setLayout(mode) {
			if (mode === this.opts.layout) {
				return;
			}
			this.opts.layout = mode;
			if (!this.network) {
				return;
			}
			this.network.destroy();
			this.network = null;
			this.nodes.clear();
			this.edges.clear();
			this.setData(this.raw);
		}

		collapseSites() {
			if (!this.network) {
				return;
			}
			this.sitesCollapsed = true;
			const sites = {};
			this.raw.nodes.forEach(n => {
				if (n.site) {
					sites[n.site] = (sites[n.site] || 0) + (n.kind === 'host' ? 1 : 0);
				}
			});
			const t = this.theme;
			Object.keys(sites).forEach(site => {
				const id = 'site:' + site;
				if (this.network.body.nodes[id] || sites[site] < 2) {
					return;
				}
				let worst = -1;
				let level = Infinity;
				this.raw.nodes.forEach(n => {
					if (n.site === site) {
						if (typeof n.sev === 'number') {
							worst = Math.max(worst, n.sev);
						}
						if (typeof n.level === 'number') {
							level = Math.min(level, n.level);
						}
					}
				});
				this.network.cluster({
					joinCondition: o => o.site === site,
					clusterNodeProperties: {
						id: id, label: site + '\n' + sites[site] + ' switches', shape: 'box', site: '',
						level: level === Infinity ? 0 : level,
						margin: 12, borderWidth: 2,
						color: {background: worst >= 0 ? mix(SEV[worst], t.box, 0.25) : t.box, border: worst >= 0 ? SEV[worst] : t.ok},
						font: {color: t.fg, face: t.font, size: 14, multi: false},
						shapeProperties: {borderRadius: 6}
					}
				});
			});
		}

		expandSites() {
			this.sitesCollapsed = false;
			if (!this.network) {
				return;
			}
			Object.keys(this.network.body.nodes).filter(id => this.network.isCluster(id)).forEach(id => this.network.openCluster(id));
		}

		focus(id) {
			if (!this.network || !this.nodes.get(id)) {
				return false;
			}
			const cluster = this.network.findNode(id);
			if (cluster.length > 1) {
				this.network.openCluster(cluster[0]);
			}
			this.network.selectNodes([id]);
			const still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
			this.network.focus(id, {scale: 1.1, animation: still ? false : {duration: 450, easingFunction: 'easeInOutQuad'}});
			return true;
		}

		getView() {
			return this.network ? {position: this.network.getViewPosition(), scale: this.network.getScale()} : null;
		}

		fit() {
			if (this.network) {
				this.network.fit({animation: {duration: 300}, maxZoomLevel: 1.4});
			}
		}

		redraw() {
			if (this.network) {
				this.network.redraw();
			}
		}

		destroy() {
			if (this.network) {
				this.network.destroy();
			}
			this.network = null;
		}
	}

	/** "lldp+cdp" to "LLDP+CDP"; declared and inferred links get plain words. */
	TopoGraph.proto = function (p) {
		return String(p || '').split('+').map(x => ({tag: 'declared', fdb: 'MAC table'}[x] || x.toUpperCase())).join('+');
	};
	TopoGraph.SEV = SEV;
	global.TopoGraph = TopoGraph;
})(window);
