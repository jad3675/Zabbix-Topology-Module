<?php declare(strict_types = 0);

namespace Modules\TopoMap\Lib;

/**
 * Normalisation helpers shared by the correlator and the change log.
 */
class Net {

	private const PORT_PREFIX = [
		'gigabitethernet' => 'gi', 'gige' => 'gi', 'gig' => 'gi', 'gi' => 'gi',
		'tengigabitethernet' => 'te', 'tengige' => 'te', 'ten' => 'te', 'te' => 'te',
		'twentyfivegigabitethernet' => 'twe', 'twentyfivegige' => 'twe', 'twe' => 'twe', 'tw' => 'twe',
		'fortygigabitethernet' => 'fo', 'fortygige' => 'fo', 'fo' => 'fo',
		'hundredgigabitethernet' => 'hu', 'hundredgige' => 'hu', 'hu' => 'hu',
		'fastethernet' => 'fa', 'fa' => 'fa',
		'ethernet' => 'eth', 'eth' => 'eth', 'et' => 'eth', 'e' => 'eth',
		'port-channel' => 'po', 'portchannel' => 'po', 'po' => 'po'
	];

	public static function mac(?string $s): string {
		$h = strtolower(preg_replace('/[^0-9a-fA-F]/', '', (string) $s));

		return strlen($h) === 12 ? $h : '';
	}

	public static function fmtMac(string $h): string {
		return strlen($h) === 12 ? implode(':', str_split($h, 2)) : $h;
	}

	/** Locally administered (randomised) MAC, the usual phone/laptop privacy MAC. */
	public static function isRandomMac(string $h): bool {
		return strlen($h) === 12 && (hexdec($h[1]) & 0x2) === 0x2;
	}

	/** Chassis IDs: MACs to 12 hex, anything else lowercased alnum. */
	public static function chassis(?string $s): string {
		$s = trim((string) $s);
		if ($s === '') {
			return '';
		}
		$m = self::mac($s);

		return ($m !== '' && preg_match('/^[0-9a-fA-F:.\- ]+$/', $s))
			? $m
			: strtolower(preg_replace('/[^0-9a-zA-Z]/', '', $s));
	}

	/** Device names: drop "(serial)" suffix, lowercase, short name unless it is an IP. */
	public static function name(?string $s): string {
		$s = strtolower(trim(preg_replace('/\(.*\)$/', '', (string) $s)));
		if ($s === '' || filter_var($s, FILTER_VALIDATE_IP)) {
			return $s;
		}
		$short = explode('.', $s)[0];

		return $short !== '' ? $short : $s;
	}

	/** Port names to a comparable form: GigabitEthernet1/0/1 == Gi1/0/1 == gi1/0/1. */
	public static function port(?string $s): string {
		$s = strtolower(str_replace(' ', '', trim((string) $s)));
		if (preg_match('/^([a-z\-]+)(\d[\d\/\.:]*)$/', $s, $m)) {
			return (self::PORT_PREFIX[$m[1]] ?? $m[1]).$m[2];
		}

		return $s;
	}

	public static function json(?string $v) {
		if ($v === null || $v === '') {
			return null;
		}
		$d = json_decode($v, true);

		return is_array($d) ? $d : null;
	}

	/** Port placeholders the correlator invents when it has no real name. */
	public static function isPlaceholder(?string $label): bool {
		return $label === null || $label === '' || $label === '?'
			|| (bool) preg_match('/^(ifIndex \d+|LLDP port \d+|Bridge \(LLDP port 0\))$/', $label);
	}

	/**
	 * topo.ifnames entry to [name, alias, oper, speedMbps]. Accepts every format the
	 * template has produced: a plain name (1.0.0-1.0.4), [name, alias, oper, speed]
	 * (1.0.5) and [name] or [name, alias] (1.0.6+). Oper and speed from 1.0.5 data
	 * are ignored; status and speed now come from the host's interface items.
	 */
	public static function ifInfo($v): array {
		if (is_array($v)) {
			return [(string) ($v[0] ?? ''), (string) ($v[1] ?? ''), (int) ($v[2] ?? 0), (int) ($v[3] ?? 0)];
		}

		return [(string) $v, '', 0, 0];
	}

	public static function ifName($v): string {
		return self::ifInfo($v)[0];
	}

	private static $oui = null;

	/** Vendor from the IEEE registry (MA-L only). Empty for randomised or unknown MACs. */
	public static function vendor(string $mac): string {
		if (strlen($mac) !== 12 || self::isRandomMac($mac)) {
			return '';
		}
		if (self::$oui === null) {
			$raw = @file_get_contents(__DIR__.'/oui.json');
			self::$oui = $raw !== false ? (json_decode($raw, true) ?: []) : [];
		}

		return self::$oui[substr($mac, 0, 6)] ?? '';
	}
}
