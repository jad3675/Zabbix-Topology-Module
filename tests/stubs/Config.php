<?php
namespace Modules\TopoMap\Lib;

/** Offline stand-in: settings stay at their defaults (or whatever a test set with Settings::init). */
class Config {
	public static function load(bool $force = false): void {}
}
