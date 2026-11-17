<?php declare(strict_types = 0);

namespace Modules\TopoMap\Lib;

use Modules\TopoMap\Lib\Sources\Registry;
use API;
use CWebUser;

/**
 * Reads topology items through the API as the current user (so host permissions
 * apply), resolves neighbors that live outside the filter, adds problem severity
 * and optional link utilisation, then hands everything to the Correlator.
 */
class TopologyBuilder {

	public const VERSION = '2.3.0';

	private $groupids;
	private $opt;
	private $warnings = [];

	public function __construct(array $groupids, array $opt = []) {
		$this->groupids = array_values(array_unique(array_map('strval', $groupids)));
		sort($this->groupids);
		$this->opt = $opt + ['endpoints' => Correlator::EP_SWITCH, 'unmanaged' => true, 'util' => true];
		Config::load();
	}

	/** Web path of this module's JS assets, for the dashboard widget. */
	public static function assetUrl(): string {
		return 'modules/'.basename(dirname(__DIR__)).'/assets/js/';
	}

	public function cacheKey(): string {
		return implode('|', [
			self::VERSION, Settings::hash(), CWebUser::$data['userid'] ?? 0, implode(',', $this->groupids),
			(int) $this->opt['endpoints'], (int) $this->opt['unmanaged'], (int) $this->opt['util']
		]);
	}

	/** Cached result; $refresh forces a rebuild if the cache is older than 30 s. */
	public function get(bool $refresh = false): array {
		$key = $this->cacheKey();
		$age = Cache::age($key);
		if (!$refresh || ($age !== null && $age < 30)) {
			$hit = Cache::get($key, (int) Settings::get('limits.cache_ttl'));
			if ($hit !== null) {
				$hit['cached'] = Cache::age($key);

				return $hit;
			}
		}
		$data = $this->build();
		Cache::set($key, $data);
		$data['cached'] = 0;

		return $data;
	}

