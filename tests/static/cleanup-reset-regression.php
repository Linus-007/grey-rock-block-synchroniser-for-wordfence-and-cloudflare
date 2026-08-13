<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$cleanup_options = [];
$cleanup_persistence_fails = false;
$cleanup_now = time();

function cleanup_fail(string $message): never {
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

function cleanup_assert(bool $condition, string $message): void {
  if (!$condition) {
    cleanup_fail($message);
  }
}

function __(string $text, ?string $domain = null): string {
  return $text;
}

function is_multisite(): bool {
  return false;
}

function get_option(string $name, $default = false) {
  global $cleanup_options;
  return $cleanup_options[$name] ?? $default;
}

function get_site_option(string $name, $default = false) {
  return get_option($name, $default);
}

function add_option(
  string $name,
  $value,
  string $deprecated = '',
  $autoload = true
): bool {
  global $cleanup_options, $cleanup_persistence_fails;

  if ($cleanup_persistence_fails) {
    return false;
  }

  $cleanup_options[$name] = $value;
  return true;
}

function update_option(
  string $name,
  $value,
  $autoload = null
): bool {
  global $cleanup_options, $cleanup_persistence_fails;

  if ($cleanup_persistence_fails) {
    return false;
  }

  $cleanup_options[$name] = $value;
  return true;
}

function wp_parse_url(string $url, int $component) {
  return parse_url($url, $component);
}

function wp_timezone(): DateTimeZone {
  return new DateTimeZone('UTC');
}

if (!defined('HOUR_IN_SECONDS')) {
  define('HOUR_IN_SECONDS', 3600);
}

if (!defined('ARRAY_A')) {
  define('ARRAY_A', 'ARRAY_A');
}

final class wfDB {
  public static function networkTable($table): string {
    return 'wp_wfHits';
  }
}

final class CleanupDatabaseFake {
  public string $prefix = 'wp_';
  public array $rows = [];
  public int $delete_count = 0;

  public function esc_like(string $value): string {
    return $value;
  }

  public function prepare(string $query, ...$values): string {
    return $query;
  }

  public function get_var(string $query) {
    return str_contains($query, 'SHOW TABLES')
      ? 'wp_wfHits'
      : null;
  }

  public function get_results(string $query, string $format): array {
    return $this->rows;
  }

  public function delete(
    string $table,
    array $where,
    array $formats
  ): int {
    $this->delete_count++;
    return 1;
  }
}

function cleanup_wf_hex(string $ip): string {
  $packed = inet_pton($ip);

  if ($packed === false) {
    cleanup_fail('Could not encode test IP.');
  }

  $packed = str_repeat("\0", 10) . "\xff\xff" . $packed;
  return strtoupper(bin2hex($packed));
}

require_once $root . '/src/includes/Services/IpValidator.php';
require_once $root . '/src/includes/Config.php';
require_once $root . '/src/includes/Services/BlockLogger.php';
require_once $root . '/src/includes/Services/ResetWatermarkStore.php';
require_once $root . '/src/includes/Services/HistoricalBlockReader.php';
require_once $root . '/src/includes/Services/SyncScheduler.php';

use WPCF\FirewallSync\Services\HistoricalBlockReader;
use WPCF\FirewallSync\Services\ResetWatermarkStore;
use WPCF\FirewallSync\Services\SyncScheduler;

$wpdb = new CleanupDatabaseFake();
$ip = '8.8.8.8';
$cleanup = new ReflectionMethod(
  SyncScheduler::class,
  'finalize_expired_local_cleanup'
);
$result = $cleanup->invoke(
  null,
  'wp_wpcf_sync_blocks',
  17,
  $ip,
  $cleanup_now
);

cleanup_assert(
  $result['complete'] === true && $wpdb->delete_count === 1,
  'Successful expiry cleanup did not store reset then delete the row.'
);
cleanup_assert(
  ResetWatermarkStore::get($ip) === $cleanup_now,
  'Expiry cleanup did not retain its removal-time reset.'
);

$wpdb->rows = [[
  'ip_hex' => cleanup_wf_hex($ip),
  'event_time' => $cleanup_now - 30,
  'event_url' => 'https://sweers.ch/old-episode',
]];
cleanup_assert(
  HistoricalBlockReader::get_candidates(24, 1, ['sweers.ch']) === [],
  'Pre-cleanup historical evidence recreated the IP.'
);

$wpdb->rows[] = [
  'ip_hex' => cleanup_wf_hex($ip),
  'event_time' => $cleanup_now + 30,
  'event_url' => 'https://sweers.ch/new-episode',
];
$new_evidence = HistoricalBlockReader::get_candidates(
  24,
  1,
  ['sweers.ch']
);
cleanup_assert(
  count($new_evidence ?? []) === 1
    && $new_evidence[0]['event_count'] === 1,
  'Post-cleanup evidence did not become eligible.'
);

$failure_ip = '9.9.9.9';
$cleanup_persistence_fails = true;
$deletes_before_failure = $wpdb->delete_count;
$failed = $cleanup->invoke(
  null,
  'wp_wpcf_sync_blocks',
  18,
  $failure_ip,
  $cleanup_now
);
cleanup_assert(
  $failed['complete'] === false
    && $wpdb->delete_count === $deletes_before_failure,
  'Reset persistence failure did not retain the local row.'
);

echo "Cleanup-reset regression: PASS\n";
