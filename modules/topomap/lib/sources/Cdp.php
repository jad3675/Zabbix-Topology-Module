<?php declare(strict_types = 0);

namespace Modules\TopoMap\Lib\Sources;

/** Cisco Discovery Protocol neighbors from the "Topology CDP by SNMP" template. */
class Cdp implements Source {

	public function id(): string {
		return 'cdp';
	}

	public function priority(): int {
		return 20;
	}

	public function label(): string {
		return 'CDP neighbors';
	}

	public function items(): array {
		return ['value' => 'topo.cdp.neighbors', 'walk' => 'topo.cdp.walk'];
	}

	public function declared(): bool {
		return false;
	}

	public function observe(array $host, ?array $value): array {
		$out = [];
		foreach ($value ?? [] as $nb) {
			$sys = (string) ($nb['sys'] ?? '');
			$out[] = [
				'port' => ['type' => 'ifindex', 'value' => (string) ($nb['lp'] ?? '')],
				'nb' => [
					'cid' => '',
					'mac' => preg_match('/^SEP([0-9A-Fa-f]{12})$/', $sys, $m) ? strtolower($m[1]) : '',
					'sys' => $sys,
					'ip' => (string) ($nb['ip'] ?? ''),
					'port' => (string) ($nb['pid'] ?? ''),
					'cap' => (string) ($nb['cap'] ?? ''),
					'plat' => (string) ($nb['plat'] ?? '')
				]
			];
		}

		return $out;
	}
}
