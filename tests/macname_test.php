<?php
// A router whose neighbor table names the switch by MAC, not by name: the CDP device ID
// of a small-business switch ("88f077000035") with the far port's MAC as chassis ID.
// It is still the switch we already have, not a second, unmanaged device.
require __DIR__.'/bootstrap.php';
use Modules\TopoMap\Lib\Correlator;
$ok = 0; $fail = 0;
function check($n, $c) { global $ok, $fail; $c ? $ok++ : $fail++; echo ($c ? 'PASS ' : 'FAIL ').$n."\n"; }
$H = fn($id,$n,$ips) => ['hostid'=>$id,'name'=>$n,'host'=>$n,'ips'=>$ips,'site'=>'','role'=>'','topo_ids'=>[],'external'=>false,'available'=>1];
$hosts = ['1'=>$H('1','sw1',['10.0.0.254']), '2'=>$H('2','rtr1',['10.0.0.1'])];
$run = function(string $swCid, array $arp) use ($hosts) {
  $vals = [
   '2' => ['local'=>['cst'=>null,'cid'=>'','sys'=>'rtr1','p'=>['9'=>['d'=>'ether9','id'=>'B8:69:F4:00:00:09']]],
     'ifnames'=>['9'=>['ether9'],'12'=>['bridge']],
     'lldp'=>[['lp'=>'0','cst'=>4,'pst'=>5,'sys'=>'88f077000035','sd'=>'','cap'=>'R','cid'=>'88f077000042','pid'=>'gi13']],
     'arp'=>$arp],
   '1' => ['local'=>['cst'=>4,'cid'=>$swCid,'sys'=>'sw1','p'=>[]], 'ifnames'=>['61'=>['gi13']],
     'lldp'=>[['lp'=>'61','cst'=>4,'pst'=>5,'pd'=>'','sys'=>'rtr1','cap'=>'BR','ip'=>'10.0.0.1','cid'=>'b869f4000001','pid'=>'bridge/ether9']]],
  ];
  $r = (new Correlator($hosts, $vals, [], [], ['endpoints'=>2,'unmanaged'=>true]))->build();
  return [count(array_filter($r['nodes'], fn($n)=>$n['kind']==='unmanaged')),
          count(array_filter($r['edges'], fn($e)=>strpos((string)$e['from'],'h')===0 && strpos((string)$e['to'],'h')===0))];
};
check('matched through the switch chassis MAC block', $run('88f077000035', []) === [0, 1]);
check('matched through ARP when the switch publishes no chassis ID', $run('', ['10.0.0.254'=>'88f077000035']) === [0, 1]);
check('with neither, it stays an unmanaged neighbor', $run('', [])[0] === 1);
echo "\n$ok passed, $fail failed\n"; exit($fail ? 1 : 0);
