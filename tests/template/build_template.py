import yaml, uuid, hashlib, os
H = os.path.dirname(os.path.abspath(__file__))
COMMON = open(H + '/js/common.js').read()
def js(name, common=True):
    body = open(H + '/js/' + name).read()
    return (COMMON + '\n' + body) if common else body
def uid(seed):
    # Deterministic UUIDv4 (32 hex, no dashes): version nibble 4, variant 8-b.
    h = hashlib.md5(('topomap-v1:' + seed).encode()).hexdigest()
    return h[:12] + '4' + h[13:16] + '89ab'[int(h[16], 16) & 3] + h[17:]

class Lit(str): pass
yaml.add_representer(Lit, lambda d, s: d.represent_scalar('tag:yaml.org,2002:str', s, style='|'))
yaml.add_representer(str, lambda d, s: d.represent_scalar('tag:yaml.org,2002:str', s, style="'" if (s == '' or any(c in s for c in ':{}[]#,&*!|>%@`') or s.isdigit() or s in ('YES','NO')) else None))

TAG = [{'tag': 'component', 'value': 'topology'}]
GROUP = 'Templates/Network devices'

def master(t, name, key, oid, delay, desc, timeout=True):
    it = {'uuid': uid(t + key), 'name': name, 'type': 'SNMP_AGENT', 'snmp_oid': oid, 'key': key,
          'delay': delay, 'history': '0', 'value_type': 'TEXT', 'trends': '0',
          'description': desc, 'tags': TAG}
    if timeout: it['timeout'] = '{$TOPO.WALK.TIMEOUT}'
    return it

def dep(t, name, key, masterkey, script, desc, vtype='TEXT', history='{$TOPO.HISTORY}', discard=True, units=None, common=True):
    pp = [{'type': 'JAVASCRIPT', 'parameters': [Lit(js(script, common))]}]
    if discard:
        pp.append({'type': 'DISCARD_UNCHANGED_HEARTBEAT', 'parameters': ['{$TOPO.HEARTBEAT}']})
    it = {'uuid': uid(t + key), 'name': name, 'type': 'DEPENDENT', 'key': key, 'delay': '0',
          'history': history, 'value_type': vtype, 'description': desc, 'preprocessing': pp,
          'master_item': {'key': masterkey}, 'tags': TAG}
    if vtype == 'TEXT': it['trends'] = '0'
    if units: it['units'] = units
    return it

def trig(t, key, name, desc):
    return {'uuid': uid(t + key + 'trig'), 'expression': 'change(/%s/%s)=1' % (t, key), 'name': name,
            'priority': 'INFO', 'recovery_mode': 'NONE', 'manual_close': 'YES', 'description': desc,
            'tags': [{'tag': 'scope', 'value': 'notice'}, {'tag': 'component', 'value': 'topology'}]}

def macro(m, v, d): return {'macro': m, 'value': v, 'description': d}

COMMON_MACROS = [
    macro('{$TOPO.HEARTBEAT}', '12h', 'Unchanged topology values are stored at most this often. Keep it below Administration > General > GUI > "Max history display period" or the map loses data.'),
    macro('{$TOPO.HISTORY}', '14d', 'History retention for topology text items. Only changes plus heartbeats are stored.'),
    macro('{$TOPO.WALK.TIMEOUT}', '15s', 'Per-item timeout for topology walks.'),
    macro('{$TOPO.VALUE.MAX.BYTES}', '60000', 'Hard cap on stored JSON size per value.'),
]

