<?php declare(strict_types = 0);

namespace Modules\TopoMap\Lib;

use Modules\TopoMap\Lib\Sources\Registry;

/**
 * Pure correlation: turns per-host topology values into a graph.
 * No API calls in here, so it can be tested offline.
 *
 * Input host record:  [hostid, name, host, ips[], site, role, topo_ids[], external(bool), available]
 * Input values:       hostid => [local, lldp, cdp, fdb, ifnames, arp, clock]
 *
 * A link records, per end node: own (label the node uses for its port), peer (label
 * the other end reports for it), key (port key in the node's port table), ifx
 * (ifIndex when known) and seen (whether that node reported the link itself).
 */
class Correlator {

	public const EP_NONE = 0;
	public const EP_SWITCH = 1;
	public const EP_PORT = 2;

	private $hosts;
	private $vals;
	private $sev;
	private $util;
	private $opt;

	private $nodes = [];
	private $alias = [];
	private $pairs = [];
	private $linkPorts = [];
	private $ports = [];
	private $eps = [];
	private $orphans = [];
	private $through = [];
	private $portNbr = [];
	private $declared = [];
	private $idx = ['cid' => [], 'ip' => [], 'name' => []];
	/** MAC block => hostid, '' where two hosts share a block. Built on first use. */
	private $macOwner = null;
	private $portIdx = [];
	private $warnings = [];
	private $uCount = 0;

	public function __construct(array $hosts, array $vals, array $sev = [], array $util = [], array $opt = []) {
		$this->hosts = $hosts;
		$this->vals = $vals;
		$this->sev = $sev;
		$this->util = $util;
		$this->opt = $opt + ['endpoints' => self::EP_SWITCH, 'unmanaged' => true];
	}

	public function build(): array {
		$this->indexHosts();

		foreach (Registry::sources() as $id => $source) {
			if ($source->declared()) {
				$this->declared[$id] = true;
			}
			foreach ($this->hosts as $hid => $h) {
				if ($h['external']) {
					continue;
				}
				foreach ($source->observe($h, $this->vals[$hid][$id] ?? null) as $o) {
					[$key, $label, $guess] = $this->resolvePort($hid, $id, $o['port'], $o['nb']);
					$this->observe($id, $hid, $key, $label, $guess, $o['nb'] + [
						'cid' => '', 'mac' => '', 'sys' => '', 'ip' => '', 'port' => '', 'cap' => '', 'plat' => ''
					]);
				}
			}
		}

		$this->mergeSingletons();
		$this->pruneBehind();
		$this->finalizeLinks();
		$this->inferUplinks();
		$this->endpoints();
		$edges = $this->edges();
		$this->levels($edges);
		$this->orient($edges);

		return [
			'nodes' => array_values($this->nodes),
			'edges' => $edges,
			'hosts' => $this->hostDetail(),
			'warnings' => array_values(array_unique($this->warnings)),
			'stats' => [
				'hosts' => count(array_filter($this->hosts, fn($h) => !$h['external'])),
				'external' => count(array_filter($this->hosts, fn($h) => $h['external'])),
				'unmanaged' => count(array_filter($this->nodes, fn($n) => $n['kind'] === 'unmanaged')),
				'links' => array_sum(array_map(fn($e) => $e['ep'] ? 0 : count($e['links']), $edges)),
				'endpoints' => array_sum(array_map(fn($p) => array_sum(array_map(fn($x) => count($x['macs']), $p)), $this->ports))
			]
		];
	}

	// ---------------------------------------------------------------- indexing

	private function indexHosts(): void {
		foreach ($this->hosts as $hid => $h) {
			$v = $this->vals[$hid] ?? [];
			$this->nodes['h'.$hid] = [
				'id' => 'h'.$hid,
				'kind' => $h['external'] ? 'external' : 'host',
				'hostid' => (string) $hid,
				'label' => $h['name'],
				'ip' => $h['ips'][0] ?? '',
				'site' => $h['site'] ?? '',
				'role' => $h['role'] ?? '',
				'sev' => $this->sev[$hid] ?? -1,
				'avail' => $h['available'] ?? 0,
				'cap' => '',
				'gw' => !empty($v['arp']),
				'stale' => isset($v['clock']) && $v['clock'] > 0 && (time() - $v['clock']) > 2 * Settings::staleAfter()
			];

			$cid = Net::chassis($v['local']['cid'] ?? '');
			if ($cid !== '') {
				$this->idx['cid'][$cid] = $this->idx['cid'][$cid] ?? $hid;
			}
			foreach ($h['ips'] as $ip) {
				if ($ip !== '' && $ip !== '127.0.0.1') {
					$this->idx['ip'][$ip] = $this->idx['ip'][$ip] ?? $hid;
				}
			}
			foreach (array_merge([$h['host'], $h['name'], $v['local']['sys'] ?? ''], $h['topo_ids'] ?? []) as $n) {
				$n = Net::name($n);
				if ($n === '') {
					continue;
				}
				if (isset($this->idx['name'][$n]) && $this->idx['name'][$n] != $hid) {
					$this->warnings[] = sprintf(_('Name "%1$s" matches more than one host; set a topo.id tag to disambiguate.'), $n);
					continue;
				}
				$this->idx['name'][$n] = $hid;
			}

			foreach ($v['ifnames'] ?? [] as $ifx => $info) {
				$name = Net::ifName($info);
				if ($name !== '') {
					$this->portIdx[$hid][Net::port($name)] = (string) $ifx;
				}
			}
		}
	}

