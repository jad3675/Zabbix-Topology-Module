<?php
// Loads the module's pure classes for the offline tests (Zabbix autoloads them in production).
$lib = __DIR__.'/../modules/topomap/lib';
require_once $lib.'/Net.php';
require_once $lib.'/Settings.php';
require_once $lib.'/sources/Source.php';
require_once $lib.'/sources/Registry.php';
require_once $lib.'/Correlator.php';
