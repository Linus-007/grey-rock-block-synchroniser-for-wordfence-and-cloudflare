<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$current_blog_id = 1;

$site_options = [
  1 => [
    'configuration_source' => 'network',
  ],
  2 => [
    'configuration_source' => 'network',
  ],
  3 => [
    'configuration_source' => 'site',
  ],
];

$network_options = [
  'cloudflare_mode' => 'account_list',
  'cloudflare_api_token' => str_repeat('a', 40),
  'cloudflare_zone_id' => '',
  'cloudflare_account_id' => str_repeat('b', 32),
  'cloudflare_list_id' => '',
  'cloudflare_list_name' => 'wordfence_hot_blocklist',
  'purge_local_records_missing_in_cloudflare' => '0',
];

$network_manual = [];
$network_resets = [];

$stale_ip = '8.8.4.4';
$protected_ip = '1.1.1.1';

$site_logs = [
  1 => [
    $stale_ip => true,
    $protected_ip => true,
  ],
  2 => [
    $stale_ip => true,
    $protected_ip => true,
  ],
  3 => [],
];

$site_ownership = [
  1 => [],
  2 => [
    $protected_ip => [
      'wordfence' => true,
    ],
  ],
  3 => [],
];

$list_id = str_repeat('c', 32);

$cloudflare_items = [
  $stale_ip => 'item-stale',
  $protected_ip => 'item-protected',
];

$cloudflare_delete_ips = [];

