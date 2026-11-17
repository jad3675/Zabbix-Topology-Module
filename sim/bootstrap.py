#!/usr/bin/env python3
"""Sets up the demo Zabbix that docker-compose.yml starts. Safe to run again.

Waits for the API, imports the topology templates, enables the module, creates the
simulated hosts (setup.py), runs every walk once, then loops: it sends invented
traffic figures for the link ports every five minutes so the map has utilisation
colours to show. Those figures come from network.py; nothing measures them.
"""
import json, os, random, subprocess, sys, time, urllib.request
sys.dont_write_bytecode = True
from network import DEVICES, LINKS, UTILISATION, link_ports, port_speed

URL = os.environ.get('ZABBIX_URL', 'http://zabbix-web:8080/').rstrip('/')
SIM = os.environ.get('SIM_HOST', 'snmpsim')
USER, PASSWORD = os.environ.get('ZABBIX_USER', 'Admin'), os.environ.get('ZABBIX_PASSWORD', 'zabbix')
token = None

def call(method, params, auth=True):
    head = {'Content-Type': 'application/json-rpc'}
    if auth and token: head['Authorization'] = 'Bearer ' + token
    req = urllib.request.Request(URL + '/api_jsonrpc.php', json.dumps({'jsonrpc': '2.0', 'method': method, 'params': params, 'id': 1}).encode(), head)
    r = json.load(urllib.request.urlopen(req, timeout=120))
    if 'error' in r: raise RuntimeError('%s: %s %s' % (method, r['error'].get('message'), r['error'].get('data')))
    return r['result']

def say(msg): print(time.strftime('%H:%M:%S'), msg, flush=True)

say('Waiting for Zabbix at %s (the first start builds the database, allow a few minutes)' % URL)
while True:
    try:
        v = call('apiinfo.version', [], auth=False)
        token = call('user.login', {'username': USER, 'password': PASSWORD}, auth=False)
        break
    except Exception:
        time.sleep(5)
say('Zabbix %s is up' % v)

rules = {k: {'createMissing': True, 'updateExisting': True} for k in ('templates', 'items', 'triggers', 'template_groups')}
rules['items']['deleteMissing'] = True; rules['triggers']['deleteMissing'] = True
call('configuration.import', {'format': 'yaml', 'source': open('/templates/topology_templates.yaml').read(), 'rules': rules})
say('Templates imported')

have = {m['id']: m for m in call('module.get', {'output': ['moduleid', 'id', 'status']})}
for mid, path in (('topomap', 'modules/topomap'), ('topomap_widget', 'modules/topomap-widget')):
    if mid not in have: call('module.create', {'id': mid, 'relative_path': path, 'status': 1})
    elif str(have[mid]['status']) != '1': call('module.update', {'moduleid': have[mid]['moduleid'], 'status': 1})
say('Topology module enabled')

first = not call('hostgroup.get', {'filter': {'name': 'Topology demo'}, 'output': ['groupid']})
r = subprocess.run([sys.executable, '-B', 'setup.py', '--url', URL, '--token', token, '--sim-host', SIM] + (['--poll'] if first else []))
if r.returncode: sys.exit('setup.py failed')

# Demo traffic: trapper items under the same keys the stock interface templates use.
ports = link_ports()
hostid = {h['host']: h['hostid'] for h in call('host.get', {'filter': {'host': list(DEVICES)}, 'output': ['hostid', 'host']})}
frac = {}
for a, ap, b, bp in LINKS:
    u = UTILISATION.get((a, b), UTILISATION.get((b, a), random.uniform(.03, .2)))
    frac[(a, ap)] = frac[(b, bp)] = u
KEYS = ('net.if.status[ifOperStatus.%d]', 'net.if.speed[ifHighSpeed.%d]', 'net.if.in[ifHCInOctets.%d]', 'net.if.out[ifHCOutOctets.%d]')
existing = {(i['hostid'], i['key_']) for i in call('item.get', {'hostids': list(hostid.values()), 'output': ['hostid', 'key_'], 'search': {'key_': 'net.if.'}})}
new = [{'hostid': hostid[d], 'name': 'Demo ' + (k % i), 'key_': k % i, 'type': 2, 'value_type': 3, 'history': '1d', 'trends': '0',
        'tags': [{'tag': 'component', 'value': 'demo'}]}
       for (d, p), i in ports.items() if d in hostid for k in KEYS if (hostid[d], k % i) not in existing]
if new:
    call('item.create', new)
    say('Created %d demo traffic items; waiting for the server to load them' % len(new)); time.sleep(75)

say('Ready: open the web interface, sign in as %s / %s, Monitoring > Topology, group "Topology demo"' % (USER, PASSWORD))
while True:
    vals = []
    for (d, p), i in ports.items():
        if d not in hostid: continue
        sp, u = port_speed(p), min(.98, frac.get((d, p), .1) * random.uniform(.92, 1.08))
        vals += [{'host': d, 'key': KEYS[0] % i, 'value': 1}, {'host': d, 'key': KEYS[1] % i, 'value': sp},
                 {'host': d, 'key': KEYS[2] % i, 'value': int(sp * u)}, {'host': d, 'key': KEYS[3] % i, 'value': int(sp * u * .6)}]
    try:
        token = call('user.login', {'username': USER, 'password': PASSWORD}, auth=False)
        bad = [x for x in call('history.push', vals)['data'] if 'error' in x]
        if bad: say('traffic push: %d of %d values refused (%s)' % (len(bad), len(vals), bad[0]['error']))
    except Exception as e:
        say('traffic push failed, will retry: %s' % e)
    time.sleep(300)
