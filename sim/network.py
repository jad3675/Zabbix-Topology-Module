"""The simulated network. Edit this, run generate.py, restart the simulator.

Everything here is invented: names, addresses and MACs belong to no real network.
"""
# name: (management IP, topo.role tag, chassis MAC, LLDP capabilities, platform)
DEVICES = {
    'core-rtr1':    ('10.20.0.1',  'core',         '70b5e8000100', 'R',  'Core router'),
    'core-rtr2':    ('10.20.0.2',  'core',         '70b5e8000200', 'R',  'Core router'),
    'dist-sw1':     ('10.20.0.11', 'distribution', '88f077001100', 'BR', 'Distribution switch'),
    'dist-sw2':     ('10.20.0.12', 'distribution', '88f077001200', 'BR', 'Distribution switch'),
    'acc-sw1':      ('10.20.1.1',  'access',       '88f077010100', 'B',  'Access switch'),
    'acc-sw2':      ('10.20.1.2',  'access',       '88f077010200', 'B',  'Access switch'),
    'acc-sw3':      ('10.20.1.3',  'access',       '88f077010300', 'B',  'Access switch'),
    'acc-sw4':      ('10.20.1.4',  'access',       '88f077010400', 'B',  'Access switch'),
    'acc-sw5':      ('10.20.1.5',  'access',       '88f077010500', 'B',  'Access switch'),
    'dc-sw1':       ('10.20.1.9',  'access',       '88f077010900', 'B',  'Data centre switch'),
    'ap-floor1':    ('10.20.2.1',  'edge',         'd8b370000101', 'BW', 'Access point'),
    'ap-floor2':    ('10.20.2.2',  'edge',         'd8b370000102', 'BW', 'Access point'),
    'ap-floor3':    ('10.20.2.3',  'edge',         'd8b370000103', 'BW', 'Access point'),
    'ap-lobby':     ('10.20.2.4',  'edge',         'd8b370000104', 'BW', 'Access point'),
    'ap-warehouse': ('10.20.2.5',  'edge',         'd8b370000105', 'BW', 'Access point'),
    'hv-node1':     ('10.20.3.1',  '',             'ac1f6b000301', 'BS', 'Linux hypervisor'),
    'hv-node2':     ('10.20.3.2',  '',             'ac1f6b000302', 'BS', 'Linux hypervisor'),
}
# (device, port, device, port). Both ends announce each other by LLDP.
LINKS = [
    ('core-rtr1', 'Te1/0/1', 'core-rtr2', 'Te1/0/1'),
    ('core-rtr1', 'Te1/0/2', 'dist-sw1', 'Te1/1/1'), ('core-rtr1', 'Te1/0/3', 'dist-sw2', 'Te1/1/1'),
    ('core-rtr2', 'Te1/0/2', 'dist-sw1', 'Te1/1/2'), ('core-rtr2', 'Te1/0/3', 'dist-sw2', 'Te1/1/2'),
    ('dist-sw1', 'Te1/1/4', 'dist-sw2', 'Te1/1/4'),
    ('dist-sw1', 'Gi1/0/1', 'acc-sw1', 'Gi1/0/48'), ('dist-sw1', 'Gi1/0/2', 'acc-sw2', 'Gi1/0/48'),
    ('dist-sw1', 'Gi1/0/3', 'acc-sw3', 'Gi1/0/48'),
    ('dist-sw2', 'Gi1/0/1', 'acc-sw4', 'Gi1/0/48'), ('dist-sw2', 'Gi1/0/2', 'acc-sw5', 'Gi1/0/48'),
    ('dist-sw2', 'Te1/1/3', 'dc-sw1', 'Te1/1/1'),
    ('acc-sw1', 'Gi1/0/45', 'ap-floor1', 'eth0'), ('acc-sw2', 'Gi1/0/45', 'ap-floor2', 'eth0'),
    ('acc-sw3', 'Gi1/0/45', 'ap-floor3', 'eth0'), ('acc-sw4', 'Gi1/0/45', 'ap-lobby', 'eth0'),
    ('acc-sw5', 'Gi1/0/45', 'ap-warehouse', 'eth0'),
    ('dc-sw1', 'Te1/0/1', 'hv-node1', 'ens1f0'), ('dc-sw1', 'Te1/0/2', 'hv-node2', 'ens1f0'),
]
# These links are also announced by CDP, to show the two protocols merging into one link.
CDP = {'core-rtr1', 'core-rtr2', 'dist-sw1', 'dist-sw2', 'acc-sw1', 'acc-sw2', 'acc-sw3', 'acc-sw4', 'acc-sw5', 'dc-sw1'}
# A neighbor that is announced but is not a Zabbix host: (seen by, on port, name, MAC, its port, IP, description)
UNMANAGED = [('core-rtr1', 'Te1/0/8', 'isp-pe-01', '70b5e80fff01', 'xe-0/0/1', '198.51.100.1', 'Carrier PE router')]
# Wired end devices per access switch, one per port from Gi1/0/1 up.
ENDPOINTS = {'acc-sw1': 22, 'acc-sw2': 31, 'acc-sw3': 17, 'acc-sw4': 9, 'acc-sw5': 14, 'dc-sw1': 6}
# Wireless clients behind each access point.
WIFI = {'ap-floor1': 6, 'ap-floor2': 9, 'ap-floor3': 4, 'ap-lobby': 7, 'ap-warehouse': 3}
# Virtual machines: name: (IP, MAC, hypervisor). setup.py creates these as plain Zabbix hosts.
GUESTS = {
    'app-vm1': ('10.20.3.11', 'bc2411000311', 'hv-node1'), 'db-vm1': ('10.20.3.12', 'bc2411000312', 'hv-node1'),
    'web-vm1': ('10.20.3.21', 'bc2411000321', 'hv-node2'), 'zabbix-vm': ('10.20.3.22', 'bc2411000322', 'hv-node2'),
}
# Which devices answer the ARP walk (layer 3 gateways).
GATEWAYS = ['core-rtr1', 'core-rtr2']

# Utilisation shown on a link, as a fraction of its speed. Links not listed get a low
# figure. The demo stack feeds these to Zabbix as interface traffic; nothing measures them.
UTILISATION = {
    ('core-rtr1', 'core-rtr2'): .22, ('core-rtr1', 'dist-sw1'): .31, ('core-rtr2', 'dist-sw2'): .57,
    ('dist-sw1', 'acc-sw2'): .86, ('dist-sw1', 'acc-sw3'): .34, ('dist-sw2', 'acc-sw5'): .41,
    ('dist-sw2', 'dc-sw1'): .63, ('dc-sw1', 'hv-node1'): .44, ('dc-sw1', 'hv-node2'): .29,
}


def link_ports():
    """{(device, port): ifIndex} for every link port, in the order generate.py numbers them."""
    out, count = {}, {}
    def add(d, p):
        if (d, p) not in out:
            count[d] = count.get(d, 0) + 1
            out[(d, p)] = count[d]
    for a, ap, b, bp in LINKS:
        add(a, ap); add(b, bp)
    for d, port, *_ in UNMANAGED:
        add(d, port)
    return out


def port_speed(port):
    return 10_000_000_000 if port.startswith(('Te', 'ens', 'xe')) else 1_000_000_000
