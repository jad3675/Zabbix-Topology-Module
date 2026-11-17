/*
 * Topology dashboard widget. Loads vis-network and the shared renderer from the
 * Topology module on first use, so dashboards without this widget pay nothing.
 */
class WidgetTopoMap extends CWidget {

	onInitialize() {
		this._graph = null;
		this._view = null;
	}

	processUpdateResponse(response) {
		if (this._graph) {
			this._view = this._graph.getView();
			this._graph.destroy();
			this._graph = null;
		}

		super.processUpdateResponse(response);

		if (!response.topo) {
			return;
		}
		WidgetTopoMap.load(response.topo_assets)
			.then(() => this._render(response))
			.catch(() => {
				const el = this._body.querySelector('.topomap-widget-canvas');
				if (el) {
					el.textContent = 'The Topology module scripts could not be loaded.';
				}
			});
	}

	_render(r) {
		const el = this._body.querySelector('.topomap-widget-canvas');
		if (!el) {
			return;
		}
		this._graph = new TopoGraph(el, {
			layout: r.topo.layout,
			util: r.topo.util,
			compact: true,
			view: this._view,
			onDouble: n => {
				if (n && n.hostid && n.kind !== 'unmanaged') {
					window.open(r.topo_latest + encodeURIComponent(n.hostid), '_blank');
				}
			}
		});
		this._graph.setData(r.topo);
	}

	onResize() {
		if (this._graph) {
			this._graph.redraw();
		}
	}

	onDestroy() {
		if (this._graph) {
			this._graph.destroy();
			this._graph = null;
		}
	}

	static load(base) {
		if (window.TopoGraph) {
			return Promise.resolve();
		}
		if (!WidgetTopoMap._loading) {
			const add = src => new Promise((resolve, reject) => {
				const s = document.createElement('script');
				s.src = src;
				s.onload = resolve;
				s.onerror = reject;
				document.head.appendChild(s);
			});
			WidgetTopoMap._loading = (window.vis && window.vis.Network ? Promise.resolve() : add(base + 'vis-network.min.js'))
				.then(() => add(base + 'topomap.graph.js'));
			WidgetTopoMap._loading.catch(() => {
				WidgetTopoMap._loading = null;
			});
		}
		return WidgetTopoMap._loading;
	}
}
