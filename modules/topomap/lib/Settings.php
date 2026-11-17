<?php declare(strict_types = 0);

namespace Modules\TopoMap\Lib;

/**
 * Everything an installation may want to change without editing code: tag names,
 * role names, which item keys carry interface status/speed/traffic, limits and
 * detection thresholds. Defaults live here; overrides are stored in the module's
 * own config (Administration > General > Modules keeps it with the module) and
 * edited on the Topology settings page.
 */
class Settings {

	public const DEFAULTS = [
		'tags' => [
			'site' => 'site',
			'role' => 'topo.role',
			'id' => 'topo.id',
			'link' => 'topo.link'
		],
		// Role tag value => row in the layered layout (0 = top).
		'roles' => ['core' => 0, 'distribution' => 1, 'aggregation' => 1, 'access' => 2, 'edge' => 3],
		// Existing interface items to read, first match wins. {ifindex} and {ifname} are substituted.
		'if_keys' => [
			'status' => ['net.if.status[ifOperStatus.{ifindex}]'],
			'speed' => ['net.if.speed[ifHighSpeed.{ifindex}]', 'net.if.speed[ifSpeed.{ifindex}]'],
			'in' => ['net.if.in[ifHCInOctets.{ifindex}]', 'net.if.in[ifInOctets.{ifindex}]'],
			'out' => ['net.if.out[ifHCOutOctets.{ifindex}]', 'net.if.out[ifOutOctets.{ifindex}]']
		],
		'util' => ['warn' => 50, 'high' => 80],
		'limits' => [
			'max_hosts' => 1500,
			'cache_ttl' => 120,
			'changes_max_hosts' => 300,
			'changes_max_events' => 2000,
			'changes_max_rows' => 50000
		],
		'detect' => [
			'heartbeat' => 43200,        // must match {$TOPO.HEARTBEAT} on the templates
			'majority_min' => 10,        // MACs before the "most of the switch" uplink rule applies
			'move_window' => 18000,      // seconds within which a MAC leaving one port and appearing on another is a move; a bit over the MAC table interval
			'proxy_offline' => 600,      // a proxy silent for longer than this is reported as the reason its hosts have no data
			'own_block' => 10,           // leading hex digits shared by a device's own port MACs
			'product_label_max' => 24    // longest LLDP system description used as a product name
		],
		'dns' => ['enabled' => 1, 'budget_ms' => 1500],
		// Source ids to ignore (see lib/sources).
		'sources_disabled' => [],
		// Supporting data to ignore: ifnames, local, fdb, arp.
		'data_disabled' => []
	];

	private static $data = null;

	/** Merge stored overrides over the defaults; unknown keys are dropped, types are kept. */
	public static function init(?array $stored): void {
		self::$data = self::merge(self::DEFAULTS, $stored ?? []);
	}

	private static function merge(array $def, array $over): array {
		$out = $def;
		foreach ($over as $k => $v) {
			if (!array_key_exists($k, $def)) {
				continue;
			}
			$d = $def[$k];
			if ($k === 'roles') {
				$roles = [];
				foreach ((array) $v as $name => $level) {
					$name = strtolower(trim((string) $name));
					if ($name !== '') {
						$roles[$name] = max(0, min(20, (int) $level));
					}
				}
				$out[$k] = $roles ?: $d;
			}
			elseif (is_array($d) && self::isList($d)) {
				$list = array_values(array_filter(array_map(fn($x) => trim((string) $x), (array) $v), fn($x) => $x !== ''));
				$out[$k] = ($list || $d === []) ? $list : $d;
			}
			elseif (is_array($d)) {
				$out[$k] = self::merge($d, is_array($v) ? $v : []);
			}
			elseif (is_int($d)) {
				$out[$k] = max(0, (int) $v);
			}
			else {
				$s = trim((string) $v);
				$out[$k] = $s !== '' ? $s : $d;
			}
		}

		return $out;
	}

	private static function isList(array $a): bool {
		return $a === [] || array_keys($a) === range(0, count($a) - 1);
	}

	public static function all(): array {
		if (self::$data === null) {
			self::init(null);
		}

		return self::$data;
	}

	/** Dotted path, e.g. Settings::get('detect.majority_min'). */
	public static function get(string $path) {
		$v = self::all();
		foreach (explode('.', $path) as $p) {
			$v = $v[$p] ?? null;
		}

		return $v;
	}

	/** Only the values that differ from the defaults, which is what gets stored. */
	public static function diff(array $all): array {
		$out = [];
		foreach ($all as $k => $v) {
			if (!array_key_exists($k, self::DEFAULTS) || $v === self::DEFAULTS[$k]) {
				continue;
			}
			$out[$k] = $v;
		}

		return $out;
	}

	/** "12h", "90m", "2d" or plain seconds to seconds. */
	public static function parseTime($v, int $default): int {
		if (preg_match('/^\s*(\d+)\s*([smhdw]?)\s*$/i', (string) $v, $m)) {
			return (int) $m[1] * ['' => 1, 's' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800][strtolower($m[2])];
		}

		return $default;
	}

	public static function fmtTime(int $sec): string {
		foreach (['d' => 86400, 'h' => 3600, 'm' => 60] as $u => $n) {
			if ($sec >= $n && $sec % $n === 0) {
				return ($sec / $n).$u;
			}
		}

		return $sec.'s';
	}

	/** Rough age for sentences: "36 hours", "3 days". */
	public static function fmtAge(int $sec): string {
		foreach ([[86400, 'day'], [3600, 'hour'], [60, 'minute']] as [$n, $u]) {
			if ($sec >= 2 * $n || ($n == 60 && $sec >= 60)) {
				$v = (int) floor($sec / $n);
				return $v.' '.$u.($v == 1 ? '' : 's');
			}
		}

		return $sec.' seconds';
	}

	/** A value older than this has missed its heartbeat. */
	public static function staleAfter(): int {
		return 2 * (int) self::get('detect.heartbeat') + 7200;
	}

	/** Changes whenever any setting does, so cached builds never outlive a settings change. */
	public static function hash(): string {
		return substr(sha1(json_encode(self::all())), 0, 10);
	}

	/**
	 * Expand interface key patterns for one port.
	 * @return array key => priority (0 = preferred)
	 */
	public static function ifKeys(string $kind, string $ifindex, string $ifname = ''): array {
		$out = [];
		foreach ((array) self::get('if_keys.'.$kind) as $prio => $pat) {
			if (strpos($pat, '{ifname}') !== false && $ifname === '') {
				continue;
			}
			if (strpos($pat, '{ifindex}') !== false && !ctype_digit($ifindex)) {
				continue;
			}
			$out[str_replace(['{ifindex}', '{ifname}'], [$ifindex, $ifname], $pat)] = $prio;
		}

		return $out;
	}
}