	public function build(): array {
		$t0 = microtime(true);

		$valueKeys = Registry::valueKeys();
		$walkKeys = array_values(array_filter(array_column(Registry::statusRows(), 1)));
		$items = API::Item()->get([
			'output' => ['itemid', 'hostid', 'key_', 'lastvalue', 'lastclock', 'state', 'error'],
			'groupids' => $this->groupids ?: null,
			'filter' => ['key_' => array_merge(array_keys($valueKeys), $walkKeys)],
			'monitored' => true,
			'templated' => false
		]);

		// Hosts that only declare links with a tag have no topology items; include them too.
		$tagged = API::Host()->get([
			'output' => ['hostid'],
			'groupids' => $this->groupids ?: null,
			'tags' => [['tag' => (string) Settings::get('tags.link'), 'operator' => TAG_OPERATOR_EXISTS]],
			'monitored_hosts' => true
		]);

		$hostids = array_values(array_unique(array_merge(array_column($items, 'hostid'), array_column($tagged, 'hostid'))));
		sort($hostids, SORT_NUMERIC);
		$max = (int) Settings::get('limits.max_hosts');
		if (count($hostids) > $max) {
			$this->warnings[] = sprintf(_('%1$d hosts have topology data; showing the first %2$d. Narrow the host group filter.'),
				count($hostids), $max);
			$hostids = array_slice($hostids, 0, $max);
		}
		$keep = array_flip($hostids);

		$vals = [];
		$byKey = [];
		foreach ($items as $it) {
			if (!isset($keep[$it['hostid']])) {
				continue;
			}
			$byKey[$it['hostid']][$it['key_']] = $it;
			if (!isset($valueKeys[$it['key_']])) {
				continue;
			}
			$d = Net::json($it['lastvalue']);
			if ($d !== null) {
				$vals[$it['hostid']][$valueKeys[$it['key_']]] = $d;
			}
			$vals[$it['hostid']]['clock'] = max($vals[$it['hostid']]['clock'] ?? 0, (int) $it['lastclock']);
		}
		$status = $this->lastSeen($this->status($byKey));

		$hosts = $hostids ? $this->hosts($hostids, false) : [];
		$hosts += $this->external($hosts, $vals);

		$sev = $this->severity(array_keys($hosts));

		$corr = new Correlator($hosts, $vals, $sev, [], [
			'endpoints' => (int) $this->opt['endpoints'],
			'unmanaged' => (bool) $this->opt['unmanaged']
		]);
		$res = $corr->build();

		if ($this->opt['util']) {
			$util = $this->util(Correlator::linkIfx($res['edges']), $vals);
			if ($util) {
				Correlator::applyUtil($res['edges'], $util);
			}
		}

		$this->reverseDns($res['nodes']);
		$this->guests($res);

		$blockers = $this->blockers($hosts);
		$issues = [];
		foreach ($res['hosts'] as $hid => &$hd) {
			$hd['status'] = $status[$hid] ?? [];
			$hd['unreachable'] = ($hosts[$hid]['available'] ?? 0) == INTERFACE_AVAILABLE_FALSE;
			$hd['avail_error'] = $hosts[$hid]['avail_error'] ?? '';
			$hd['blocker'] = $blockers[$hid]['blocker'] ?? null;
			$hd['via'] = $blockers[$hid]['via'] ?? '';
			if ($hd['blocker'] !== null) {
				// One line says why; the per-item rows underneath are only its consequences.
				if ($hd['status']) {
					$issues[] = ['hostid' => (string) $hid, 'name' => $hd['name'], 'source' => 'Collection',
						'state' => 'blocked', 'error' => $hd['blocker']['text'], 'age' => null];
				}
				continue;
			}
			if ($hd['unreachable']) {
				$issues[] = ['hostid' => (string) $hid, 'name' => $hd['name'], 'source' => 'SNMP',
					'state' => 'unreachable', 'error' => $hd['avail_error'], 'age' => null];
			}
			foreach ($hd['status'] as $src => $st) {
				if (in_array($st['state'], ['error', 'stale'])) {
					$issues[] = ['hostid' => (string) $hid, 'name' => $hd['name'], 'source' => $st['label'],
						'state' => $st['state'], 'error' => $st['error'] ?? '', 'age' => $st['age'] ?? null];
				}
			}
		}
		unset($hd);
		$res['issues'] = $issues;

		$res['warnings'] = array_merge($this->warnings, $res['warnings']);
		$res['generated'] = time();
		$res['build_ms'] = (int) round((microtime(true) - $t0) * 1000);

		return $res;
	}

