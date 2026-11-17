# The LLDP and CDP walks are single-subtree requests, so the parsers see every column and table
# under the subtree, not only the ones they need. This checks they pick out the right ones.
import dukpy, json, os
H=os.path.dirname(os.path.abspath(__file__)); common=open(H+'/js/common.js').read()
run=lambda f,v: json.loads(dukpy.evaljs(common+'\nfunction __f(value){\n'+open(H+'/js/'+f).read()+'\n}\n__f(dukpy.value);', value=v))
rem='''.1.0.8802.1.1.2.1.4.1.1.4.0.0.1 = INTEGER: 4
.1.0.8802.1.1.2.1.4.1.1.4.0.0.3 = INTEGER: 4
.1.0.8802.1.1.2.1.4.1.1.5.0.0.1 = Hex-STRING: 88 F0 77 00 00 35
.1.0.8802.1.1.2.1.4.1.1.5.0.0.3 = Hex-STRING: 80 69 1A 00 00 58
.1.0.8802.1.1.2.1.4.1.1.6.0.0.1 = INTEGER: 5
.1.0.8802.1.1.2.1.4.1.1.6.0.0.3 = INTEGER: 3
.1.0.8802.1.1.2.1.4.1.1.7.0.0.1 = STRING: "gi13"
.1.0.8802.1.1.2.1.4.1.1.7.0.0.3 = Hex-STRING: 80 69 1A 00 00 58
.1.0.8802.1.1.2.1.4.1.1.8.0.0.1 = STRING: "gigabitethernet13"
.1.0.8802.1.1.2.1.4.1.1.8.0.0.3 = STRING: "eth0"
.1.0.8802.1.1.2.1.4.1.1.9.0.0.1 = STRING: "sw1"
.1.0.8802.1.1.2.1.4.1.1.9.0.0.3 = STRING: "10.0.0.58"
.1.0.8802.1.1.2.1.4.1.1.10.0.0.1 = ""
.1.0.8802.1.1.2.1.4.1.1.10.0.0.3 = STRING: "Velop"
.1.0.8802.1.1.2.1.4.1.1.11.0.0.1 = Hex-STRING: 20 00
.1.0.8802.1.1.2.1.4.1.1.11.0.0.3 = Hex-STRING: 38 00
.1.0.8802.1.1.2.1.4.1.1.12.0.0.1 = Hex-STRING: 20 00
.1.0.8802.1.1.2.1.4.1.1.12.0.0.3 = Hex-STRING: 38 00
.1.0.8802.1.1.2.1.4.2.1.3.0.0.1.1.4.10.0.0.254 = INTEGER: 2
.1.0.8802.1.1.2.1.4.2.1.4.0.0.1.1.4.10.0.0.254 = INTEGER: 9
.1.0.8802.1.1.2.1.4.2.1.3.0.0.3.1.4.10.0.0.58 = INTEGER: 1
.1.0.8802.1.1.2.1.4.4.1.4.0.0.1.0.18.15.1.1 = Hex-STRING: 03 6C 00 00 10'''
out={n['sys']: n for n in run('lldp_remote.js',rem)}
assert len(out)==2 and out['sw1']['ip']=='10.0.0.254' and out['sw1']['pid']=='gi13' and out['sw1']['cap']=='B' and out['sw1']['sd']=='', out
v=out['10.0.0.58']; assert v['sd']=='Velop' and v['pd']=='eth0' and v['cid']=='80691a000058' and v['lp']=='0', v
loc='''.1.0.8802.1.1.2.1.3.1.0 = INTEGER: 4
.1.0.8802.1.1.2.1.3.2.0 = Hex-STRING: B8 69 F4 00 00 01
.1.0.8802.1.1.2.1.3.3.0 = STRING: "rtr1"
.1.0.8802.1.1.2.1.3.4.0 = STRING: "RouterOS 7.4.1 (stable) RB4011"
.1.0.8802.1.1.2.1.3.5.0 = Hex-STRING: 28 00
.1.0.8802.1.1.2.1.3.6.0 = Hex-STRING: 28 00
.1.0.8802.1.1.2.1.3.7.1.2.9 = INTEGER: 5
.1.0.8802.1.1.2.1.3.7.1.3.9 = STRING: "ether9"
.1.0.8802.1.1.2.1.3.7.1.4.9 = STRING: "to switch"
.1.0.8802.1.1.2.1.3.8.1.3.1.4.10.0.0.1 = INTEGER: 5
.1.0.8802.1.1.2.1.3.8.1.5.1.4.10.0.0.1 = INTEGER: 2'''
out=run('lldp_local.js',loc)
assert out['cid']=='b869f4000001' and out['sys']=='rtr1' and out['p']=={'9':{'id':'ether9','d':'to switch'}}, out
cdp='''.1.3.6.1.4.1.9.9.23.1.2.1.1.3.61.1 = INTEGER: 1
.1.3.6.1.4.1.9.9.23.1.2.1.1.4.61.1 = Hex-STRING: 0A 00 00 01
.1.3.6.1.4.1.9.9.23.1.2.1.1.5.61.1 = STRING: "RouterOS 7.4.1
second line"
.1.3.6.1.4.1.9.9.23.1.2.1.1.6.61.1 = STRING: "rtr1"
.1.3.6.1.4.1.9.9.23.1.2.1.1.7.61.1 = STRING: "bridge/ether9"
.1.3.6.1.4.1.9.9.23.1.2.1.1.8.61.1 = STRING: "RB4011"
.1.3.6.1.4.1.9.9.23.1.2.1.1.9.61.1 = Hex-STRING: 00 00 00 09
.1.3.6.1.4.1.9.9.23.1.2.1.1.11.61.1 = INTEGER: 1
.1.3.6.1.4.1.9.9.23.1.2.1.1.12.61.1 = INTEGER: 3'''
out=run('cdp.js',cdp)
assert out==[{'lp':'61','ip':'10.0.0.1','sys':'rtr1','pid':'bridge/ether9','plat':'RB4011','cap':'BR'}], out
# guard: no topology walk may ask for more than 3 OIDs in one request
import yaml, re
tpl=yaml.safe_load(open(H+'/../../templates/topology_templates.yaml'))
for t in tpl['zabbix_export']['templates']:
    for i in t['items']:
        m=re.match(r'walk\[(.*)\]$', i.get('snmp_oid',''))
        if m: assert len(m.group(1).split(','))<=3, (i['key'], i['snmp_oid'])
print('subtree parser checks passed; no walk asks for more than 3 OIDs')
