<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Services;

use WPCF\FirewallSync\Cloudflare\Client;
use WPCF\FirewallSync\Config;

final class NetworkSynchronizer {
  /**
   * Synchronize every multisite site inheriting Network Admin settings.
   *
   * When $due_only is true, only externally scheduled sites whose configured
   * interval has elapsed are synchronized.
   */
  public static function run(bool $due_only = false): array {
    $summary = [
      'processed' => 0,
      'successful' => 0,
      'not_due' => 0,
      'disabled' => 0,
      'failed' => [],
      'reconciliation' => null,
    ];

    if (!is_multisite()) {
      return $summary;
    }

    $inheriting_site_ids = [];

    foreach (get_sites(['fields' => 'ids']) as $blog_id) {
      switch_to_blog((int) $blog_id);

      try {
        if (!Config::uses_network_options()) {
          continue;
        }

        $inheriting_site_ids[] = (int) $blog_id;

        $summary['processed']++;

        if ($due_only) {
          $result = SyncScheduler::run_if_due(
            Config::SCHEDULER_EXTERNAL
          );
        } else {
          $result = SyncScheduler::run_now()
            ? SyncScheduler::RESULT_SUCCESS
            : SyncScheduler::RESULT_FAILURE;
        }

        if ($result === SyncScheduler::RESULT_SUCCESS) {
          $summary['successful']++;
          continue;
        }

        if ($result === SyncScheduler::RESULT_NOT_DUE) {
          $summary['not_due']++;
          continue;
        }

        if ($result === SyncScheduler::RESULT_DISABLED) {
          $summary['disabled']++;
          continue;
        }

        $site_name = get_bloginfo('name');
        $site_url = home_url('/');
        $error = SyncScheduler::get_last_error_message();

        $summary['failed'][] = [
          'site_id' => (int) $blog_id,
          'site_name' => $site_name !== ''
            ? $site_name
            : $site_url,
          'site_url' => $site_url,
          'error' => $error !== ''
            ? $error
            : __(
              'Synchronization failed.',
              'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
            ),
        ];
      } finally {
        restore_current_blog();
      }
    }

    if (
      $summary['processed'] > 0
      && $summary['successful'] === $summary['processed']
      && $summary['not_due'] === 0
      && $summary['disabled'] === 0
      && empty($summary['failed'])
    ) {
      $summary['reconciliation'] = self::reconcile_network(
        $inheriting_site_ids
      );
    }

    return $summary;
  }

  /**
   * Reconcile one shared account-list destination only after every
   * inheriting site has completed its evidence evaluation.
   */
  private static function reconcile_network(array $site_ids): array {
    $options = Config::get_network_options();

    if (($options['cloudflare_mode'] ?? '') !== 'account_list') {
      return ['complete' => true, 'purged' => [], 'error' => ''];
    }

    $client = new Client(
      (string) ($options['cloudflare_api_token'] ?? ''),
      (string) ($options['cloudflare_zone_id'] ?? '')
    );
    $account_id = (string) (
      $options['cloudflare_account_id'] ?? ''
    );
    $list_id = $client->resolve_account_list_id(
      $account_id,
      (string) ($options['cloudflare_list_name'] ?? ''),
      (string) ($options['cloudflare_list_id'] ?? '')
    );

    if ($list_id === null) {
      return self::network_error($client);
    }

    $inventory = $client->get_current_account_list_ips(
      $account_id,
      $list_id
    );

    if ($inventory === null) {
      return self::network_error($client);
    }

    $cf_set = array_fill_keys($inventory, true);
    $missing = [];
    $log_set = [];

    foreach ($site_ids as $blog_id) {
      switch_to_blog((int) $blog_id);

      try {
        foreach (self::normalize_logged_ips(
          BlockLogger::get_all_ips()
        ) as $ip) {
          $log_set[$ip] = true;

          if (!isset($cf_set[$ip])) {
            $missing[$ip] = true;
          }
        }
      } finally {
        restore_current_blog();
      }
    }

    $purged = [];
    $cleanup_error = '';

    if (!empty(
      $options['purge_local_records_missing_in_cloudflare']
    )) {
      foreach (array_keys($missing) as $ip) {
        $watermark_set = false;

        foreach ($site_ids as $blog_id) {
          switch_to_blog((int) $blog_id);

          try {
            if (!$watermark_set) {
              $watermark_set = ResetWatermarkStore::set(
                $ip,
                time()
              );
            }

            if (!$watermark_set) {
              $cleanup_error = __(
                'Shared cleanup stopped because the network reset-watermark store is at safe capacity. No required watermark was evicted.',
                'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
              );
              break;
            }

            if (!BlockLogger::remove($ip)) {
              $cleanup_error = __(
                'Shared cleanup stopped because a site synchronization record could not be removed safely.',
                'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
              );
              break;
            }
          } finally {
            restore_current_blog();
          }
        }

        if ($watermark_set && $cleanup_error === '') {
          $purged[] = $ip;
        }

        if ($cleanup_error !== '') {
          break;
        }
      }
    }

    return [
      'complete' => $cleanup_error === '',
      'missing_in_cf' => array_keys($missing),
      'orphaned_in_cf' => array_values(
        array_diff($inventory, array_keys($log_set))
      ),
      'purged' => $purged,
      'error' => $cleanup_error,
    ];
  }

  private static function normalize_logged_ips(array $ips): array {
    $normalized = [];

    foreach ($ips as $ip) {
      $canonical = IpValidator::normalize_public_ip((string) $ip);

      if ($canonical !== null) {
        $normalized[$canonical] = true;
      }
    }

    return array_keys($normalized);
  }

  private static function network_error(Client $client): array {
    $error = $client->get_last_error_message();

    return [
      'complete' => false,
      'missing_in_cf' => [],
      'orphaned_in_cf' => [],
      'purged' => [],
      'error' => $error !== ''
        ? $error
        : __('Cloudflare inventory is incomplete.', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'),
    ];
  }
}