	/**
	 * Devices found behind a host (MACs from the switch port facing it, IPs from
	 * ARP) whose IP belongs to a Zabbix host: name them, and draw them as nodes
	 * under the host instead of counting them in its endpoint bubble. One exact
	 * IP lookup per build, only for those addresses.
	 */
	private function guests(array &$res): void {
		$byIp = [];
		foreach ($res['hosts'] as $hid => $h) {
			foreach ($h['ports'] as $pi => $p) {
				if (empty($p['behind'])) {
					continue;
				}
				foreach ($p['macs'] as $mi => $m) {
					if ($m['ip'] !== '') {
						$byIp[$m['ip']][] = [$hid, $pi, $mi, $p['k']];
					}
				}
			}
		}
		if (!$byIp) {
			return;
		}
		$ifs = API::HostInterface()->get([
			'output' => ['hostid', 'ip'],
			'filter' => ['ip' => array_slice(array_keys($byIp), 0, 2000)]
		]);
		if (!$ifs) {
			return;
		}
		$ipHost = [];
		foreach ($ifs as $if) {
			$ipHost[$if['ip']] = $ipHost[$if['ip']] ?? $if['hostid'];
		}
		$names = API::Host()->get([
			'output' => ['hostid', 'name'],
			'hostids' => array_values(array_unique($ipHost)),
			'preservekeys' => true
		]);
		$sev = $this->severity(array_keys($names));

		$nodeIdx = [];
		foreach ($res['nodes'] as $i => $n) {
			$nodeIdx[$n['id']] = $i;
		}
		$drawn = [];
		foreach ($byIp as $ip => $refs) {
			$gid = $ipHost[$ip] ?? null;
			if ($gid === null || !isset($names[$gid])) {
				continue;
			}
			foreach ($refs as [$hid, $pi, $mi, $key]) {
				if ((string) $gid === (string) $hid) {
					continue;
				}
				$res['hosts'][$hid]['ports'][$pi]['macs'][$mi]['zh'] = (string) $gid;
				$res['hosts'][$hid]['ports'][$pi]['macs'][$mi]['zn'] = $names[$gid]['name'];
				if (isset($nodeIdx['h'.$gid]) || isset($drawn[$gid])) {
					continue;
				}
				$parent = $res['nodes'][$nodeIdx['h'.$hid]] ?? null;
				if (!$parent) {
					continue;
				}
				$drawn[$gid] = true;
				$res['nodes'][] = [
					'id' => 'g'.$gid, 'kind' => 'guest', 'hostid' => (string) $gid, 'label' => $names[$gid]['name'],
					'ip' => $ip, 'sev' => $sev[$gid] ?? -1, 'site' => $parent['site'] ?? '', 'avail' => 0,
					'level' => ($parent['level'] ?? 0) + 1, 'via' => $parent['label']
				];
				$res['edges'][] = [
					'id' => 'h'.$hid.'|g'.$gid, 'from' => 'h'.$hid, 'to' => 'g'.$gid, 'util' => null, 'ep' => true,
					'one_sided' => false, 'guest' => true,
					'links' => [['a' => '', 'b' => '', 'ifx' => [], 'proto' => 'fdb', 'one_sided' => false, 'util' => null]]
				];
				foreach (['e'.$hid, 'p'.$hid.':'.$key] as $bid) {
					if (!isset($nodeIdx[$bid])) {
						continue;
					}
					$b = &$res['nodes'][$nodeIdx[$bid]];
					$b['count']--;
					if ($b['kind'] === 'endpoints') {
						$b['label'] = $b['count'].' '.($b['count'] == 1 ? 'device' : 'devices');
					}
					unset($b);
				}
			}
		}

		// Drop bubbles that are now empty, with their edges.
		$gone = [];
		foreach ($res['nodes'] as $i => $n) {
			if (in_array($n['kind'], ['endpoints', 'port']) && ($n['count'] ?? 1) <= 0) {
				$gone[$n['id']] = true;
				unset($res['nodes'][$i]);
			}
		}
		if ($gone) {
			$res['nodes'] = array_values($res['nodes']);
			$res['edges'] = array_values(array_filter($res['edges'], fn($e) => !isset($gone[$e['to']]) && !isset($gone[$e['from']])));
		}
	}

	/**
	 * Port status and speed for one switch, read from the host's existing interface
	 * items by exact key (patterns in Settings 'if_keys'). Only called when someone
	 * opens a switch in the side panel, so it never runs for the whole map.
	 *
	 * @param array $ports  port records with 'k' (ifIndex when known) and 'name'
	 * @return array port key => ['oper' => 1 up / 2 down / ..., 'speed' => Mbps]
	 */
	public static function portState(string $hostid, array $ports): array {
		Config::load();
		$map = [];
		foreach (array_slice($ports, 0, 2000) as $p) {
			$k = (string) $p['k'];
			$name = Net::isPlaceholder($p['name'] ?? '') ? '' : (string) $p['name'];
			foreach (['status' => 'oper', 'speed' => 'speed'] as $kind => $field) {
				foreach (Settings::ifKeys($kind, $k, $name) as $key => $prio) {
					$map[$key] = [$field, $k, $prio];
				}
			}
		}
		if (!$map) {
			return [];
		}
		$items = API::Item()->get([
			'output' => ['key_', 'lastvalue', 'lastclock'],
			'hostids' => [$hostid],
			'filter' => ['key_' => array_keys($map)],
			'monitored' => true
		]);
		$out = [];
		$prio = [];
		foreach ($items as $it) {
			if ((int) $it['lastclock'] === 0 || $it['lastvalue'] === '') {
				continue;
			}
			[$f, $k, $p] = $map[$it['key_']];
			if (isset($prio[$k.$f]) && $prio[$k.$f] <= $p) {
				continue;
			}
			$prio[$k.$f] = $p;
			$v = (float) $it['lastvalue'];
			$out[$k][$f] = $f === 'speed' ? (int) round($v >= 100000 ? $v / 1000000 : $v) : (int) $v;
		}

		return $out;
	}

