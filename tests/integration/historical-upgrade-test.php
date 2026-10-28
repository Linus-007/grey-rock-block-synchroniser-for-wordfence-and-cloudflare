<?php
/** Recreate the 1.3.13 schema/version boundary in a fresh disposable network. */
declare(strict_types=1);
use WPCF\FirewallSync\Config;
use WPCF\FirewallSync\Plugin;
use WPCF\FirewallSync\Services\AuditLog;
use WPCF\FirewallSync\Services\AtomicFence;
use WPCF\FirewallSync\Services\BlockLogger;
use WPCF\FirewallSync\Services\BlockOwnership;
use WPCF\FirewallSync\Services\DestinationLease;
use WPCF\FirewallSync\Services\NetworkManualOwnershipStore;
use WPCF\FirewallSync\Services\ResetWatermarkStore;
if (getenv('GREY_ROCK_TASK13_DISPOSABLE') !== 'YES' || wp_get_environment_type() !== 'local' || !str_starts_with(DB_NAME, 'greyrock_task13_') || !is_multisite()) { throw new RuntimeException('Fresh disposable local network required.'); }
function upgrade_check(bool $ok, string $reason): void {
  if (!$ok) { throw new RuntimeException($reason); }
  echo 'PASS: ' . $reason . "\n";
}
global $wpdb;
foreach (['salus.zone', 'third.zone', 'fourth.zone', 'fifth.zone'] as $host) {
  upgrade_check(!is_wp_error(wp_insert_site(['domain' => $host, 'path' => '/', 'network_id' => get_current_network_id()])), 'Created legacy-network fixture site');
}
$sites = get_sites(['fields' => 'ids', 'number' => 0]);
upgrade_check(count($sites) === 5, 'Upgrade fixture contains five sites');
update_site_option(Config::NETWORK_OPTION, ['schedule_method' => 'manual']);
$lease = new DestinationLease('fixture:legacy-seed'); $lease->acquire();
try {
  foreach ($sites as $site) {
    switch_to_blog((int) $site);
    try {
      upgrade_check(AuditLog::count() === 0, 'Audit is empty before constructing the legacy schema');
      BlockLogger::log('8.8.8.9', 'legacy permanent state');
      BlockLogger::mark_failed('8.8.8.10', 'legacy failed state', '2040-01-01 00:00:00');
      BlockOwnership::add('8.8.8.9', BlockOwnership::OWNER_MANUAL);
      // Direct setup is confined to this disposable schema. Published 1.3.13
      // has these same state tables and no audit table; no old code is edited.
      update_option(ResetWatermarkStore::SITE_OPTION, ['8.8.8.11' => time() - 10]);
      $wpdb->query("DROP TABLE {$wpdb->prefix}wpcf_sync_audit");
      update_option('firewall_sync_version', '1.3.13');
    } finally { restore_current_blog(); }
  }
  NetworkManualOwnershipStore::add('8.8.4.4');
  ResetWatermarkStore::set('9.9.9.9', time() - 10);
} finally { $lease->release(); }
update_site_option('firewall_sync_network_version', '1.3.13');
$pending = new DestinationLease('fixture:upgrade-unknown'); $pending->acquire(); $pending->set_pending('unknown'); $pending->release();
$key = 'firewall_sync_destination_' . hash('sha256', 'fixture:upgrade-unknown');
function upgrade_snapshot(array $sites, string $key): string {
  global $wpdb;
  $state = [];
  foreach ($sites as $site) {
    switch_to_blog((int) $site);
    try {
      $state[$site] = [
        $wpdb->get_results("SELECT * FROM {$wpdb->prefix}wpcf_sync_blocks ORDER BY id", ARRAY_A),
        $wpdb->get_results("SELECT * FROM {$wpdb->prefix}wpcf_sync_ownership ORDER BY ip, owner", ARRAY_A),
        AtomicFence::option(ResetWatermarkStore::SITE_OPTION, false),
        AtomicFence::option(Config::SITE_OPTION, false),
      ];
    } finally { restore_current_blog(); }
  }
  $state['network'] = [AtomicFence::option(Config::NETWORK_OPTION, true), AtomicFence::option(ResetWatermarkStore::NETWORK_OPTION, true), AtomicFence::option(NetworkManualOwnershipStore::OPTION, true)];
  $state['pending'] = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->base_prefix}options WHERE option_name=%s", $key));
  return hash('sha256', serialize($state));
}
$before = upgrade_snapshot($sites, $key);
(new ReflectionMethod(Plugin::class, 'maybe_run_upgrade'))->invoke(null);
upgrade_check(hash_equals($before, upgrade_snapshot($sites, $key)), 'Upgrade preserves permanent/failed state, owners, configuration, reset watermarks and unresolved receipts');
foreach ($sites as $site) {
  switch_to_blog((int) $site);
  try {
    upgrade_check(get_option('firewall_sync_version') === '1.3.14' && AuditLog::count() === 0, 'Each legacy site migrates without inventing audit history');
    upgrade_check($wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $wpdb->prefix . AuditLog::TABLE)) === 'InnoDB', 'Each new audit table uses InnoDB');
  } finally { restore_current_blog(); }
}
upgrade_check(get_site_option('firewall_sync_network_version') === '1.3.14', 'Network migration version advances after all sites');
echo "Historical audit upgrade integration: PASS\n";
