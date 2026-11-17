<?php declare(strict_types = 0);

namespace Modules\TopoMap\Actions;

use API;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CProfile;
use CArrayHelper;
use Modules\TopoMap\Lib\{Config, Settings, TopologyBuilder};

class TopoView extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'groupids' =>   'array_db hosts_groups.groupid',
			'endpoints' =>  'in 0,1,2',
			'unmanaged' =>  'in 0,1',
			'util' =>       'in 0,1',
			'filter_set' => 'in 1'
		]);
		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		if ($this->hasInput('filter_set')) {
			CProfile::updateArray('web.topomap.groupids', $this->getInput('groupids', []), PROFILE_TYPE_ID);
			CProfile::update('web.topomap.endpoints', (int) $this->getInput('endpoints', 1), PROFILE_TYPE_INT);
			CProfile::update('web.topomap.unmanaged', (int) $this->getInput('unmanaged', 0), PROFILE_TYPE_INT);
			CProfile::update('web.topomap.util', (int) $this->getInput('util', 0), PROFILE_TYPE_INT);
		}

		$filter = [
			'groupids' => CProfile::getArray('web.topomap.groupids', []),
			'endpoints' => (int) CProfile::get('web.topomap.endpoints', 1),
			'unmanaged' => (int) CProfile::get('web.topomap.unmanaged', 1),
			'util' => (int) CProfile::get('web.topomap.util', 1)
		];

		$groups = $filter['groupids']
			? API::HostGroup()->get(['output' => ['groupid', 'name'], 'groupids' => $filter['groupids']])
			: [];
		CArrayHelper::sort($groups, ['name']);
		$filter['groupids'] = array_column($groups, 'groupid');

		Config::load();
		$urls_extra = $this->getUserType() == USER_TYPE_SUPER_ADMIN
			? ['settings' => 'zabbix.php?action=topomap.settings']
			: [];

		$this->setResponse(new CControllerResponseData([
			'title' => _('Topology'),
			'filter' => $filter,
			'groups_ms' => array_map(fn($g) => ['id' => $g['groupid'], 'name' => $g['name']], $groups),
			'js' => [
				'filter' => $filter,
				'version' => TopologyBuilder::VERSION,
				'util' => Settings::get('util'),
				'urls' => [
					'data' => 'zabbix.php?action=topomap.data',
					'changes' => 'zabbix.php?action=topomap.changes',
					'latest' => 'zabbix.php?action=latest.view&filter_set=1&hostids%5B%5D=',
					'problems' => 'zabbix.php?action=problem.view&filter_set=1&hostids%5B%5D='
				] + $urls_extra
			]
		]));
	}
}
