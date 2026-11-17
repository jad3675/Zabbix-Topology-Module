<?php declare(strict_types = 0);

namespace Modules\TopoMap\Actions;

use CController;
use CControllerResponseData;
use Modules\TopoMap\Lib\{Net, TopologyBuilder};

/**
 * JSON endpoint. mode=graph (default) returns nodes and edges without per-port
 * MAC lists; mode=host returns one host's ports; mode=find searches names, IPs
 * and MACs; mode=endpoints returns every endpoint row for CSV export.
 * All modes read the same cached build.
 */
class TopoData extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'groupids' =>  'array_db hosts_groups.groupid',
			'endpoints' => 'in 0,1,2',
			'unmanaged' => 'in 0,1',
			'util' =>      'in 0,1',
			'refresh' =>   'in 0,1',
			'mode' =>      'in graph,host,find,endpoints',
			'hostid' =>    'db hosts.hostid',
			'q' =>         'string'
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
		try {
			$builder = new TopologyBuilder($this->getInput('groupids', []), [
				'endpoints' => (int) $this->getInput('endpoints', 1),
				'unmanaged' => (bool) $this->getInput('unmanaged', 1),
				'util' => (bool) $this->getInput('util', 1)
			]);
			$data = $builder->get((bool) $this->getInput('refresh', 0));
			$out = $this->shape($data);
		}
		catch (\Throwable $e) {
			$out = ['error' => $e->getMessage()];
		}

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode($out, JSON_UNESCAPED_SLASHES)]));
	}

	private function shape(array $d): array {
		switch ($this->getInput('mode', 'graph')) {
			case 'host':
				$hostid = (string) $this->getInput('hostid', '');
				$h = $d['hosts'][$hostid] ?? null;
				if (!$h) {
					return ['error' => _('No topology data for this host.')];
				}
				$state = TopologyBuilder::portState($hostid, $h['ports']);
				foreach ($h['ports'] as &$p) {
					$p['oper'] = $state[$p['k']]['oper'] ?? 0;
					$p['speed'] = $state[$p['k']]['speed'] ?? 0;
				}
				unset($p);

				return ['host' => $h];

			case 'find':
				return ['matches' => $this->find($d, trim($this->getInput('q', '')))];

			case 'endpoints':
				$rows = [];
				foreach ($d['hosts'] as $hid => $h) {
					foreach ($h['ports'] as $p) {
						foreach ($p['macs'] as $m) {
							$rows[] = [$h['name'], $h['site'], $p['name'], $p['alias'] ?? '', Net::fmtMac($m['m']), $m['ip'],
								($m['zn'] ?? '') !== '' ? $m['zn'] : $m['n'], $m['v'] ?? '', $m['t'], $m['r'] ? 'yes' : ''];
						}
					}
				}

				return ['rows' => $rows];

			default:
				$summary = [];
				foreach ($d['hosts'] as $hid => $h) {
					$summary[$hid] = [
						'ports' => count($h['ports']),
						'endpoints' => array_sum(array_map(fn($p) => count($p['macs']), $h['ports'])),
						'has' => $h['has'], 'sys' => $h['sys']
					];
				}

				return [
					'nodes' => $d['nodes'], 'edges' => $d['edges'], 'summary' => $summary,
					'stats' => $d['stats'], 'warnings' => $d['warnings'], 'issues' => $d['issues'] ?? [],
					'generated' => $d['generated'], 'build_ms' => $d['build_ms'], 'cached' => $d['cached'] ?? 0
				];
		}
	}

	private function find(array $d, string $q): array {
		if (mb_strlen($q) < 2) {
			return [];
		}
		$frag = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $q));
		$macish = preg_match('/^[0-9a-fA-F:.\-]+$/', $q) && strlen($frag) >= 4;
		$ipish = substr_count($q, '.') >= 2 && preg_match('/^[\d.]+$/', $q);
		$out = [];
		foreach ($d['nodes'] as $n) {
			if (in_array($n['kind'], ['endpoints', 'port'])) {
				continue;
			}
			if (mb_stripos($n['label'], $q) !== false || ($n['ip'] ?? '') === $q) {
				$out[] = ['node' => $n['id'], 'label' => $n['label'], 'what' => $n['kind']];
			}
		}
		foreach ($d['hosts'] as $hid => $h) {
			foreach ($h['ports'] as $p) {
				foreach ($p['macs'] as $m) {
					$hit = ($macish && $m['m'] !== '' && strpos($m['m'], $frag) !== false)
						|| ($ipish && $m['ip'] !== '' && strpos($m['ip'], $q) === 0)
						|| ($m['n'] !== '' && mb_stripos($m['n'], $q) !== false)
						|| (($m['zn'] ?? '') !== '' && mb_stripos($m['zn'], $q) !== false)
						|| (($m['v'] ?? '') !== '' && mb_strlen($q) >= 3 && mb_stripos($m['v'], $q) !== false);
					if ($hit) {
						$out[] = [
							'node' => 'h'.$hid, 'hostid' => (string) $hid, 'port' => $p['name'],
							'label' => $h['name'].' '.$p['name'],
							'what' => trim(Net::fmtMac($m['m']).' '.$m['ip'].' '.($m['zn'] ?? $m['n']).' '.($m['v'] ?? ''))
						];
					}
				}
			}
			if (count($out) >= 100) {
				break;
			}
		}

		return array_slice($out, 0, 100);
	}
}
