# Topology for Zabbix 7.4

A network map that draws itself from what your devices already know: LLDP and CDP neighbors, switch MAC tables and ARP. Zabbix does the collecting with a handful of bulk SNMP walks per hour; a frontend module turns the stored values into a map, a per-port device list and a change log.

No sidecar daemon, no second poller, no files in the web root, no discovery rules creating thousands of items.

## What you get

- **Map** (Monitoring > Topology): routers on top, switches below, one line per cable with both port names. Layered or organic layout, group by site, problem severity on nodes, utilisation colour on links.
- **Where is it plugged in**: every MAC on every access port with IP (from ARP) and vendor. Search by name, MAC, IP or vendor. CSV export.
- **What is behind that server**: VMs and containers behind a hypervisor are found from the switch port facing it, with no agent or API on the hypervisor. Those that are Zabbix hosts are drawn by name.
- **What changed**: neighbor added or lost, device moved, new device, IP changed MAC, read from item history on demand.
- **Things that cannot speak LLDP**: state the link with a host tag (`topo.link`) and the UPS, PDU or unmanaged switch is on the map. No items, no polling.
- **Dashboard widget** with the same map.
- **Settings page** for tag names, role names, which interface items to read, thresholds and limits.
- **Extensible**: a new neighbor protocol is one small class dropped into a folder. See [docs/EXTENDING.md](docs/EXTENDING.md).

## Contents

| Path | What it is |
|---|---|
| `templates/topology_templates.yaml` | `Topology by SNMP`, `Topology CDP by SNMP`, `Topology ARP by SNMP` |
| `modules/topomap/` | Frontend module: map, changes, settings |
| `modules/topomap-widget/` | Dashboard widget (needs `topomap` enabled) |
| `docs/EXTENDING.md` | Adding a neighbor source |
| `tests/` | Offline tests; `tests/run_all.sh` runs them all |

## Install

1. Import `templates/topology_templates.yaml` (Data collection > Templates > Import).
2. Before rolling out, snmpwalk one switch of each family:
   - `1.0.8802.1.1.2.1.4.1.1.9` (LLDP neighbors)
   - `1.3.6.1.2.1.17.7.1.2.2.1.2` (Q-BRIDGE MAC table). If empty, try `1.3.6.1.2.1.17.4.3.1.2` and set `{$TOPO.FDB.OID}` on those hosts. If both are empty the device only exposes MACs per VLAN context (common on Cisco); it still gets links, but no endpoint list.
3. Link `Topology by SNMP` to switches and routers, `Topology CDP by SNMP` to Cisco devices, `Topology ARP by SNMP` only to layer 3 gateways.
4. Copy `modules/topomap` and `modules/topomap-widget` into `<zabbix ui>/modules/`.
5. Administration > General > Modules > **Scan directory**, then enable both.
6. Use **Execute now** on the new walk items, or wait one interval, then open Monitoring > Topology and pick host groups.

Keep `{$TOPO.HEARTBEAT}` (12h) below Administration > General > GUI > "Max history display period"; the map reads last values and the API hides older ones. If you change the macro, change **Heartbeat** on the settings page to match.

## Polling load per switch

| Item | Default interval | Stored |
|---|---|---|
| LLDP remote table (one subtree) | 1h | parsed value, on change |
| CDP cache (Cisco only) | 1h | parsed value, on change |
| MAC table port column + bridge port map | 4h | edge-port MACs, on change |
| LLDP local data, port names and descriptions | 6h | parsed value, on change |
| ARP (gateways only) | 30m | IP to MAC map, on change |

