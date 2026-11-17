<?php declare(strict_types = 0);

/**
 * @var CView $this
 * @var array $data
 */

$s = $data['settings'];
$d = $data['defaults'];

$text = fn(string $name, $value, int $width = ZBX_TEXTAREA_SMALL_WIDTH) =>
	(new CTextBox($name, (string) $value))->setWidth($width);
$area = fn(string $name, array $lines) =>
	(new CTextArea($name, implode("\n", $lines)))->setWidth(ZBX_TEXTAREA_BIG_WIDTH)->setAttribute('rows', max(2, count($lines) + 1));
$hint = fn(string $t) => (new CDiv($t))->addClass('topomap-set-hint');
$row = fn(string $label, $field, ?string $help = null) => [
	new CLabel($label),
	new CFormField($help !== null ? [$field, $hint($help)] : $field)
];
$head = fn(string $t, string $sub) => (new CFormField([new CTag('h4', true, $t), $hint($sub)]))->addClass('topomap-set-head');

$grid = new CFormGrid();

// ---- sources
$grid->addItem($head(_('Where links come from'),
	_('Each source is one way of learning that two devices are connected. Switch one off to ignore it everywhere.')));
foreach ($data['sources'] as $src) {
	$grid->addItem($row($src['label'],
		(new CCheckBox('sources['.$src['id'].']', '1'))->setChecked($src['enabled'])->setLabel(_('Use')),
		$src['item'] !== null
			? sprintf(_('Reads the item %1$s.'), $src['item'])
			: _('Reads host tags; needs no items and no polling.')
	));
}

// ---- supporting data
$grid->addItem($head(_('Supporting data'),
	_('What the map reads besides neighbor tables. None of these create links. How often each is collected, and from which OID, is set per device with template macros; the switches here only decide whether the map uses the data.')));
foreach ($data['data_rows'] as $r) {
	$grid->addItem($row($r['label'],
		[
			(new CCheckBox('data['.$r['id'].']', '1'))->setChecked($r['enabled'])->setLabel(_('Use')),
			$hint($r['use']),
			$hint(_('If off').': '.$r['lost']),
			$hint(sprintf(_('Item %1$s on template "%2$s". Macros: %3$s'), $r['item'], $r['template'], $r['macros']))
		]
	));
}

// ---- tags
$grid->addItem($head(_('Host tags'), _('Tag names this module reads from your hosts.')));
$grid->addItem($row(_('Site'), $text('tags[site]', $s['tags']['site']), _('Groups switches by location ("Group by site").')));
$grid->addItem($row(_('Role'), $text('tags[role]', $s['tags']['role']), _('Pins a device to a row of the layered layout. Values are the role names below.')));
$grid->addItem($row(_('Extra name'), $text('tags[id]', $s['tags']['id']), _('Another name a device goes by in neighbor tables, when it differs from its Zabbix host name.')));
$grid->addItem($row(_('Declared link'), $text('tags[link]', $s['tags']['link']),
	_('States a link by hand, for gear that reports no neighbors (UPS, PDU, unmanaged switch). Value: neighbor, or neighbor|my port, or neighbor|my port|their port.')));

$roles = [];
foreach ($s['roles'] as $name => $level) {
	$roles[] = $name.' = '.$level;
}
$grid->addItem($row(_('Roles'), $area('roles', $roles), _('One per line as "name = row". Row 0 is the top of the map.')));

// ---- interface items
$grid->addItem($head(_('Interface items'),
	_('Port status, speed and link utilisation are read from items your interface templates already collect. One key pattern per line, first match wins. {ifindex} is the interface index, {ifname} the port name.')));
$grid->addItem($row(_('Operational status'), $area('if_keys[status]', $s['if_keys']['status'])));
$grid->addItem($row(_('Speed'), $area('if_keys[speed]', $s['if_keys']['speed']), _('Values under 100000 are taken as Mbps, larger ones as bps.')));
$grid->addItem($row(_('Bits received'), $area('if_keys[in]', $s['if_keys']['in'])));
$grid->addItem($row(_('Bits sent'), $area('if_keys[out]', $s['if_keys']['out'])));
$grid->addItem($row(_('Utilisation colours'), [
	$text('util[warn]', $s['util']['warn'], ZBX_TEXTAREA_TINY_WIDTH), ' % ', _('amber'), '   ',
	$text('util[high]', $s['util']['high'], ZBX_TEXTAREA_TINY_WIDTH), ' % ', _('red')
]));

