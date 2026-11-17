<?php declare(strict_types = 0);

namespace Modules\TopoMap\Lib;

use API;
use Modules\TopoMap\Lib\Sources\Registry;

/**
 * Builds a change timeline by diffing consecutive stored values of the topology
 * items. Because the items only store on change (plus heartbeat), the history is
 * already the change log; this just reads it on demand.
 */
class ChangeLog {

	private $groupids;
	private $hostids;
	private $period;
	private $warnings = [];
	private $names = [];
	private $ifnames = [];
	private $local = [];

	public function __construct(array $groupids, int $period, array $hostids = []) {
		$this->groupids = $groupids;
		$this->hostids = $hostids;
		$this->period = $period;
		Config::load();
	}

	public function build(): array {
		$from = time() - $this->period;
		$withFdb = $this->period <= 86400 * 3;
		if (!$withFdb) {
			$this->warnings[] = _('Endpoint moves are only tracked for periods up to 3 days.');
		}

		// value item key => source, for every neighbor source that is backed by an item
		$srcKeys = [];
		foreach (Registry::sources() as $id => $src) {
			if ($src->items()['value'] !== null) {
				$srcKeys[$src->items()['value']] = $src;
			}
		}
		$data = Registry::data();
		$keys = array_keys($srcKeys);
		foreach ($withFdb ? ['local', 'ifnames', 'fdb', 'arp'] : ['local', 'ifnames'] as $slot) {
			if (isset($data[$slot])) {
				$keys[] = $data[$slot][2];
			}
		}
		$items = API::Item()->get([
			'output' => ['itemid', 'hostid', 'key_', 'lastvalue'],
			'groupids' => $this->groupids ?: null,
			'hostids' => $this->hostids ?: null,
			'filter' => ['key_' => $keys],
			'monitored' => true,
			'templated' => false
		]);

		$hostids = array_values(array_unique(array_column($items, 'hostid')));
		if ($withFdb && count($hostids) > (int) Settings::get('limits.changes_max_hosts')) {
			$this->warnings[] = sprintf(_('Endpoint moves skipped: %1$d hosts selected, limit is %2$d. Narrow the filter.'),
				count($hostids), (int) Settings::get('limits.changes_max_hosts'));
			$items = array_filter($items, fn($i) => !in_array($i['key_'], ['topo.fdb.edge', 'topo.arp']));
		}
		if (!$items) {
			return ['events' => [], 'warnings' => $this->warnings, 'counts' => []];
		}

		foreach (API::Host()->get(['output' => ['hostid', 'name'], 'hostids' => $hostids]) as $h) {
			$this->names[$h['hostid']] = $h['name'];
		}

		$byKey = [];
		foreach ($items as $it) {
			if ($it['key_'] === 'topo.ifnames') {
				$this->ifnames[$it['hostid']] = Net::json($it['lastvalue']) ?? [];
			}
			elseif ($it['key_'] === 'topo.lldp.local') {
				$this->local[$it['hostid']] = Net::json($it['lastvalue']) ?? [];
			}
			else {
				$byKey[$it['key_']][$it['itemid']] = $it;
			}
		}

		$events = [];
		$nbItems = [];
		foreach ($srcKeys as $key => $src) {
			foreach ($byKey[$key] ?? [] as $iid => $it) {
				$nbItems[$iid] = [$it['hostid'], $src];
			}
		}
		if ($nbItems) {
			$this->neighbors($this->history(array_keys($nbItems), $from), $nbItems, $from, $events);
		}
		$macIp = [];
		if (!empty($byKey['topo.arp'])) {
			$macIp = $this->arp($this->history(array_keys($byKey['topo.arp']), $from), $byKey, $from, $events);
		}
		if (!empty($byKey['topo.fdb.edge'])) {
			$this->fdb($this->history(array_keys($byKey['topo.fdb.edge']), $from), $byKey, $from, $macIp, $events);
		}

		usort($events, fn($a, $b) => $b['clock'] <=> $a['clock']);
		$counts = array_count_values(array_column($events, 'type'));
		$maxEvents = (int) Settings::get('limits.changes_max_events');
		if (count($events) > $maxEvents) {
			$this->warnings[] = sprintf(_('Showing the newest %1$d of %2$d changes.'), $maxEvents, count($events));
			$events = array_slice($events, 0, $maxEvents);
		}

		return ['events' => $events, 'counts' => $counts, 'warnings' => $this->warnings];
	}

