<?php
require __DIR__.'/bootstrap.php';
use Modules\TopoMap\Lib\{Correlator, Net};

$hosts = [
  '1' => ['hostid'=>'1','name'=>'core-n9k-1','host'=>'core-n9k-1','ips'=>['10.1.0.1'],'site'=>'Main','role'=>'core','topo_ids'=>[],'external'=>false,'available'=>1],
  '2' => ['hostid'=>'2','name'=>'acc-sw1','host'=>'acc-sw1','ips'=>['10.1.0.11'],'site'=>'Main','role'=>'','topo_ids'=>[],'external'=>false,'available'=>1],
  '3' => ['hostid'=>'3','name'=>'acc-sw2','host'=>'acc-sw2','ips'=>['10.1.0.12'],'site'=>'Main','role'=>'','topo_ids'=>[],'external'=>false,'available'=>2],
];
$vals = [
  '1' => [
    'local' => ['cst'=>4,'cid'=>'00aabbccdd01','sys'=>'core-n9k-1.example.net','p'=>[]],
    'ifnames' => ['436207616'=>'Ethernet1/12','436207617'=>'Ethernet1/13','436207618'=>'Ethernet1/14'],
    'lldp' => [
      ['lp'=>'436207616','cst'=>4,'cid'=>'001a2b3c4d5e','pst'=>5,'pid'=>'Te1/1/1','sys'=>'acc-sw1.example.net','cap'=>'BR'],
    ],
    'cdp' => [
      ['lp'=>'436207616','sys'=>'acc-sw1.example.net(FOC1)','pid'=>'TenGigabitEthernet1/1/1','ip'=>'10.1.0.11','cap'=>'BR'],
      ['lp'=>'436207617','sys'=>'acc-sw2','pid'=>'GigabitEthernet1/0/48','ip'=>'10.1.0.12','cap'=>'BR'],
      ['lp'=>'436207618','sys'=>'mystery-sw','pid'=>'Gi0/1','ip'=>'10.1.0.99','cap'=>'B','plat'=>'WS-C2960'],
    ],
    'fdb' => ['e'=>[],'u'=>['436207616'=>40,'436207617'=>20],'t'=>60],
    'arp' => ['10.1.5.20'=>'005056010203','10.1.5.21'=>'64167f010203','10.1.5.22'=>'aabbcc000001'],
    'clock' => time(),
  ],
  '2' => [
    'local' => ['cst'=>4,'cid'=>'001a2b3c4d5e','sys'=>'acc-sw1.example.net','p'=>['1'=>['id'=>'Gi1/0/1','d'=>'GigabitEthernet1/0/1'],'49'=>['id'=>'Te1/1/1']]],
    'ifnames' => ['10101'=>'Gi1/0/1','10102'=>'Gi1/0/2','10149'=>'Te1/1/1'],
    'lldp' => [
      ['lp'=>'49','cst'=>4,'cid'=>'00aabbccdd01','pst'=>5,'pid'=>'Ethernet1/12','sys'=>'core-n9k-1','cap'=>'BR'],
      ['lp'=>'1','cst'=>4,'cid'=>'64167f010203','pst'=>3,'pid'=>'64167f010203','sys'=>'SEP64167F010203','cap'=>'BT','ip'=>'10.1.5.21'],
    ],
    'fdb' => ['e'=>['10101'=>['005056010203','64167f010203'],'10102'=>['aabbcc000001'],'10149'=>['00aabbccdd01']],'u'=>[],'t'=>4],
    'clock' => time(),
  ],
  '3' => [
    'ifnames' => ['10148'=>'Gi1/0/48','10105'=>'Gi1/0/5'],
    'lldp' => [
      ['lp'=>'5','cst'=>4,'cid'=>'f0f0f0000005','pst'=>7,'pid'=>'eth0','sys'=>'ap-lobby','cap'=>'BW','ip'=>'10.1.9.5'],
    ],
    'fdb' => ['e'=>['10105'=>['f0f0f0000005','aabbcc000001']],'u'=>[],'t'=>3],
    'clock' => time(),
  ],
];
$util = ['1'=>['436207616'=>['in'=>2e9,'out'=>5e8,'speed'=>10000000000]]];
foreach ([1,2] as $ep) {
  $r = (new Correlator($hosts, $vals, ['3'=>4], $util, ['endpoints'=>$ep,'unmanaged'=>true]))->build();
  echo "=== endpoints=$ep\n";
  foreach ($r['nodes'] as $n) printf("%-18s %-10s L%d %s\n", $n['id'], $n['kind'], $n['level'], str_replace("\n",' / ',$n['label']));
  foreach ($r['edges'] as $e) { printf("EDGE %s -> %s util=%s one_sided=%s\n", $e['from'],$e['to'],var_export($e['util'],true),var_export($e['one_sided'],true)); foreach($e['links'] as $l) printf("     %s <-> %s [%s]%s\n",$l['a'],$l['b'],$l['proto'],$l['one_sided']?' one-sided':''); }
  print_r($r['stats']); print_r($r['warnings']);
}
foreach ($r['hosts']['2']['ports'] as $p) { echo $p['name'],' nb=',implode(';',$p['nb']),' macs=',json_encode($p['macs']),"\n"; }
echo Net::port('TenGigabitEthernet1/1/1'),' ',Net::port('Te1/1/1'),' ',Net::port('Ethernet1/12'),' ',Net::port('Eth1/12'),"\n";