function network_reconcile_fail(string $message): never {
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

function network_reconcile_assert(
  bool $condition,
  string $message
): void {
  if (!$condition) {
    network_reconcile_fail($message);
  }
}

function __(string $text, ?string $domain = null): string {
  return $text;
}

function _n(
  string $single,
  string $plural,
  int $number,
  ?string $domain = null
): string {
  return $number === 1 ? $single : $plural;
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
  global
    $network_options,
    $network_manual,
    $network_resets;

  if ($name === 'firewall_sync_network_options') {
    return $network_options;
  }

  if ($name === 'firewall_sync_network_manual_ownership') {
    return $network_manual;
  }

  if ($name === 'firewall_sync_network_reset_watermarks') {
    return $network_resets;
  }

  return $default;
}

function update_site_option(string $name, $value): bool {
  global $network_manual, $network_resets;

  if ($name === 'firewall_sync_network_manual_ownership') {
    $network_manual = $value;
    return true;
  }

  if ($name === 'firewall_sync_network_reset_watermarks') {
    $network_resets = $value;
    return true;
  }

  return true;
}

function add_option(
  string $name,
  $value,
  string $deprecated = '',
  bool $autoload = true
): bool {
  return true;
}

function update_option(
  string $name,
  $value,
  $autoload = null
): bool {
  return true;
}

function current_time(string $type): string {
  return gmdate('Y-m-d H:i:s');
}

function switch_to_blog(int $blog_id): bool {
  global $current_blog_id;
  $current_blog_id = $blog_id;
  return true;
}

function restore_current_blog(): bool {
  global $current_blog_id;

  /*
   * reconcile_network() switches one site at a time from the network
   * context. For this controlled regression, returning to blog 1 is enough
   * because each subsequent switch supplies the exact target blog.
   */
  $current_blog_id = 1;
  return true;
}

function wp_json_encode($value): string {
  return json_encode($value, JSON_THROW_ON_ERROR);
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

function network_reconcile_response(
  array $result,
  int $total_pages = 1
): array {
  return [
    'response' => [
      'code' => 200,
      'message' => 'OK',
    ],
    'body' => json_encode(
      [
        'success' => true,
        'errors' => [],
        'messages' => [],
        'result' => $result,
        'result_info' => [
          'total_pages' => $total_pages,
        ],
      ],
      JSON_THROW_ON_ERROR
    ),
  ];
}

function wp_remote_get(string $url, array $args): array {
  global $list_id, $cloudflare_items;

  if (
    str_contains($url, '/rules/lists?')
  ) {
    return network_reconcile_response([
      [
        'id' => $list_id,
        'name' => 'wordfence_hot_blocklist',
        'kind' => 'ip',
      ],
    ]);
  }

  if (
    str_contains($url, '/items?')
  ) {
    $result = [];

    foreach ($cloudflare_items as $ip => $item_id) {
      $result[] = [
        'id' => $item_id,
        'ip' => $ip,
      ];
    }

    return network_reconcile_response($result);
  }

  network_reconcile_fail(
    'Unexpected Cloudflare GET request: ' . $url
  );
}

function wp_remote_request(string $url, array $args): array {
  global
    $cloudflare_items,
    $cloudflare_delete_ips;

  if (
    ($args['method'] ?? '') !== 'DELETE'
    || !str_contains($url, '/items')
  ) {
    network_reconcile_fail(
      'Unexpected Cloudflare request: '
      . ($args['method'] ?? 'UNKNOWN')
      . ' '
      . $url
    );
  }

  $body = json_decode(
    (string) ($args['body'] ?? ''),
    true
  );

  $item_id = (string) (
    $body['items'][0]['id'] ?? ''
  );

  $matched_ip = null;

  foreach ($cloudflare_items as $ip => $id) {
    if ($id === $item_id) {
      $matched_ip = $ip;
      break;
    }
  }

  if ($matched_ip === null) {
    network_reconcile_fail(
      'Cloudflare deletion referenced an unknown item ID.'
    );
  }

  $cloudflare_delete_ips[] = $matched_ip;
  unset($cloudflare_items[$matched_ip]);

  return network_reconcile_response([]);
}

final class NetworkReconcileDatabaseFake {
  public string $prefix = 'wp_';

  private array $prepared_values = [];

  public function prepare(string $query, ...$values): string {
    $this->prepared_values = $values;
    return $query;
  }

  public function get_col(string $query): array {
    global
      $current_blog_id,
      $site_logs,
      $site_ownership;

    if (str_contains($query, 'wpcf_sync_blocks')) {
      return array_keys(
        array_filter(
          $site_logs[$current_blog_id] ?? []
        )
      );
    }

    if (str_contains($query, 'wpcf_sync_ownership')) {
      $owner = (string) (
        $this->prepared_values[0] ?? ''
      );

      $ips = [];

      foreach (
        $site_ownership[$current_blog_id] ?? []
        as $ip => $owners
      ) {
        if (!empty($owners[$owner])) {
          $ips[] = $ip;
        }
      }

      return $ips;
    }

    return [];
  }

  public function get_var(string $query) {
    global
      $current_blog_id,
      $site_ownership;

    $ip = (string) (
      $this->prepared_values[0] ?? ''
    );

    if (str_contains($query, 'wpcf_sync_ownership')) {
      $owners = $site_ownership[$current_blog_id][$ip] ?? [];

      if (str_contains($query, 'owner =')) {
        $owner = (string) (
          $this->prepared_values[1] ?? ''
        );

        return !empty($owners[$owner])
          ? '1'
          : null;
      }

      return $owners !== [] ? '1' : null;
    }

    return null;
  }

  public function delete(
    string $table,
    array $where,
    array $formats = []
  ): int|false {
    global
      $current_blog_id,
      $site_logs,
      $site_ownership;

    $ip = (string) ($where['ip'] ?? '');

    if (str_contains($table, 'wpcf_sync_blocks')) {
      unset($site_logs[$current_blog_id][$ip]);
      return 1;
    }

    if (str_contains($table, 'wpcf_sync_ownership')) {
      $owner = (string) ($where['owner'] ?? '');

      unset(
        $site_ownership[$current_blog_id][$ip][$owner]
      );

      if (
        empty(
          $site_ownership[$current_blog_id][$ip]
        )
      ) {
        unset(
          $site_ownership[$current_blog_id][$ip]
        );
      }

      return 1;
    }

    return 0;
  }
}

if (!defined('HOUR_IN_SECONDS')) {
  define('HOUR_IN_SECONDS', 3600);
}

require_once $root . '/src/includes/Services/IpValidator.php';
require_once $root . '/src/includes/Services/CloudflareIdentifierValidator.php';
require_once $root . '/src/includes/Services/DnsAllowList.php';
require_once $root . '/src/includes/Config.php';
require_once $root . '/src/includes/Services/BlockLogger.php';
require_once $root . '/src/includes/Services/BlockOwnership.php';
require_once $root . '/src/includes/Services/NetworkManualOwnershipStore.php';
require_once $root . '/src/includes/Services/ResetWatermarkStore.php';
require_once $root . '/src/includes/Cloudflare/Client.php';
require_once $root . '/src/includes/Services/NetworkSynchronizer.php';

$wpdb = new NetworkReconcileDatabaseFake();

$method = new ReflectionMethod(
  WPCF\FirewallSync\Services\NetworkSynchronizer::class,
  'reconcile_network'
);

$result = $method->invoke(
  null,
  [1, 2]
);

network_reconcile_assert(
  !empty($result['complete']),
  'Network reconciliation did not complete successfully.'
);

network_reconcile_assert(
  $result['error'] === '',
  'Network reconciliation returned an unexpected error.'
);

network_reconcile_assert(
  $result['removed_from_cf'] === [$stale_ip],
  'The stale managed IP was not reported as removed from Cloudflare.'
);

network_reconcile_assert(
  $cloudflare_delete_ips === [$stale_ip],
  'Cloudflare deletion did not target exactly the stale managed IP.'
);

network_reconcile_assert(
  !isset($cloudflare_items[$stale_ip]),
  'Stale managed IP remained in Cloudflare.'
);

network_reconcile_assert(
  isset($cloudflare_items[$protected_ip]),
  'Still-owned IP was incorrectly removed from Cloudflare.'
);

network_reconcile_assert(
  !isset($site_logs[1][$stale_ip])
    && !isset($site_logs[2][$stale_ip]),
  'Stale per-site synchronization provenance was not cleared.'
);

network_reconcile_assert(
  isset($site_logs[1][$protected_ip])
    && isset($site_logs[2][$protected_ip]),
  'Protected IP synchronization provenance was incorrectly cleared.'
);

network_reconcile_assert(
  isset($network_resets[$stale_ip])
    && (int) $network_resets[$stale_ip] > 0,
  'Network reset watermark was not recorded for the removed stale IP.'
);

echo "Network reconciliation ownership regression: PASS\n";