	/** Newest-first fetch with a row cap, returned oldest-first and grouped by item. */
	private function history(array $itemids, int $from): array {
		$rows = API::History()->get([
			'history' => ITEM_VALUE_TYPE_TEXT,
			'itemids' => $itemids,
			'time_from' => $from - (int) Settings::get('detect.heartbeat') - 300,
			'output' => ['itemid', 'clock', 'value'],
			'sortfield' => 'clock',
			'sortorder' => ZBX_SORT_DOWN,
			'limit' => (int) Settings::get('limits.changes_max_rows')
		]);
		if (count($rows) >= (int) Settings::get('limits.changes_max_rows')) {
			$this->warnings[] = _('History row limit reached; the oldest part of the period is incomplete.');
		}

		return array_reverse($rows);
	}

	private function portLabel($hid, string $ifx): string {
		$n = isset($this->ifnames[$hid][$ifx]) ? Net::ifName($this->ifnames[$hid][$ifx]) : '';

		return $n !== '' ? $n : (preg_match('/^\d+$/', $ifx) ? 'ifIndex '.$ifx : $ifx);
	}

	private function lldpPort($hid, string $lp): string {
		$p = $this->local[$hid]['p'][$lp] ?? [];
		$want = [Net::port($p['id'] ?? ''), Net::port($p['d'] ?? '')];
		foreach ($this->ifnames[$hid] ?? [] as $ifx => $info) {
			$name = Net::ifName($info);
			if (Net::port($name) !== '' && in_array(Net::port($name), $want, true)) {
				return $name;
			}
		}
		if (isset($this->ifnames[$hid][$lp])) {
			return Net::ifName($this->ifnames[$hid][$lp]);
		}

		if (($p['id'] ?? '') !== '') {
			return $p['id'];
		}

		return $lp === '0' ? 'Bridge (LLDP port 0)' : 'LLDP port '.$lp;
	}

	private function ev(int $clock, string $type, $hid, string $port, string $detail): array {
		return [
			'clock' => $clock, 'type' => $type, 'hostid' => (string) $hid,
			'host' => $this->names[$hid] ?? $hid, 'port' => $port, 'detail' => $detail
		];
	}

	/** @param array $nbItems itemid => [hostid, Source] */
	private function neighbors(array $rows, array $nbItems, int $from, array &$events): void {
		$prev = [];
		foreach ($rows as $r) {
			$iid = $r['itemid'];
			[$hid, $src] = $nbItems[$iid];
			$proto = strtoupper($src->id());
			$set = [];
			foreach ($src->observe(['hostid' => (string) $hid], Net::json($r['value'])) as $o) {
				$nb = $o['nb'];
				$cap = (string) ($nb['cap'] ?? '');
				if ($cap !== '' && (strpos($cap, 'T') !== false || !preg_match('/[BRW]/', $cap))) {
					continue;
				}
				$ref = (string) ($o['port']['value'] ?? '');
				switch ($o['port']['type'] ?? 'none') {
					case 'lldp': $port = $this->lldpPort($hid, $ref); break;
					case 'ifindex': $port = $this->portLabel($hid, $ref); break;
					case 'name': $port = $ref; break;
					default: $port = '';
				}
				$who = ($nb['sys'] ?? '') !== '' ? $nb['sys'] : (($nb['ip'] ?? '') !== '' ? $nb['ip'] : (($nb['cid'] ?? '') ?: '?'));
				$set[$port."\t".$who.' '.($nb['port'] ?? '')] = true;
			}
			if (isset($prev[$iid]) && (int) $r['clock'] >= $from) {
				foreach (array_diff_key($set, $prev[$iid]) as $k => $_) {
					[$port, $what] = explode("\t", $k);
					$events[] = $this->ev((int) $r['clock'], 'link_added', $hid, $port, trim($what).' ('.$proto.')');
				}
				foreach (array_diff_key($prev[$iid], $set) as $k => $_) {
					[$port, $what] = explode("\t", $k);
					$events[] = $this->ev((int) $r['clock'], 'link_removed', $hid, $port, trim($what).' ('.$proto.')');
				}
			}
			$prev[$iid] = $set;
		}
	}

