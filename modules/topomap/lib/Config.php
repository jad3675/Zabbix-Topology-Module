<?php declare(strict_types = 0);

namespace Modules\TopoMap\Lib;

use APP;

/** Loads and saves Settings in the module's own Zabbix config. */
class Config {

	public const MODULE_ID = 'topomap';

	private static $loaded = false;

	public static function load(bool $force = false): void {
		if (self::$loaded && !$force) {
			return;
		}
		$module = APP::ModuleManager()->getModule(self::MODULE_ID);
		Settings::init($module !== null ? $module->getConfig() : null);
		self::$loaded = true;
	}

	/** Validates through Settings, stores only what differs from the defaults. Super admin only (API enforced). */
	public static function save(array $values): void {
		Settings::init($values);
		$module = APP::ModuleManager()->getModule(self::MODULE_ID);
		if ($module !== null) {
			$module->setConfig(Settings::diff(Settings::all()));
		}
		self::$loaded = true;
	}
}