	private function resolve(array $nb, $self): ?string {
		if ($nb['cid'] !== '' && isset($this->idx['cid'][$nb['cid']])) {
			return (string) $this->idx['cid'][$nb['cid']];
		}
		if ($nb['ip'] !== '' && isset($this->idx['ip'][$nb['ip']]) && $this->idx['ip'][$nb['ip']] != $self) {
			return (string) $this->idx['ip'][$nb['ip']];
		}
		$n = Net::name($nb['sys']);
		if ($n !== '' && isset($this->idx['name'][$n])) {
			return (string) $this->idx['name'][$n];
		}

		// Last resort: a MAC that belongs to a known host. Some devices announce themselves by
		// MAC instead of by name (CDP device IDs on small-business switches), and some
		// neighbor tables give the far port's MAC as the chassis ID. Either way the MAC sits
		// in the block of a host we already have.
		$blk = max(6, min(12, (int) Settings::get('detect.own_block')));
		if ($this->macOwner === null) {
			$this->macOwner = [];
			[, $ipMac] = $this->arpMaps();
			foreach ($this->hosts as $hid => $h) {
				$own = $this->ownIds($hid);
				foreach ($h['ips'] ?? [] as $ip) {
					if (isset($ipMac[$ip])) {
						$own[] = $ipMac[$ip];
					}
				}
				foreach ($own as $c) {
					if (Net::mac($c) === $c) {
						$b = substr($c, 0, $blk);
						$this->macOwner[$b] = isset($this->macOwner[$b]) && $this->macOwner[$b] !== (string) $hid ? '' : (string) $hid;
					}
				}
			}
		}
		$sysMac = preg_match('/^[0-9a-f]{2}([:.\-]?[0-9a-f]{2}){5}$/i', (string) $nb['sys']) ? Net::mac((string) $nb['sys']) : '';
		foreach ([$nb['cid'], $nb['mac'] ?? '', $sysMac] as $m) {
			if ($m === '' || Net::mac($m) !== $m) {
				continue;
			}
			$owner = $this->macOwner[substr($m, 0, $blk)] ?? '';
			if ($owner !== '' && $owner != $self) {
				return $owner;
			}
		}

		return null;
	}

	// ---------------------------------------------------------------- ports

	private function portName($hid, string $ifx): string {
		$n = isset($this->vals[$hid]['ifnames'][$ifx]) ? Net::ifName($this->vals[$hid]['ifnames'][$ifx]) : '';
		if ($n !== '') {
			return $n;
		}

		return preg_match('/^\d+$/', $ifx) ? 'ifIndex '.$ifx : $ifx;
	}

	/**
	 * LLDP local port number to [key, label, guessed]. When port names are not
	 * collected yet, a numeric LLDP port is taken as the ifIndex (true on most
	 * gear) so LLDP and CDP sightings of the same port land on one row.
	 */
	private function lldpLocalPort($hid, string $lp): array {
		$p = $this->vals[$hid]['local']['p'][$lp] ?? [];
		foreach ([$p['id'] ?? '', $p['d'] ?? ''] as $cand) {
			$k = Net::port($cand);
			if ($k !== '' && isset($this->portIdx[$hid][$k])) {
				$ifx = $this->portIdx[$hid][$k];

				return [$ifx, $this->portName($hid, $ifx), false];
			}
		}
		if (isset($this->vals[$hid]['ifnames'][$lp])) {
			return [$lp, $this->portName($hid, $lp), false];
		}

		if (($p['id'] ?? '') !== '' && Net::mac($p['id']) === '') {
			$label = $p['id'];
		}
		elseif (($p['d'] ?? '') !== '') {
			$label = $p['d'];
		}
		else {
			$label = $lp === '0' ? 'Bridge (LLDP port 0)' : 'LLDP port '.$lp;
		}

		if ($lp !== '0' && preg_match('/^\d+$/', $lp) && empty($this->vals[$hid]['ifnames'])) {
			return [$lp, Net::isPlaceholder($label) ? 'ifIndex '.$lp : $label, true];
		}

		return ['lp'.$lp, $label, false];
	}

	/** A source's port reference to [port key, label, guessed]. */
	private function resolvePort($hid, string $proto, array $ref, array $nb): array {
		$value = (string) ($ref['value'] ?? '');
		switch ($ref['type'] ?? 'none') {
			case 'lldp':
				return $this->lldpLocalPort($hid, $value);

			case 'ifindex':
				return [$value, $this->portName($hid, $value), false];

			case 'name':
				$ifx = $this->portIdx[$hid][Net::port($value)] ?? null;

				return $ifx !== null ? [$ifx, $this->portName($hid, $ifx), false] : ['n:'.Net::port($value), $value, false];
		}

		return ['x:'.$proto.':'.Net::name($nb['sys'] ?? '').($nb['ip'] ?? ''), '', false];
	}

	private function &port($hid, string $key, ?string $label = null, bool $guess = false): array {
		if (!isset($this->ports[$hid][$key])) {
			$info = isset($this->vals[$hid]['ifnames'][$key]) ? Net::ifInfo($this->vals[$hid]['ifnames'][$key]) : ['', '', 0, 0];
			$this->ports[$hid][$key] = [
				'k' => $key, 'name' => $label ?? $this->portName($hid, $key), 'alias' => $info[1],
				'nb' => [], 'macs' => [], 'upl' => 0, 'guess' => $guess
			];
		}
		elseif ($label !== null && $key !== 'lp0' && Net::isPlaceholder($this->ports[$hid][$key]['name'])
				&& !Net::isPlaceholder($label)) {
			// The bridge pseudo-port keeps its name: a neighbor's label for it only
			// describes one member port.
			$this->ports[$hid][$key]['name'] = $label;
		}

		return $this->ports[$hid][$key];
	}

	// ---------------------------------------------------------------- neighbors