	/**
	 * Per host and source: ok, waiting (never polled), error (item unsupported,
	 * with the error text), stale (nothing stored for longer than the heartbeat
	 * allows) or off (template for that source not linked).
	 */
	private function status(array $byKey): array {
		$now = time();
		$out = [];
		foreach ($byKey as $hid => $items) {
			foreach (Registry::statusRows() as $src => [$label, $walk, $parsed, $optional]) {
				$m = $items[$walk] ?? null;
				$d = $items[$parsed] ?? null;
				if (!$m && !$d) {
					$out[$hid][$src] = ['label' => $label, 'state' => 'off', 'optional' => $optional];
					continue;
				}
				$st = ['label' => $label, 'state' => 'waiting', 'age' => null, 'optional' => $optional];
				if ($d) {
					$st['itemid'] = $d['itemid'];
				}
				if ($m && $m['state'] == ITEM_STATE_NOTSUPPORTED) {
					$st['state'] = 'error';
					$st['error'] = $m['error'];
				}
				elseif ($d && $d['state'] == ITEM_STATE_NOTSUPPORTED) {
					$st['state'] = 'error';
					$st['error'] = $d['error'];
				}
				elseif ($d && (int) $d['lastclock'] > 0) {
					$st['age'] = $now - (int) $d['lastclock'];
					$st['state'] = $st['age'] > Settings::staleAfter() ? 'stale' : 'ok';
				}
				if ($st['state'] === 'error') {
					$lines = array_values(array_unique(array_filter(array_map('trim', explode("\n", (string) $st['error'])))));
					$st['error'] = implode(' ', $lines);
					// The device simply does not implement this MIB (CDP on non-Cisco, the
					// bridge MIB on a Linux host). Not a fault, so not an issue either.
					if ($lines && !array_filter($lines, fn($l) => !preg_match('/^No Such (Instance|Object)/i', $l))) {
						$st['state'] = 'unsupported';
						unset($st['error']);
					}
				}
				$out[$hid][$src] = $st;
			}
		}

		return $out;
	}

	/**
	 * The API hides last values older than the frontend's history display period,
	 * so "no last value" can mean "never polled" or "stopped arriving a day ago".
	 * For those rows only, look at history: if a value exists, the row is stale
	 * with its real age, not waiting. Normally there are no such rows and this
	 * costs nothing.
	 */
	private function lastSeen(array $status): array {
		$ask = [];
		foreach ($status as $hid => $rows) {
			foreach ($rows as $src => $st) {
				if ($st['state'] === 'waiting' && isset($st['itemid'])) {
					$ask[$st['itemid']] = [$hid, $src];
				}
			}
		}
		if ($ask) {
			$rows = API::History()->get([
				'history' => ITEM_VALUE_TYPE_TEXT,
				'itemids' => array_keys($ask),
				'time_from' => time() - 14 * 86400,
				'output' => ['itemid', 'clock'],
				'sortfield' => 'clock',
				'sortorder' => ZBX_SORT_DOWN,
				'limit' => 5000
			]);
			$seen = [];
			foreach ($rows as $r) {
				$seen[$r['itemid']] = max($seen[$r['itemid']] ?? 0, (int) $r['clock']);
			}
			foreach ($seen as $itemid => $clock) {
				[$hid, $src] = $ask[$itemid];
				$status[$hid][$src]['state'] = 'stale';
				$status[$hid][$src]['age'] = time() - $clock;
			}
		}
		foreach ($status as &$rows) {
			foreach ($rows as &$st) {
				unset($st['itemid']);
			}
			unset($st);
		}
		unset($rows);

		return $status;
	}

