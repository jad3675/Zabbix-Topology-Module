<?php declare(strict_types = 0);

namespace Modules\TopoMap\Lib\Sources;

/**
 * A neighbor source: anything that can say "this host has that neighbor on this
 * port". To add one, drop a class implementing this interface into lib/sources;
 * it is picked up automatically. Nothing else in the module needs to change.
 *
 * observe() returns a list of observations:
 *   [
 *     'port' => ['type' => 'ifindex'|'lldp'|'name'|'none', 'value' => string],
 *     'nb'   => ['sys' => name, 'ip' => mgmt IP, 'cid' => chassis id (normalised), 'mac' => 12 hex,
 *                'port' => the neighbor's port, 'cap' => capability letters, 'plat' => platform/description]
 *   ]
 * Port types: 'ifindex' is the IF-MIB index; 'lldp' is an LLDP local port number
 * (resolved through the host's LLDP local data); 'name' is a port name as the
 * device shows it; 'none' when the port is unknown.
 * Capability letters: B bridge, R router, W wireless AP, T telephone, S station.
 */
interface Source {

	/** Short unique id; also the protocol label on links ("lldp" shows as LLDP). */
	public function id(): string;

	/** Order in which sources are applied and listed; lower first (LLDP 10, CDP 20, tags 90). */
	public function priority(): int;

	/** Name shown in the Data collection panel. */
	public function label(): string;

	/**
	 * Item that holds this source's parsed value, and the item whose state says
	 * whether collection works. Both null for sources that need no items.
	 * @return array ['value' => key|null, 'walk' => key|null]
	 */
	public function items(): array;

	/** True when links are stated by a person rather than discovered, so a single report is enough. */
	public function declared(): bool;

	/**
	 * @param array      $host   host record (hostid, name, host, ips, tags, ...)
	 * @param array|null $value  decoded value of items()['value'], or null
	 */
	public function observe(array $host, ?array $value): array;
}
