<?php declare(strict_types = 0);

/**
 * @var CView $this
 * @var array $data
 */

$view = new CWidgetView($data);

if ($data['error'] !== null) {
	$view->addItem((new CTableInfo())->setNoDataMessage($data['error']));
}
else {
	$view
		->addItem((new CDiv())->addClass('topomap-widget-canvas'))
		->setVar('topo', $data['topo'])
		->setVar('topo_assets', $data['assets'])
		->setVar('topo_latest', $data['latest']);
}

$view->show();
