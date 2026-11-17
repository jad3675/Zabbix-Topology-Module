<?php declare(strict_types = 0);

namespace Modules\TopoMap\Lib;

/**
 * Small per-user file cache so repeated page loads and widget refreshes
 * do not re-read every topology item from the database.
 */
class Cache {

	private static function dir(): string {
		$d = rtrim(sys_get_temp_dir(), '/').'/zabbix-topomap';
		if (!is_dir($d)) {
			@mkdir($d, 0700, true);
		}

		return $d;
	}

	private static function file(string $key): string {
		return self::dir().'/'.sha1($key).'.json';
	}

	public static function get(string $key, int $ttl): ?array {
		$f = self::file($key);
		if (!is_file($f) || (time() - filemtime($f)) >= $ttl) {
			return null;
		}
		$d = json_decode((string) @file_get_contents($f), true);

		return is_array($d) ? $d : null;
	}

	public static function age(string $key): ?int {
		$f = self::file($key);

		return is_file($f) ? time() - filemtime($f) : null;
	}

	public static function set(string $key, array $data): void {
		$f = self::file($key);
		$tmp = $f.'.'.getmypid().'.tmp';
		if (@file_put_contents($tmp, json_encode($data)) !== false) {
			@rename($tmp, $f);
		}
		self::sweep();
	}

	/** Drop stale files now and then. */
	private static function sweep(): void {
		if (mt_rand(1, 50) !== 1) {
			return;
		}
		foreach (glob(self::dir().'/*.json') ?: [] as $f) {
			if (time() - filemtime($f) > 3600) {
				@unlink($f);
			}
		}
	}
}
