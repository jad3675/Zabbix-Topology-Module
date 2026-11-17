<?php declare(strict_types = 0);

namespace Modules\TopoMap\Actions;

use CController;
use CControllerResponseFatal;
use CControllerResponseRedirect;
use CMessageHelper;
use CUrl;
use Modules\TopoMap\Lib\{Config, Settings};
use Modules\TopoMap\Lib\Sources\Registry;

class TopoSettingsSave extends CController {

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'tags' =>        'array',
			'roles' =>       'string',
			'if_keys' =>     'array',
			'util' =>        'array',
			'limits' =>      'array',
			'detect' =>      'array',
			'dns' =>         'array',
			'sources' =>     'array',
			'data' =>        'array',
			'reset' =>       'in 1'
		]);
		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function doAction(): void {
		if ($this->hasInput('reset')) {
			$data_disabled = array_values(array_diff(array_keys(Registry::DATA), array_keys($this->getInput('data', []))));

			Config::save([]);
			CMessageHelper::setSuccessTitle(_('Topology settings reset to defaults'));
		}
		else {
			$lines = fn($text) => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $text)), 'strlen'));

			$roles = [];
			foreach ($lines($this->getInput('roles', '')) as $line) {
				if (preg_match('/^(.+?)\s*[=:]\s*(\d+)$/', $line, $m)) {
					$roles[$m[1]] = (int) $m[2];
				}
			}

			$if_keys = [];
			foreach ($this->getInput('if_keys', []) as $kind => $text) {
				$if_keys[$kind] = $lines($text);
			}

			$detect = $this->getInput('detect', []);
			$def = Settings::DEFAULTS['detect'];
			$detect['heartbeat'] = Settings::parseTime($detect['heartbeat'] ?? '', $def['heartbeat']);
			$detect['move_window'] = Settings::parseTime($detect['move_window'] ?? '', $def['move_window']);
			$detect['proxy_offline'] = max(60, Settings::parseTime($detect['proxy_offline'] ?? '', $def['proxy_offline']));

			$dns = $this->getInput('dns', []);
			$dns['enabled'] = empty($dns['enabled']) ? 0 : 1;

			$enabled = array_keys($this->getInput('sources', []));
			$disabled = array_values(array_diff(array_keys(Registry::available()), $enabled));

			$data_disabled = array_values(array_diff(array_keys(Registry::DATA), array_keys($this->getInput('data', []))));

			Config::save([
				'tags' => $this->getInput('tags', []),
				'roles' => $roles,
				'if_keys' => $if_keys,
				'util' => $this->getInput('util', []),
				'limits' => $this->getInput('limits', []),
				'detect' => $detect,
				'dns' => $dns,
				'sources_disabled' => $disabled,
				'data_disabled' => $data_disabled
			]);
			CMessageHelper::setSuccessTitle(_('Topology settings saved'));
		}

		$this->setResponse(new CControllerResponseRedirect(
			(new CUrl('zabbix.php'))->setArgument('action', 'topomap.settings')
		));
	}
}
