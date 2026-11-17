import dukpy, json, sys, os
H = os.path.dirname(os.path.abspath(__file__))
common = open(H + '/js/common.js').read()
def run(script, value):
    body = open(H + '/js/' + script).read()
    code = common + '\nfunction __f(value){\n' + body + '\n}\n__f(dukpy.value);'
    return dukpy.evaljs(code, value=value)

lldp_local = """.1.0.8802.1.1.2.1.3.1.0 = INTEGER: 4
.1.0.8802.1.1.2.1.3.2.0 = Hex-STRING: 00 1A 2B 3C 4D 5E
.1.0.8802.1.1.2.1.3.3.0 = STRING: "acc-sw1.example.net"
.1.0.8802.1.1.2.1.3.7.1.2.1 = INTEGER: 5
.1.0.8802.1.1.2.1.3.7.1.2.49 = INTEGER: 5
.1.0.8802.1.1.2.1.3.7.1.3.1 = STRING: "Gi1/0/1"
.1.0.8802.1.1.2.1.3.7.1.3.49 = STRING: "Te1/1/1"
.1.0.8802.1.1.2.1.3.7.1.4.1 = STRING: "GigabitEthernet1/0/1"
.1.0.8802.1.1.2.1.3.7.1.4.49 = STRING: "TenGigabitEthernet1/1/1"
"""
print('lldp_local', run('lldp_local.js', lldp_local))

lldp_rem = """.1.0.8802.1.1.2.1.4.1.1.4.0.49.1 = INTEGER: 4
.1.0.8802.1.1.2.1.4.1.1.5.0.49.1 = Hex-STRING: 00 AA BB CC DD 01
.1.0.8802.1.1.2.1.4.1.1.6.0.49.1 = INTEGER: 5
.1.0.8802.1.1.2.1.4.1.1.7.0.49.1 = STRING: "Ethernet1/12"
.1.0.8802.1.1.2.1.4.1.1.8.0.49.1 = STRING: "to acc-sw1"
.1.0.8802.1.1.2.1.4.1.1.9.0.49.1 = STRING: "core-n9k-1"
.1.0.8802.1.1.2.1.4.1.1.12.0.49.1 = Hex-STRING: 28 00
.1.0.8802.1.1.2.1.4.1.1.4.0.5.3 = INTEGER: 4
.1.0.8802.1.1.2.1.4.1.1.5.0.5.3 = STRING: "AB012F"
.1.0.8802.1.1.2.1.4.1.1.6.0.5.3 = INTEGER: 3
.1.0.8802.1.1.2.1.4.1.1.7.0.5.3 = Hex-STRING: 64 16 7F 01 02 03
.1.0.8802.1.1.2.1.4.1.1.9.0.5.3 = STRING: "SEP64167F010203"
.1.0.8802.1.1.2.1.4.1.1.12.0.5.3 = Hex-STRING: 24 00
.1.0.8802.1.1.2.1.4.2.1.3.0.49.1.1.4.10.1.0.1 = INTEGER: 2
.1.0.8802.1.1.2.1.4.2.1.3.0.5.3.1.4.10.20.30.40 = INTEGER: 2
"""
print('lldp_rem', run('lldp_remote.js', lldp_rem))
print('lldp_empty', run('lldp_remote.js', ''))
print('lldp_nosuch', run('lldp_remote.js', '.1.0.8802.1.1.2.1.4.1.1 = No Such Object available on this agent at this OID'))

cdp = """.1.3.6.1.4.1.9.9.23.1.2.1.1.4.10101.1 = Hex-STRING: 0A 01 00 01
.1.3.6.1.4.1.9.9.23.1.2.1.1.6.10101.1 = STRING: "core-n9k-1.example.net(FDO12345)"
.1.3.6.1.4.1.9.9.23.1.2.1.1.7.10101.1 = STRING: "Ethernet1/12"
.1.3.6.1.4.1.9.9.23.1.2.1.1.8.10101.1 = STRING: "N9K-C93180YC-FX"
.1.3.6.1.4.1.9.9.23.1.2.1.1.9.10101.1 = Hex-STRING: 00 00 02 29
"""
print('cdp', run('cdp.js', cdp))

fdb = [".1.3.6.1.2.1.17.1.4.1.2.1 = INTEGER: 10101", ".1.3.6.1.2.1.17.1.4.1.2.49 = INTEGER: 10149"]
fdb += [".1.3.6.1.2.1.17.7.1.2.2.1.2.10.0.80.86.1.2.3 = INTEGER: 1",
        ".1.3.6.1.2.1.17.7.1.2.2.1.2.20.0.80.86.1.2.3 = INTEGER: 1",
        ".1.3.6.1.2.1.17.7.1.2.2.1.2.10.100.22.127.1.2.3 = INTEGER: 1",
        ".1.3.6.1.2.1.17.7.1.2.2.1.2.10.0.26.43.60.77.95 = INTEGER: 0"]
for i in range(40):
    fdb.append(".1.3.6.1.2.1.17.7.1.2.2.1.2.10.0.17.34.51.%d.%d = INTEGER: 49" % (i // 200, i % 200))
fdbv = "\n".join(fdb)
r = run('fdb.js', fdbv); print('fdb', r)
# size guard
big = [".1.3.6.1.2.1.17.7.1.2.2.1.2.1.2.%d.%d.%d.%d.%d = INTEGER: %d" % (p, a, b, 1, 1, p) for p in range(1, 2000) for a in range(5) for b in [7]]
r2 = run('fdb.js', "\n".join(big)); print('fdb big len', len(r2), json.loads(r2).get('x'))

ifn = """.1.3.6.1.2.1.31.1.1.1.1.10101 = STRING: "Gi1/0/1"
.1.3.6.1.2.1.31.1.1.1.1.10149 = STRING: "Te1/1/1"
.1.3.6.1.2.1.2.2.1.2.5 = STRING: "Vlan10"
"""
print('ifnames', run('ifnames.js', ifn))
arp = """.1.3.6.1.2.1.4.22.1.2.12.10.1.0.5 = Hex-STRING: 00 50 56 01 02 03
.1.3.6.1.2.1.4.22.1.2.12.10.1.0.6 = STRING: "PV\\x01"
.1.3.6.1.2.1.4.35.1.4.12.1.4.10.1.0.9 = Hex-STRING: 64 16 7F 01 02 03
.1.3.6.1.2.1.4.35.1.4.12.2.16.254.128.0.0.0.0.0.0.0.0.0.0.0.0.0.1 = Hex-STRING: 64 16 7F 01 02 03
"""
print('arp', run('arp.js', arp))