	private function observe(string $proto, $hid, string $key, string $label, bool $guess, array $nb): void {
		$cap = $nb['cap'];
		$isEndpoint = $cap !== '' && (strpos($cap, 'T') !== false || !preg_match('/[BRW]/', $cap));
		$target = $this->resolve($nb, $hid);

		if ($isEndpoint && $target === null) {
			$this->port($hid, $key, $label, $guess);
			$this->eps[$hid][$key][] = [
				'm' => $nb['mac'], 'n' => $nb['sys'] !== '' ? $nb['sys'] : $nb['plat'], 'ip' => $nb['ip'],
				't' => strpos($cap, 'T') !== false ? 'phone' : 'station'
			];

			return;
		}
		if ($target !== null && $target == $hid) {
			return;
		}

		$this->port($hid, $key, $label, $guess);
		$this->linkPorts[$hid][$key] = true;

		if ($target !== null) {
			$tnode = 'h'.$target;
			foreach (str_split($cap) as $c) {
				if ($c !== '' && strpos($this->nodes[$tnode]['cap'], $c) === false) {
					$this->nodes[$tnode]['cap'] .= $c;
				}
			}
		}
		else {
			$tnode = $this->unmanaged($nb);
		}

		if ($tnode === null) {
			$who = $nb['sys'] !== '' ? $nb['sys'] : ($nb['ip'] !== '' ? $nb['ip'] : $nb['cid']);
			$this->orphans[$hid][$key][] = trim($who.' '.$nb['port']).' ('.strtoupper($proto).')';

			return;
		}
		$this->addLink('h'.$hid, $label, $key, preg_match('/^\d+$/', $key) ? $key : null, $tnode, $nb['port'], $proto);
	}

	private function unmanaged(array $nb): ?string {
		$keys = [];
		if ($nb['cid'] !== '') {
			$keys[] = 'c:'.$nb['cid'];
		}
		if ($nb['ip'] !== '') {
			$keys[] = 'i:'.$nb['ip'];
		}
		if (($n = Net::name($nb['sys'])) !== '') {
			$keys[] = 'n:'.$n;
		}
		if (!$keys) {
			return null;
		}

		$id = null;
		foreach ($keys as $k) {
			if (isset($this->alias[$k])) {
				$id = $this->alias[$k];
				break;
			}
		}
		if ($id === null) {
			if (!$this->opt['unmanaged']) {
				return null;
			}
			$id = 'u'.(++$this->uCount);
			$this->nodes[$id] = [
				'id' => $id, 'kind' => 'unmanaged', 'label' => '', 'sys' => $nb['sys'],
				'ip' => $nb['ip'], 'cap' => $nb['cap'], 'plat' => $nb['plat'], 'cid' => $nb['cid'],
				'mac' => $nb['mac'] !== '' ? $nb['mac'] : (strlen($nb['cid']) === 12 && Net::mac($nb['cid']) !== '' ? $nb['cid'] : ''),
				'vendor' => '', 'sev' => -1, 'site' => ''
			];
		}
		foreach ($keys as $k) {
			$this->alias[$k] = $id;
		}
		$node = &$this->nodes[$id];
		foreach (['ip', 'cap', 'plat', 'cid', 'sys'] as $f) {
			if (($node[$f] ?? '') === '' && $nb[$f] !== '') {
				$node[$f] = $nb[$f];
			}
		}
		$this->labelUnmanaged($node);

		return $id;
	}

	/** Name unmanaged nodes: real sysName first, otherwise vendor plus IP. */
	private function labelUnmanaged(array &$node): void {
		$sys = trim(preg_replace('/\(.*\)$/', '', (string) $node['sys']));
		if ($node['vendor'] === '' && $node['mac'] !== '') {
			$node['vendor'] = Net::vendor($node['mac']);
		}
		if ($sys !== '' && !filter_var($sys, FILTER_VALIDATE_IP) && Net::mac($sys) !== $sys) {
			$node['label'] = $sys;
		}
		else {
			$ip = $node['ip'] !== '' ? $node['ip'] : $sys;
			// A short LLDP system description is usually the product name ("Velop").
			$plat = trim((string) $node['plat']);
			$what = ($plat !== '' && mb_strlen($plat) <= (int) Settings::get('detect.product_label_max')) ? $plat : $node['vendor'];
			if ($what !== $node['vendor'] && $node['vendor'] !== '' && stripos($what, $node['vendor']) === false) {
				$what = $node['vendor'].' '.$what;
			}
			$node['label'] = trim($what.($ip !== '' ? ($what !== '' ? "\n" : '').$ip : ''));
			if ($node['label'] === '') {
				$node['label'] = $node['cid'] !== '' ? Net::fmtMac($node['cid']) : '?';
			}
		}
	}

	private static function sideMatch(array $l, string $x, string $label, ?string $ifx): bool {
		if ($ifx !== null && isset($l['ifx'][$x]) && $l['ifx'][$x] === $ifx) {
			return true;
		}
		$n = Net::port($label);
		if ($n === '') {
			return false;
		}
		foreach ([$l['own'][$x] ?? null, $l['peer'][$x] ?? null] as $c) {
			if ($c !== null && Net::port($c) === $n) {
				return true;
			}
		}

		return false;
	}

	private static function better(?string $cur, ?string $new): ?string {
		if ($new === null || $new === '') {
			return $cur;
		}
		if ($cur === null || $cur === '' || (Net::isPlaceholder($cur) && !Net::isPlaceholder($new))) {
			return $new;
		}

		return $cur;
	}