	/**
	 * PTR names for unmanaged neighbors that only have an IP. Cached for a day,
	 * misses included, and bounded by a time budget so a slow resolver cannot
	 * stall the page.
	 */
	private function reverseDns(array &$nodes): void {
		if (!Settings::get('dns.enabled')) {
			return;
		}
		$start = microtime(true);
		foreach ($nodes as &$n) {
			if ($n['kind'] !== 'unmanaged' || ($n['ip'] ?? '') === '') {
				continue;
			}
			$sys = trim((string) ($n['sys'] ?? ''));
			if ($sys !== '' && !filter_var($sys, FILTER_VALIDATE_IP)) {
				continue;
			}
			$key = 'ptr|'.$n['ip'];
			$hit = Cache::get($key, 86400);
			if ($hit === null) {
				if ((microtime(true) - $start) * 1000 > (int) Settings::get('dns.budget_ms')) {
					continue;
				}
				$name = @gethostbyaddr($n['ip']);
				$hit = ['n' => ($name !== false && $name !== $n['ip']) ? $name : ''];
				Cache::set($key, $hit);
			}
			if ($hit['n'] !== '') {
				$n['dns'] = $hit['n'];
				$n['label'] = Net::name($hit['n']).(($n['vendor'] ?? '') !== '' ? "\n".$n['vendor'] : '');
			}
		}
		unset($n);
	}

	/**
	 * Why a host is not being polled at all, as Zabbix itself knows it: in a
	 * no-data maintenance, or behind a proxy that is not talking to the server.
	 * Reads configuration only; nothing here polls anything.
	 */
	private function blockers(array $hosts): array {
		$out = [];
		$proxyids = [];
		$groupids = [];
		$maintids = [];
		foreach ($hosts as $hid => $h) {
			if (!empty($h['external'])) {
				continue;
			}
			if (($h['monitored_by'] ?? 0) != 0) {
				$proxyids[$h['proxyid']] = true;
				$groupids[$h['proxy_groupid']] = true;
			}
			if (($h['nodata_maint'] ?? '') !== '') {
				$maintids[$h['nodata_maint']] = true;
			}
		}
		unset($proxyids['0'], $groupids['0'], $maintids['0'], $maintids['']);

		$proxies = [];
		$groups = [];
		$maints = [];
		$known = true;
		try {
			if ($proxyids) {
				$proxies = API::Proxy()->get(['output' => ['proxyid', 'name', 'lastaccess'],
					'proxyids' => array_keys($proxyids), 'preservekeys' => true]) ?: [];
			}
			if ($groupids) {
				$groups = API::ProxyGroup()->get(['output' => ['proxy_groupid', 'name', 'state'],
					'proxy_groupids' => array_keys($groupids), 'preservekeys' => true]) ?: [];
			}
		}
		catch (\Throwable $e) {
			$known = false;
		}
		try {
			if ($maintids) {
				$maints = API::Maintenance()->get(['output' => ['maintenanceid', 'name'],
					'maintenanceids' => array_keys($maintids), 'preservekeys' => true]) ?: [];
			}
		}
		catch (\Throwable $e) {
		}

		$limit = (int) Settings::get('detect.proxy_offline');
		$now = time();
		foreach ($hosts as $hid => $h) {
			if (!empty($h['external'])) {
				continue;
			}
			$b = null;
			$via = '';
			$by = (int) ($h['monitored_by'] ?? 0);
			$px = $proxies[$h['proxyid'] ?? ''] ?? null;
			$gr = $groups[$h['proxy_groupid'] ?? ''] ?? null;
			if ($by == 1) {
				$via = $px ? 'proxy '.$px['name'] : 'a proxy';
			}
			elseif ($by == 2) {
				$via = ($gr ? 'proxy group '.$gr['name'] : 'a proxy group').($px ? ' (proxy '.$px['name'].')' : '');
			}

			if (($h['nodata_maint'] ?? '') !== '') {
				$name = $maints[$h['nodata_maint']]['name'] ?? '';
				$b = ['kind' => 'maintenance', 'text' => 'Host is in maintenance'.($name !== '' ? ' "'.$name.'"' : '')
					.' with no data collection, so nothing on it is polled until the window ends.'];
			}
			elseif ($by != 0 && $known) {
				if ($by == 2 && !$px) {
					$b = ['kind' => 'proxy', 'text' => 'Monitored by '.$via.', which has no working proxy assigned to this host. '
						.'Nothing on it is polled until a proxy in the group comes back.'];
				}
				elseif ($px) {
					$last = (int) $px['lastaccess'];
					if ($last == 0) {
						$b = ['kind' => 'proxy', 'text' => 'Monitored by proxy '.$px['name'].', which has never connected to the server. '
							.'Nothing on this host is polled until it does.'];
					}
					elseif ($now - $last > $limit) {
						$b = ['kind' => 'proxy', 'age' => $now - $last, 'text' => 'Monitored by proxy '.$px['name']
							.', which last contacted the server '.Settings::fmtAge($now - $last).' ago. '
							.'Nothing on this host is polled until the proxy is back.'];
					}
				}
			}
			$out[$hid] = ['blocker' => $b, 'via' => $via];
		}

		return $out;
	}

