<?php
/**
 * Dependency double for the pre-existing isolated business-logic unit tests.
 * These tests have neither mysqli nor real leases and do NOT prove fencing.
 * Existing lease guards still execute (locked() is false). The production
 * transaction implementation is tested separately by task14-atomic-fencing.
 */
namespace WPCF\FirewallSync\Services;
final class AtomicFence {
  private static int $depth = 0;
  public static function active(): bool { return self::$depth > 0; }
  public static function locked(): bool { return false; }
  public static function run(callable $work, array $tables = [], bool $site_only = false) {
    self::$depth++;
    try { return $work(); } finally { self::$depth--; }
  }
  public static function option(string $key, bool $network, bool $write = false, $value = null) {
    if (!$write) { return $network ? get_site_option($key, []) : get_option($key, []); }
    if ($network) { return update_site_option($key, $value); }
    if (get_option($key, null) === null) { return add_option($key, $value, '', false); }
    return update_option($key, $value, false);
  }
}
