<?php declare(strict_types = 0);

namespace Modules\TopoMap\Lib\Sources;

use Modules\TopoMap\Lib\Settings;

/**
 * Finds the neighbor sources in this directory. Supporting data that is not a
 * neighbor source (port names, LLDP local data, MAC table, ARP) is listed here
 * too so the rest of the module has one place to ask "which items do I read".
 */
class Registry {

	/**
	 * slot => [label, walk key, value key, optional]; read by the correlator under
	 * these slot names. Optional rows are left out of the Data collection panel
	 * when a host does not collect them.
	 */
	public const DATA = [
		'ifnames' => ['Port names', 'topo.ifnames.walk', 'topo.ifnames', false],
		'local' => ['LLDP local', 'topo.lldp.local.walk', 'topo.lldp.local', false],
		'fdb' => ['MAC table', 'topo.fdb.walk', 'topo.fdb.edge', false],
		'arp' => ['ARP', 'topo.arp.walk', 'topo.arp', true]
	];

	/** slot => [what the map uses it for, what is lost when it is off, template, macros] (settings page). */
	public const DATA_HELP = [
		'ifnames' => [
			'Port names and descriptions, so ports read "gi13" and not "ifIndex 61".',
			'Ports are shown by interface number, and a link reported only by the far end cannot be tied to a local port.',
			'Topology by SNMP', '{$TOPO.IFNAMES.INTERVAL}'
		],
		'local' => [
			'Each device\'s own LLDP identity (chassis ID, system name, port numbering), used to recognise it in other devices\' neighbor tables.',
			'Devices are matched by name and IP only, and their own port MACs can be mistaken for attached devices.',
			'Topology by SNMP', '{$TOPO.LLDP.LOCAL.INTERVAL}'
		],
		'fdb' => [
			'Switch MAC tables: which devices sit on which port, what is behind a server, and uplink detection.',
			'The map shows infrastructure links only: no endpoint lists, no device moves in Changes.',
			'Topology by SNMP', '{$TOPO.FDB.INTERVAL}, {$TOPO.FDB.OID}, {$TOPO.FDB.EDGE.MAX}, {$TOPO.FDB.HISTORY}'
		],
		'arp' => [
			'IP addresses for the MACs the switches found, and names for devices behind a server that are Zabbix hosts. Never creates links.',
			'Endpoints are listed by MAC and vendor only, devices behind a server are not matched to Zabbix hosts, and a server\'s own NIC can show up behind it.',
			'Topology ARP by SNMP', '{$TOPO.ARP.INTERVAL}, {$TOPO.ARP.OID}, {$TOPO.ARP.NET.MATCH}, {$TOPO.ARP.HISTORY}'
		]
	];

	private static $all = null;

	/** Supporting data slots that are switched on. */
	public static function data(): array {
		$off = array_flip((array) Settings::get('data_disabled'));

		return array_diff_key(self::DATA, $off);
	}

	/** @return Source[] keyed by id, enabled ones only */
	public static function sources(): array {
		if (self::$all === null) {
			self::$all = [];
			require_once __DIR__.'/Source.php';
			$files = glob(__DIR__.'/*.php') ?: [];
			sort($files);
			foreach ($files as $f) {
				$name = basename($f, '.php');
				if ($name === 'Source' || $name === 'Registry') {
					continue;
				}
				require_once $f;
				$class = __NAMESPACE__.'\\'.$name;
				if (class_exists($class) && is_subclass_of($class, Source::class)) {
					$s = new $class();
					self::$all[$s->id()] = $s;
				}
			}
			uasort(self::$all, fn($a, $b) => [$a->priority(), $a->id()] <=> [$b->priority(), $b->id()]);
		}
		$off = array_flip((array) Settings::get('sources_disabled'));

		return array_filter(self::$all, fn($s) => !isset($off[$s->id()]));
	}

	/**
	 * Add a source from outside this directory, e.g. from another module's
	 * Module::init(). Same effect as dropping the class file in here.
	 */
	public static function register(Source $source): void {
		self::sources();
		self::$all[$source->id()] = $source;
		uasort(self::$all, fn($a, $b) => [$a->priority(), $a->id()] <=> [$b->priority(), $b->id()]);
	}

	/** Forget everything found or registered (tests). */
	public static function reset(): void {
		self::$all = null;
	}

	/** Every source found, enabled or not (for the settings page). */
	public static function available(): array {
		self::sources();

		return self::$all;
	}

	/** value item key => slot, for every item the builder has to read. */
	public static function valueKeys(): array {
		$out = [];
		foreach (self::data() as $slot => [, , $key, ]) {
			$out[$key] = $slot;
		}
		foreach (self::sources() as $id => $s) {
			if ($s->items()['value'] !== null) {
				$out[$s->items()['value']] = $id;
			}
		}

		return $out;
	}

	/** slot => [label, walk key, value key, optional] for the Data collection panel. */
	public static function statusRows(): array {
		$data = self::data();
		$rows = array_intersect_key($data, ['ifnames' => 1, 'local' => 1]);
		foreach (self::sources() as $id => $s) {
			$it = $s->items();
			if ($it['value'] !== null) {
				$rows[$id] = [$s->label(), $it['walk'], $it['value'], true];
			}
		}

		return $rows + array_intersect_key($data, ['fdb' => 1, 'arp' => 1]);
	}
}