	private function arp(array $rows, array $byKey, int $from, array &$events): array {
		$prev = [];
		$latest = [];
		foreach ($rows as $r) {
			$iid = $r['itemid'];
			$hid = $byKey['topo.arp'][$iid]['hostid'];
			$cur = Net::json($r['value']) ?? [];
			unset($cur['_truncated']);
			if (isset($prev[$iid]) && (int) $r['clock'] >= $from) {
				foreach ($cur as $ip => $mac) {
					$old = $prev[$iid][$ip] ?? null;
					if ($old !== null && $old !== $mac) {
						$events[] = $this->ev((int) $r['clock'], 'ip_changed', $hid, '',
							$ip.': '.Net::fmtMac($old).' to '.Net::fmtMac($mac));
					}
				}
			}
			$prev[$iid] = $cur;
			foreach ($cur as $ip => $mac) {
				$latest[$mac] = $ip;
			}
		}

		return $latest;
	}

	private function fdb(array $rows, array $byKey, int $from, array $macIp, array &$events): void {
		$prev = [];
		$seen = [];
		$lastAdd = [];
		$lastDel = [];
		$label = function($mac) use ($macIp) {
			return Net::fmtMac($mac).(isset($macIp[$mac]) ? ' ('.$macIp[$mac].')' : '');
		};

		foreach ($rows as $r) {
			$iid = $r['itemid'];
			$hid = $byKey['topo.fdb.edge'][$iid]['hostid'];
			$clock = (int) $r['clock'];
			$cur = [];
			foreach ((Net::json($r['value'])['e'] ?? []) as $ifx => $macs) {
				foreach ($macs as $m) {
					$cur[$m] = (string) $ifx;
				}
			}
			$live = isset($prev[$iid]) && $clock >= $from;
			if (!isset($prev[$iid])) {
				foreach ($cur as $m => $_) {
					$seen[$m] = true;
				}
				$prev[$iid] = $cur;
				continue;
			}
			foreach ($cur as $m => $ifx) {
				if (($prev[$iid][$m] ?? null) === $ifx) {
					continue;
				}
				$here = [$hid, $ifx, $clock];
				if ($live) {
					$d = $lastDel[$m] ?? null;
					if (!isset($seen[$m])) {
						$events[] = $this->ev($clock, 'mac_new', $hid, $this->portLabel($hid, $ifx), $label($m));
					}
					elseif ($d && ($d[0] != $hid || $d[1] !== $ifx) && $clock - $d[2] <= (int) Settings::get('detect.move_window')) {
						$events[] = $this->ev($clock, 'mac_moved', $hid, $this->portLabel($hid, $ifx),
							$label($m).' from '.($this->names[$d[0]] ?? $d[0]).' '.$this->portLabel($d[0], $d[1]));
						unset($lastDel[$m]);
						$here[3] = true;
					}
				}
				$seen[$m] = true;
				$lastAdd[$m] = $here;
			}
			foreach ($prev[$iid] as $m => $ifx) {
				if (($cur[$m] ?? null) === $ifx) {
					continue;
				}
				$a = $lastAdd[$m] ?? null;
				if ($live && $a && empty($a[3]) && ($a[0] != $hid || $a[1] !== $ifx) && $clock - $a[2] <= (int) Settings::get('detect.move_window')) {
					$events[] = $this->ev($clock, 'mac_moved', $a[0], $this->portLabel($a[0], $a[1]),
						$label($m).' from '.($this->names[$hid] ?? $hid).' '.$this->portLabel($hid, $ifx));
					$lastAdd[$m][3] = true;
					continue;
				}
				$lastDel[$m] = [$hid, $ifx, $clock];
			}
			$prev[$iid] = $cur;
		}
	}
}
