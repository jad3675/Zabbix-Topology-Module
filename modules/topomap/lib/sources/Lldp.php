<?php declare(strict_types = 0);

namespace Modules\TopoMap\Lib\Sources;

use Modules\TopoMap\Lib\Net;

/** IEEE 802.1AB neighbors from the "Topology by SNMP" template. */
class Lldp implements Source {

	public function id(): string {
		return 'lldp';
	}

	public function priority(): int {
		return 10;
	}

	public function label(): string {
		return 'LLDP neighbors';
	}

	public function items(): array {
		return ['value' => 'topo.lldp.neighbors', 'walk' => 'topo.lldp.remote.walk'];
	}

	public function declared(): bool {
		return false;
	}

	public function observe(array $host, ?array $value): array {
		$out = [];
		foreach ($value ?? [] as $nb) {
			$out[] = [
				'port' => ['type' => 'lldp', 'value' => (string) ($nb['lp'] ?? '')],
				'nb' => [
					'cid' => Net::chassis($nb['cid'] ?? ''),
					'mac' => (($nb['cst'] ?? 0) == 4)
						? Net::mac($nb['cid'] ?? '')
						: ((($nb['pst'] ?? 0) == 3) ? Net::mac($nb['pid'] ?? '') : ''),
					'sys' => (string) ($nb['sys'] ?? ''),
					'ip' => (string) ($nb['ip'] ?? ''),
					'port' => self::remotePort($nb),
					'cap' => (string) ($nb['cap'] ?? ''),
					'plat' => (string) ($nb['sd'] ?? '')
				]
			];
		}

		return $out;
	}

	/** The neighbor's port as a person would write it: the name, not a MAC or a bare number. */
	private static function remotePort(array $nb): string {
		$pid = (string) ($nb['pid'] ?? '');
		$pd = (string) ($nb['pd'] ?? '');
		if (($nb['pst'] ?? 0) == 3 || (Net::mac($pid) !== '' && strlen(preg_replace('/[^0-9a-f]/i', '', $pid)) === 12)) {
			return $pd !== '' ? $pd : Net::fmtMac(Net::mac($pid));
		}
		if (preg_match('/^\d+$/', $pid) && $pd !== '') {
			return $pd;
		}

		return $pid !== '' ? $pid : $pd;
	}
}
