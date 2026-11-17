#!/usr/bin/env python3
"""Creates (or removes) the Zabbix hosts for the simulated network.

    python3 setup.py --url http://zabbix.example/ --token TOKEN --sim-host 192.0.2.10
    python3 setup.py --url ... --token ... --remove

--sim-host is the address of the machine running the simulator AS THE ZABBIX SERVER
(or proxy) REACHES IT. Standard library only. The three topology templates must be
imported first. Nothing outside the host group it creates is touched.
"""
import argparse, json, os, sys, time, urllib.request
from network import DEVICES, GUESTS, GATEWAYS, CDP

ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
ap.add_argument('--url', default=os.environ.get('ZABBIX_URL'), help='Zabbix frontend URL (or ZABBIX_URL)')
ap.add_argument('--token', default=os.environ.get('ZABBIX_TOKEN'), help='API token (or ZABBIX_TOKEN)')
ap.add_argument('--sim-host', help='simulator address as seen from the Zabbix server or proxy')
ap.add_argument('--sim-port', default='1161')
ap.add_argument('--group', default='Topology demo')
ap.add_argument('--proxy', help='name of a proxy that should poll the simulated hosts')
ap.add_argument('--poll', action='store_true', help='after creating, wait for the server to load the hosts and run every walk once')
ap.add_argument('--remove', action='store_true', help='delete the demo hosts and the group')
a = ap.parse_args()
if not a.url or not a.token: ap.error('--url and --token are required')
if not a.remove and not a.sim_host: ap.error('--sim-host is required')

def call(method, params):
    req = urllib.request.Request(a.url.rstrip('/') + '/api_jsonrpc.php',
        json.dumps({'jsonrpc': '2.0', 'method': method, 'params': params, 'id': 1}).encode(),
        {'Content-Type': 'application/json-rpc', 'Authorization': 'Bearer ' + a.token})
    r = json.load(urllib.request.urlopen(req, timeout=60))
    if 'error' in r: sys.exit('%s failed: %s %s' % (method, r['error'].get('message'), r['error'].get('data')))
    return r['result']

g = call('hostgroup.get', {'filter': {'name': a.group}, 'output': ['groupid']})
if a.remove:
    if not g: sys.exit('Group "%s" does not exist; nothing to remove.' % a.group)
    hosts = call('host.get', {'groupids': g[0]['groupid'], 'output': ['hostid', 'host']})
    ours = [h for h in hosts if h['host'] in DEVICES or h['host'] in GUESTS]
    if ours: call('host.delete', [h['hostid'] for h in ours])
    if len(ours) == len(hosts): call('hostgroup.delete', [g[0]['groupid']])
    print('Removed %d hosts%s.' % (len(ours), '' if len(ours) == len(hosts) else ' (group kept: it has other hosts)'))
    sys.exit(0)

names = ['Topology by SNMP', 'Topology CDP by SNMP', 'Topology ARP by SNMP']
tpl = {t['host']: t['templateid'] for t in call('template.get', {'filter': {'host': names}, 'output': ['templateid', 'host']})}
missing = [n for n in names if n not in tpl]
if missing: sys.exit('Import templates/topology_templates.yaml first. Missing: ' + ', '.join(missing))
proxyid = None
if a.proxy:
    p = call('proxy.get', {'filter': {'name': a.proxy}, 'output': ['proxyid']})
    if not p: sys.exit('No proxy named "%s".' % a.proxy)
    proxyid = p[0]['proxyid']
gid = g[0]['groupid'] if g else call('hostgroup.create', {'name': a.group})['groupids'][0]
have = {h['host'] for h in call('host.get', {'filter': {'host': list(DEVICES) + list(GUESTS)}, 'output': ['host']})}
made = 0
for name, (ip, role, mac, cap, plat) in DEVICES.items():
    if name in have: continue
    t = [tpl[names[0]]] + ([tpl[names[1]]] if name in CDP else []) + ([tpl[names[2]]] if name in GATEWAYS else [])
    h = {'host': name, 'groups': [{'groupid': gid}], 'templates': [{'templateid': x} for x in t],
         'tags': [{'tag': 'topo.role', 'value': role}] if role else [],
         'macros': [{'macro': '{$SNMP_COMMUNITY}', 'value': name}],
         'description': plat + '. Simulated device, management address %s.' % ip,
         'interfaces': [{'type': 2, 'main': 1, 'useip': 0 if any(c.isalpha() for c in a.sim_host) else 1,
                         'ip': '' if any(c.isalpha() for c in a.sim_host) else a.sim_host,
                         'dns': a.sim_host if any(c.isalpha() for c in a.sim_host) else '', 'port': a.sim_port,
                         'details': {'version': 2, 'bulk': 1, 'community': '{$SNMP_COMMUNITY}', 'max_repetitions': 10}}]}
    if proxyid: h.update({'monitored_by': 1, 'proxyid': proxyid})
    call('host.create', h); made += 1
for name, (ip, mac, hv) in GUESTS.items():     # plain hosts with the address the gateways' ARP tables give their MACs
    if name in have: continue
    call('host.create', {'host': name, 'groups': [{'groupid': gid}], 'description': 'Simulated virtual machine on %s.' % hv,
                         'interfaces': [{'type': 1, 'main': 1, 'useip': 1, 'ip': ip, 'dns': '', 'port': '10050'}]}); made += 1
print('Created %d hosts in group "%s" (%d already existed).' % (made, a.group, len(have)))
if a.poll:
    print('Waiting 90 seconds for the server to load the new hosts...'); time.sleep(90)
    items = call('item.get', {'groupids': gid, 'output': ['itemid'], 'filter': {'type': 20}, 'search': {'key_': 'topo.'}})
    call('task.create', [{'type': 6, 'request': {'itemid': i['itemid']}} for i in items])
    print('Asked Zabbix to run %d walks now. Open Monitoring > Topology and pick the group in a minute.' % len(items))
else:
    print('The walks run on their normal schedule (1h to 6h). Add --poll next time, or use Execute now, to see the map sooner.')
