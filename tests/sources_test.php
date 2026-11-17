<?php
// Settings, the source registry, tag-declared links and a drop-in source.
require __DIR__.'/bootstrap.php';
use Modules\TopoMap\Lib\{Correlator, Settings};
use Modules\TopoMap\Lib\Sources\{Registry, Source};
$ok = 0; $fail = 0;
function check($n, $c) { global $ok, $fail; $c ? $ok++ : $fail++; echo ($c ? 'PASS ' : 'FAIL ').$n."\n"; }
$H = fn($id,$n,$ips,$tags=[]) => ['hostid'=>$id,'name'=>$n,'host'=>$n,'ips'=>$ips,'site'=>'','role'=>'','topo_ids'=>[],'tags'=>$tags,'external'=>false,'available'=>1];
$edge = function($r, $a, $b) { foreach ($r['edges'] as $e) if ([$e['from'],$e['to']] == [$a,$b] || [$e['from'],$e['to']] == [$b,$a]) return $e; return null; };
$node = function($r, $label) { foreach ($r['nodes'] as $n) if (explode("\n", $n['label'])[0] === $label) return $n; return null; };

echo "-- registry\n";
check('LLDP, CDP and tag sources found, in priority order', array_keys(Registry::sources()) === ['lldp', 'cdp', 'tag']);
check('value keys cover sources and supporting data', array_keys(Registry::valueKeys()) == ['topo.ifnames','topo.lldp.local','topo.fdb.edge','topo.arp','topo.lldp.neighbors','topo.cdp.neighbors']);
check('tag source needs no items', !isset(Registry::statusRows()['tag']));

echo "-- tag-declared links\n";
$hosts = [
  '1' => $H('1','sw1',['10.0.0.2']),
  '2' => $H('2','ups1',['10.0.0.50'], [['tag'=>'topo.link','value'=>'sw1||gi7']]),
  '3' => $H('3','pdu1',['10.0.0.51'], [['tag'=>'topo.link','value'=>'lobby-dumb-switch'], ['tag'=>'other','value'=>'x']]),
];
$vals = ['1' => [
  'local'=>['cid'=>'88f077000035','sys'=>'sw1','p'=>[]], 'ifnames'=>['7'=>['gi7'],'8'=>['gi8']],
  'fdb'=>['e'=>['7'=>['00c0b7000001'],'8'=>['001132000001']],'u'=>[],'t'=>2], 'arp'=>['10.0.0.50'=>'00c0b7000001'],
]];
$r = (new Correlator($hosts, $vals))->build();
$e = $edge($r, 'h1', 'h2');
check('ups1 linked to sw1 from its tag alone', $e !== null && $e['links'][0]['proto'] === 'tag');
check('declared link is not drawn as "seen from one side"', $e && !$e['one_sided'] && !$e['links'][0]['one_sided']);
$ports = []; foreach ($r['hosts']['1']['ports'] as $p) $ports[$p['name']] = $p;
check('the far port from the tag lands on the real switch port', isset($ports['gi7']) && (bool) preg_grep('/ups1/', $ports['gi7']['nb']));
check("the UPS's own MAC is not listed as a device on gi7", empty($ports['gi7']['macs']));
check('unrelated port keeps its endpoint', count($ports['gi8']['macs']) === 1);
$u = $node($r, 'lobby-dumb-switch');
check('a neighbor that is not in Zabbix becomes an unmanaged node', $u !== null && $u['kind'] === 'unmanaged' && $edge($r, 'h3', $u['id']) !== null);

echo "-- settings\n";
Settings::init(['tags' => ['link' => 'net.link'], 'bogus' => 1, 'util' => ['warn' => '65']]);
check('unknown settings dropped, numbers coerced', !isset(Settings::all()['bogus']) && Settings::get('util.warn') === 65 && Settings::get('util.high') === 80);
check('only differences are stored', Settings::diff(Settings::all()) == ['tags' => ['site'=>'site','role'=>'topo.role','id'=>'topo.id','link'=>'net.link'], 'util' => ['warn'=>65,'high'=>80]]);
$r = (new Correlator($hosts, $vals))->build();
check('renamed link tag: old tag name no longer creates links', $edge($r, 'h1', 'h2') === null || $edge($r, 'h1', 'h2')['links'][0]['proto'] !== 'tag');
$hosts['2']['tags'] = [['tag'=>'net.link','value'=>'sw1|mgmt|gi7']];
$r = (new Correlator($hosts, $vals))->build(); $e = $edge($r, 'h1', 'h2');
check('renamed link tag works, both ports carried', $e && in_array('mgmt', [$e['links'][0]['a'], $e['links'][0]['b']]) && in_array('gi7', [$e['links'][0]['a'], $e['links'][0]['b']]));

