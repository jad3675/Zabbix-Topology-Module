<?php
require __DIR__.'/bootstrap.php';
use Modules\TopoMap\Lib\Correlator;
$hosts = ['1' => ['hostid'=>'1','name'=>'rtr1','host'=>'rtr1','ips'=>['10.0.0.1'],'site'=>'','role'=>'','topo_ids'=>[],'external'=>false,'available'=>1]];
$vals = ['1' => [
  'ifnames' => ['2'=>'ether2-wifi'],
  'lldp' => [['lp'=>'0','cst'=>4,'cid'=>'88f077000035','pst'=>5,'pid'=>'gi14','sys'=>'sw1','cap'=>'B']],
]];
$r = (new Correlator($hosts, $vals))->build();
foreach ($r['hosts']['1']['ports'] as $p) echo $p['name'], ' => ', implode('; ', $p['nb']), "\n";
foreach ($r['edges'] as $e) foreach ($e['links'] as $l) echo 'link: ', $l['a'], ' <-> ', $l['b'], "\n";
