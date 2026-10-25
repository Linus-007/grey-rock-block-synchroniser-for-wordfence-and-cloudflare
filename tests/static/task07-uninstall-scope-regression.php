<?php

declare(strict_types=1);
define('WP_UNINSTALL_PLUGIN', true);
$network_state = [1 => ['firewall_sync_network_options' => 'offline-private-fixture'], 2 => ['firewall_sync_network_options' => 'offline-private-fixture']];
$destination_state = ['pending' => 'unknown']; $blog_stack = []; $blog = 1;
function is_multisite(): bool { return true; }
function get_sites(array $args): array { return [1, 2]; }
function get_networks(array $args): array { return ($args['number'] ?? 100) === 0 ? [1, 2] : [1]; }
function switch_to_blog(int $id): void { $GLOBALS['blog_stack'][] = $GLOBALS['blog']; $GLOBALS['blog'] = $id; }
function restore_current_blog(): void { $GLOBALS['blog'] = array_pop($GLOBALS['blog_stack']); }
function delete_option(string $key): bool { if (str_starts_with($key, 'firewall_sync_destination_')) { $GLOBALS['destination_state'] = []; } return true; }
function delete_site_option(string $key): bool { return delete_network_option(1, $key); }
function delete_network_option(int $network, string $key): bool { unset($GLOBALS['network_state'][$network][$key]); return true; }
function wp_clear_scheduled_hook(string $hook): void {}
function delete_transient(string $key): bool { return true; }
$wpdb = new class {
  public string $prefix = 'wp_';
  public string $base_prefix = 'wp_';
  public array $deleted_state_keys = [];
  public function prepare(string $query, ...$values): string { return serialize([$query, $values]); }
  public function query(string $query): int {
    if (!str_starts_with($query, 'DROP TABLE')) {
      [$sql, $values] = unserialize($query);
      if ($sql !== 'DELETE FROM wp_options WHERE option_name = %s') { throw new RuntimeException('Unscoped state mutex cleanup.'); }
      $this->deleted_state_keys[] = $values[0];
    }
    return 1;
  }
};
require dirname(__DIR__, 2) . '/src/uninstall.php';
if ($network_state[1] !== [] || $network_state[2] !== []) { throw new RuntimeException('Uninstall retained configuration in a secondary network.'); }
if ($destination_state['pending'] !== 'unknown' || $blog !== 1 || $blog_stack !== []) { throw new RuntimeException('Uninstall cleared unresolved coordination or failed context restoration.'); }
echo "PASS: uninstall removes each network configuration and preserves unresolved destination state\n";
$expected = [];
foreach ([1, 2] as $network) {
  foreach (['firewall_sync_network_reset_watermarks', 'firewall_sync_network_manual_ownership'] as $key) {
    $expected[] = 'firewall_sync_state_' . hash('sha256', $network . ':' . $key);
  }
}
if ($wpdb->deleted_state_keys !== $expected) { throw new RuntimeException('Uninstall did not remove exact network mutex keys.'); }
echo "PASS: uninstall removes only the four exact network state mutexes\n";