	private function addLink(string $a, string $aLabel, string $aKey, ?string $aIfx, string $b, string $bLabel, string $proto): void {
		$pk = strcmp($a, $b) < 0 ? $a.'|'.$b : $b.'|'.$a;

		foreach ($this->pairs[$pk] ?? [] as $i => $l) {
			if (self::sideMatch($l, $a, $aLabel, $aIfx) || ($bLabel !== '' && self::sideMatch($l, $b, $bLabel, null))) {
				$l = &$this->pairs[$pk][$i];
				$l['own'][$a] = self::better($l['own'][$a] ?? null, $aLabel);
				$l['key'][$a] = $l['key'][$a] ?? $aKey;
				if ($aIfx !== null) {
					$l['ifx'][$a] = $l['ifx'][$a] ?? $aIfx;
				}
				$l['peer'][$b] = self::better($l['peer'][$b] ?? null, $bLabel);
				$l['seen'][$a] = 1;
				$l['proto'][$proto] = 1;

				return;
			}
		}

		$this->pairs[$pk][] = [
			'own' => [$a => $aLabel], 'peer' => $bLabel !== '' ? [$b => $bLabel] : [], 'key' => [$a => $aKey],
			'ifx' => $aIfx !== null ? [$a => $aIfx] : [], 'seen' => [$a => 1], 'proto' => [$proto => 1]
		];
	}

	/**
	 * When each end reports exactly one link the other end has not confirmed,
	 * they are the same cable seen from both sides even if the names differ
	 * (ifIndex on one side, "gi14" on the other).
	 */
	private function mergeSingletons(): void {
		foreach ($this->pairs as $pk => $links) {
			[$a, $b] = explode('|', $pk);
			$onlyA = [];
			$onlyB = [];
			foreach ($links as $i => $l) {
				if (isset($l['seen'][$a]) && !isset($l['seen'][$b])) {
					$onlyA[] = $i;
				}
				elseif (isset($l['seen'][$b]) && !isset($l['seen'][$a])) {
					$onlyB[] = $i;
				}
			}
			if (count($onlyA) !== 1 || count($onlyB) !== 1) {
				continue;
			}
			$x = $links[$onlyA[0]];
			$y = $links[$onlyB[0]];
			foreach (['own', 'peer'] as $f) {
				foreach ($y[$f] as $n => $v) {
					$x[$f][$n] = self::better($x[$f][$n] ?? null, $v);
				}
			}
			foreach (['key', 'ifx', 'seen', 'proto'] as $f) {
				$x[$f] = $x[$f] + $y[$f];
			}
			$links[$onlyA[0]] = $x;
			unset($links[$onlyB[0]]);
			$this->pairs[$pk] = array_values($links);
		}
	}

	/**
	 * If H1 sees N and H2 on the same port, H2 also sees N, and H2 reaches N on a
	 * different port than it reaches H1, then N is behind H2 and H1's sighting is
	 * second-hand (LLDP leaking through a bridge). Drop that sighting. The port
	 * table keeps it as "seen through H2".
	 */
	private function pruneBehind(): void {
		$onPort = [];
		$hostPort = [];
		foreach ($this->pairs as $pk => $links) {
			[$a, $b] = explode('|', $pk);
			foreach ($links as $i => $l) {
				foreach ([$a => $b, $b => $a] as $h => $other) {
					if (isset($l['key'][$h])) {
						$onPort[$h][$l['key'][$h]][$other][] = [$pk, $i];
						$hostPort[$h][$other][] = $l['key'][$h];
					}
				}
			}
		}

		$drop = [];
		foreach ($onPort as $h1 => $ports) {
			if (($this->nodes[$h1]['kind'] ?? '') !== 'host') {
				continue;
			}
			foreach ($ports as $key => $others) {
				if (count($others) < 2) {
					continue;
				}
				foreach ($others as $n => $refs) {
					foreach (array_keys($others) as $h2) {
						if ($h2 === $n || ($this->nodes[$h2]['kind'] ?? '') !== 'host' || empty($hostPort[$h2][$n])) {
							continue;
						}
						// H2 sees N on the same port it sees H1: a shared segment. Only settle
						// it when H2's port is a bridge pseudo-port (MikroTik LLDP port 0),
						// which says nothing about direction, and H1's port is physical.
						$common = array_intersect($hostPort[$h2][$n], $hostPort[$h2][$h1] ?? []);
						if ($common && (array_diff($common, ['lp0']) || $key === 'lp0')) {
							continue;
						}
						foreach ($refs as [$pk, $i]) {
							$l = $this->pairs[$pk][$i];
							if (isset($l['seen'][$n])) {
								continue;
							}
							$drop[$pk][$i] = true;
							$this->through[substr($h1, 1)][$key][] = [$n, $l, $h2];
						}
						break;
					}
				}
			}
		}
		foreach ($drop as $pk => $idx) {
			$this->pairs[$pk] = array_values(array_diff_key($this->pairs[$pk], $idx));
			if (!$this->pairs[$pk]) {
				unset($this->pairs[$pk]);
			}
		}
	}

	private function linkLabel(array $l, string $node): string {
		$own = $l['own'][$node] ?? null;
		$peer = $l['peer'][$node] ?? null;
		$best = self::better($own, $peer);

		return $best ?? '?';
	}

	/** "LLDP+CDP"; links stated by a person read "declared". */
	private function protoLabel(array $proto): string {
		return implode('+', array_map(
			fn($p) => isset($this->declared[$p]) ? 'declared' : strtoupper($p), array_keys($proto)
		));
	}

