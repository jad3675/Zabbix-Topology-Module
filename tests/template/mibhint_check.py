#!/usr/bin/env python3
"""The same device data as a Zabbix server or proxy WITH MIB files loaded prints it.

With MIBs present, net-snmp formats binary values by the MIB's display hint, so a MAC
arrives as text instead of Hex-STRING. The first line below is copied from a real
Zabbix item test. Every parser has to give the same answer for both spellings.
"""
import json, os, sys
H = os.path.dirname(os.path.abspath(__file__)); sys.path.insert(0, H)
import dukpy
common = open(H + '/js/common.js').read()
def run(script, value):
    body = open(H + '/js/' + script).read()
    return json.loads(dukpy.evaljs(common + '\nfunction __f(value){\n' + body + '\n}\n__f(dukpy.value);', value=value))

plain = '''.1.3.6.1.2.1.4.22.1.2.11.10.9.8.1 = Hex-STRING: 00 1A 2B 3C 4D 5E
.1.3.6.1.2.1.4.22.1.2.12.10.0.0.2 = Hex-STRING: 00 50 56 01 02 03'''
hinted = '''.1.3.6.1.2.1.4.22.1.2.11.10.9.8.1 = STRING: "00:1a:2b:3c:4d:5e"
.1.3.6.1.2.1.4.22.1.2.12.10.0.0.2 = STRING: 0:50:56:1:2:3'''
want = {'10.0.0.2': '005056010203', '10.9.8.1': '001a2b3c4d5e'}
assert run('arp.js', plain) == want, run('arp.js', plain)
assert run('arp.js', hinted) == want, run('arp.js', hinted)

cdp = '''.1.3.6.1.4.1.9.9.23.1.2.1.1.4.61.1 = %s
.1.3.6.1.4.1.9.9.23.1.2.1.1.6.61.1 = STRING: "rtr1"
.1.3.6.1.4.1.9.9.23.1.2.1.1.7.61.1 = STRING: "ether9"
.1.3.6.1.4.1.9.9.23.1.2.1.1.9.61.1 = %s'''
a = run('cdp.js', cdp % ('Hex-STRING: 0A 00 00 01', 'Hex-STRING: 00 00 00 09'))
b = run('cdp.js', cdp % ('STRING: "a:0:0:1"', 'STRING: "0:0:0:9"'))
assert a == b and a[0]['ip'] == '10.0.0.1' and a[0]['cap'] == 'BR', (a, b)

lldp = '''.1.0.8802.1.1.2.1.4.1.1.4.0.5.1 = INTEGER: 4
.1.0.8802.1.1.2.1.4.1.1.5.0.5.1 = %s
.1.0.8802.1.1.2.1.4.1.1.6.0.5.1 = INTEGER: 5
.1.0.8802.1.1.2.1.4.1.1.7.0.5.1 = STRING: "gi13"
.1.0.8802.1.1.2.1.4.1.1.9.0.5.1 = STRING: "sw1"'''
a = run('lldp_remote.js', lldp % 'Hex-STRING: 00 1A 2B 00 00 42')
b = run('lldp_remote.js', lldp % 'STRING: "00:1A:2B:00:00:42"')
assert a == b and '001a2b000042' in json.dumps(a), (a, b)
# ordinary text that merely contains a colon is left alone
c = run('lldp_remote.js', lldp.replace('"gi13"', '"1:13"') % 'Hex-STRING: 00 1A 2B 00 00 42')
assert '1:13' in json.dumps(c), c
print('MIB-formatted values parse the same as raw ones')
