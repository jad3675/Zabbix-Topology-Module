<?php
// Uplink detection that does not depend on the switch's own LLDP/CDP.
// Models a switch whose own LLDP is not being collected.
require __DIR__.'/bootstrap.php';
use Modules\TopoMap\Lib\Correlator;
$ok = 0; $fail = 0;
function check($name, $cond) { global $ok, $fail; $cond ? $ok++ : $fail++; echo ($cond ? 'PASS ' : 'FAIL ').$name."\n"; }
$H = fn($id,$n,$ips) => ['hostid'=>$id,'name'=>$n,'host'=>$n,'ips'=>$ips,'site'=>'','role'=>'','topo_ids'=>[],'external'=>false,'available'=>1];
$hosts = ['1'=>$H('1','sw1',['10.0.0.254']), '2'=>$H('2','rtr1',['10.0.0.1']), '3'=>$H('3','hv1',['10.0.0.200'])];

// 57 upstream MACs on gi13 (ifIndex 61), incl. two MikroTik interface MACs next to rtr1's chassis b869f4000001
$up = ['b869f4000002','b869f4000009']; for ($i = 0; $i < 55; $i++) $up[] = sprintf('80691a%06x', $i);
$vms = ['bc2411000101','bc2411000102','bc2411000103'];
$base = fn($withNames, $withArp) => [
  '1' => array_filter([
     'ifnames' => $withNames ? ['61'=>['gi13'],'62'=>['gi14'],'63'=>['gi15']] : null,
     'fdb' => ['e'=>['61'=>$up, '62'=>array_merge(['14b31f000061'], $vms), '63'=>['001132090909']], 'u'=>[], 't'=>62],
     'arp' => $withArp ? ['10.0.0.1'=>'b869f4000002','10.0.0.200'=>'14b31f000061','10.0.0.150'=>'bc2411000101'] : null,
  ]),
  '2' => ['local'=>['cid'=>'b869f4000001','sys'=>'rtr1','p'=>[]], 'ifnames'=>['9'=>['ether9']], 'fdb'=>['e'=>['3'=>['5a0000000001']],'u'=>[],'t'=>1]],
  '3' => ['local'=>['cid'=>'aabbcc000200','sys'=>'hv1.example.net','p'=>[]], 'ifnames'=>['2'=>['nic0']]],
];
$withFarEnd = function($v) {
  $v['2']['lldp'] = [['lp'=>'0','cst'=>4,'cid'=>'88f077000035','pst'=>5,'pid'=>'gi13','sys'=>'sw1','cap'=>'B','ip'=>'10.0.0.254']];
  $v['3']['lldp'] = [['lp'=>'2','cst'=>4,'cid'=>'88f077000035','pst'=>5,'pid'=>'gi14','sys'=>'sw1','cap'=>'B','ip'=>'10.0.0.254']];
  return $v;
};
$ports = function($r, $hid) { $o = []; foreach ($r['hosts'][$hid]['ports'] as $p) $o[$p['name']] = $p; return $o; };
$behind = fn($r) => array_merge([], ...array_map(fn($p) => array_column($p['macs'], 'm'), array_filter($r['hosts']['3']['ports'], fn($p) => !empty($p['behind']))));

echo "-- A: switch LLDP missing, neighbors report it, port names known, ARP present\n";
$r = (new Correlator($hosts, $withFarEnd($base(true, true))))->build(); $P = $ports($r, '1');
check('gi13 (uplink to rtr1) lists no devices', empty($P['gi13']['macs']));
check('gi14 lists no devices on the switch', empty($P['gi14']['macs']));
check('VMs shown behind hv1, its own NIC excluded', $behind($r) == $vms);
check('gi15 endpoint still on the switch', array_column($P['gi15']['macs'], 'm') == ['001132090909']);

echo "-- B: no port names and no neighbor reports, ARP present (infrastructure MACs only)\n";
$r = (new Correlator($hosts, $base(false, true)))->build(); $P = $ports($r, '1');
check('ifIndex 61 marked uplink via rtr1 MAC, no devices', empty($P['ifIndex 61']['macs']) && preg_grep('/MAC of rtr1/', $P['ifIndex 61']['nb']));
check('ifIndex 62 inferred as direct link to hv1', (bool) preg_grep('/MAC of hv1/', $P['ifIndex 62']['nb']));
check('VMs shown behind hv1', $behind($r) == $vms);
$e = array_values(array_filter($r['edges'], fn($e) => in_array('h3', [$e['from'], $e['to']]) && in_array('h1', [$e['from'], $e['to']])));
check('switch-hv1 drawn as an inferred FDB link (dashed)', count($e) == 1 && $e[0]['links'][0]['proto'] == 'fdb' && $e[0]['one_sided']);
check('no inferred link from switch to rtr1 (could be several hops)', !array_filter($r['edges'], fn($e) => in_array('h2', [$e['from'], $e['to']]) && in_array('h1', [$e['from'], $e['to']])));

echo "-- C: no names, no ARP, no reports, chassis unknown (majority rule only)\n";
$v = $base(false, false); unset($v['2']['local']);
$r = (new Correlator($hosts, $v))->build(); $P = $ports($r, '1');
check('ifIndex 61 marked uplink by majority, no devices', empty($P['ifIndex 61']['macs']) && preg_grep('/carries 57 of the 62/', $P['ifIndex 61']['nb']));
check('small ports untouched by the majority rule', count($P['ifIndex 63']['macs']) == 1);

echo "-- D: MikroTik interface MAC in rtr1's chassis block is enough without ARP\n";
$v = $base(false, false);
$r = (new Correlator($hosts, $v))->build(); $P = $ports($r, '1');
check('ifIndex 61 marked uplink via chassis block', (bool) preg_grep('/MAC of rtr1/', $P['ifIndex 61']['nb']));

echo "\n$ok passed, $fail failed\n"; exit($fail ? 1 : 0);
