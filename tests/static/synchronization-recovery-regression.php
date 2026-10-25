<?php

declare(strict_types=1);

namespace WPCF\FirewallSync {
  final class Config {
    public static function get_effective_options(): array {
      if ($GLOBALS['throw_config']) { throw new \RuntimeException('Synthetic private dependency detail'); }
      return ['cloudflare_api_token' => 'fixture-credential-value', 'cloudflare_zone_id' => str_repeat('c', 32), 'cloudflare_mode' => 'zone_access_rules'];
    }
    public static function uses_network_options(): bool { return $GLOBALS['shared_cleanup'] ?? false; }
  }
}
namespace WPCF\FirewallSync\Services {
  final class BlockLogger {
    public const TABLE = 'wpcf_sync_blocks';
    public static function log($ip, $reason, $expires): bool { $GLOBALS['success_ips'][] = $ip; return !$GLOBALS['fail_log']; }
    public static function mark_failed($ip, $reason, $expires): bool { $GLOBALS['failed_ips'][] = $ip; return true; }
  }
  final class ResetWatermarkStore {
    public static function clear($ip): bool { return true; }
    public static function set($ip, $time): bool { $GLOBALS['watermarks'][] = $ip; return true; }
  }
  final class BlockOwnership {
    public const OWNER_MANUAL = 'manual';
    public static function has($ip, $owner): bool {
      if ($GLOBALS['ownership_error'] ?? false) { throw new \RuntimeException('Synthetic ownership read failure'); }
      return $GLOBALS['manual_owner'];
    }
  }
}
namespace {
  require_once __DIR__ . '/../fixtures/task14-unit-fence.php';
  use WPCF\FirewallSync\Services\SyncScheduler;
  $root = dirname(__DIR__, 2);
  $options = []; $throw_config = false; $manual_owner = false;
  $success_ips = []; $failed_ips = []; $fail_log = false; $requests = 0; $watermarks = [];
  function __($text, $domain = null): string { return $text; }
  function sanitize_text_field($text): string { return (string) $text; }
  function is_multisite(): bool { return $GLOBALS['shared_cleanup'] ?? false; }
  function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
  function add_option($key, $value, ...$args): bool {
    if (!empty($GLOBALS['simulate_core_duplicate_update'])) { $GLOBALS['options'][$key] = $value; return true; }
    if (isset($GLOBALS['options'][$key])) { return false; }
    $GLOBALS['options'][$key] = $value; return true;
  }
  function update_option($key, $value, ...$args): bool { $GLOBALS['options'][$key] = $value; return true; }
  function wp_cache_delete(...$args): void {}
  function wp_generate_uuid4(): string { return 'fixture-lock-owner'; }
  function maybe_serialize($value): string { return serialize($value); }
  function maybe_unserialize($value) { return unserialize($value); }
  function current_time($format): string { return '2030-01-01 00:00:00'; }
  function is_wp_error($response): bool { return false; }
  function wp_remote_get($url, $args): array {
    $GLOBALS['requests']++;
    return ['response' => ['code' => 200], 'body' => json_encode(['success' => true, 'result' => [['id' => str_repeat('1', 32), 'mode' => 'block', 'configuration' => ['target' => 'ip', 'value' => '8.8.8.8']]]])];
  }
  function wp_remote_request($url, $args): array {
    $GLOBALS['requests']++;
    if (!empty($GLOBALS['replace_site_during_http'])) {
      $GLOBALS['options']['firewall_sync_is_running'] = ['owner' => 'replacement-during-http', 'started_at' => \WPCF\FirewallSync\Services\time()];
      $GLOBALS['options']['firewall_sync_last_result'] = 'replacement-result';
    }
    return ['response' => ['code' => 200], 'body' => json_encode(['success' => true, 'result' => ['id' => str_repeat('1', 32)]])];
  }
  function wp_remote_retrieve_response_code($response): int { return $response['response']['code']; }
  function wp_remote_retrieve_body($response): string { return $response['body']; }
  function wp_json_encode($value): string { return json_encode($value); }
  define('ARRAY_A', 'ARRAY_A');
  final class RecoveryDatabase {
    public string $prefix = 'wp_';
    public string $base_prefix = 'wp_';
    public string $options = 'wp_options';
    public array $values = [];
    public string $last_error = '';
    public function prepare($query, ...$values): string { $this->values = $values; return $query; }
    public function get_var($query) {
      $this->last_error = !empty($GLOBALS['lease_read_error']) ? 'Synthetic lease read failure' : '';
      if ($query === 'SELECT UNIX_TIMESTAMP()') { return (function_exists('WPCF\\FirewallSync\\Services\\time') ? \WPCF\FirewallSync\Services\time() : time()); }
      return isset($GLOBALS['options'][$this->values[0]]) ? serialize($GLOBALS['options'][$this->values[0]]) : null;
    }
    public function query($query): int|false {
      if (!empty($GLOBALS['lease_write_error'])) { return false; }
      if (str_starts_with($query, 'INSERT IGNORE')) {
        $key = $this->values[0];
        if (isset($GLOBALS['options'][$key])) { return 0; }
        $GLOBALS['options'][$key] = unserialize($this->values[1]); return 1;
      }
      if (str_starts_with($query, 'UPDATE')) {
        $key = $this->values[1];
        $clock = function_exists('WPCF\\FirewallSync\\Services\\time') ? \WPCF\FirewallSync\Services\time() : time();
        if (isset($this->values[3]) && $clock >= $this->values[3]) { return 0; }
        if (serialize($GLOBALS['options'][$key] ?? null) !== $this->values[2]) { return 0; }
        $GLOBALS['options'][$key] = unserialize($this->values[0]); return 1;
      }
      if (str_starts_with($query, 'DELETE')) {
        $key = $this->values[0];
        if (serialize($GLOBALS['options'][$key] ?? null) === $this->values[1]) { unset($GLOBALS['options'][$key]); return 1; }
      }
      return 0;
    }
    public function get_results($query, $format): array {
      $this->last_error = !empty($GLOBALS['cleanup_query_error']) ? 'Synthetic query failure' : '';
      if ($this->last_error !== '') { return []; }
      return !empty($GLOBALS['cleanup_partial']) ? [['id' => 1, 'ip' => '8.8.8.8'], ['id' => 2, 'ip' => '1.1.1.1']] : [['id' => 1, 'ip' => '8.8.8.8']];
    }
    public function delete($table, $where, $formats): int {
      if (!empty($GLOBALS['cleanup_partial']) && ($where['id'] ?? 0) === 2) { throw new RuntimeException('Synthetic later database failure'); }
      return 1;
    }
  }
  $wpdb = new RecoveryDatabase();
  require $root . '/src/includes/Services/IpValidator.php';
  require $root . '/src/includes/Services/CloudflareIdentifierValidator.php';
  require $root . '/src/includes/Services/DestinationLease.php';
  require $root . '/src/includes/Cloudflare/Client.php';
  require $root . '/src/includes/Services/SyncScheduler.php';
  function check(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
  $cases = [];
  $cases['exception'] = function (): void {
    $GLOBALS['throw_config'] = true;
    $GLOBALS['options']['firewall_sync_last_result'] = 'success';
    $escaped = false;
    try { $result = SyncScheduler::run_now(); } catch (Throwable) { $escaped = true; $result = true; }
    check(!$escaped && $result === false, 'Dependency exception escaped instead of persisting a recoverable failure.');
    check(get_option('firewall_sync_last_result') === 'failure', 'Previous success remained after interrupted sync.');
    check(!str_contains(SyncScheduler::get_last_persisted_error(), 'private dependency'), 'Private exception detail escaped.');
    check(!get_option('firewall_sync_is_running'), 'Interrupted synchronization leaked its lock.');
  };
  $cases['cleanup-owner'] = function (): void {
    $GLOBALS['manual_owner'] = true;
    $result = SyncScheduler::run_cleanup();
    check($result['complete'] && $result['removed'] === [], 'Manual ownership was expired as Wordfence ownership.');
    check($GLOBALS['requests'] === 0 && $GLOBALS['watermarks'] === [], 'Manual block was removed/reset by expiry cleanup.');
  };
  $cases['cleanup-lock'] = function (): void {
    $GLOBALS['options']['firewall_sync_is_running'] = ['owner' => 'another-worker', 'started_at' => time()];
    $result = SyncScheduler::run_cleanup();
    check(!$result['complete'] && $GLOBALS['requests'] === 0, 'Cleanup mutated Cloudflare while synchronization owned the lock.');
    check(get_option('firewall_sync_is_running')['owner'] === 'another-worker', 'Cleanup removed another worker lock.');
  };
  $cases['local-recording'] = function (): void {
    $method = new ReflectionMethod(SyncScheduler::class, 'record_batch_results');
    $batch = [['ip' => '8.8.8.8', 'reason' => 'fixture', 'expires_at' => null], ['ip' => '1.1.1.1', 'reason' => 'fixture', 'expires_at' => null]];
    $result = $method->invoke(null, $batch, ['1.1.1.1']);
    check($result === ['1.1.1.1'] && $GLOBALS['success_ips'] === ['8.8.8.8'] && $GLOBALS['failed_ips'] === ['1.1.1.1'], 'Remote failure was recorded as local success.');
    $GLOBALS['fail_log'] = true;
    check($method->invoke(null, [$batch[0]], []) === ['8.8.8.8'], 'Remote success plus DB failure was concealed.');
    $GLOBALS['fail_log'] = false;
    check($method->invoke(null, [$batch[0]], []) === [], 'Retry could not repair local persistence.');
  };
  $cases['cleanup-read-failure'] = function (): void {
    $GLOBALS['ownership_error'] = true;
    $result = SyncScheduler::run_cleanup();
    check(!$result['complete'] && $GLOBALS['requests'] === 0 && $GLOBALS['watermarks'] === [], 'Ownership read failure did not stop destructive cleanup.');
    check(get_option('firewall_sync_last_result') === 'failure' && !get_option('firewall_sync_is_running'), 'Cleanup failure was not persisted or its lock was leaked.');
    $GLOBALS['ownership_error'] = false;
  };
  $cases['lock-owner-safety'] = function (): void {
    $acquire = new ReflectionMethod(SyncScheduler::class, 'acquire_lock');
    $release = new ReflectionMethod(SyncScheduler::class, 'release_lock');
    check($acquire->invoke(null), 'Could not acquire test lock.');
    $GLOBALS['options']['firewall_sync_is_running'] = ['owner' => 'new-worker', 'started_at' => time()];
    $release->invoke(null);
    check(get_option('firewall_sync_is_running')['owner'] === 'new-worker', 'Older worker released a replacement lock.');
  };
  $cases['shared-cleanup-disabled'] = function (): void {
    $GLOBALS['shared_cleanup'] = true;
    $GLOBALS['options']['firewall_sync_last_result'] = 'success';
    $result = SyncScheduler::run_cleanup();
    check(!$result['complete'] && $GLOBALS['requests'] === 0, 'Disabled shared cleanup touched Cloudflare.');
    check(get_option('firewall_sync_last_result') === 'success', 'Expected disabled cleanup overwrote successful synchronization status.');
    $GLOBALS['shared_cleanup'] = false;
  };
  $cases['cleanup-partial-progress'] = function (): void {
    $GLOBALS['cleanup_partial'] = true;
    $result = SyncScheduler::run_cleanup();
    check(!$result['complete'] && $result['removed'] === ['8.8.8.8'], 'Later failure concealed completed cleanup progress.');
    check(!get_option('firewall_sync_is_running'), 'Partial cleanup leaked its lock.');
    $GLOBALS['cleanup_partial'] = false;
  };
  $cases['cleanup-query-failure'] = function (): void {
    $GLOBALS['cleanup_query_error'] = true;
    $result = SyncScheduler::run_cleanup();
    check(!$result['complete'] && $GLOBALS['requests'] === 0, 'Failed cleanup query was reported as successful empty cleanup.');
    $GLOBALS['cleanup_query_error'] = false;
  };
  foreach ($cases as $name => $case) {
    if (($argv[1] ?? '') !== '' && $argv[1] !== $name) { continue; }
    $options = []; $throw_config = false; $manual_owner = false; $success_ips = []; $failed_ips = []; $fail_log = false; $requests = 0; $watermarks = [];
    $case(); echo "PASS: Synchronization recovery {$name}\n";
  }
}
