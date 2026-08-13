<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$capacity_options = [];
$capacity_now = time();

function capacity_fail(string $message): never {
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

function capacity_assert(bool $condition, string $message): void {
  if (!$condition) {
    capacity_fail($message);
  }
}

function __(string $text, ?string $domain = null): string {
  return $text;
}

function is_multisite(): bool {
  return false;
}

function get_option(string $name, $default = false) {
  global $capacity_options;
  return $capacity_options[$name] ?? $default;
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
  global $capacity_options;
  $capacity_options[$name] = $value;
  return true;
}

function update_option(
  string $name,
  $value,
  $autoload = null
): bool {
  global $capacity_options;
  $capacity_options[$name] = $value;
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
    capacity_assert($table === 'wfHits', 'Wrong Wordfence table.');
    return 'wp_wfHits';
  }
}

final class CapacityDatabaseFake {
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

function capacity_ip(int $number): string {
  return sprintf(
    '2606:4700:%x:%x::1',
    intdiv($number, 65535),
    $number % 65535
  );
}

function capacity_wf_hex(string $ip): string {
  $packed = inet_pton($ip);
  return strtoupper(bin2hex($packed === false ? '' : $packed));
}

require_once $root . '/src/includes/Services/IpValidator.php';
require_once $root . '/src/includes/Config.php';
require_once $root . '/src/includes/Services/BlockLogger.php';
require_once $root . '/src/includes/Services/ResetWatermarkStore.php';
require_once $root . '/src/includes/Services/HistoricalBlockReader.php';
require_once $root . '/src/includes/Services/Reconciler.php';
require_once $root . '/src/includes/Services/NetworkSynchronizer.php';

use WPCF\FirewallSync\Services\HistoricalBlockReader;
use WPCF\FirewallSync\Services\Reconciler;
use WPCF\FirewallSync\Services\ResetWatermarkStore;

$target_ip = '8.8.8.8';
$extra_ip = '9.9.9.9';
$reset_at = $capacity_now - 60;
$relevant = [$target_ip => $reset_at];

for ($index = 1; $index < ResetWatermarkStore::MAX_ENTRIES; $index++) {
  $relevant[capacity_ip($index)] = $capacity_now - 30;
}

$capacity_options[ResetWatermarkStore::SITE_OPTION] = $relevant;

capacity_assert(
  ResetWatermarkStore::set($extra_ip, $capacity_now) === false,
  'A still-relevant watermark was evicted to exceed capacity.'
);
capacity_assert(
  ResetWatermarkStore::get($target_ip) === $reset_at,
  'The oldest still-relevant watermark was not retained.'
);
capacity_assert(
  count($capacity_options[ResetWatermarkStore::SITE_OPTION])
    === ResetWatermarkStore::MAX_ENTRIES,
  'The bounded store changed after a refused insertion.'
);

$wpdb = new CapacityDatabaseFake();
$wpdb->rows = [[
  'ip_hex' => capacity_wf_hex($target_ip),
  'event_time' => $reset_at - 10,
  'event_url' => 'https://sweers.ch/old-evidence',
]];

capacity_assert(
  HistoricalBlockReader::get_candidates(24, 1, ['sweers.ch']) === [],
  'Stale pre-reset evidence became eligible after capacity pressure.'
);

$purge = new ReflectionMethod(
  Reconciler::class,
  'purge_missing_local_records'
);
$result = $purge->invoke(null, [$extra_ip], $capacity_now);

capacity_assert(
  $result['error'] !== '' && $result['purged'] === [],
  'Destructive reconciliation did not report safe-capacity failure.'
);
capacity_assert(
  $wpdb->delete_count === 0,
  'Local synchronization state was deleted without a retained reset.'
);

$safely_old_ip = capacity_ip(65000);
$with_expired = [$safely_old_ip => (
  $capacity_now - ResetWatermarkStore::SAFETY_RETENTION - 1
)];

for ($index = 1; $index < ResetWatermarkStore::MAX_ENTRIES; $index++) {
  $with_expired[capacity_ip($index)] = $capacity_now - 30;
}

$capacity_options[ResetWatermarkStore::SITE_OPTION] = $with_expired;

capacity_assert(
  ResetWatermarkStore::set($extra_ip, $capacity_now) === true,
  'A safely old watermark did not free bounded capacity.'
);
capacity_assert(
  ResetWatermarkStore::get($safely_old_ip) === 0,
  'A safely old watermark was not pruned.'
);
capacity_assert(
  ResetWatermarkStore::get($extra_ip) === $capacity_now,
  'The new watermark was not retained after safe pruning.'
);

echo "Reset-watermark capacity regression: PASS\n";

$boundary_ip = '1.1.1.1';
$boundary_reset = $capacity_now - (24 * HOUR_IN_SECONDS) - 5;
$capacity_options[ResetWatermarkStore::SITE_OPTION] = [
  $boundary_ip => $boundary_reset,
];
$wpdb->rows = [[
  'ip_hex' => capacity_wf_hex($boundary_ip),
  'event_time' => $boundary_reset - 1,
  'event_url' => 'https://sweers.ch/already-selected',
]];

capacity_assert(
  ResetWatermarkStore::SAFETY_RETENTION > 24 * HOUR_IN_SECONDS,
  'Reset safety retention does not exceed historical lookback.'
);
capacity_assert(
  ResetWatermarkStore::get($boundary_ip) === $boundary_reset,
  'A boundary reset disappeared inside the safety interval.'
);
capacity_assert(
  HistoricalBlockReader::get_candidates(24, 1, ['sweers.ch']) === [],
  'An already-selected pre-reset boundary event requalified.'
);

echo "Reset-retention boundary regression: PASS\n";

$ipv6_expanded = '2606:4700:0000:0000:0000:0000:0000:1111';
$ipv6_canonical = '2606:4700::1111';
$ipv6_reset = $capacity_now - 30;
$capacity_options[ResetWatermarkStore::SITE_OPTION] = [];

capacity_assert(
  ResetWatermarkStore::set($ipv6_expanded, $ipv6_reset),
  'Expanded IPv6 reset could not be stored.'
);
capacity_assert(
  ResetWatermarkStore::get($ipv6_canonical) === $ipv6_reset,
  'Canonical IPv6 lookup missed an equivalent reset key.'
);
capacity_assert(
  array_keys($capacity_options[ResetWatermarkStore::SITE_OPTION])
    === [$ipv6_canonical],
  'A noncanonical IPv6 reset key was written.'
);
capacity_assert(
  ResetWatermarkStore::clear($ipv6_canonical),
  'Canonical IPv6 clear failed.'
);
capacity_assert(
  ResetWatermarkStore::get($ipv6_expanded) === 0,
  'Clearing canonical IPv6 did not clear its expanded equivalent.'
);

$capacity_options[ResetWatermarkStore::SITE_OPTION] = [
  $ipv6_expanded => $ipv6_reset - 10,
  $ipv6_canonical => $ipv6_reset,
];
capacity_assert(
  ResetWatermarkStore::get($ipv6_expanded) === $ipv6_reset,
  'Duplicate legacy IPv6 keys did not retain the newest watermark.'
);
capacity_assert(
  ResetWatermarkStore::set($ipv6_expanded, $ipv6_reset),
  'Normalized legacy IPv6 option data could not be persisted.'
);
capacity_assert(
  $capacity_options[ResetWatermarkStore::SITE_OPTION]
    === [$ipv6_canonical => $ipv6_reset],
  'Legacy equivalent IPv6 keys were written back noncanonically.'
);

$plain_ipv4 = '8.8.8.8';
$mapped_ipv6 = '::ffff:8.8.8.8';
$expanded_mapped_ipv6 = '0:0:0:0:0:ffff:0808:0808';
capacity_assert(
  WPCF\FirewallSync\Services\IpValidator::normalize_public_ip(
    $plain_ipv4
  ) === $plain_ipv4,
  'Plain IPv4 canonicalization changed its identity.'
);
capacity_assert(
  WPCF\FirewallSync\Services\IpValidator::normalize_public_ip(
    $mapped_ipv6
  ) === $plain_ipv4,
  'Dotted IPv4-mapped IPv6 did not canonicalize to IPv4.'
);
capacity_assert(
  WPCF\FirewallSync\Services\IpValidator::normalize_public_ip(
    $expanded_mapped_ipv6
  ) === $plain_ipv4,
  'Expanded IPv4-mapped IPv6 did not canonicalize by packed prefix.'
);
capacity_assert(
  WPCF\FirewallSync\Services\IpValidator::normalize_public_ip(
    '::ffff:0808:0808'
  ) === null,
  'The regression improperly broadened mapped IPv6 validation.'
);
capacity_assert(
  ResetWatermarkStore::set($expanded_mapped_ipv6, $ipv6_reset),
  'Expanded IPv4-mapped IPv6 reset could not be stored.'
);
capacity_assert(
  ResetWatermarkStore::get($plain_ipv4) === $ipv6_reset,
  'Expanded mapped reset did not share canonical IPv4 identity.'
);
capacity_assert(
  array_key_exists(
    $plain_ipv4,
    $capacity_options[ResetWatermarkStore::SITE_OPTION]
  ),
  'Mapped reset was not stored under one plain IPv4 key.'
);
capacity_assert(
  ResetWatermarkStore::clear($plain_ipv4)
    && ResetWatermarkStore::get($expanded_mapped_ipv6) === 0,
  'Plain IPv4 did not clear the expanded mapped reset.'
);

$normalize_logs = new ReflectionMethod(
  WPCF\FirewallSync\Services\NetworkSynchronizer::class,
  'normalize_logged_ips'
);
$normalized_logs = $normalize_logs->invoke(null, [
  $ipv6_expanded,
  $ipv6_canonical,
]);
capacity_assert(
  $normalized_logs === [$ipv6_canonical]
    && array_diff([$ipv6_canonical], $normalized_logs) === [],
  'Network reconciliation split equivalent IPv6 identities.'
);
$normalized_mapped_logs = $normalize_logs->invoke(null, [
  $plain_ipv4,
  $mapped_ipv6,
  $expanded_mapped_ipv6,
]);
capacity_assert(
  $normalized_mapped_logs === [$plain_ipv4],
  'Network reconciliation split mapped IPv6 from plain IPv4.'
);
capacity_assert(
  WPCF\FirewallSync\Services\IpValidator::normalize_public_ip(
    $ipv6_expanded
  ) === $ipv6_canonical,
  'Native IPv6 canonicalization changed.'
);

echo "Canonical IPv6 reset/reconciliation regression: PASS\n";
