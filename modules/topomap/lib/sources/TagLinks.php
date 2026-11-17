<?php declare(strict_types = 0);

namespace Modules\TopoMap\Lib\Sources;

use Modules\TopoMap\Lib\Settings;

/**
 * Links stated with a host tag, for gear that cannot report a neighbor: a UPS,
 * a PDU, an unmanaged switch, an old camera. Needs no items and no polling.
 *
 *   topo.link = <neighbor>                         neighbor only
 *   topo.link = <neighbor>|<my port>               and the port on this host
 *   topo.link = <neighbor>|<my port>|<their port>  both ends
 *
 * <neighbor> is a Zabbix host name, or any name for a device that is not in
 * Zabbix (it is then drawn as an unmanaged node). Repeat the tag for more links.
 * Leave <my port> empty to give only the far port: "sw1||gi7".
 */
class TagLinks implements Source {

	public function id(): string {
		return 'tag';
	}

	public function priority(): int {
		return 90;
	}

	public function label(): string {
		return 'Declared links (tag)';
	}

	public function items(): array {
		return ['value' => null, 'walk' => null];
	}

	public function declared(): bool {
		return true;
	}

	public function observe(array $host, ?array $value): array {
		$tag = (string) Settings::get('tags.link');
		$out = [];
		foreach ($host['tags'] ?? [] as $t) {
			if (($t['tag'] ?? '') !== $tag) {
				continue;
			}
			$p = array_map('trim', explode('|', (string) ($t['value'] ?? '')));
			if (($p[0] ?? '') === '') {
				continue;
			}
			$mine = $p[1] ?? '';
			$out[] = [
				'port' => $mine !== '' ? ['type' => 'name', 'value' => $mine] : ['type' => 'none', 'value' => $p[0]],
				'nb' => ['cid' => '', 'mac' => '', 'sys' => $p[0], 'ip' => '', 'port' => $p[2] ?? '', 'cap' => '', 'plat' => '']
			];
		}

		return $out;
	}
}