# ---------------- Topology by SNMP ----------------
T1 = 'Topology by SNMP'
t1_items = [
    master(T1, 'LLDP: Local system walk', 'topo.lldp.local.walk',
           'walk[1.0.8802.1.1.2.1.3]',
           '{$TOPO.LLDP.LOCAL.INTERVAL}', 'LLDP-MIB lldpLocalSystemData, walked as one subtree. A single OID per request is the shape every agent answers; wide multi-column requests are dropped by some (MikroTik, small-business switches). Not stored.'),
    dep(T1, 'LLDP: Local system', 'topo.lldp.local', 'topo.lldp.local.walk', 'lldp_local.js',
        'Own chassis ID, system name and LLDP port numbers. Used by the Topology module to recognise this device in neighbor tables.'),
    master(T1, 'LLDP: Remote table walk', 'topo.lldp.remote.walk',
           'walk[1.0.8802.1.1.2.1.4]',
           '{$TOPO.LLDP.INTERVAL}', 'LLDP-MIB lldpRemoteSystemsData, walked as one subtree (see the local walk for why). Only the columns the map needs are kept when parsed; sysDesc is cut to 48 characters. Not stored.'),
    dep(T1, 'LLDP: Neighbors', 'topo.lldp.neighbors', 'topo.lldp.remote.walk', 'lldp_remote.js',
        'Normalised LLDP neighbor list. Stored only on change plus heartbeat.'),
    dep(T1, 'LLDP: Infrastructure neighbors', 'topo.lldp.infra', 'topo.lldp.neighbors', 'lldp_infra.js',
        'Bridge/router/AP neighbors only (phones and stations removed). Drives the change trigger.', common=False),
    master(T1, 'Interfaces: Name walk', 'topo.ifnames.walk',
           'walk[1.3.6.1.2.1.31.1.1.1.1,1.3.6.1.2.1.2.2.1.2,1.3.6.1.2.1.31.1.1.1.18]', '{$TOPO.IFNAMES.INTERVAL}',
           'ifName (ifDescr fallback) and ifAlias, so the module can label ports without depending on interface LLD. Near-static, so the parsed value is rarely stored. Not stored itself.'),
    dep(T1, 'Interfaces: Names', 'topo.ifnames', 'topo.ifnames.walk', 'ifnames.js', 'ifIndex to [name, description]. Port status and speed come from the host interface items, not from here.'),
    master(T1, 'FDB: Forwarding table walk', 'topo.fdb.walk',
           'walk[{$TOPO.FDB.OID},1.3.6.1.2.1.17.1.4.1.2]', '{$TOPO.FDB.INTERVAL}',
           'One column of the forwarding database plus the bridge-port to ifIndex map. Not stored.'),
    dep(T1, 'FDB: Edge ports', 'topo.fdb.edge', 'topo.fdb.walk', 'fdb.js',
        'MACs per port for ports with {$TOPO.FDB.EDGE.MAX} or fewer MACs; busier ports are kept as counts only.', history='{$TOPO.FDB.HISTORY}'),
]
for it in t1_items:
    if it['key'] == 'topo.lldp.infra':
        it['triggers'] = [trig(T1, 'topo.lldp.infra', 'LLDP: Infrastructure neighbors changed on {HOST.NAME}',
            'A switch, router or AP neighbor appeared, disappeared or moved ports. Compare the last two values of "LLDP: Infrastructure neighbors" or open Monitoring > Topology > Changes. Close manually.')]

t1 = {'uuid': uid(T1), 'template': T1, 'name': T1,
      'description': 'Topology collection for the Topology frontend module: LLDP, port names and the forwarding database, polled with one small walk[] request per table (hourly for neighbors, every few hours for the rest) and stored only on change.\n\nLink "Topology CDP by SNMP" to Cisco devices and "Topology ARP by SNMP" to layer 3 gateways.',
      'groups': [{'name': GROUP}], 'items': t1_items,
      'tags': [{'tag': 'class', 'value': 'network'}, {'tag': 'target', 'value': 'topology'}],
      'macros': COMMON_MACROS + [
        macro('{$TOPO.LLDP.INTERVAL}', '1h', 'LLDP remote table poll interval.'),
        macro('{$TOPO.LLDP.LOCAL.INTERVAL}', '6h', 'LLDP local data poll interval.'),
        macro('{$TOPO.IFNAMES.INTERVAL}', '6h', 'Port names and descriptions poll interval.'),
        macro('{$TOPO.FDB.INTERVAL}', '4h', 'Forwarding table poll interval. This is the only walk whose size grows with the network, so it runs rarely. Shorten it on switches where you want endpoint moves noticed sooner.'),
        macro('{$TOPO.FDB.OID}', '1.3.6.1.2.1.17.7.1.2.2.1.2', 'FDB port column. Default Q-BRIDGE dot1qTpFdbPort. Set to 1.3.6.1.2.1.17.4.3.1.2 (dot1dTpFdbPort) on devices without Q-BRIDGE.'),
        macro('{$TOPO.FDB.HISTORY}', '3d', 'History for the edge-port MAC list. Endpoint churn changes it often, so keep this short.'),
        macro('{$TOPO.FDB.EDGE.MAX}', '8', 'Ports with more MACs than this are treated as uplinks and stored as a count only.'),
      ]}