	/** Port names from the far end, neighbor text, reverse-reported ports. */
	private function finalizeLinks(): void {
		foreach ($this->pairs as $pk => $links) {
			[$a, $b] = explode('|', $pk);
			foreach ($links as $l) {
				foreach ([$a => $b, $b => $a] as $h => $other) {
					if (($this->nodes[$h]['kind'] ?? '') !== 'host') {
						continue;
					}
					$hid = substr($h, 1);
					$isDeclared = (bool) array_intersect_key($l['proto'], $this->declared);
					$protos = $this->protoLabel($l['proto']);
					$text = trim($this->nodes[$other]['label'].' '.$this->linkLabel($l, $other));
					$text = str_replace("\n", ' ', $text);
					if (isset($l['key'][$h])) {
						$this->portNbr[$hid][$l['key'][$h]][$other] = true;
						$p = &$this->port($hid, $l['key'][$h], $this->linkLabel($l, $h));
						$p['nb'][] = $text.' ('.$protos.')';
						$p['nb'] = array_values(array_unique($p['nb']));
						unset($p);
						continue;
					}
					$label = $this->linkLabel($l, $h);
					// The far end names our port; with our port names known, that is a
					// real ifIndex, so the port is an uplink even without our own LLDP.
					$ifx = $this->portIdx[$hid][Net::port($label)] ?? null;
					$key = $ifx ?? 'r:'.$label;
					if ($ifx === null) {
						foreach ($this->ports[$hid] ?? [] as $k => $p) {
							if (Net::port($p['name']) === Net::port($label)) {
								$key = $k;
								break;
							}
						}
					}
					$p = &$this->port($hid, (string) $key, $label);
					$p['nb'][] = $text.' ('.$protos.($isDeclared ? '' : ', reported by neighbor').')';
					$p['nb'] = array_values(array_unique($p['nb']));
					unset($p);
					$this->linkPorts[$hid][(string) $key] = true;
					$this->portNbr[$hid][(string) $key][$other] = true;
				}
			}
		}
		foreach ($this->orphans as $hid => $ports) {
			foreach ($ports as $key => $texts) {
				$p = &$this->port($hid, (string) $key);
				$p['nb'] = array_values(array_unique(array_merge($p['nb'], $texts)));
				unset($p);
			}
		}
		foreach ($this->through as $hid => $ports) {
			foreach ($ports as $key => $list) {
				$p = &$this->port($hid, (string) $key);
				foreach ($list as [$n, $l, $h2]) {
					$p['nb'][] = str_replace("\n", ' ', $this->nodes[$n]['label']).' (seen through '.$this->nodes[$h2]['label'].')';
				}
				$p['nb'] = array_values(array_unique($p['nb']));
				unset($p);
			}
		}
	}

	private function edges(): array {
		$out = [];
		foreach ($this->pairs as $pk => $links) {
			[$from, $to] = explode('|', $pk);
			$ep = in_array($this->nodes[$to]['kind'] ?? '', ['endpoints', 'port']);
			$list = [];
			foreach ($links as $l) {
				$oneSided = !$ep && count($l['seen']) < 2 && !array_intersect_key($l['proto'], $this->declared);
				foreach ([$from, $to] as $n) {
					if (($this->nodes[$n]['kind'] ?? '') !== 'host') {
						$oneSided = false;
					}
				}
				$list[] = [
					'a' => $ep ? ($l['own'][$from] ?? '') : $this->linkLabel($l, $from),
					'b' => $ep ? '' : $this->linkLabel($l, $to),
					'ifx' => $l['ifx'],
					'proto' => implode('+', array_keys($l['proto'])), 'one_sided' => $oneSided, 'util' => null
				];
			}
			usort($list, fn($x, $y) => strnatcasecmp($x['a'], $y['a']));
			$out[] = [
				'id' => $pk, 'from' => $from, 'to' => $to, 'links' => $list, 'util' => null, 'ep' => $ep,
				'one_sided' => !$ep && count(array_filter($list, fn($x) => $x['one_sided'])) === count($list)
			];
		}
		if ($this->util) {
			self::applyUtil($out, $this->util);
		}

		return $out;
	}

	/** Host/ifIndex pairs on infrastructure links, so the builder only fetches util items it needs. */
	public static function linkIfx(array $edges): array {
		$need = [];
		foreach ($edges as $e) {
			foreach ($e['links'] as $l) {
				foreach ($l['ifx'] as $node => $ifx) {
					if ($node[0] === 'h') {
						$need[substr($node, 1)][$ifx] = true;
					}
				}
			}
		}

		return $need;
	}

	/** util: hostid => ifIndex => [in, out, speed] (bps). */
	public static function applyUtil(array &$edges, array $util): void {
		foreach ($edges as &$e) {
			foreach ($e['links'] as &$l) {
				foreach ($l['ifx'] as $node => $ifx) {
					$u = $util[substr($node, 1)][$ifx] ?? null;
					if (!$u || ($u['speed'] ?? 0) <= 0) {
						continue;
					}
					$speed = $u['speed'] < 100000 ? $u['speed'] * 1000000 : $u['speed'];
					$pct = round(max($u['in'] ?? 0, $u['out'] ?? 0) / $speed * 100, 1);
					$l['util'] = max($l['util'] ?? 0, $pct);
					$l['speed'] = $speed;
				}
				if ($l['util'] !== null) {
					$e['util'] = max($e['util'] ?? 0, $l['util']);
				}
			}
			unset($l);
		}
		unset($e);
	}

	// ---------------------------------------------------------------- uplinks without LLDP

	/** ARP across all gateways: [mac => ip, ip => mac]. */
	private function arpMaps(): array {
		$macIp = [];
		$ipMac = [];
		foreach ($this->vals as $v) {
			foreach ($v['arp'] ?? [] as $ip => $mac) {
				if ($ip === '_truncated') {
					continue;
				}
				$macIp[$mac] = $macIp[$mac] ?? (string) $ip;
				$ipMac[(string) $ip] = $ipMac[(string) $ip] ?? $mac;
			}
		}

		return [$macIp, $ipMac];
	}

