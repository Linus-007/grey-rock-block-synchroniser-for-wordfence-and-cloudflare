<?php

declare(strict_types=1);

namespace WPCF\FirewallSync;

if (!defined('WP_UNINSTALL_PLUGIN')) {
  exit;
}

/*
 * Plugin uninstall intentionally removes the plugin-owned table.
 * The table name uses the trusted WordPress prefix plus a fixed suffix.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
 */

/**
 * Remove this plugin's site-specific table and options from the current site.
 */
function grey_rock_block_synchroniser_for_wordfence_and_cloudflare_uninstall_site(): void {
  global $wpdb;

  $table = $wpdb->prefix . 'wpcf_sync_blocks';
  $audit_table = $wpdb->prefix . 'wpcf_sync_audit';
  $wpdb->query("DROP TABLE IF EXISTS `{$audit_table}`");
  $ownership_table = $wpdb->prefix . 'wpcf_sync_ownership';

  $wpdb->query(
    "DROP TABLE IF EXISTS `{$table}`"
  );

  $wpdb->query(
    "DROP TABLE IF EXISTS `{$ownership_table}`"
  );

  delete_option('firewall_sync_options');
  delete_option('firewall_sync_last_run');
  delete_option('firewall_sync_last_attempt_timestamp');
  delete_option('firewall_sync_last_result');
  delete_option('firewall_sync_last_error');
  delete_option('firewall_sync_is_running');
  delete_option('firewall_sync_version');
  delete_option('firewall_sync_ddns_state');
  delete_option('firewall_sync_reset_watermarks');

  wp_clear_scheduled_hook('firewall_sync_cron_event');
  wp_clear_scheduled_hook('firewall_sync_cleanup_event');

  delete_transient('firewall_sync_reconcile_result');
}

if (is_multisite()) {
  foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $blog_id) {
    switch_to_blog((int) $blog_id);

    try {
      grey_rock_block_synchroniser_for_wordfence_and_cloudflare_uninstall_site();
    } finally {
      restore_current_blog();
    }
  }

  // Physical plugin removal affects every network, unlike a network-scoped
  // administration action. Do not leave another network's credentials behind.
  foreach (get_networks(['fields' => 'ids', 'number' => 0]) as $network_id) {
    foreach ([
      'firewall_sync_network_options',
      'firewall_sync_network_version',
      'firewall_sync_network_ddns_state',
      'firewall_sync_network_reset_watermarks',
      'firewall_sync_network_manual_ownership',
    ] as $option) {
      delete_network_option((int) $network_id, $option);
    }
    // State mutexes contain no receipts; remove only this network's exact keys.
    // As with table removal, uninstall requires drained synchronization workers.
    foreach (['firewall_sync_network_reset_watermarks', 'firewall_sync_network_manual_ownership'] as $state_key) {
      $sentinel = 'firewall_sync_state_' . hash('sha256', (int) $network_id . ':' . $state_key);
      $table = $wpdb->base_prefix . 'options';
      $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE option_name = %s", $sentinel));
    }

  }
} else {
  grey_rock_block_synchroniser_for_wordfence_and_cloudflare_uninstall_site();
}
