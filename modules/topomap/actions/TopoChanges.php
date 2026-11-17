<?php declare(strict_types = 0);

namespace Modules\TopoMap\Actions;

use CController;
use CControllerResponseData;
use Modules\TopoMap\Lib\{Cache, ChangeLog, TopologyBuilder};
use CWebUser;

class TopoChanges extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'groupids' => 'array_db hosts_groups.groupid',
			'hostids' =>  'array_db hosts.hostid',
			'period' =>   'in 3600,21600,86400,259200,604800'
		]);
		if (!$ret) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['error' => _('Invalid request.')])]));
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$groupids = $this->getInput('groupids', []);
		$hostids = $this->getInput('hostids', []);
		$period = (int) $this->getInput('period', 86400);
		sort($groupids);
		sort($hostids);
		$key = implode('|', ['changes', TopologyBuilder::VERSION, CWebUser::$data['userid'] ?? 0,
			implode(',', $groupids), implode(',', $hostids), $period]);

		try {
			$out = Cache::get($key, 60);
			if ($out === null) {
				$out = (new ChangeLog($groupids, $period, $hostids))->build();
				Cache::set($key, $out);
			}
		}
		catch (\Throwable $e) {
			$out = ['error' => $e->getMessage()];
		}

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode($out, JSON_UNESCAPED_SLASHES)]));
	}
}
