<?php declare(strict_types = 0);

namespace Modules\TopoMapWidget\Actions;

use CControllerDashboardWidgetView;
use CControllerResponseData;
use Modules\TopoMap\Lib\Settings;
use Modules\TopoMap\Lib\TopologyBuilder;

class WidgetView extends CControllerDashboardWidgetView {

	protected function doAction(): void {
		$data = [
			'name' => $this->getInput('name', $this->widget->getDefaultName()),
			'user' => ['debug_mode' => $this->getDebugMode()],
			'error' => null,
			'topo' => null,
			'assets' => null,
			'latest' => 'zabbix.php?action=latest.view&filter_set=1&hostids%5B%5D='
		];

		if (!class_exists(TopologyBuilder::class)) {
			$data['error'] = _('Enable the Topology module in Administration > General > Modules.');
		}
		elseif (!$this->fields_values['groupids']) {
			$data['error'] = _('Choose host groups in the widget settings.');
		}
		else {
			try {
				$d = (new TopologyBuilder($this->fields_values['groupids'], [
					'endpoints' => (int) $this->fields_values['endpoints'],
					'unmanaged' => (bool) $this->fields_values['unmanaged'],
					'util' => true
				]))->get(false);

				if (!$d['nodes']) {
					$data['error'] = _('No topology data in the selected host groups.');
				}
				else {
					$data['topo'] = [
						'nodes' => $d['nodes'],
						'edges' => $d['edges'],
						'stats' => $d['stats'],
						'layout' => $this->fields_values['layout'] == 1 ? 'organic' : 'layered',
						'util' => Settings::get('util')
					];
					$data['assets'] = TopologyBuilder::assetUrl();
				}
			}
			catch (\Throwable $e) {
				$data['error'] = $e->getMessage();
			}
		}

		$this->setResponse(new CControllerResponseData($data));
	}
}
