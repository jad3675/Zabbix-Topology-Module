<?php declare(strict_types = 0);

/**
 * @var CView $this
 * @var array $data
 */

$this->addJsFile('multiselect.js');

$groups_ms = (new CMultiSelect([
	'name' => 'groupids[]',
	'object_name' => 'hostGroup',
	'data' => $data['groups_ms'],
	'popup' => [
		'parameters' => [
			'srctbl' => 'host_groups',
			'srcfld1' => 'groupid',
			'dstfrm' => 'topomap_filter',
			'dstfld1' => 'groupids_',
			'with_hosts' => true,
			'enrich_parent_groups' => true
		]
	]
]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH);

$endpoints = (new CSelect('endpoints'))
	->setFocusableElementId('topomap-endpoints-select')
	->addOptions(CSelect::createOptionsFromArray([
		0 => _('Hidden'),
		1 => _('One node per switch'),
		2 => _('One node per port')
	]))
	->setValue($data['filter']['endpoints']);

$form = (new CForm('get'))
	->setName('topomap_filter')
	->setId('topomap-filter')
	->addVar('action', 'topomap.view')
	->addVar('filter_set', '1')
	->addItem(
		(new CDiv([
			(new CDiv([new CLabel(_('Host groups'), 'groupids__ms'), $groups_ms]))->addClass('topomap-field'),
			(new CDiv([new CLabel(_('Endpoints'), 'topomap-endpoints-select'), $endpoints]))->addClass('topomap-field'),
			(new CDiv([
				(new CCheckBox('unmanaged'))
					->setChecked($data['filter']['unmanaged'] == 1)
					->setUncheckedValue(0)
					->setLabel(_('Unmanaged neighbors'))
			]))->addClass('topomap-field topomap-check'),
			(new CDiv([
				(new CCheckBox('util'))
					->setChecked($data['filter']['util'] == 1)
					->setUncheckedValue(0)
					->setLabel(_('Link utilisation'))
			]))->addClass('topomap-field topomap-check'),
			(new CDiv(new CSubmit('apply', _('Apply'))))->addClass('topomap-field')
		]))->addClass('topomap-filter-row')
	);

$main = (new CDiv([
	(new CDiv())->setId('topomap-toolbar')->addClass('topomap-toolbar'),
	(new CDiv([
		(new CDiv([
			(new CDiv())->setId('topomap-canvas')->addClass('topomap-canvas'),
			(new CDiv())->setId('topomap-legend')->addClass('topomap-legend'),
			(new CDiv())->setId('topomap-status')->addClass('topomap-status')
		]))->setId('topomap-map-pane')->addClass('topomap-pane'),
		(new CDiv())->setId('topomap-changes-pane')->addClass('topomap-pane topomap-changes')->addStyle('display:none'),
		(new CDiv())->setId('topomap-side')->addClass('topomap-side')
	]))->addClass('topomap-body')
]))->setId('topomap-root')->addClass('topomap-root');

(new CHtmlPage())
	->setTitle($data['title'])
	->addItem($form)
	->addItem($main)
	->show();

(new CScriptTag('topomapPage.init('.json_encode($data['js'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES).');'))
	->setOnDocumentReady()
	->show();
