<?php
// A device's MAC table lists its own port MACs (MikroTik and many others). Those must not
// be counted as devices. Also: unmanaged neighbours labelled by short LLDP sysDesc.
// Modelled on a router whose MAC table includes its own port MACs.
require __DIR__.'/bootstrap.php';
use Modules\TopoMap\Lib\Correlator;
$ok = 0; $fail = 0;
function check($n, $c) { global $ok, $fail; $c ? $ok++ : $fail++; echo ($c ? 'PASS ' : 'FAIL ').$n."\n"; }
$H = fn($id,$n,$ips) => ['hostid'=>$id,'name'=>$n,'host'=>$n,'ips'=>$ips,'site'=>'','role'=>'','topo_ids'=>[],'external'=>false,'available'=>1];
$hosts = ['2'=>$H('2','rtr1',['10.0.0.1'])];
$vals = ['2' => [
  'local' => ['cid'=>'b869f4000001','sys'=>'rtr1','p'=>[]],
  'ifnames' => ['3'=>['ether3-roomba'],'4'=>['ether4-emby'],'7'=>['ether7'],'10'=>['NAS-igc1:']],
  'lldp' => [['lp'=>'0','cst'=>4,'cid'=>'80691a000058','pst'=>3,'pid'=>'80691a000058','sys'=>'10.0.0.58','sd'=>'Velop','cap'=>'B','ip'=>'10.0.0.58']],
  'fdb' => ['e'=>[
     '3'=>['14b31f0003ed','b869f4000003'], '4'=>['001132aaaaaa','b869f4000004'],
     '7'=>['5a1122334455','b869f4000007'], '10'=>['0011320b0b0b','b869f400000a'],
  ],'u'=>[],'t'=>8],
  'arp' => ['10.0.0.2'=>'14b31f0003ed'],
]];
$r = (new Correlator($hosts, $vals, [], [], ['endpoints'=>2]))->build();
$P = []; foreach ($r['hosts']['2']['ports'] as $p) $P[$p['name']] = $p;
foreach (['ether3-roomba','ether4-emby','ether7','NAS-igc1:'] as $port)
  check("$port lists 1 device, not the router's own port MAC", count($P[$port]['macs']) == 1 && strpos($P[$port]['macs'][0]['m'], 'b869f40000') !== 0);
check('Dell on ether3-roomba keeps its ARP IP', $P['ether3-roomba']['macs'][0]['ip'] === '10.0.0.2');
$bub = array_values(array_filter($r['nodes'], fn($n) => $n['kind'] === 'port'));
check('every port bubble says 1 device', count($bub) == 4 && !array_filter($bub, fn($n) => $n['count'] != 1));
$u = array_values(array_filter($r['nodes'], fn($n) => $n['kind'] === 'unmanaged'));
check('.58 labelled with vendor and product from LLDP', count($u) == 1 && $u[0]['label'] === "Belkin Velop\n10.0.0.58");
// A neighbour whose sysDesc is long stays on the vendor label
$vals['2']['lldp'][0]['sd'] = 'Some very long firmware banner string that is not a product name';
$r = (new Correlator($hosts, $vals))->build();
$u = array_values(array_filter($r['nodes'], fn($n) => $n['kind'] === 'unmanaged'));
check('long sysDesc ignored for the label', $u[0]['label'] === "Belkin\n10.0.0.58");
// The same router as it really answers: no chassis ID at all, only per-port IDs, written as text.
$vals['2']['local'] = ['cst'=>null,'cid'=>'','sys'=>'rtr1','p'=>['3'=>['d'=>'ether3-roomba','id'=>'B8:69:F4:00:00:03'],'12'=>['d'=>'bridge','id'=>'B8:69:F4:00:00:02']]];
$r = (new Correlator($hosts, $vals, [], [], ['endpoints'=>2]))->build();
$P = []; foreach ($r['hosts']['2']['ports'] as $p) $P[$p['name']] = $p;
foreach (['ether3-roomba','ether4-emby','ether7','NAS-igc1:'] as $port)
  check("no chassis ID: $port still lists 1 device", count($P[$port]['macs']) == 1 && strpos($P[$port]['macs'][0]['m'], 'b869f40000') !== 0);
echo "\n$ok passed, $fail failed\n"; exit($fail ? 1 : 0);