Nine items per host on the base template (four walks, five parsed values), three more for CDP and two for ARP. No low-level discovery, no numeric items, no trends. Each walk asks for one subtree (three columns at most for port names), so every GETBULK carries a small request that any agent answers. Wide multi-column requests are deliberately avoided: some agents (MikroTik RouterOS, for one) drop them without replying, and the timeouts then stall every SNMP item on that host. Walk items keep no history; parsed items use "discard unchanged with heartbeat", so a quiet network stores two rows per item per day. Every interval, OID choice, size cap and history period is a template macro (see the template's macro list for descriptions).

The MAC table is the only walk whose size grows with the network, so it runs every 4 hours by default. The price is freshness: a switch only remembers a MAC for about five minutes after it last spoke, so a device that was quiet at poll time stays off the map until the next poll, and a moved device shows up in its new place up to 4 hours late. Lower `{$TOPO.FDB.INTERVAL}` on the switches where that matters; raise it to `1d` where it does not.

Port status, speed and traffic are **not** collected here. They are read from the interface items you already have, only for the switch someone opens (status, speed) and only for ports on drawn links (utilisation).

## Tags

| Tag (default name) | Effect |
|---|---|
| `site` | Grouping and "Group by site" |
| `topo.role` = `core` / `distribution` / `access` / `edge` | Pins the device to that row of the layered layout |
| `topo.id` = name | Another name the device goes by in neighbor tables |
| `topo.link` = `neighbor` or `neighbor\|my port` or `neighbor\|my port\|their port` | Declares a link by hand. `neighbor` is a Zabbix host name, or any name for a device that is not in Zabbix. Repeatable. `sw1\|\|gi7` gives only the far port. |

Tag names and the list of roles are settings.

## Settings

Monitoring > Topology > **Settings** (Super admin). Stored in the module's own Zabbix config; only values that differ from the defaults are saved, and saving invalidates cached maps.

| Section | What it controls |
|---|---|
| Where links come from | Switch any neighbor source on or off |
| Supporting data | Whether the map uses port names, LLDP local data, MAC tables and ARP. Each row says what it is for, what is lost when it is off, and which template macros tune its collection |
| Host tags | The four tag names above, and role names with their layout row |
| Interface items | Key patterns for status, speed, bits in and bits out. `{ifindex}` and `{ifname}` are substituted; first match wins. The defaults match the stock "by SNMP" templates. |
| Utilisation colours | Amber and red thresholds |
| Detection | Heartbeat, uplink-by-majority minimum, move window, own-MAC block, product-name length, reverse DNS |
| Limits | Hosts per map, cache lifetime, Changes tab caps |

## How the map decides things

- LLDP and CDP sightings of the same cable are merged. A link reported by one end only is dashed.
- Before a switch's port names arrive, the far end's name for the port is used, and numeric LLDP port numbers are taken as interface indexes. The side panel says so.
- A device seen through another switch is drawn behind that switch, and noted on the port as "seen through".
- **Uplinks never list devices**, even when that switch's own LLDP is missing. A port is an uplink if a neighbor reports it, if it carries a MAC of another managed device, or if it holds most of the switch's learned MACs.
- When a switch port faces one managed host that has no MAC table (hypervisor, server), the MACs learned there are shown behind that host. Its own NICs are left out.
- A device's own port MACs (same block as its chassis MAC) are never counted as devices.
- Gateways (ARP data or router capability) go above switches unless a role tag says otherwise.
- Unmanaged neighbors are labelled by system name, else vendor plus a short LLDP description ("Belkin Velop"), else reverse DNS.

The **Data collection** block on each switch shows, per source: OK, waiting for first poll, error (with Zabbix's text), no new data, not collected, or not supported by this device.

## Try it without a network

`sim/` holds a Docker Compose simulator with 17 SNMP devices (core, distribution, access, access points, hypervisors) and a script that creates the matching hosts in Zabbix. The devices are polled by the real templates. See `sim/README.md`.

## Troubleshooting

- **A host shows "Not polled".** The panel says why in Zabbix's own terms: it is monitored by a proxy that has stopped contacting the server (with how long ago), its proxy group has no working proxy for it, or it is in a maintenance window with no data collection. Fix that first; nothing in the template can collect until then. The threshold for calling a proxy offline is under Settings > Detection.
- **Everything says "Waiting for first poll" for hours, nothing errors.** Check the server's SNMP poller: `ps -o args | grep "snmp poller"` (inside the container if you run one). A healthy poller shows `[got N values ...]`. One that still says `started` never finished initialising, usually because of a broken MIB file in the server's MIB directory; remove it and restart the server. The server needs no MIBs for this module.
- **"Stopped arriving" or "Zabbix cannot reach this device" on one host.** The host had data and then went quiet. The panel shows Zabbix's own interface error when there is one. Check the host's SNMP availability, run `snmpbulkwalk -v2c -c <community> -Cr10 <ip> 1.0.8802.1.1.2.1.4` from the server, and if that times out while `snmpget` works, lower "Max repetition count" on the host's SNMP interface. If you imported the 1.0.11, 2.0.0 or 2.1.0 template, re-import this one: those sent a nine-column LLDP request that some agents drop.
- **Endpoints have no IP addresses and are not matched to Zabbix hosts.** Look at the latest value of "ARP: IP to MAC" on the gateway. Before 2.2.1 it came out as `{}` on any server or proxy that has MIB files loaded, because those print MAC addresses as text (`"00:1a:2b:3c:4d:5e"`) where a server without MIBs prints `Hex-STRING`. Re-import the template; the parsers accept both now.
- **A hypervisor shows a count instead of its guests.** The switch port has more MACs than `{$TOPO.FDB.EDGE.MAX}` (8). Raise the macro on that **switch**.
- **No status dots or utilisation.** Your interface items use other keys than the stock templates. Add their patterns under Settings > Interface items.
- **Links but no endpoints on a Cisco switch.** Its MAC table is per VLAN context, which a single Zabbix SNMP item cannot address.

## Upgrading

Copy both module folders over the old ones, click **Scan directory**, and re-import the template (UUIDs are unchanged, so it updates in place). Re-import, do not unlink and clear: clearing deletes the stored values, and the map then cannot tell a device that stopped answering from one that was never polled. To remove the items that 2.2 dropped (the neighbor and MAC counts), tick "Delete missing" for items in the import dialog; leaving them does no harm. No settings are needed to keep 1.x behaviour. Coming from 1.0.11, 2.0.0 or 2.1.0, the template re-import is the important part.

## Known gaps

- No per-VLAN MAC table contexts.
- IPv6 neighbors and ARP are ignored.
- Parallel links between two devices are merged per port pair; LAG membership is not shown.

## Tests

`tests/run_all.sh` runs the offline suites (PHP only; the template parser tests also need `python3` with `dukpy` and `pyyaml`). With `snmpsim` and the net-snmp tools installed it also sends every request the template makes through `tests/hostile/picky_agent.py`, a stand-in for agents that silently drop wide or oversized requests. Any template change has to pass that. Fixtures use invented names and addresses.
