<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$shared_ip = '8.8.4.4';
$current_blog_id = 1;
$site_logs = [1 => [], 2 => [], 3 => []];
$site_options = [
  1 => ['configuration_source' => 'network'],
  2 => ['configuration_source' => 'network'],
  3 => ['configuration_source' => 'network'],
];
$network_options = [];
$network_resets = [];
$cloudflare_items = [];
$cloudflare_create_count = 0;

function shared_fail(string $message): never {
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

function shared_assert(bool $condition, string $message): void {
  if (!$condition) {
    shared_fail($message);
  }
}

function __(string $text, ?string $domain = null): string {
  return $text;
}

function is_multisite(): bool {
  return true;
}

function get_option(string $name, $default = false) {
  global $current_blog_id, $site_options;

  if ($name === 'firewall_sync_options') {
    return $site_options[$current_blog_id] ?? $default;
  }

  return $default;
}

function get_site_option(string $name, $default = false) {
  global $network_options, $network_resets;

  if ($name === 'firewall_sync_network_options') {
    return $network_options;
  }

  if ($name === 'firewall_sync_network_reset_watermarks') {
    return $network_resets;
  }

  return $default;
}

function update_site_option(string $name, $value): bool {
  global $network_resets;

  if ($name === 'firewall_sync_network_reset_watermarks') {
    $network_resets = $value;
  }

  return true;
}

function current_time(string $type): string {
  return gmdate('Y-m-d H:i:s');
}

function wp_timezone(): DateTimeZone {
  return new DateTimeZone('UTC');
}

function wp_parse_url(string $url, int $component) {
  return parse_url($url, $component);
}

function wp_json_encode($value): string {
  return json_encode($value, JSON_THROW_ON_ERROR);
}

function sanitize_text_field($value): string {
  return trim((string) $value);
}

function is_wp_error($response): bool {
  return false;
}

function wp_remote_retrieve_response_code($response): int {
  return (int) ($response['response']['code'] ?? 0);
}

function wp_remote_retrieve_response_message($response): string {
  return (string) ($response['response']['message'] ?? '');
}

function wp_remote_retrieve_body($response): string {
  return (string) ($response['body'] ?? '');
}

function shared_response(array $result): array {
  return [
    'response' => ['code' => 200, 'message' => 'OK'],
    'body' => json_encode([
      'success' => true,
      'errors' => [],
      'messages' => [],
      'result' => $result,
      'result_info' => ['total_pages' => 1],
    ], JSON_THROW_ON_ERROR),
  ];
}

function wp_remote_get(string $url, array $args): array {
  global $cloudflare_items;

  $result = [];

  foreach ($cloudflare_items as $ip => $id) {
    $result[] = ['ip' => $ip, 'id' => $id];
  }

  return shared_response($result);
}

function wp_remote_post(string $url, array $args): array {
  global $cloudflare_items, $cloudflare_create_count;

  $body = json_decode((string) ($args['body'] ?? ''), true);
  $ip = (string) ($body[0]['ip'] ?? '');
  $cloudflare_create_count++;
  $cloudflare_items[$ip] = 'item-' . $cloudflare_create_count;

  return shared_response([]);
}

if (!defined('HOUR_IN_SECONDS')) {
  define('HOUR_IN_SECONDS', 3600);
}

if (!defined('ARRAY_A')) {
  define('ARRAY_A', 'ARRAY_A');
}

final class wfDB {
  public static function networkTable($table): string {
    shared_assert($table === 'wfHits', 'Wrong Wordfence table.');
    return 'wp_wfHits';
  }
}

final class SharedAttributionDatabaseFake {
  public string $prefix = 'wp_';
  public array $history_rows = [];
  private array $prepared_values = [];

  public function esc_like(string $value): string {
    return $value;
  }

  public function prepare(string $query, ...$values): string {
    $this->prepared_values = $values;
    return $query;
  }

  public function get_var(string $query) {
    global $current_blog_id, $site_logs;

    if (str_contains($query, 'SHOW TABLES')) {
      return 'wp_wfHits';
    }

    $ip = (string) ($this->prepared_values[0] ?? '');

    if (str_contains($query, 'SELECT synced_at')) {
      return $site_logs[$current_blog_id][$ip]['synced_at'] ?? null;
    }

    if (str_contains($query, 'SELECT 1')) {
      return isset($site_logs[$current_blog_id][$ip]) ? '1' : null;
    }

    return null;
  }

  public function get_results(string $query, string $format): array {
    return $this->history_rows;
  }

  public function query(string $query): int {
    global $current_blog_id, $site_logs;

    if (str_contains($query, 'INSERT INTO')) {
      $ip = (string) ($this->prepared_values[0] ?? '');
      $site_logs[$current_blog_id][$ip] = [
        'reason' => (string) ($this->prepared_values[1] ?? ''),
        'synced_at' => (string) ($this->prepared_values[3] ?? ''),
      ];
    }

    return 1;
  }
}

function shared_wf_hex(string $ip): string {
  $packed = inet_pton($ip);

  if ($packed === false) {
    shared_fail('Could not encode the test IP.');
  }

  if (strlen($packed) === 4) {
    $packed = str_repeat("\0", 10) . "\xff\xff" . $packed;
  }

  return strtoupper(bin2hex($packed));
}

require_once $root . '/src/includes/Services/IpValidator.php';
require_once $root . '/src/includes/Services/CloudflareIdentifierValidator.php';
require_once $root . '/src/includes/Config.php';
require_once $root . '/src/includes/Services/BlockLogger.php';
require_once $root . '/src/includes/Services/ResetWatermarkStore.php';
require_once $root . '/src/includes/Services/HistoricalBlockReader.php';
require_once $root . '/src/includes/Cloudflare/Client.php';
require_once $root . '/src/includes/Services/SyncScheduler.php';

$wpdb = new SharedAttributionDatabaseFake();
$event_time = time() - 30;
$wpdb->history_rows = [
  [
    'ip_hex' => shared_wf_hex($shared_ip),
    'event_time' => $event_time,
    'event_url' => 'https://site-a.example/attack',
  ],
  [
    'ip_hex' => shared_wf_hex($shared_ip),
    'event_time' => $event_time,
    'event_url' => 'https://site-b.example/attack',
  ],
];

$sync_batch = new ReflectionMethod(
  WPCF\FirewallSync\Services\SyncScheduler::class,
  'synchronize_account_list_batch'
);
$skip_present = new ReflectionMethod(
  WPCF\FirewallSync\Services\SyncScheduler::class,
  'should_skip_present_account_list_ip'
);
$account_id = str_repeat('a', 32);
$list_id = str_repeat('b', 32);
$hosts = [
  1 => 'site-a.example',
  2 => 'site-b.example',
  3 => 'site-c.example',
];

foreach ($hosts as $blog_id => $host) {
  $current_blog_id = $blog_id;
  $candidates = WPCF\FirewallSync\Services\HistoricalBlockReader::
    get_candidates(24, 1, [$host]);
  $batch = [];

  foreach ($candidates ?? [] as $candidate) {
    $batch[] = [
      'ip' => $candidate['ip'],
      'reason' => 'Attributable historical evidence for ' . $host,
      'expires_at' => null,
    ];
  }

  $client = new WPCF\FirewallSync\Cloudflare\Client('token', '');
  $sync_batch->invoke(
    null,
    $client,
    $account_id,
    $list_id,
    $batch
  );
}

shared_assert(
  $cloudflare_create_count === 1,
  'The shared IP caused more than one Cloudflare create operation.'
);
shared_assert(
  array_keys($cloudflare_items) === [$shared_ip],
  'Cloudflare did not retain exactly one deduplicated shared IP.'
);
shared_assert(
  isset($site_logs[1][$shared_ip]),
  'Site A did not receive attributable synchronization state.'
);
shared_assert(
  isset($site_logs[2][$shared_ip]),
  'Site B did not receive attributable synchronization state.'
);
shared_assert(
  !isset($site_logs[3][$shared_ip]),
  'Unattacked Site C received synchronization state.'
);
shared_assert(
  $skip_present->invoke(null, true, false) === false,
  'Cloudflare membership incorrectly suppresses first site attribution.'
);
shared_assert(
  $skip_present->invoke(null, true, true) === true,
  'An existing site episode was not suppressed while Cloudflare remained active.'
);

echo "Multisite shared-IP attribution regression: PASS\n";

/* A site success must not clear a network reset needed by a later site. */
$site_logs = [1 => [], 2 => [], 3 => []];
$cloudflare_items = [];
$cloudflare_create_count = 0;
$network_reset_at = time() - 120;
$network_resets = [$shared_ip => $network_reset_at];
$wpdb->history_rows = [
  [
    'ip_hex' => shared_wf_hex($shared_ip),
    'event_time' => $network_reset_at + 30,
    'event_url' => 'https://site-a.example/new-attack',
  ],
  [
    'ip_hex' => shared_wf_hex($shared_ip),
    'event_time' => $network_reset_at - 30,
    'event_url' => 'https://site-b.example/old-attack',
  ],
];

$current_blog_id = 1;
$site_a = WPCF\FirewallSync\Services\HistoricalBlockReader::
  get_candidates(24, 1, ['site-a.example']);
$sync_batch->invoke(null, new WPCF\FirewallSync\Cloudflare\Client('token', ''), $account_id, $list_id, [[
  'ip' => $site_a[0]['ip'],
  'reason' => 'Site A post-reset evidence',
  'expires_at' => null,
]]);

shared_assert(
  WPCF\FirewallSync\Services\ResetWatermarkStore::get($shared_ip)
    === $network_reset_at,
  'Site A cleared a network reset before Site B evaluation.'
);

$current_blog_id = 2;
$site_b = WPCF\FirewallSync\Services\HistoricalBlockReader::
  get_candidates(24, 1, ['site-b.example']);

shared_assert(
  $site_b === [],
  'Site B reused pre-reset evidence after Site A synchronized.'
);
shared_assert(
  $cloudflare_create_count === 1
    && array_keys($cloudflare_items) === [$shared_ip],
  'Network reset ordering did not preserve one Cloudflare entry.'
);
shared_assert(
  isset($site_logs[1][$shared_ip])
    && !isset($site_logs[2][$shared_ip]),
  'Network reset ordering created false Site B attribution.'
);

echo "Network-reset ordering regression: PASS\n";