# ---------------- CDP ----------------
T2 = 'Topology CDP by SNMP'
t2_items = [
    master(T2, 'CDP: Cache walk', 'topo.cdp.walk',
           'walk[1.3.6.1.4.1.9.9.23.1.2.1]',
           '{$TOPO.CDP.INTERVAL}', 'CISCO-CDP-MIB cdpCacheTable, walked as one subtree. Only address, device ID, port, platform and capabilities are kept when parsed. Not stored.'),
    dep(T2, 'CDP: Neighbors', 'topo.cdp.neighbors', 'topo.cdp.walk', 'cdp.js', 'Normalised CDP neighbor list, keyed by local ifIndex.'),
    dep(T2, 'CDP: Infrastructure neighbors', 'topo.cdp.infra', 'topo.cdp.neighbors', 'lldp_infra.js',
        'Bridge/router neighbors only (phones and hosts removed). Drives the change trigger.', common=False),
]
for it in t2_items:
    if it['key'] == 'topo.cdp.infra':
        it['triggers'] = [trig(T2, 'topo.cdp.infra', 'CDP: Infrastructure neighbors changed on {HOST.NAME}',
            'A switch or router CDP neighbor appeared, disappeared or moved ports. Close manually.')]
t2 = {'uuid': uid(T2), 'template': T2, 'name': T2,
      'description': 'CDP neighbor collection for the Topology module. Link to Cisco devices alongside "Topology by SNMP".',
      'groups': [{'name': GROUP}], 'items': t2_items,
      'tags': [{'tag': 'class', 'value': 'network'}, {'tag': 'target', 'value': 'topology'}],
      'macros': COMMON_MACROS + [macro('{$TOPO.CDP.INTERVAL}', '1h', 'CDP cache poll interval.')]}

# ---------------- ARP ----------------
T3 = 'Topology ARP by SNMP'
t3_items = [
    master(T3, 'ARP: Table walk', 'topo.arp.walk', 'walk[{$TOPO.ARP.OID}]', '{$TOPO.ARP.INTERVAL}',
           'IPv4 ARP table, one column only. Not stored.'),
    dep(T3, 'ARP: IP to MAC', 'topo.arp', 'topo.arp.walk', 'arp.js', 'IPv4 to MAC map filtered by {$TOPO.ARP.NET.MATCH}.', history='{$TOPO.ARP.HISTORY}'),
]
t3 = {'uuid': uid(T3), 'template': T3, 'name': T3,
      'description': 'ARP collection for the Topology module, so endpoints get IP addresses. Link only to layer 3 gateways (cores, firewalls that answer SNMP), not every switch.',
      'groups': [{'name': GROUP}], 'items': t3_items,
      'tags': [{'tag': 'class', 'value': 'network'}, {'tag': 'target', 'value': 'topology'}],
      'macros': COMMON_MACROS + [
        macro('{$TOPO.ARP.INTERVAL}', '30m', 'ARP poll interval.'),
        macro('{$TOPO.ARP.HISTORY}', '3d', 'History for the ARP map.'),
        macro('{$TOPO.ARP.OID}', '1.3.6.1.2.1.4.22.1.2', 'ipNetToMediaPhysAddress. Use 1.3.6.1.2.1.4.35.1.4 (ipNetToPhysicalPhysAddress) where the old table is missing.'),
        macro('{$TOPO.ARP.NET.MATCH}', '', 'Optional regex on IPv4 addresses to keep, e.g. ^10[.](20|21)[.] (use [.] rather than backslashes). Empty keeps all, subject to the size cap.'),
      ]}

doc = {'zabbix_export': {'version': '7.4', 'template_groups': [{'uuid': uid('grp' + GROUP), 'name': GROUP}],
                         'templates': [t1, t2, t3]}}
out = H + '/../../templates/topology_templates.yaml'
os.makedirs(os.path.dirname(out), exist_ok=True)
class D(yaml.Dumper):
    def ignore_aliases(self, data): return True
D.add_representer(Lit, lambda d, s: d.represent_scalar('tag:yaml.org,2002:str', s, style='|'))
D.add_representer(str, lambda d, s: d.represent_scalar('tag:yaml.org,2002:str', s, style="'" if (s == '' or any(c in s for c in ':{}[]#,&*!|>%@`') or s.isdigit() or s in ('YES','NO')) else None))
open(out, 'w').write(yaml.dump(doc, Dumper=D, sort_keys=False, allow_unicode=True, width=1000))
print('wrote', out)
