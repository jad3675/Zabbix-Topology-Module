<?php
require __DIR__.'/bootstrap.php';
use Modules\TopoMap\Lib\Correlator;
mt_srand(4);
$hosts = []; $vals = []; $id = 100;
$mk = function($name, $role, $site, $ip) use (&$hosts, &$id) { $id++; $hosts[$id] = ['hostid'=>(string)$id,'name'=>$name,'host'=>$name,'ips'=>[$ip],'site'=>$site,'role'=>$role,'topo_ids'=>[],'external'=>false,'available'=>1]; return $id; };
$cid = fn($i) => sprintf('00aa00%06x', $i);
$link = function($a, $ap, $b, $bp) use (&$vals, $cid, &$hosts) {
  $vals[$a]['ifnames'][$ap] = 'Te1/1/'.$ap; $vals[$b]['ifnames'][$bp] = 'Te1/1/'.$bp;
  $vals[$a]['lldp'][] = ['lp'=>(string)$ap,'cst'=>4,'cid'=>$cid($b),'pst'=>5,'pid'=>'Te1/1/'.$bp,'sys'=>$hosts[$b]['name'],'cap'=>'BR'];
  $vals[$b]['lldp'][] = ['lp'=>(string)$bp,'cst'=>4,'cid'=>$cid($a),'pst'=>5,'pid'=>'Te1/1/'.$ap,'sys'=>$hosts[$a]['name'],'cap'=>'BR'];
};
$c1 = $mk('core-n9k-1','core','Main','10.0.0.1'); $c2 = $mk('core-n9k-2','core','Main','10.0.0.2');
$link($c1, 1, $c2, 1); $link($c1, 2, $c2, 2);
$dists = [];
foreach (['Main','Burnet','Liberty'] as $si => $site) {
  foreach ([1,2] as $k) {
    $d = $mk(strtolower($site).'-dist-'.$k, 'distribution', $site, "10.$si.1.$k"); $dists[$site][] = $d;
    $link($c1, 10+$si*2+$k, $d, 49); $link($c2, 10+$si*2+$k, $d, 50);
  }
  for ($a = 1; $a <= 7; $a++) {
    $acc = $mk(strtolower($site).'-acc-'.$a, '', $site, "10.$si.2.$a");
    $link($dists[$site][0], $a, $acc, 49);
    if ($a % 3) $link($dists[$site][1], $a, $acc, 50);
    else { $vals[$acc]['ifnames']['50']='Te1/1/50'; $vals[$dists[$site][1]]['ifnames'][(string)$a]='Te1/1/'.$a; $vals[$dists[$site][1]]['lldp'][] = ['lp'=>(string)$a,'cst'=>4,'cid'=>$cid($acc),'pst'=>5,'pid'=>'Te1/1/50','sys'=>$hosts[$acc]['name'],'cap'=>'BR']; }
    $e = [];
    for ($p = 1; $p <= 24; $p++) { if (mt_rand(0,3)) { $vals[$acc]['ifnames']["$p"]="Gi1/0/$p"; $e["$p"] = [sprintf('%02x%010x', mt_rand(0,1)?0x00:0x5a, $acc*1000+$p)]; } }
    $vals[$acc]['fdb'] = ['e'=>$e,'u'=>['49'=>300],'t'=>count($e)+300];
    $vals[$acc]['local'] = ['cid'=>$cid($acc),'sys'=>$hosts[$acc]['name'],'p'=>[]];
    if ($a == 3) $vals[$acc]['lldp'][] = ['lp'=>'5','cst'=>4,'cid'=>'f0f0f0000005','pst'=>7,'pid'=>'eth0','sys'=>"ap-$site-lobby",'cap'=>'BW','ip'=>"10.$si.9.5"];
  }
}
foreach ($hosts as $hid => $h) { $vals[$hid]['local'] = $vals[$hid]['local'] ?? ['cid'=>$cid($hid),'sys'=>$h['name'],'p'=>[]]; $vals[$hid]['local']['cid']=$cid($hid); $vals[$hid]['clock']=time(); }
$vals[$c1]['cdp'][] = ['lp'=>'40','sys'=>'fw-edge-1','pid'=>'port1','ip'=>'10.0.0.254','cap'=>'R','plat'=>'FortiGate-600F'];
$vals[$c1]['ifnames']['40']='Eth1/40';
$sev = [$hosts ? array_keys($hosts)[9] : 0 => 4, array_keys($hosts)[15] => 2];
$util = [$c1 => ['1'=>['in'=>8.5e9,'out'=>3e9,'speed'=>1e10], '2'=>['in'=>5.6e9,'out'=>1e9,'speed'=>1e10]]];
$out = [];
foreach ([1,2] as $ep) {
  $r = (new Correlator($hosts, $vals, $sev, $util, ['endpoints'=>$ep,'unmanaged'=>true]))->build();
  $sum = []; foreach ($r['hosts'] as $hid=>$h) $sum[$hid]=['ports'=>count($h['ports'])];
  $out[$ep] = ['graph'=>['nodes'=>$r['nodes'],'edges'=>$r['edges'],'summary'=>$sum,'stats'=>$r['stats'],'warnings'=>$r['warnings'],'generated'=>time(),'build_ms'=>412,'cached'=>34], 'hosts'=>$r['hosts']];
}
$t = time();
$out['changes'] = ['events'=>[
  ['clock'=>$t-600,'type'=>'mac_moved','hostid'=>'110','host'=>'main-acc-3','port'=>'Gi1/0/7','detail'=>'00:00:00:01:ad:b7 (10.0.5.20) from main-acc-2 Gi1/0/11'],
  ['clock'=>$t-1800,'type'=>'link_removed','hostid'=>'104','host'=>'main-dist-2','port'=>'Te1/1/3','detail'=>'main-acc-3 Te1/1/50 (LLDP)'],
  ['clock'=>$t-5400,'type'=>'mac_new','hostid'=>'112','host'=>'main-acc-5','port'=>'Gi1/0/2','detail'=>'5a:00:00:01:b5:52'],
  ['clock'=>$t-7200,'type'=>'ip_changed','hostid'=>'101','host'=>'core-n9k-1','port'=>'','detail'=>'10.0.5.20: 00:50:56:01:02:03 to 00:50:56:aa:bb:cc'],
],'counts'=>['mac_moved'=>1,'link_removed'=>1,'mac_new'=>1,'ip_changed'=>1],'warnings'=>[]];
file_put_contents('/tmp/fixture105.json', json_encode($out));
echo count($out[1]['graph']['nodes'])," nodes ", count($out[1]['graph']['edges'])," edges\n"; print_r($out[1]['graph']['stats']);
