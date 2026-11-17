#!/usr/bin/env python3
"""Writes one snmpsim data file per device in network.py into ./data.

Each device answers on its own SNMP community (the device name), all on one UDP port.
The files hold exactly the tables the topology templates walk: LLDP local and remote,
CDP, interface names, the Q-BRIDGE MAC table and, on gateways, ARP.
"""
import os, random, shutil
from network import DEVICES, LINKS, CDP, UNMANAGED, ENDPOINTS, WIFI, GUESTS, GATEWAYS, link_ports

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(HERE, 'data')
L = '1.0.8802.1.1.2.1'; R = L + '.4.1.1'; C = '1.3.6.1.4.1.9.9.23.1.2.1.1'
FDB = '1.3.6.1.2.1.17.7.1.2.2.1.2.1.'
CAPS = {'R': '0800', 'B': '2000', 'BR': '2800', 'BW': '3000', 'BS': '2100'}       # LLDP capability bits
CDPCAPS = {'R': '00000001', 'B': '00000008', 'BR': '00000009', 'BW': '00000008', 'BS': '00000010'}
OUIS = ['14b31f', '3c5282', 'f4f5e8', 'a4bb6d', '001b21', '9c8e99', '0004f2', 'fcecda']
random.seed(7)

mi = lambda m: '.'.join(str(int(m[i:i + 2], 16)) for i in range(0, 12, 2))
iphex = lambda ip: ''.join('%02x' % int(x) for x in ip.split('.'))
ports = {d: {} for d in DEVICES}
for (_d, _p), _i in link_ports().items(): ports[_d][_p] = _i      # link ports first, numbered as setup expects
def ifx(d, p):
    if p not in ports[d]: ports[d][p] = len(ports[d]) + 1
    return ports[d][p]
rows = {d: [('1.3.6.1.2.1.1.1.0', '4', DEVICES[d][4] + ' (simulated)'), ('1.3.6.1.2.1.1.5.0', '4', d)] for d in DEVICES}
below = {d: set() for d in DEVICES}       # MACs reachable through each device, for uplink MAC tables
fdb = {d: {} for d in DEVICES}
arp = {}
_n = 0
def newmac():
    global _n; _n += 1
    return random.choice(OUIS) + '%06x' % (0x100000 + _n)

def remote(d, port, idx, cid, pid, name, cap, ip, descr):
    i = '0.%d.%d' % (ifx(d, port), idx)
    rows[d] += [(f'{R}.4.{i}', '2', '4'), (f'{R}.5.{i}', '4x', cid), (f'{R}.6.{i}', '2', '5'), (f'{R}.7.{i}', '4', pid),
                (f'{R}.8.{i}', '4', pid), (f'{R}.9.{i}', '4', name), (f'{R}.10.{i}', '4', descr),
                (f'{R}.11.{i}', '4x', CAPS[cap]), (f'{R}.12.{i}', '4x', CAPS[cap]),
                (f'{L}.4.2.1.3.{i}.1.4.{ip}', '2', '2')]

for a, ap, b, bp in LINKS:
    for x, xp, y, yp in ((a, ap, b, bp), (b, bp, a, ap)):
        ip, role, mac, cap, plat = DEVICES[y]
        remote(x, xp, 1, mac, yp, y, cap, ip, plat)
        if x in CDP and y in CDP:
            i = '%d.1' % ifx(x, xp)
            rows[x] += [(f'{C}.4.{i}', '4x', iphex(ip)), (f'{C}.6.{i}', '4', y), (f'{C}.7.{i}', '4', yp),
                        (f'{C}.8.{i}', '4', plat), (f'{C}.9.{i}', '4x', CDPCAPS[cap])]
for d, port, name, mac, rport, ip, descr in UNMANAGED:
    remote(d, port, 1, mac, rport, name, 'R', ip, descr)

# End devices, wireless clients, hypervisors and their guests
for sw, count in ENDPOINTS.items():
    for i in range(1, count + 1):
        m = newmac(); fdb[sw].setdefault(ifx(sw, 'Gi1/0/%d' % i), []).append(m)
        arp['10.20.%d.%d' % (10 + int(DEVICES[sw][0].split('.')[-1]), 20 + i)] = m
