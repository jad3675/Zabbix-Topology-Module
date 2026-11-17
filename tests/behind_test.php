<?php
// MACs learned on a switch port that faces a managed host with no MAC table (a hypervisor)
// are attributed to that host. Infrastructure MACs (chassis IDs) are excluded, a more
// specific edge port elsewhere still wins, and count-only ports carry their count.
require __DIR__.'/bootstrap.php';
use Modules\TopoMap\Lib\Correlator;
$H = fn($id,$n,$ip) => ['hostid'=>$id,'name'=>$n,'host'=>$n,'ips'=>[$ip],'site'=>'','role'=>'','topo_ids'=>[],'external'=>false,'available'=>1];
$hosts = ['1'=>$H('1','switch','10.0.0.2'), '3'=>$H('3','pve1','10.0.0.10'), '4'=>$H('4','pve2','10.0.0.11'), '5'=>$H('5','acc','10.0.0.3')];
$vals = [
 '1' => [
   'local' => ['cid'=>'88f077000035','sys'=>'switch','p'=>[]],
   'ifnames' => ['14'=>['gi14'],'15'=>['gi15'],'16'=>['gi16']],
   'lldp' => [
     ['lp'=>'14','cst'=>4,'cid'=>'aabbcc000200','pst'=>5,'pid'=>'nic0','sys'=>'pve1','cap'=>'BR'],
     ['lp'=>'15','cst'=>4,'cid'=>'aabbcc000300','pst'=>5,'pid'=>'nic0','sys'=>'pve2','cap'=>'BR'],
     ['lp'=>'16','cst'=>4,'cid'=>'aabbcc000400','pst'=>5,'pid'=>'gi1','sys'=>'acc','cap'=>'B'],
   ],
   'arp' => ['10.0.0.10'=>'14b31f000061','10.0.0.150'=>'bc2411000001'],
   'fdb' => ['e'=>[
       '14'=>['aabbcc000200','14b31f000061','bc2411000001','bc2411000002','001132aaaaaa'],  // pve1 own MAC + 2 VMs + one MAC also seen on acc gi5
       '16'=>['00aaaa000001'],                                              // behind acc, which HAS a MAC table: not attributed
     ], 'u'=>['15'=>40], 't'=>45],                                          // pve2 port: count only
 ],
 '3' => ['local'=>['cid'=>'aabbcc000200','sys'=>'pve1','p'=>['2'=>['id'=>'nic0']]], 'ifnames'=>['2'=>['nic0']],
         'lldp'=>[['lp'=>'2','cst'=>4,'cid'=>'88f077000035','pst'=>5,'pid'=>'gi14','sys'=>'switch','cap'=>'B']]],
 '4' => ['local'=>['cid'=>'aabbcc000300','sys'=>'pve2','p'=>[]]],
 '5' => ['local'=>['cid'=>'aabbcc000400','sys'=>'acc','p'=>[]], 'ifnames'=>['1'=>['gi1'],'5'=>['gi5']],
         'fdb'=>['e'=>['5'=>['001132aaaaaa'],'1'=>['00aaaa000001']],'u'=>[],'t'=>2]],
];
$r = (new Correlator($hosts, $vals, [], [], ['endpoints'=>1]))->build();
foreach ($r['nodes'] as $n) if (in_array($n['kind'], ['endpoints'])) echo "bubble ", $n['id'], ' ', $n['label'], "\n";
foreach (['3','4','1','5'] as $h) foreach ($r['hosts'][$h]['ports'] as $p) {
    if (!$p['macs'] && empty($p['hidden'])) continue;
    printf("%-5s %-22s behind=%s hidden=%s macs=%s\n", $r['hosts'][$h]['name'], $p['name'], !empty($p['behind'])?'y':'n', $p['hidden']??0, implode(',', array_map(fn($m)=>$m['m'].'('.($m['v']?:'-').')', $p['macs'])));
}