// ---- detection
$grid->addItem($head(_('Detection'), _('How the map interprets what it collected.')));
$grid->addItem($row(_('Heartbeat'), $text('detect[heartbeat]', $data['heartbeat'], ZBX_TEXTAREA_TINY_WIDTH),
	_('Must equal {$TOPO.HEARTBEAT} on the templates. Used to tell "unchanged" from "stopped collecting".')));
$grid->addItem($row(_('Uplink by majority'), $text('detect[majority_min]', $s['detect']['majority_min'], ZBX_TEXTAREA_TINY_WIDTH),
	_('A port holding most of a switch\'s learned MACs, and at least this many, is treated as an uplink.')));
$grid->addItem($row(_('Move window'), $text('detect[move_window]', $data['move_window'], ZBX_TEXTAREA_TINY_WIDTH),
	_('A MAC that leaves one port and appears on another within this time is reported as a move. Keep it a little above the MAC table interval.')));
$grid->addItem($row(_('Proxy offline after'), $text('detect[proxy_offline]', $data['proxy_offline'], ZBX_TEXTAREA_TINY_WIDTH),
	_('A proxy that has not contacted the server for this long is named as the reason its hosts show no data.')));
$grid->addItem($row(_('Own MAC block'), $text('detect[own_block]', $s['detect']['own_block'], ZBX_TEXTAREA_TINY_WIDTH),
	_('Leading hex digits (6 to 12) a device\'s own port MACs share with its chassis MAC. 10 suits most vendors.')));
$grid->addItem($row(_('Product name length'), $text('detect[product_label_max]', $s['detect']['product_label_max'], ZBX_TEXTAREA_TINY_WIDTH),
	_('An LLDP system description up to this long labels an unmanaged device; longer ones are firmware banners and are ignored.')));
$grid->addItem($row(_('Reverse DNS'), [
	(new CCheckBox('dns[enabled]', '1'))->setChecked((bool) $s['dns']['enabled'])->setLabel(_('Look up names for unmanaged devices')),
	'   ', $text('dns[budget_ms]', $s['dns']['budget_ms'], ZBX_TEXTAREA_TINY_WIDTH), ' ', _('ms at most per map build')
]));

// ---- limits
$grid->addItem($head(_('Limits'), _('Guard rails for large installations.')));
$grid->addItem($row(_('Hosts per map'), $text('limits[max_hosts]', $s['limits']['max_hosts'], ZBX_TEXTAREA_TINY_WIDTH)));
$grid->addItem($row(_('Cache'), [$text('limits[cache_ttl]', $s['limits']['cache_ttl'], ZBX_TEXTAREA_TINY_WIDTH), ' ', _('seconds a built map is reused per user')]));
$grid->addItem($row(_('Changes: hosts'), $text('limits[changes_max_hosts]', $s['limits']['changes_max_hosts'], ZBX_TEXTAREA_TINY_WIDTH),
	_('Above this many hosts the Changes tab skips endpoint moves.')));
$grid->addItem($row(_('Changes: events'), $text('limits[changes_max_events]', $s['limits']['changes_max_events'], ZBX_TEXTAREA_TINY_WIDTH)));
$grid->addItem($row(_('Changes: history rows'), $text('limits[changes_max_rows]', $s['limits']['changes_max_rows'], ZBX_TEXTAREA_TINY_WIDTH)));

$grid->addItem(new CFormActions(new CSubmit('save', _('Save')), [
	(new CSubmit('reset', _('Reset to defaults')))->setAttribute('value', '1')->addClass(ZBX_STYLE_BTN_ALT),
	(new CRedirectButton(_('Back to map'), (new CUrl('zabbix.php'))->setArgument('action', 'topomap.view')))
]));

$form = (new CForm('post', (new CUrl('zabbix.php'))->setArgument('action', 'topomap.settings.save')->getUrl()))
	->setName('topomap_settings')
	->addItem((new CVar(CSRF_TOKEN_NAME, CCsrfTokenHelper::get('topomap.settings.save')))->removeId())
	->addItem($grid);

(new CHtmlPage())
	->setTitle($data['title'])
	->addItem((new CDiv($form))->addClass('topomap-settings'))
	->show();