	/**
	 * Mark uplinks that LLDP/CDP on this switch did not report, so their MACs are
	 * never shown as devices on the switch:
	 *  - a port carrying a MAC that belongs to another managed host (its chassis
	 *    MAC, a MAC in the same /40 block as its chassis, or the MAC its interface
	 *    IP resolves to in ARP);
	 *  - a port holding most of the MACs the switch has learned.
	 * Only a port whose single foreign MAC is a host without a MAC table of its
	 * own, on a port that is not the majority port, is taken as a direct link to
	 * that host (drawn as an inferred FDB link, its MACs shown behind it).
	 */
	/**
	 * MACs a device says are its own: the LLDP chassis ID, and every LLDP local
	 * port ID that is a MAC. Some devices (MikroTik) publish no chassis ID at
	 * all, only the per-port ones, so both are needed.
	 */
	private function ownIds($hid): array {
		$local = $this->vals[$hid]['local'] ?? [];
		$out = [Net::chassis($local['cid'] ?? '')];
		foreach (($local['p'] ?? []) as $port) {
			$m = Net::chassis(is_array($port) ? ($port['id'] ?? '') : '');
			if ($m !== '' && Net::mac($m) === $m) {
				$out[] = $m;
			}
		}

		return array_values(array_unique(array_filter($out, fn($c) => $c !== '')));
	}

	private function inferUplinks(): void {
		[, $ipMac] = $this->arpMaps();
		$blk = max(6, min(12, (int) Settings::get('detect.own_block')));
		$owner = [];
		$block = [];
		foreach ($this->hosts as $hid => $h) {
			$cids = $this->ownIds($hid);
			foreach ($this->idx['cid'] as $c => $owned) {
				if ($owned == $hid) {
					$cids[] = $c;
				}
			}
			foreach ($cids as $c) {
				if ($c !== '' && Net::mac($c) === $c) {
					$owner[$c] = (string) $hid;
					$block[substr($c, 0, $blk)] = (string) $hid;
				}
			}
			foreach ($h['ips'] as $ip) {
				if (isset($ipMac[$ip])) {
					$owner[$ipMac[$ip]] = (string) $hid;
				}
			}
		}

		foreach ($this->hosts as $hid => $h) {
			$fdb = $this->vals[$hid]['fdb'] ?? null;
			if ($h['external'] || !$fdb || empty($fdb['e'])) {
				continue;
			}
			$total = array_sum(array_map('count', $fdb['e'])) + array_sum(array_map('intval', $fdb['u'] ?? []));
			$busyPorts = count($fdb['e']) + count($fdb['u'] ?? []);

			foreach ($fdb['e'] as $ifx => $macs) {
				$ifx = (string) $ifx;
				if (isset($this->linkPorts[$hid][$ifx])) {
					continue;
				}
				$owners = [];
				foreach ($macs as $m) {
					$o = $owner[$m] ?? ($block[substr($m, 0, $blk)] ?? null);
					if ($o !== null && $o != $hid) {
						$owners[$o] = true;
					}
				}
				$majority = count($macs) >= (int) Settings::get('detect.majority_min') && $busyPorts >= 2 && count($macs) * 2 > $total;
				if (!$owners && !$majority) {
					continue;
				}

				$this->linkPorts[$hid][$ifx] = true;
				$p = &$this->port((string) $hid, $ifx);
				if ($owners) {
					$names = array_map(fn($o) => $this->hosts[$o]['name'], array_keys($owners));
					$p['nb'][] = 'Uplink: MAC of '.implode(', ', $names).' learned here';
				}
				if ($majority) {
					$p['nb'][] = sprintf('Uplink: carries %d of the %d MACs learned on this switch', count($macs), $total);
				}
				$p['nb'] = array_values(array_unique($p['nb']));
				unset($p);

				// A direct link to one MAC-table-less host (server, hypervisor).
				if (count($owners) === 1 && !$majority) {
					$t = (string) array_key_first($owners);
					$tnode = 'h'.$t;
					if (isset($this->nodes[$tnode]) && $this->nodes[$tnode]['kind'] === 'host'
							&& empty($this->vals[$t]['fdb'])) {
						$this->portNbr[$hid][$ifx][$tnode] = true;
						$a = 'h'.$hid;
						$pk = strcmp($a, $tnode) < 0 ? $a.'|'.$tnode : $tnode.'|'.$a;
						if (!isset($this->pairs[$pk])) {
							$this->pairs[$pk][] = [
								'own' => [$a => $this->ports[$hid][$ifx]['name']], 'peer' => [], 'key' => [$a => $ifx],
								'ifx' => [$a => $ifx], 'seen' => [], 'proto' => ['fdb' => 1]
							];
						}
					}
				}
			}
		}
	}

	// ---------------------------------------------------------------- endpoints