	private function hosts(array $hostids, bool $external): array {
		$rows = API::Host()->get([
			'output' => ['hostid', 'host', 'name', 'maintenance_status', 'maintenance_type', 'maintenanceid',
				'monitored_by', 'proxyid', 'proxy_groupid', 'assigned_proxyid'],
			'hostids' => $hostids,
			'selectInterfaces' => ['ip', 'dns', 'main', 'type', 'available', 'error'],
			'selectTags' => ['tag', 'value'],
			'preservekeys' => true
		]);

		$out = [];
		foreach ($rows as $hid => $h) {
			$ifs = $h['interfaces'];
			usort($ifs, fn($a, $b) => [$b['main'], $b['type'] == INTERFACE_TYPE_SNMP] <=> [$a['main'], $a['type'] == INTERFACE_TYPE_SNMP]);
			$avail = 0;
			$avail_error = '';
			foreach ($ifs as $if) {
				if ($if['type'] == INTERFACE_TYPE_SNMP || $avail == 0) {
					$avail = (int) $if['available'];
					$avail_error = (string) ($if['error'] ?? '');
				}
			}
			$names = (array) Settings::get('tags');
			$tags = ['site' => '', 'role' => '', 'ids' => []];
			foreach ($h['tags'] as $t) {
				if ($t['tag'] === $names['site'] && $tags['site'] === '') {
					$tags['site'] = $t['value'];
				}
				elseif ($t['tag'] === $names['role']) {
					$tags['role'] = strtolower($t['value']);
				}
				elseif ($t['tag'] === $names['id'] && $t['value'] !== '') {
					$tags['ids'][] = $t['value'];
				}
			}
			$out[$hid] = [
				'hostid' => (string) $hid, 'host' => $h['host'], 'name' => $h['name'],
				'ips' => array_values(array_unique(array_filter(array_column($ifs, 'ip')))),
				'site' => $tags['site'], 'role' => $tags['role'], 'topo_ids' => $tags['ids'],
				'tags' => $h['tags'], 'external' => $external, 'available' => $avail,
				'avail_error' => $avail == INTERFACE_AVAILABLE_FALSE ? $avail_error : '',
				'nodata_maint' => ($h['maintenance_status'] ?? 0) == 1 && ($h['maintenance_type'] ?? 0) == 1
					? (string) ($h['maintenanceid'] ?? '') : '',
				'monitored_by' => (int) ($h['monitored_by'] ?? 0),
				'proxyid' => (string) ((int) ($h['monitored_by'] ?? 0) == 2 ? ($h['assigned_proxyid'] ?? 0) : ($h['proxyid'] ?? 0)),
				'proxy_groupid' => (string) ($h['proxy_groupid'] ?? 0)
			];
		}

		return $out;
	}

