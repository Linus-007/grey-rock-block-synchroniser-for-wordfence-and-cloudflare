<?php
/** Exercise actual multisite audit HTML and pagination, including old duplicate rows. */
declare(strict_types=1);
use WPCF\FirewallSync\Admin\Settings;
if (getenv('GREY_ROCK_TASK13_DISPOSABLE') !== 'YES' || !str_starts_with(DB_NAME, 'greyrock_task13_') || !is_multisite()) { throw new RuntimeException('Disposable multisite required.'); }
define('WP_NETWORK_ADMIN', true);
wp_set_current_user(1);
grant_super_admin(1);
$_SERVER['REQUEST_URI'] = '/wp-admin/network/admin.php?page=firewall-sync-network-log-audit';
function ui_page(int $page, string $ip = '8.8.4.4', string $orderby = 'created_at'): string {
  $_GET = ['page' => 'firewall-sync-network-log-audit', 'site' => '1', 'ip' => $ip, 'paged' => (string) $page, 'orderby' => $orderby, 'order' => 'DESC'];
  ob_start();
  try { Settings::render_network_log(true); return (string) ob_get_contents(); }
  finally { ob_end_clean(); }
}
global $wpdb;
$table = $wpdb->base_prefix . 'wpcf_sync_audit';
if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE ip='9.9.9.8'") === 0) {
  $values = [];
  foreach (range(1, 601) as $n) {
    $values[] = $wpdb->prepare('(%s,%s,%s,%s)', hash('sha256', random_bytes(32)), '9.9.9.8', 'skip: pagination fixture', gmdate('Y-m-d H:i:s', 1577836800 + $n));
  }
  $wpdb->query("INSERT INTO {$table} (fingerprint,ip,reason,created_at) VALUES " . implode(',', $values));
}
if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE ip='8.8.4.5'") === 0) {
  $values = [];
  foreach (range(1, 40) as $n) {
    $values[] = $wpdb->prepare('(%s,%s,%s,%s)', hash('sha256', random_bytes(32)), '8.8.4.5', 'fixture natural reason ' . $n, gmdate('Y-m-d H:i:s', 1609459200 + $n));
  }
  $wpdb->query("INSERT INTO {$table} (fingerprint,ip,reason,created_at) VALUES " . implode(',', $values));
}
$first = ui_page(1); $second = ui_page(2); $last = ui_page(3);
foreach ([[$first, 20], [$second, 20], [$last, 5]] as [$html, $count]) {
  if (substr_count($html, '<code>8.8.4.4</code>') !== $count || !str_contains($html, 'Events') || !str_contains($html, 'failure: unresolved')) {
    throw new RuntimeException('Network audit group pagination or event display failed.');
  }
}
if ($first === $second || !str_contains($first, 'paged=2')) { throw new RuntimeException('Network page links or stable sorting failed.'); }
$deep_page = ui_page(31, '9.9.9.8');
if (substr_count($deep_page, '<code>9.9.9.8</code>') !== 1) { throw new RuntimeException('Network pagination lost records beyond 500.'); }
$by_reason = ui_page(1, '8.8.4.5', 'reason');
foreach (range(21, 40) as $n) {
  if (!str_contains($by_reason, 'fixture natural reason ' . $n)) { throw new RuntimeException('Natural network reason sorting lost a group at pagination.'); }
}
$by_count = ui_page(1, '', 'event_count');
if (substr_count($by_count, '<code>8.8.4.4</code>') !== 20) { throw new RuntimeException('Network event count sorting failed.'); }
echo "PASS: Network audit site/IP filters, event count column, distinct outcomes, sorting and pagination beyond 500 records\n";