	private function endpoints(): void {
		[$macIp] = $this->arpMaps();

		// MACs of known infrastructure (chassis IDs) are never endpoints.
		$infra = [];
		foreach ($this->nodes as $n) {
			if (($n['cid'] ?? '') !== '' && Net::mac($n['cid']) === $n['cid']) {
				$infra[$n['cid']] = true;
			}
		}
		foreach (array_keys($this->idx['cid']) as $c) {
			if (Net::mac($c) === $c) {
				$infra[$c] = true;
			}
		}

		// Each host's own MAC block (/40 around its chassis MAC). Many MAC tables
		// list the device's own port MACs; those are not devices behind it.
		$blk = max(6, min(12, (int) Settings::get('detect.own_block')));
		$ownBlock = [];
		foreach ($this->hosts as $bh => $bhost) {
			foreach ($this->ownIds($bh) as $c) {
				if ($c !== '' && Net::mac($c) === $c) {
					$ownBlock[$bh][substr($c, 0, $blk)] = true;
				}
			}
		}
		foreach ($this->idx['cid'] as $c => $bh) {
			if (Net::mac($c) === $c) {
				$ownBlock[$bh][substr($c, 0, $blk)] = true;
			}
		}
		$isOwn = fn($h, $m) => isset($ownBlock[$h][substr($m, 0, $blk)]);

		// Owner per MAC: the port with the fewest MACs wins (most specific).
		$owner = [];
		$hidden = [];
		foreach ($this->hosts as $hid => $h) {
			if ($h['external']) {
				continue;
			}
			$fdb = $this->vals[$hid]['fdb'] ?? null;
			if (!$fdb) {
				continue;
			}
			foreach ($fdb['u'] ?? [] as $ifx => $n) {
				$ifx = (string) $ifx;
				$p = &$this->port($hid, $ifx);
				$p['upl'] = (int) $n;
				unset($p);
				if (isset($this->linkPorts[$hid][$ifx]) && ($t = $this->behindTarget($hid, $ifx)) !== null) {
					// Only a count is known for a port over the limit, and the host itself is one of
					// the addresses learned there, so it is not one of the devices behind it.
					$hidden[$t][$this->viaKey($hid, $ifx)] = [max(0, (int) $n - 1), $this->viaLabel($hid, $ifx)];
				}
			}
			foreach ($fdb['e'] ?? [] as $ifx => $macs) {
				$ifx = (string) $ifx;
				$n = count($macs);
				if (isset($this->linkPorts[$hid][$ifx])) {
					// A switch port facing one managed host that has no MAC table of its
					// own (hypervisor, server): what the switch learns there is behind it.
					$t = $this->behindTarget($hid, $ifx);
					if ($t === null) {
						continue;
					}
					// The host's own NICs: anything whose ARP address is one of the host's
					// interface IPs in Zabbix (lldpd's chassis MAC is often a different NIC).
					$own = array_flip($this->hosts[$t]['ips'] ?? []);
					foreach ($macs as $m) {
						if (isset($own[$macIp[$m] ?? '']) || $isOwn($t, $m) || $isOwn($hid, $m)) {
							continue;
						}
						if (!isset($infra[$m]) && (!isset($owner[$m]) || $owner[$m][2] > $n)) {
							$owner[$m] = [$t, $this->viaKey($hid, $ifx), $n, $this->viaLabel($hid, $ifx)];
						}
					}
					continue;
				}
				foreach ($macs as $m) {
					if ($isOwn($hid, $m)) {
						continue;
					}
					if (!isset($owner[$m]) || $owner[$m][2] > $n) {
						$owner[$m] = [$hid, $ifx, $n, null];
					}
				}
			}
		}
		foreach ($owner as $m => [$hid, $ifx, , $label]) {
			$p = &$this->port((string) $hid, $ifx, $label);
			if ($label !== null) {
				$p['behind'] = true;
			}
			$p['macs'][$m] = ['m' => $m, 'ip' => $macIp[$m] ?? '', 'n' => '', 't' => '', 'r' => Net::isRandomMac($m), 'v' => Net::vendor($m)];
			unset($p);
		}
		foreach ($hidden as $t => $ports) {
			foreach ($ports as $key => [$n, $label]) {
				$p = &$this->port((string) $t, $key, $label);
				$p['behind'] = true;
				$p['hidden'] = $n;
				unset($p);
			}
		}

		foreach ($this->eps as $hid => $ports) {
			foreach ($ports as $key => $list) {
				$p = &$this->port($hid, (string) $key);
				foreach ($list as $e) {
					$k = $e['m'] !== '' ? $e['m'] : 'n:'.$e['n'];
					$cur = $p['macs'][$k] ?? ['m' => $e['m'], 'ip' => '', 'r' => false, 'v' => Net::vendor($e['m'])];
					$cur['n'] = $e['n'];
					$cur['t'] = $e['t'];
					$cur['ip'] = $cur['ip'] !== '' ? $cur['ip'] : ($e['ip'] !== '' ? $e['ip'] : ($macIp[$e['m']] ?? ''));
					$p['macs'][$k] = $cur;
				}
				unset($p);
			}
		}
		foreach ($this->ports as $hid => &$ports) {
			foreach ($ports as &$p) {
				$p['macs'] = array_values($p['macs']);
			}
			unset($p);
		}
		unset($ports);

		if ($this->opt['endpoints'] == self::EP_NONE) {
			return;
		}
		foreach ($this->ports as $hid => $ports) {
			if (!isset($this->nodes['h'.$hid])) {
				continue;
			}
			$total = 0;
			foreach ($ports as $key => $p) {
				$c = count($p['macs']) + ($p['hidden'] ?? 0);
				if ($c == 0) {
					continue;
				}
				$total += $c;
				if ($this->opt['endpoints'] == self::EP_PORT) {
					$id = 'p'.$hid.':'.$key;
					$one = ($c == 1 && $p['macs']) ? $p['macs'][0] : null;
					$this->nodes[$id] = [
						'id' => $id, 'kind' => 'port', 'parent' => 'h'.$hid, 'hostid' => (string) $hid, 'port' => (string) $key,
						'label' => $p['name']."\n".($one ? ($one['n'] ?: ($one['ip'] ?: Net::fmtMac($one['m']))) : $c.' devices'),
						'count' => $c, 'sev' => -1, 'site' => $this->nodes['h'.$hid]['site']
					];
					$this->pairs['h'.$hid.'|'.$id] = [[
						'own' => ['h'.$hid => $p['name']], 'peer' => [], 'key' => [], 'ifx' => [],
						'seen' => ['h'.$hid => 1], 'proto' => ['fdb' => 1]
					]];
				}
			}
			if ($this->opt['endpoints'] == self::EP_SWITCH && $total > 0) {
				$id = 'e'.$hid;
				$this->nodes[$id] = [
					'id' => $id, 'kind' => 'endpoints', 'parent' => 'h'.$hid, 'hostid' => (string) $hid,
					'label' => $total.' '.($total == 1 ? 'device' : 'devices'), 'count' => $total, 'sev' => -1,
					'site' => $this->nodes['h'.$hid]['site']
				];
				$this->pairs['h'.$hid.'|'.$id] = [[
					'own' => [], 'peer' => [], 'key' => [], 'ifx' => [], 'seen' => ['h'.$hid => 1], 'proto' => ['fdb' => 1]
				]];
			}
		}
	}

