<?php

namespace WPCF\FirewallSync\Services;

use WPCF\FirewallSync\Cloudflare\Client;
use WPCF\FirewallSync\Services\BlockLogger;

final class Reconciler {
  public static function run(
    Client $client,
    array $options = [],
    ?array $complete_inventory = null,
    bool $allow_purge = true
  ): array {
    $mode = $options['cloudflare_mode'] ?? 'zone_access_rules';

    if ($mode === 'account_list') {
      $account_id = $options['cloudflare_account_id'] ?? '';
      $list_id = $client->resolve_account_list_id(
        $account_id,
        $options['cloudflare_list_name'] ?? '',
        $options['cloudflare_list_id'] ?? ''
      );

      if ($list_id === null) {
        return self::error_result($client);
      }

      $cf_ips = $complete_inventory
        ?? $client->get_current_account_list_ips(
          $account_id,
          $list_id
        );
    } else {
      $cf_ips = $complete_inventory
        ?? $client->get_current_blocked_ips();
    }

    if (!is_array($cf_ips)) {
      return self::error_result($client);
    }

    $log_ips = array_values(array_filter(array_map(
      static fn (string $ip): ?string =>
        IpValidator::normalize_public_ip($ip),
      BlockLogger::get_all_ips()
    )));
    $cf_set = array_flip($cf_ips);
    $log_set = array_flip($log_ips);
    $missing_in_cf = array_diff_key($log_set, $cf_set);
    $orphaned_in_cf = array_diff_key($cf_set, $log_set);

    $purged = [];
    $cleanup_error = '';

    if (
      $mode === 'account_list'
      && $allow_purge
      && !empty(
        $options['purge_local_records_missing_in_cloudflare']
      )
    ) {
      $cleanup = self::purge_missing_local_records(
        array_keys($missing_in_cf),
        time()
      );
      $purged = $cleanup['purged'];
      $cleanup_error = $cleanup['error'];
    }

    return [
      'complete' => $cleanup_error === '',
      'missing_in_cf' => array_keys($missing_in_cf),
      'orphaned_in_cf' => array_keys($orphaned_in_cf),
      'purged' => $purged,
      'error' => $cleanup_error,
    ];
  }

  private static function purge_missing_local_records(
    array $missing_ips,
    int $reset_at
  ): array {
    $purged = [];

    foreach ($missing_ips as $ip) {
      if (!ResetWatermarkStore::set($ip, $reset_at)) {
        return [
          'purged' => $purged,
          'error' => __(
            'Local cleanup stopped because the reset-watermark store is at safe capacity. No required watermark was evicted.',
            'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
          ),
        ];
      }

      if (!BlockLogger::remove($ip)) {
        return [
          'purged' => $purged,
          'error' => __(
            'Local cleanup stopped because a synchronization record could not be removed safely.',
            'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
          ),
        ];
      }

      $purged[] = $ip;
    }

    return ['purged' => $purged, 'error' => ''];
  }

  private static function error_result(Client $client): array {
    $error = $client->get_last_error_message();

    return [
      'complete' => false,
      'missing_in_cf' => [],
      'orphaned_in_cf' => [],
      'purged' => [],
      'error' => $error !== ''
        ? $error
        : __(
          'Cloudflare inventory could not be proven complete.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        ),
    ];
  }
}
