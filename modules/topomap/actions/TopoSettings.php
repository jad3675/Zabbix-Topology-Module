<?php declare(strict_types = 0);

namespace Modules\TopoMap\Actions;

use CController;
use CControllerResponseData;
use Modules\TopoMap\Lib\{Config, Settings};
use Modules\TopoMap\Lib\Sources\Registry;

class TopoSettings extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return true;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function doAction(): void {
		Config::load(true);

		$sources = [];
		$off = array_flip((array) Settings::get('sources_disabled'));
		foreach (Registry::available() as $id => $s) {
			$sources[] = [
				'id' => $id, 'label' => $s->label(), 'enabled' => !isset($off[$id]),
				'item' => $s->items()['value'], 'declared' => $s->declared()
			];
		}

		$data_rows = [];
		$data_off = array_flip((array) Settings::get('data_disabled'));
		foreach (Registry::DATA as $slot => [$label, , $item, ]) {
			[$use, $lost, $template, $macros] = Registry::DATA_HELP[$slot];
			$data_rows[] = [
				'id' => $slot, 'label' => $label, 'enabled' => !isset($data_off[$slot]), 'item' => $item,
				'use' => $use, 'lost' => $lost, 'template' => $template, 'macros' => $macros
			];
		}

		$this->setResponse(new CControllerResponseData([
			'title' => _('Topology settings'),
			'settings' => Settings::all(),
			'defaults' => Settings::DEFAULTS,
			'sources' => $sources,
			'data_rows' => $data_rows,
			'heartbeat' => Settings::fmtTime((int) Settings::get('detect.heartbeat')),
			'move_window' => Settings::fmtTime((int) Settings::get('detect.move_window')),
			'proxy_offline' => Settings::fmtTime((int) Settings::get('detect.proxy_offline'))
		]));
	}
}
