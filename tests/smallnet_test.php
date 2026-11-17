<?php
// Models a small network: access switch whose port names are not polled yet,
// LLDP and CDP from the switch, a router with a bridge pseudo-port, lldpd on a hypervisor.
require __DIR__.'/bootstrap.php';
use Modules\TopoMap\Lib\Correlator;
$H = fn($id,$n,$ip) => ['hostid'=>$id,'name'=>$n,'host'=>$n,'ips'=>[$ip],'site'=>'','role'=>'','topo_ids'=>[],'external'=>false,'available'=>1];
$hosts = ['1'=>$H('1','sw1','10.0.0.254'), '2'=>$H('2','rtr1','10.0.0.1'), '3'=>$H('3','hv1','10.0.0.200')];
$vals = [
 '1' => [ // access switch: no port names, no LLDP local data yet
   'lldp' => [
     ['lp'=>'61','cst'=>4,'cid'=>'14b31f000061','pst'=>5,'pid'=>'bridge/ether9','sys'=>'rtr1','cap'=>'BR','ip'=>'10.0.0.1'],
     ['lp'=>'61','cst'=>4,'cid'=>'0018dd000ad4','pst'=>5,'pid'=>'eth0','sys'=>'10.0.0.58','cap'=>'B','ip'=>'10.0.0.58'],
     ['lp'=>'62','cst'=>4,'cid'=>'aabbcc000200','pst'=>5,'pid'=>'nic0','sys'=>'hv1.example.net','cap'=>'BR','ip'=>'10.0.0.200'],
   ],
   'cdp' => [
     ['lp'=>'61','sys'=>'rtr1','pid'=>'bridge/ether9','ip'=>'10.0.0.1','cap'=>'BR'],
     ['lp'=>'62','sys'=>'hv1.example.net','pid'=>'nic0','ip'=>'10.0.0.200','cap'=>'BR'],
   ],
   'fdb' => ['e'=>['61'=>['0018dd000ad4'],'62'=>['aabbcc000200']],'u'=>[],'t'=>2],
 ],
 '2' => [ // router with ARP, LLDP on bridge pseudo-port 0
   'local' => ['cst'=>4,'cid'=>'14b31f000061','sys'=>'rtr1','p'=>[]],
   'ifnames' => ['1'=>['ether1','',1,1000],'9'=>['ether9','',1,1000]],
   'lldp' => [
     ['lp'=>'0','cst'=>4,'cid'=>'88f077000035','pst'=>5,'pid'=>'gi13','sys'=>'sw1','cap'=>'B','ip'=>'10.0.0.254'],
     ['lp'=>'0','cst'=>4,'cid'=>'0018dd000ad4','pst'=>5,'pid'=>'eth0','sys'=>'10.0.0.58','cap'=>'B','ip'=>'10.0.0.58'],
   ],
   'arp' => ['10.0.0.58'=>'0018dd000ad4','10.0.0.200'=>'aabbcc000200'],
   'fdb' => ['e'=>['1'=>['5a0000000001','0018dd000001']],'u'=>[],'t'=>2],
 ],
 '3' => [ // proxmox lldpd, no ifnames
   'local' => ['cst'=>4,'cid'=>'aabbcc000200','sys'=>'hv1.example.net','p'=>['2'=>['id'=>'nic0']]],
   'lldp' => [['lp'=>'2','cst'=>4,'cid'=>'88f077000035','pst'=>5,'pid'=>'gi14','sys'=>'sw1','cap'=>'B','ip'=>'10.0.0.254']],
 ],
];
$hosts['1']['ips']=['10.0.0.254']; $vals['1']['local']=['cst'=>4,'cid'=>'88f077000035','sys'=>'sw1','p'=>[]];
$r = (new Correlator($hosts, $vals, ['2'=>4,'1'=>3]))->build();
foreach ($r['nodes'] as $n) printf("%-6s %-10s L%d %s\n", $n['id'], $n['kind'], $n['level'], str_replace("\n",' / ',$n['label']));
foreach ($r['edges'] as $e) { printf("EDGE %s -> %s%s\n", $e['from'],$e['to'],$e['one_sided']?' (one-sided)':''); foreach($e['links'] as $l) printf("     %s <-> %s [%s]\n",$l['a'],$l['b'],$l['proto']); }
foreach (['1','2','3'] as $h) { echo "-- ", $r['hosts'][$h]['name'], "\n"; foreach ($r['hosts'][$h]['ports'] as $p) printf("   %-24s %s | macs=%d\n", $p['name'].($p['guess']?' *':''), implode('; ',$p['nb']), count($p['macs'])); }