for a, ap, b, bp in LINKS:
    if b in WIFI:
        clients = [newmac() for _ in range(WIFI[b])]
        fdb[a].setdefault(ifx(a, ap), []).extend([DEVICES[b][2]] + clients)
        for k, m in enumerate(clients): arp['10.20.%d.%d' % (20 + int(DEVICES[b][0].split('.')[-1]), 50 + k)] = m
    if b.startswith('hv-'):
        vms = [m for g, (ip, m, hv) in GUESTS.items() if hv == b]
        fdb[a].setdefault(ifx(a, ap), []).extend([DEVICES[b][2]] + vms)
for g, (ip, m, hv) in GUESTS.items(): arp[ip] = m
for d, (ip, role, mac, cap, plat) in DEVICES.items(): arp[ip] = mac

# What sits below each switch shows up on the uplink ports of the switches above it,
# the way a real MAC table looks. The map has to work out that those ports are uplinks.
def gather(d, seen):
    macs = {m for ms in fdb[d].values() for m in ms}
    for a, ap, b, bp in LINKS:
        if a == d and b not in seen: macs |= gather(b, seen | {d}) | {DEVICES[b][2]}
    return macs
ORDER = ['dist-sw1', 'dist-sw2']
for d in ORDER:
    for a, ap, b, bp in LINKS:
        if a == d and DEVICES[b][1] in ('access',):
            fdb[d].setdefault(ifx(d, ap), []).extend(sorted(gather(b, {d}) | {DEVICES[b][2]}))
for a, ap, b, bp in LINKS:                 # every access switch sees the rest of the network on its uplink
    if DEVICES[b][1] == 'access':
        fdb[b].setdefault(ifx(b, bp), []).extend([DEVICES[a][2], DEVICES['core-rtr1'][2], DEVICES['core-rtr2'][2]])

for d, (ip, role, mac, cap, plat) in DEVICES.items():
    r = rows[d]
    r += [(L + '.3.1.0', '2', '4'), (L + '.3.2.0', '4x', mac), (L + '.3.3.0', '4', d), (L + '.3.4.0', '4', plat + ' (simulated)')]
    bridge = role in ('distribution', 'access')      # routers, access points and servers publish no MAC table
    for p, i in ports[d].items():
        speed = 10000 if p.startswith(('Te', 'ens')) else 1000
        r += [(f'{L}.3.7.1.2.{i}', '2', '5'), (f'{L}.3.7.1.3.{i}', '4', p), (f'{L}.3.7.1.4.{i}', '4', p),
              (f'1.3.6.1.2.1.31.1.1.1.1.{i}', '4', p), (f'1.3.6.1.2.1.2.2.1.2.{i}', '4', p), (f'1.3.6.1.2.1.31.1.1.1.18.{i}', '4', ''),
              (f'1.3.6.1.2.1.2.2.1.8.{i}', '2', '1'), (f'1.3.6.1.2.1.31.1.1.1.15.{i}', '66', str(speed))]
        if bridge: r.append((f'1.3.6.1.2.1.17.1.4.1.2.{i}', '2', str(i)))
    for port, macs in fdb[d].items():
        for m in dict.fromkeys(macs): r.append((FDB + mi(m), '2', str(port)))
    if d in GATEWAYS:
        for ip2, m in arp.items():
            if ip2 != ip: r.append((f'1.3.6.1.2.1.4.22.1.2.1.{ip2}', '4x', m))

shutil.rmtree(OUT, ignore_errors=True); os.makedirs(OUT)
key = lambda o: [int(x) for x in o.split('.')]
for d, r in rows.items():
    seen = {}
    for o, t, v in r: seen[o] = (t, v)
    with open(os.path.join(OUT, d + '.snmprec'), 'w') as f:
        for o in sorted(seen, key=key): f.write('%s|%s|%s\n' % (o, seen[o][0], seen[o][1]))
print('%d devices, %d end devices and clients, %d guests -> %s' % (len(rows), _n, len(GUESTS), OUT))