	/** The one managed host on a switch port, if it has no MAC table of its own. */
	private function behindTarget($hid, string $ifx): ?string {
		$nbrs = array_keys($this->portNbr[$hid][$ifx] ?? []);
		if (count($nbrs) !== 1 || ($this->nodes[$nbrs[0]]['kind'] ?? '') !== 'host') {
			return null;
		}
		$t = substr($nbrs[0], 1);

		return empty($this->vals[$t]['fdb']) ? $t : null;
	}

	private function viaKey($hid, string $ifx): string {
		return 'via:'.$hid.':'.$ifx;
	}

	private function viaLabel($hid, string $ifx): string {
		$port = $this->ports[$hid][$ifx]['name'] ?? $this->portName($hid, $ifx);

		return 'via '.$this->hosts[$hid]['name'].' '.$port;
	}

	// ---------------------------------------------------------------- layout levels

	/** Root preference: gateways (ARP data or router capability) above plain switches. */
	private function rootScore(string $id, array $adj): float {
		$n = $this->nodes[$id] ?? [];
		$deg = count(array_filter($adj[$id] ?? [], fn($x) => !in_array($this->nodes[$x]['kind'] ?? '', ['endpoints', 'port'])));
		$score = $deg;
		if (!empty($n['gw'])) {
			$score += 100;
		}
		if (strpos((string) ($n['cap'] ?? ''), 'R') !== false) {
			$score += 50;
		}
		if (($n['kind'] ?? '') === 'host') {
			$score += 0.5;
		}

		return $score;
	}

	private function levels(array $edges): void {
		$adj = [];
		foreach ($edges as $e) {
			$adj[$e['from']][] = $e['to'];
			$adj[$e['to']][] = $e['from'];
		}
		$roles = (array) Settings::get('roles');
		$level = [];
		$queue = [];
		foreach ($this->nodes as $id => $n) {
			$r = strtolower($n['role'] ?? '');
			if (isset($roles[$r]) && $roles[$r] === 0) {
				$level[$id] = 0;
				$queue[] = $id;
			}
		}

		$bfs = function(array $queue) use (&$level, $adj) {
			while ($queue) {
				$id = array_shift($queue);
				foreach ($adj[$id] ?? [] as $nb) {
					if (!isset($level[$nb])) {
						$level[$nb] = $level[$id] + 1;
						$queue[] = $nb;
					}
				}
			}
		};
		$bfs($queue);

		while (true) {
			$best = null;
			$bestScore = -1;
			foreach ($adj as $id => $nbs) {
				if (isset($level[$id]) || in_array($this->nodes[$id]['kind'] ?? '', ['endpoints', 'port'])) {
					continue;
				}
				$s = $this->rootScore($id, $adj);
				if ($s > $bestScore) {
					$best = $id;
					$bestScore = $s;
				}
			}
			if ($best === null) {
				break;
			}
			$level[$best] = 0;
			$bfs([$best]);
		}

		$max = $level ? max($level) : 0;
		foreach ($this->nodes as $id => &$n) {
			$r = strtolower($n['role'] ?? '');
			if (isset($roles[$r])) {
				$n['level'] = $roles[$r];
				if (isset($level[$id]) && $level[$id] > $n['level'] && $roles[$r] > 0) {
					$n['level'] = $level[$id];
				}
			}
			else {
				$n['level'] = $level[$id] ?? ($max + 1);
			}
		}
		unset($n);
		foreach ($this->nodes as $id => &$n) {
			if (isset($n['parent'])) {
				$n['level'] = ($this->nodes[$n['parent']]['level'] ?? 0) + 1;
			}
		}
		unset($n);
	}

	/** Point every edge from the upper level down, so tree layouts read top to bottom. */
	private function orient(array &$edges): void {
		foreach ($edges as &$e) {
			$lf = $this->nodes[$e['from']]['level'] ?? 0;
			$lt = $this->nodes[$e['to']]['level'] ?? 0;
			if ($lf > $lt || ($lf == $lt && ($this->nodes[$e['from']]['kind'] ?? '') === 'unmanaged')) {
				[$e['from'], $e['to']] = [$e['to'], $e['from']];
				foreach ($e['links'] as &$l) {
					[$l['a'], $l['b']] = [$l['b'], $l['a']];
				}
				unset($l);
			}
		}
		unset($e);
	}

	// ---------------------------------------------------------------- detail

	private function hostDetail(): array {
		$out = [];
		foreach ($this->hosts as $hid => $h) {
			if ($h['external']) {
				continue;
			}
			$v = $this->vals[$hid] ?? [];
			$ports = array_values($this->ports[$hid] ?? []);
			usort($ports, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
			$out[(string) $hid] = [
				'name' => $h['name'],
				'ip' => $h['ips'][0] ?? '',
				'site' => $h['site'] ?? '',
				'sys' => $v['local']['sys'] ?? '',
				'cid' => $v['local']['cid'] ?? '',
				'clock' => $v['clock'] ?? 0,
				'has' => array_values(array_filter(
					array_merge(array_keys(Registry::sources()), ['fdb', 'arp']), fn($k) => isset($v[$k])
				)),
				'names' => !empty($v['ifnames']),
				'guessed' => count(array_filter($ports, fn($p) => !empty($p['guess']))) > 0,
				'fdb_trimmed' => !empty($v['fdb']['x']),
				'ports' => $ports
			];
		}

		return $out;
	}
}
