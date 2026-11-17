#!/usr/bin/env python3
"""Sends every request the template would send through the awkward agent.

Needs snmpsim (pip install snmpsim) and the net-snmp tools. For each walk[] item
in the generated template it sends the same first request Zabbix sends (all of the
item's OIDs in one GETBULK, 10 repetitions, the Zabbix default) and then walks each
OID to the end. Everything must be answered. A nine-column request is sent first to
prove the agent really does drop what it should.
"""
import os, re, shutil, signal, subprocess, sys, tempfile, time
import yaml

H = os.path.dirname(os.path.abspath(__file__))
SIM, PICKY, REPS = 21170, 21171, 10

tpl = yaml.safe_load(open(os.path.join(H, '..', '..', 'templates', 'topology_templates.yaml')))
walks = []
for t in tpl['zabbix_export']['templates']:
    macros = {m['macro']: m.get('value', '') for m in t.get('macros', [])}
    for it in t['items']:
        m = re.match(r'walk\[(.*)\]$', it.get('snmp_oid', ''))
        if m:
            oids = [macros.get(o.strip(), o.strip()) for o in m.group(1).split(',')]
            walks.append((t['template'], it['key'], oids))

tmp = tempfile.mkdtemp(prefix='topopicky')
os.chmod(tmp, 0o755)
shutil.copytree(os.path.join(H, 'data'), tmp + '/data')
os.makedirs(tmp + '/cache')
for r, d, f in os.walk(tmp):
    for x in d + f: os.chmod(os.path.join(r, x), 0o777 if x == 'cache' else 0o755)
cmd = ['snmpsim-command-responder', '--data-dir=' + tmp + '/data', '--cache-dir=' + tmp + '/cache',
       '--agent-udpv4-endpoint=127.0.0.1:%d' % SIM]
if os.geteuid() == 0: cmd += ['--process-user=nobody', '--process-group=nogroup']
procs = [subprocess.Popen(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL),
         subprocess.Popen([sys.executable, os.path.join(H, 'picky_agent.py'), str(PICKY), str(SIM)], stdout=subprocess.DEVNULL)]

def snmp(tool, oids, extra=()):
    r = subprocess.run([tool, '-v2c', '-c', 'sw1', '-On', '-t', '2', '-r', '0', *extra, '127.0.0.1:%d' % PICKY, *oids],
                       capture_output=True, text=True)
    return r.returncode == 0 and 'Timeout' not in r.stderr, r.stdout

ok = fail = 0
def check(name, cond):
    global ok, fail
    print(('ok    ' if cond else 'FAIL  ') + name)
    if cond: ok += 1
    else: fail += 1

try:
    for _ in range(40):
        time.sleep(0.5)
        if snmp('snmpget', ['1.3.6.1.2.1.1.5.0'])[0]: break
    check('agent answers a plain get', snmp('snmpget', ['1.3.6.1.2.1.1.5.0'])[0])
    nine = ['1.0.8802.1.1.2.1.4.1.1.%d' % c for c in (4, 5, 6, 7, 8, 9, 10, 11, 12)]
    check('agent stays silent on a nine-column request (the trap is armed)', not snmp('snmpbulkget', nine, ['-Cr%d' % REPS])[0])
    for tname, key, oids in walks:
        check('%s: first request answered (%d OID%s)' % (key, len(oids), '' if len(oids) == 1 else 's'),
              snmp('snmpbulkget', oids, ['-Cr%d' % REPS])[0])
        for o in oids:
            good, out = snmp('snmpbulkwalk', [o], ['-Cr%d' % REPS])
            check('%s: %s walks to the end' % (key, o), good)
finally:
    for p in procs: p.send_signal(signal.SIGTERM)
    shutil.rmtree(tmp, ignore_errors=True)
print('%d passed, %d failed' % (ok, fail))
sys.exit(1 if fail else 0)
