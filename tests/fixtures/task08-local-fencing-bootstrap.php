<?php

declare(strict_types=1);
namespace WPCF\FirewallSync {
  final class Config { public static function uses_network_options(): bool { return $GLOBALS['fence_network']; } }
}
namespace WPCF\FirewallSync\Services {
  function time(): int { return $GLOBALS['fence_clock']; }
}
namespace {
  $fence_clock = 2200000000; $fence_network = false; $pause_write = null; $paused = false; $stale_writes = 0;
  define('HOUR_IN_SECONDS', 3600);
  function __($text, $domain = null): string { return $text; }
  function is_multisite(): bool { return $GLOBALS['fence_network']; }
  function wp_generate_uuid4(): string { static $sequence = 0; return sprintf('00000000-0000-4000-8000-%012d', ++$sequence); }
  function maybe_serialize($value): string { return serialize($value); }
  function maybe_unserialize($value) { return unserialize($value); }
  function wp_cache_delete(...$args): void {}
  function current_time($format): string { return '2039-09-18 23:06:40'; }
  function wp_remote_get(...$args) { throw new RuntimeException('Cloudflare HTTP prohibited in local mutation tests.'); }
  function wp_remote_post(...$args) { throw new RuntimeException('Cloudflare HTTP prohibited in local mutation tests.'); }
  function wp_remote_request(...$args) { throw new RuntimeException('Cloudflare HTTP prohibited in local mutation tests.'); }
  function fence_pause(string $kind): void {
    if ($GLOBALS['pause_write'] !== null && ($GLOBALS['pause_kind'] ?? '') === $kind) {
      $callback = $GLOBALS['pause_write']; $GLOBALS['pause_write'] = null;
      $callback(); $GLOBALS['paused'] = true;
    }
  }
  function fence_record(): void { if ($GLOBALS['paused']) { $GLOBALS['stale_writes']++; } }
  function get_option($key, $default = false) {
    global $wpdb;
    return isset($wpdb->rows[$wpdb->options][$key]) ? unserialize($wpdb->rows[$wpdb->options][$key]) : $default;
  }
  function update_option($key, $value, ...$args): bool {
    global $wpdb;
    fence_pause('option'); fence_record(); $wpdb->rows[$wpdb->options][$key] = serialize($value); return true;
  }
  function add_option($key, $value, ...$args): bool {
    if (get_option($key, null) !== null) { return false; }
    return update_option($key, $value);
  }
  function get_site_option($key, $default = false) { return $GLOBALS['network_values'][$key] ?? $default; }
  function update_site_option($key, $value): bool {
    fence_pause('network-option'); fence_record(); $GLOBALS['network_values'][$key] = $value; return true;
  }
  final class FencingDatabase {
    public string $prefix = 'wp_2_';
    public string $base_prefix = 'wp_';
    public string $options = 'wp_2_options';
    public string $last_error = '';
    public array $rows = [];
    public function prepare(string $query, ...$values): string { return serialize([$query, $values]); }
    public function get_var(string $prepared) {
      if ($prepared === 'SELECT UNIX_TIMESTAMP()') { return $GLOBALS['fence_clock']; }
      [$query, $values] = unserialize($prepared);
      if (str_contains($query, 'SELECT option_value')) {
        preg_match('/FROM ([a-zA-Z0-9_]+)/', $query, $match);
        return $this->rows[$match[1]][$values[0]] ?? null;
      }
      return null;
    }
    public function get_col(string $prepared): array { return ['8.8.8.8']; }
    public function query(string $prepared) {
      [$query, $values] = unserialize($prepared);
      if (str_contains($query, 'wpcf_sync_ownership') || str_contains($query, 'wpcf_sync_blocks')) {
        fence_pause('data-query'); fence_record(); return 1;
      }
      preg_match('/(?:INTO|UPDATE|FROM) ([a-zA-Z0-9_]+)/', $query, $match); $table = $match[1];
      if (str_starts_with($query, 'INSERT IGNORE')) {
        if (isset($this->rows[$table][$values[0]])) { return 0; }
        $this->rows[$table][$values[0]] = $values[1]; return 1;
      }
      if (str_starts_with($query, 'UPDATE')) {
        fence_pause('lease-update');
        if (($this->rows[$table][$values[1]] ?? null) !== $values[2]
          || (isset($values[3]) && $GLOBALS['fence_clock'] >= $values[3])) { return 0; }
        $this->rows[$table][$values[1]] = $values[0]; return 1;
      }
      if (str_starts_with($query, 'DELETE')) {
        fence_pause('lease-delete');
        if (($this->rows[$table][$values[0]] ?? null) !== $values[1]) { return 0; }
        unset($this->rows[$table][$values[0]]); return 1;
      }
      throw new RuntimeException('Unmodeled fixture statement.');
    }
    public function delete($table, $where, $formats = []): int {
      fence_pause('data-delete'); fence_record(); return 1;
    }
  }
  $wpdb = new FencingDatabase(); $network_values = [];
  foreach (['IpValidator', 'DestinationLease', 'SyncScheduler', 'BlockOwnership', 'BlockLogger', 'ResetWatermarkStore', 'NetworkManualOwnershipStore', 'Reconciler'] as $class) {
    require dirname(__DIR__, 2) . '/src/includes/Services/' . $class . '.php';
  }
}
