<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
  exit;
}

global $wpdb;

$table = $wpdb->prefix . 'wpcf_sync_blocks';

$wpdb->query("DROP TABLE IF EXISTS {$table}");

delete_option('firewall_sync_options');
delete_option('firewall_sync_last_run');
delete_option('firewall_sync_is_running');