	/**
	 * Neighbors that are Zabbix hosts but outside the filter (another site's core,
	 * a firewall). Targeted lookups only: by interface IP and by host name.
	 */
	private function external(array $hosts, array $vals): array {
		$knownIp = [];
		$knownName = [];
		foreach ($hosts as $h) {
			foreach ($h['ips'] as $ip) {
				$knownIp[$ip] = true;
			}
			foreach (array_merge([$h['host'], $h['name']], $h['topo_ids']) as $n) {
				$knownName[Net::name($n)] = true;
			}
		}
		foreach ($vals as $v) {
			if (!empty($v['local']['sys'])) {
				$knownName[Net::name($v['local']['sys'])] = true;
			}
		}

		$ips = [];
		$names = [];
		foreach (Registry::sources() as $id => $source) {
			foreach ($hosts as $hid => $h) {
				foreach ($source->observe($h, $vals[$hid][$id] ?? null) as $o) {
					$nb = $o['nb'];
					$cap = (string) ($nb['cap'] ?? '');
					if ($cap !== '' && (strpos($cap, 'T') !== false || !preg_match('/[BRW]/', $cap))) {
						continue;
					}
					$ip = (string) ($nb['ip'] ?? '');
					if ($ip !== '' && !isset($knownIp[$ip])) {
						$ips[$ip] = true;
					}
					$sys = trim(preg_replace('/\(.*\)$/', '', (string) ($nb['sys'] ?? '')));
					if ($sys !== '' && !isset($knownName[Net::name($sys)])) {
						$names[$sys] = true;
						$names[explode('.', $sys)[0]] = true;
					}
				}
			}
		}
		$ips = array_slice(array_keys($ips), 0, 1000);
		$names = array_slice(array_keys($names), 0, 1000);

		$found = [];
		if ($ips) {
			foreach (API::HostInterface()->get(['output' => ['hostid'], 'filter' => ['ip' => $ips]]) as $r) {
				$found[$r['hostid']] = true;
			}
		}
		if ($names) {
			foreach (['host', 'name'] as $f) {
				foreach (API::Host()->get(['output' => ['hostid'], 'filter' => [$f => $names]]) as $r) {
					$found[$r['hostid']] = true;
				}
			}
		}
		$ids = array_diff(array_keys($found), array_keys($hosts));

		return $ids ? $this->hosts(array_values($ids), true) : [];
	}

	private function severity(array $hostids): array {
		if (!$hostids) {
			return [];
		}
		$problems = API::Problem()->get([
			'output' => ['objectid', 'severity'],
			'hostids' => $hostids,
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'suppressed' => false,
			'symptom' => false
		]);
		if (!$problems) {
			return [];
		}
		$triggers = API::Trigger()->get([
			'output' => ['triggerid'],
			'triggerids' => array_values(array_unique(array_column($problems, 'objectid'))),
			'selectHosts' => ['hostid'],
			'preservekeys' => true
		]);
		$sev = [];
		foreach ($problems as $p) {
			foreach ($triggers[$p['objectid']]['hosts'] ?? [] as $h) {
				$sev[$h['hostid']] = max($sev[$h['hostid']] ?? -1, (int) $p['severity']);
			}
		}

		return $sev;
	}

	/**
	 * Only the interface items that sit on a drawn link, by exact key, so this
	 * never scans a host's full interface item set.
	 */
	private function util(array $need, array $vals = []): array {
		if (!$need) {
			return [];
		}
		$keys = [];
		$map = [];
		foreach ($need as $hid => $ifxs) {
			foreach (array_keys($ifxs) as $ifx) {
				$ifx = (string) $ifx;
				$name = isset($vals[$hid]['ifnames'][$ifx]) ? Net::ifName($vals[$hid]['ifnames'][$ifx]) : '';
				foreach (['in', 'out', 'speed'] as $f) {
					foreach (Settings::ifKeys($f, $ifx, $name) as $k => $prio) {
						$keys[$k] = true;
						$map[$hid][$k][] = [$f, $ifx, $prio];
					}
				}
			}
		}
		$items = API::Item()->get([
			'output' => ['hostid', 'key_', 'lastvalue'],
			'hostids' => array_keys($need),
			'filter' => ['key_' => array_keys($keys)],
			'monitored' => true
		]);
		$util = [];
		$prioSeen = [];
		foreach ($items as $it) {
			foreach ($map[$it['hostid']][$it['key_']] ?? [] as [$f, $ifx, $prio]) {
				if (!isset($need[$it['hostid']][$ifx]) || $it['lastvalue'] === '') {
					continue;
				}
				$pk = $it['hostid'].':'.$ifx.':'.$f;
				if (isset($prioSeen[$pk]) && $prioSeen[$pk] <= $prio) {
					continue;
				}
				$prioSeen[$pk] = $prio;
				$util[$it['hostid']][$ifx][$f] = (float) $it['lastvalue'];
			}
		}

		return $util;
	}
}