Settings::init(['roles' => ['spine' => 0, 'leaf' => 1]]);
$h2 = ['1'=>$H('1','a',[]), '2'=>$H('2','b',[])]; $h2['1']['role']='leaf'; $h2['2']['role']='spine';
$v2 = ['1'=>['lldp'=>[['lp'=>'1','sys'=>'b','cap'=>'B']]], '2'=>['lldp'=>[['lp'=>'1','sys'=>'a','cap'=>'B']]]];
$r = (new Correlator($h2, $v2))->build();
check('custom role names set the layout rows', $node($r,'b')['level'] === 0 && $node($r,'a')['level'] === 1);

Settings::init(['if_keys' => ['status' => ['ifOperStatus[{ifname}]', 'net.if.status[ifOperStatus.{ifindex}]']]]);
check('key patterns expand {ifname} and {ifindex}', Settings::ifKeys('status', '61', 'gi13') === ['ifOperStatus[gi13]' => 0, 'net.if.status[ifOperStatus.61]' => 1]);
check('patterns needing a missing value are skipped', Settings::ifKeys('status', 'lp0', '') === []);

Settings::init(['sources_disabled' => ['cdp']]);
check('a source can be switched off', array_keys(Registry::sources()) === ['lldp', 'tag']);
$h3 = ['1'=>$H('1','a',[]), '2'=>$H('2','b',[])];
$r = (new Correlator($h3, ['1'=>['cdp'=>[['lp'=>'1','sys'=>'b','pid'=>'Gi0/1','cap'=>'BR']]]]))->build();
check('its data is then ignored', $edge($r, 'h1', 'h2') === null);
Settings::init(['data_disabled' => ['arp', 'fdb']]);
check('supporting data can be switched off: items no longer fetched', !isset(Registry::valueKeys()['topo.arp']) && !isset(Registry::valueKeys()['topo.fdb.edge']) && isset(Registry::valueKeys()['topo.ifnames']));
check('and their rows leave the Data collection panel', array_keys(Registry::statusRows()) === ['ifnames', 'local', 'lldp', 'cdp']);
Settings::init(['data_disabled' => ['bogus']]);
check('nothing breaks on an unknown slot name', count(Registry::valueKeys()) === 6);
Settings::init(null);
check('defaults store nothing', Settings::diff(Settings::all()) === []);

echo "-- drop-in source\n";
// What a third protocol looks like: ~20 lines, no change to the correlator.
class StaticMap implements Source {
  public function id(): string { return 'fdp'; }
  public function priority(): int { return 30; }
  public function label(): string { return 'FDP neighbors'; }
  public function items(): array { return ['value' => 'topo.fdp.neighbors', 'walk' => 'topo.fdp.walk']; }
  public function declared(): bool { return false; }
  public function observe(array $host, ?array $value): array {
    $out = [];
    foreach ($value ?? [] as $nb) $out[] = ['port' => ['type' => 'ifindex', 'value' => (string) $nb['if']], 'nb' => ['sys' => $nb['name'], 'port' => $nb['rport'], 'cap' => 'B']];
    return $out;
  }
}
Registry::register(new StaticMap());
check('registered source is listed between CDP and tags', array_keys(Registry::sources()) === ['lldp', 'cdp', 'fdp', 'tag']);
check('its item is fetched and shown in Data collection', (Registry::valueKeys()['topo.fdp.neighbors'] ?? '') === 'fdp' && Registry::statusRows()['fdp'][0] === 'FDP neighbors');
$r = (new Correlator($h3, ['1'=>['ifnames'=>['5'=>['e5']],'fdp'=>[['if'=>5,'name'=>'b','rport'=>'e9']]]]))->build(); $e = $edge($r, 'h1', 'h2');
check('and it draws links with no correlator changes', $e && $e['links'][0]['proto'] === 'fdp' && [$e['links'][0]['a'], $e['links'][0]['b']] == ['e5', 'e9']);
Registry::reset();

echo "\n$ok passed, $fail failed\n"; exit($fail ? 1 : 0);
