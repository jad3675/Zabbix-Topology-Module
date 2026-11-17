# Adding a neighbor source

A neighbor source is anything that can say "this host has that neighbor on this port". LLDP, CDP and tag-declared links are the three that ship; each is one class in `modules/topomap/lib/sources/`. Adding a fourth does not touch the correlator, the builder, the change log or the frontend.

## 1. Collect the data

Add an item to a template that stores the neighbor list as JSON, one object per neighbor. Follow the pattern of the shipped templates so it stays cheap:

- a `walk[]` master item with **history 0**;
- a dependent item with JavaScript preprocessing that turns the walk into JSON, followed by **Discard unchanged with heartbeat** `{$TOPO.HEARTBEAT}`.

`tests/template/js/common.js` has the walk parser the shipped items use, and `tests/template/build_template.py` generates the template YAML from the scripts in that folder.

(A source does not have to be backed by an item. The tag source reads host tags and returns `null` for both item keys.)

## 2. Write the class

`modules/topomap/lib/sources/Fdp.php`:

```php
<?php declare(strict_types = 0);

namespace Modules\TopoMap\Lib\Sources;

class Fdp implements Source {

    public function id(): string { return 'fdp'; }              // also the label on links: "FDP"
    public function priority(): int { return 30; }              // LLDP 10, CDP 20, tags 90
    public function label(): string { return 'FDP neighbors'; } // Data collection panel
    public function declared(): bool { return false; }          // true = stated by a person, never "one-sided"

    public function items(): array {
        return ['value' => 'topo.fdp.neighbors', 'walk' => 'topo.fdp.walk'];
    }

    public function observe(array $host, ?array $value): array {
        $out = [];
        foreach ($value ?? [] as $nb) {
            $out[] = [
                'port' => ['type' => 'ifindex', 'value' => (string) $nb['if']],
                'nb' => [
                    'sys' => (string) $nb['name'],   // neighbor's name
                    'ip' => (string) ($nb['ip'] ?? ''),
                    'port' => (string) ($nb['port'] ?? ''),
                    'cap' => 'B'                      // B bridge, R router, W AP, T phone, S station
                ]
            ];
        }
        return $out;
    }
}
```

That is the whole integration. The file is found automatically; the source appears on the settings page with an on/off switch, its item is fetched with the others, its state shows in each switch's Data collection block, its links are merged with LLDP and CDP sightings of the same cable, and its neighbor changes show in the Changes tab.

### The observation

| Field | Meaning |
|---|---|
| `port.type` | `ifindex` (IF-MIB index), `lldp` (LLDP local port number, resolved through the host's LLDP local data), `name` (port name as the device shows it), `none` (unknown) |
| `port.value` | The index, number or name |
| `nb.sys` | Neighbor's system name. Matched to Zabbix hosts by name, by the `topo.id` tag, or drawn as an unmanaged device |
| `nb.ip` | Management IP, matched to host interfaces |
| `nb.cid` | Chassis ID, normalised with `Net::chassis()`; matched to hosts' LLDP chassis IDs |
| `nb.mac` | 12 hex digits if known; used for vendor lookup |
| `nb.port` | The neighbor's port, as text |
| `nb.cap` | Capability letters. Neighbors with only `T` or `S` are treated as endpoints on the port, not as infrastructure |
| `nb.plat` | Platform or description; a short one labels unmanaged devices |

Leave out what you do not have.

## 3. From another module

If you would rather not put files into this module's folder, register the source from your own module's `Module::init()`:

```php
\Modules\TopoMap\Lib\Sources\Registry::register(new MySource());
```

## 4. Test it

`tests/sources_test.php` registers a throwaway source and checks that it is listed, fetched and drawn. Copy that block for yours and run `tests/run_all.sh`.
