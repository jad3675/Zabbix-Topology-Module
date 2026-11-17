<?php declare(strict_types = 0);

namespace Modules\TopoMapWidget\Includes;

use Zabbix\Widgets\CWidgetForm;
use Zabbix\Widgets\Fields\{
	CWidgetFieldCheckBox,
	CWidgetFieldMultiSelectGroup,
	CWidgetFieldSelect
};

class WidgetForm extends CWidgetForm {

	public function addFields(): self {
		return $this
			->addField(
				new CWidgetFieldMultiSelectGroup('groupids', _('Host groups'))
			)
			->addField(
				(new CWidgetFieldSelect('endpoints', _('Endpoints'), [
					0 => _('Hidden'),
					1 => _('One node per switch'),
					2 => _('One node per port')
				]))->setDefault(1)
			)
			->addField(
				(new CWidgetFieldCheckBox('unmanaged', _('Show neighbors not in Zabbix')))->setDefault(1)
			)
			->addField(
				(new CWidgetFieldSelect('layout', _('Layout'), [
					0 => _('Layered'),
					1 => _('Organic')
				]))->setDefault(0)
			);
	}
}
