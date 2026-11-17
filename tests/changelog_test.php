<?php
const ITEM_VALUE_TYPE_TEXT = 4; const ZBX_SORT_DOWN = 'DESC';
class Stub { public $fn; function __construct($fn){$this->fn=$fn;} function get($o){return ($this->fn)($o);} }
class API {
  static $items, $hist;
  static function Item(){ return new Stub(fn($o)=>array_values(array_filter(self::$items, fn($i)=>in_array($i['key_'],$o['filter']['key_'])))); }
  static function Host(){ return new Stub(fn($o)=>[['hostid'=>'2','name'=>'acc-sw1'],['hostid'=>'3','name'=>'acc-sw2'],['hostid'=>'1','name'=>'core']]); }
  static function History(){ return new Stub(function($o){ $r=array_values(array_filter(self::$hist, fn($h)=>in_array($h['itemid'],$o['itemids']))); usort($r, fn($a,$b)=>$b['clock']<=>$a['clock']); return $r; }); }
}
require __DIR__.'/bootstrap.php';
require __DIR__.'/stubs/Config.php';
require __DIR__.'/../modules/topomap/lib/ChangeLog.php';
$t = time();
API::$items = [
  ['itemid'=>'10','hostid'=>'2','key_'=>'topo.fdb.edge','lastvalue'=>''],
  ['itemid'=>'11','hostid'=>'3','key_'=>'topo.fdb.edge','lastvalue'=>''],
  ['itemid'=>'12','hostid'=>'2','key_'=>'topo.ifnames','lastvalue'=>'{"1":"Gi1/0/1","2":"Gi1/0/2"}'],
  ['itemid'=>'13','hostid'=>'3','key_'=>'topo.ifnames','lastvalue'=>'{"5":"Gi1/0/5"}'],
  ['itemid'=>'14','hostid'=>'1','key_'=>'topo.lldp.neighbors','lastvalue'=>''],
  ['itemid'=>'15','hostid'=>'1','key_'=>'topo.arp','lastvalue'=>''],
];
$f = fn($e)=>json_encode(['e'=>$e,'u'=>[],'t'=>0]);
API::$hist = [
  ['itemid'=>'10','clock'=>$t-90000,'value'=>$f(['1'=>['aaaaaaaaaaaa'],'2'=>['bbbbbbbbbbbb']])], // baseline
  ['itemid'=>'11','clock'=>$t-90000,'value'=>$f(['5'=>['cccccccccccc']])],
  ['itemid'=>'10','clock'=>$t-3600,'value'=>$f(['1'=>['aaaaaaaaaaaa'],'2'=>['dddddddddddd']])],   // b gone, d new
  ['itemid'=>'11','clock'=>$t-3000,'value'=>$f(['5'=>['cccccccccccc','bbbbbbbbbbbb']])],         // b moved to sw2
  ['itemid'=>'10','clock'=>$t-1000,'value'=>$f(['2'=>['dddddddddddd','aaaaaaaaaaaa']])],          // a moved port 1->2 same switch
  ['itemid'=>'14','clock'=>$t-90000,'value'=>'[{"lp":"7","sys":"dist1","pid":"Gi0/1","cap":"BR"}]'],
  ['itemid'=>'14','clock'=>$t-500,'value'=>'[{"lp":"8","sys":"dist1","pid":"Gi0/1","cap":"BR"},{"lp":"9","sys":"SEP1","cap":"BT"}]'],
  ['itemid'=>'15','clock'=>$t-90000,'value'=>'{"10.0.0.5":"bbbbbbbbbbbb"}'],
  ['itemid'=>'15','clock'=>$t-200,'value'=>'{"10.0.0.5":"eeeeeeeeeeee"}'],
];
$r = (new Modules\TopoMap\Lib\ChangeLog([], 86400))->build();
foreach ($r['events'] as $e) printf("%-13s %-8s %-10s %s\n", $e['type'], $e['host'], $e['port'], $e['detail']);
print_r($r['counts']); print_r($r['warnings']);
