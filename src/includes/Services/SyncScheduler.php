<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Services;

use WPCF\FirewallSync\Plugin;
use WPCF\FirewallSync\Config;
use WPCF\FirewallSync\Cloudflare\Client;
use WPCF\FirewallSync\Services\BlockLogger;

final class SyncScheduler {
  private const HOOK = 'firewall_sync_cron_event';
  private const DELETE_BATCH_SIZE = 100;
  private const CLEANUP_HOOK = 'firewall_sync_cleanup_event';

  public static function register(): void {
    add_action(self::HOOK, [self::class, 'run_now']);
    add_action(self::CLEANUP_HOOK, [self::class, 'run_cleanup']);
    add_filter('cron_schedules', [self::class, 'custom_intervals']);

    self::schedule_events();
  }

  /**
   * Create missing synchronization and cleanup cron events.
   */
  private static function schedule_events(): void {
    $options = Config::get_effective_options();

    $minutes = max(5, (int) ($options['sync_interval'] ?? 60));
    $interval_key = $minutes === 5
      ? 'every_5_minutes'
      : ($minutes === 15 ? 'every_15_minutes' : 'hourly');

    if (!wp_next_scheduled(self::HOOK)) {
      wp_schedule_event(time(), $interval_key, self::HOOK);
    }

    if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
      wp_schedule_event(time(), $interval_key, self::CLEANUP_HOOK);
    }
  }

  public static function custom_intervals(array $schedules): array {
    $schedules['every_5_minutes'] = [
      'interval' => 300,
      'display' => __('Every 5 Minutes', Plugin::get_text_domain())
    ];

    $schedules['every_15_minutes'] = [
      'interval' => 900,
      'display' => __('Every 15 Minutes', Plugin::get_text_domain())
    ];

    return $schedules;
  }

  public static function run_now(): bool {
    $options = Config::get_effective_options();
    $token = $options['cloudflare_api_token'] ?? '';
    $zone = $options['cloudflare_zone_id'] ?? '';
    $mode = $options['cloudflare_mode'] ?? 'zone_access_rules';
    $account_id = $options['cloudflare_account_id'] ?? '';
    $list_id = $options['cloudflare_list_id'] ?? '';

    if (empty($token)) {
      return false;
    }

    if ($mode === 'account_list') {
      if (empty($account_id) || empty($list_id)) {
        return false;
      }
    } elseif (empty($zone)) {
      return false;
    }

    if (!class_exists('\wfBlock')) {
      return false;
    }

    $client = new Client($token, $zone);
    $blocks = \wfBlock::getBlocks();
    $batch = [];

    foreach ($blocks as $block) {
      $ip = $block['ip'] ?? null;
      $reason = $block['reason'] ?? __('Unknown', Plugin::get_text_domain());
      $expiration = (int) ($block['expirationUnix'] ?? 0);
      $is_permanent = $block['permanent'] ?? false;

      if (
        !$ip ||
        (!$is_permanent && time() > $expiration) ||
        BlockLogger::has_synced($ip) ||
        BlockLogger::is_blacklisted($ip)
      ) {
        continue;
      }

      $batch[] = ['ip' => $ip, 'reason' => $reason];
    }
    
    if ($mode === 'account_list') {
      $failed = [];

      foreach ($batch as $entry) {
        $added = $client->add_ip_to_account_list(
          $account_id,
          $list_id,
          $entry['ip'],
          'Wordfence sync: ' . $entry['reason']
        );

        if (!$added) {
          $failed[] = $entry['ip'];
        }
      }
    } else {
      $failed = $client->batch_block($batch);
    }

    foreach ($batch as $entry) {
      if (in_array($entry['ip'], $failed, true)) {
        BlockLogger::mark_failed($entry['ip']);
      } else {
        BlockLogger::log($entry['ip'], 'sync: ' . $entry['reason']);
      }
    }

    update_option('firewall_sync_last_run', current_time('mysql'));
    delete_option('firewall_sync_is_running');

    return true;
  }

  public static function run_cleanup(): void {
    global $wpdb;

    /*
     * A site inheriting Network Admin settings may share its Cloudflare
     * destination with other sites. Its local log cannot determine whether
     * another site still requires an address, so it must not delete entries
     * from that shared destination.
     */
    if (is_multisite() && Config::uses_network_options()) {
      return;
    }
    
    $options = Config::get_effective_options();
    $token = $options['cloudflare_api_token'] ?? '';
    $zone = $options['cloudflare_zone_id'] ?? '';
    $mode = $options['cloudflare_mode'] ?? 'zone_access_rules';
    $account_id = $options['cloudflare_account_id'] ?? '';
    $list_id = $options['cloudflare_list_id'] ?? '';

    if (empty($token)) {
      return;
    }

    if ($mode === 'account_list') {
      if (empty($account_id) || empty($list_id)) {
        return;
      }
    } elseif (empty($zone)) {
      return;
    }

    $client = new Client($token, $zone);
    
    $table = $wpdb->prefix . BlockLogger::TABLE;

    do {
      $rows = $wpdb->get_results(
        $wpdb->prepare(
          "SELECT ip FROM {$table} WHERE expires_at IS NOT NULL AND expires_at < NOW() LIMIT %d",
          self::DELETE_BATCH_SIZE
        ),
        ARRAY_A
      );

      foreach ($rows as $row) {
        $ip = $row['ip'] ?? null;

        if ($ip) {
          if ($mode === 'account_list') {
            $client->remove_ip_from_account_list($account_id, $list_id, $ip);
          } else {
            $client->delete_block($ip);
          }

          $wpdb->delete($table, ['ip' => $ip], ['%s']);
        }
      }
    } while (count($rows) === self::DELETE_BATCH_SIZE);
  }

  /**
   * Replace existing schedules using the currently effective interval.
   */
  public static function reschedule(): void {
    self::deactivate();
    self::schedule_events();
  }

  public static function deactivate(): void {
    wp_clear_scheduled_hook(self::HOOK);
    wp_clear_scheduled_hook(self::CLEANUP_HOOK);
  }
}
