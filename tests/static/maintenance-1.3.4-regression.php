<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$now = time();
$test_options = [];

function maintenance_fail(string $message): never {
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

function maintenance_assert(bool $condition, string $message): void {
  if (!$condition) {
    maintenance_fail($message);
  }
}

function wp_parse_url(string $url, int $component) {
  return parse_url($url, $component);
}

function wp_timezone(): DateTimeZone {
  return new DateTimeZone('UTC');
}

function is_multisite(): bool {
  return false;
}

function get_option(string $name, $default = false) {
  global $test_options;
  return $test_options[$name] ?? $default;
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
  global $test_options;
  $test_options[$name] = $value;
  return true;
}

function update_option(
  string $name,
  $value,
  $autoload = null
): bool {
  global $test_options;
  $test_options[$name] = $value;
  return true;
}

if (!defined('HOUR_IN_SECONDS')) {
  define('HOUR_IN_SECONDS', 3600);
}

if (!defined('DAY_IN_SECONDS')) {
  define('DAY_IN_SECONDS', 86400);
}

if (!defined('ARRAY_A')) {
  define('ARRAY_A', 'ARRAY_A');
}

final class wfDB {
  public static function networkTable(
    $table,
    $applyCaseConversion = true
  ): string {
    maintenance_assert(
      $table === 'wfHits',
      'The Wordfence network table resolver received a wrong name.'
    );
    return 'wp_wfHits';
  }
}

final class MaintenanceDatabaseFake {
  public string $prefix = 'wp_';
  public array $rows = [];
  public array $synced = [];
  private array $prepared_values = [];

  public function esc_like(string $value): string {
    return $value;
  }

  public function prepare(string $query, ...$values): string {
    $this->prepared_values = $values;
    return $query;
  }

  public function get_var(string $query) {
    if (str_contains($query, 'SHOW TABLES')) {
      return 'wp_wfHits';
    }

    if (str_contains($query, 'SELECT synced_at')) {
      $ip = (string) ($this->prepared_values[0] ?? '');
      return $this->synced[$ip] ?? null;
    }

    return null;
  }

  public function get_results(string $query, string $format): array {
    maintenance_assert(
      str_contains($query, 'FROM wp_wfHits'),
      'Historical evidence did not use wfDB::networkTable().'
    );
    maintenance_assert(
      str_contains($query, 'URL AS event_url'),
      'Historical evidence did not retain its attacked URL.'
    );
    return $this->rows;
  }
}

function wf_ip_hex(string $ip): string {
  $packed = inet_pton($ip);

  if ($packed === false) {
    maintenance_fail('Test IP could not be packed.');
  }

  if (strlen($packed) === 4) {
    $packed = str_repeat("\0", 10) . "\xff\xff" . $packed;
  }

  return strtoupper(bin2hex($packed));
}

require_once $root . '/src/includes/Services/IpValidator.php';
require_once $root . '/src/includes/Config.php';
require_once $root . '/src/includes/Services/BlockLogger.php';
require_once $root . '/src/includes/Services/ResetWatermarkStore.php';
require_once $root . '/src/includes/Services/HistoricalBlockReader.php';
require_once $root . '/src/includes/Services/SyncScheduler.php';

$wpdb = new MaintenanceDatabaseFake();
$ip = '194.180.48.64';
$second_ip = '2001:4860:4860::8888';
$wpdb->rows = [
  [
    'ip_hex' => wf_ip_hex($ip),
    'event_time' => $now - 40,
    'event_url' => 'http://sweers.ch/wp-json/test',
  ],
  [
    'ip_hex' => wf_ip_hex($ip),
    'event_time' => $now - 50,
    'event_url' => 'https://sweers.ch/second',
  ],
  [
    'ip_hex' => wf_ip_hex($ip),
    'event_time' => $now - 60,
    'event_url' => 'https://sweers.ch/third',
  ],
  [
    'ip_hex' => wf_ip_hex($ip),
    'event_time' => $now - 30,
    'event_url' => 'https://sweers.ch.attacker.example/trap',
  ],
  [
    'ip_hex' => wf_ip_hex($ip),
    'event_time' => $now - 20,
    'event_url' => 'https://salus.zone/real-attack',
  ],
  [
    'ip_hex' => wf_ip_hex($second_ip),
    'event_time' => $now - 10,
    'event_url' => 'https://sweers.ch/ipv6',
  ],
];

$sweers = \WPCF\FirewallSync\Services\HistoricalBlockReader::
  get_candidates(24, 1, ['sweers.ch']);
$greyscale = \WPCF\FirewallSync\Services\HistoricalBlockReader::
  get_candidates(24, 1, ['greyscale.zone']);
$salus = \WPCF\FirewallSync\Services\HistoricalBlockReader::
  get_candidates(24, 1, ['salus.zone']);

maintenance_assert(count($sweers) === 2, 'Sweers attribution failed.');
maintenance_assert($greyscale === [], 'Evidence leaked to greyscale.zone.');
maintenance_assert(
  count($salus) === 1 && $salus[0]['event_count'] === 1,
  'A genuine second-site attack was not independently attributable.'
);
maintenance_assert(
  $sweers[1]['event_count'] === 3,
  'A host-prefix attack was incorrectly matched to sweers.ch.'
);

$wpdb->synced[$ip] = gmdate('Y-m-d H:i:s', $now - 45);
$post_sync = \WPCF\FirewallSync\Services\HistoricalBlockReader::
  get_candidates(24, 3, ['sweers.ch']);
maintenance_assert(
  !in_array($ip, array_column($post_sync, 'ip'), true),
  'Pre-watermark events were reused toward a threshold.'
);

$wpdb->synced[$ip] = gmdate('Y-m-d H:i:s', $now - 70);
$requalified = \WPCF\FirewallSync\Services\HistoricalBlockReader::
  get_candidates(24, 3, ['sweers.ch']);
maintenance_assert(
  in_array($ip, array_column($requalified, 'ip'), true),
  'Three newer attributable events did not requalify the IP.'
);

unset($wpdb->synced[$ip]);
\WPCF\FirewallSync\Services\ResetWatermarkStore::set(
  $ip,
  $now - 45
);
$reset_blocked = \WPCF\FirewallSync\Services\HistoricalBlockReader::
  get_candidates(24, 3, ['sweers.ch']);
maintenance_assert(
  !in_array($ip, array_column($reset_blocked, 'ip'), true),
  'Pre-reset evidence recreated an IP.'
);
maintenance_assert(
  \WPCF\FirewallSync\Services\ResetWatermarkStore::get($ip)
    === $now - 45,
  'The reset watermark was not retained independently.'
);

$source = '';
$iterator = new RecursiveIteratorIterator(
  new RecursiveDirectoryIterator($root . '/src')
);

foreach ($iterator as $file) {
  if ($file->isFile() && $file->getExtension() === 'php') {
    $contents = file_get_contents($file->getPathname());
    $source .= $contents === false ? '' : $contents;
  }
}

foreach ([
  'first_seen_at',
  'last_seen_at',
  'occurrence_count',
  'previous_expires_at',
  'lifecycle_state',
] as $abandoned_column) {
  maintenance_assert(
    !str_contains($source, $abandoned_column),
    "Abandoned beta column is referenced: {$abandoned_column}"
  );
}

$client_source = file_get_contents(
  $root . '/src/includes/Cloudflare/Client.php'
);
$scheduler_source = file_get_contents(
  $root . '/src/includes/Services/SyncScheduler.php'
);
$network_source = file_get_contents(
  $root . '/src/includes/Services/NetworkSynchronizer.php'
);
$settings_source = file_get_contents(
  $root . '/src/includes/Admin/Settings.php'
);
$cli_source = file_get_contents(
  $root . '/src/includes/CLI/Commands.php'
);

maintenance_assert(
  is_string($client_source)
    && str_contains($client_source, '): ?array {'),
  'Cloudflare inventory does not expose a nullable contract.'
);
maintenance_assert(
  is_string($settings_source)
    && str_contains($settings_source, 'Incomplete cleanup:'),
  'Admin reconciliation hides safely completed partial purges.'
);
maintenance_assert(
  is_string($cli_source)
    && str_contains($cli_source, 'completed purge(s)'),
  'WP-CLI reconciliation hides safely completed partial purges.'
);
maintenance_assert(
  is_string($scheduler_source)
    && str_contains($scheduler_source, 'isset($cloudflare_set[$ip])'),
  'Cloudflare membership is absent from synchronization decisions.'
);
maintenance_assert(
  str_contains($scheduler_source, 'active_evidence_is_newer'),
  'Stale active Wordfence blocks are not checked against the watermark.'
);

$active_evidence = new ReflectionMethod(
  \WPCF\FirewallSync\Services\SyncScheduler::class,
  'active_evidence_is_newer'
);
maintenance_assert(
  $active_evidence->invoke(null, $now - 10, $now - 20) === true,
  'A genuinely newer active block did not pass its watermark.'
);
maintenance_assert(
  $active_evidence->invoke(null, $now - 30, $now - 20) === false,
  'A stale active block passed its watermark.'
);
maintenance_assert(
  $active_evidence->invoke(null, 0, $now - 20) === false,
  'An active block without reliable temporal identity passed.'
);
maintenance_assert(
  is_string($network_source)
    && str_contains(
      $network_source,
      '$summary[\'successful\'] === $summary[\'processed\']'
    ),
  'Network reconciliation is not gated on every site succeeding.'
);

echo "Grey Rock 1.3.4 maintenance regression: PASS\n";
