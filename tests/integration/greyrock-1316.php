<?php
/** Real WordPress/database regression checks; Cloudflare is mocked locally. */
declare(strict_types=1);
use WPCF\FirewallSync\Config;
use WPCF\FirewallSync\Services\AtomicFence;
use WPCF\FirewallSync\Services\AuditLog;
use WPCF\FirewallSync\Services\BlockLogger;
use WPCF\FirewallSync\Services\DestinationLease;
use WPCF\FirewallSync\Services\SyncScheduler;
if (getenv('GREY_ROCK_TASK13_DISPOSABLE') !== 'YES' || !str_starts_with(DB_NAME, 'greyrock_task13_')) { throw new RuntimeException('Disposable database required.'); }
function gr_check(bool $ok, string $message): void {
  if (!$ok) { throw new RuntimeException($message . '; ' . SyncScheduler::get_last_error_message()); }
  echo "PASS: {$message}\n";
}
global $wpdb, $wp_version;
echo 'Runtime: WP ' . $wp_version . '; PHP ' . PHP_VERSION . '; DB ' . $wpdb->get_var('SELECT VERSION()') . '; multisite=' . (int) is_multisite() . "\n";
if (is_multisite()) {
  $fixture_site = wp_insert_site(['domain' => 'matrix-multi.test', 'path' => '/']);
  gr_check(!is_wp_error($fixture_site), 'Fresh multisite fixture created');
  switch_to_blog((int) $fixture_site);
}
AuditLog::create_table();
$table = $wpdb->prefix . AuditLog::TABLE;
// Fixtures intentionally bypass writer de-duplication to reproduce old rows.
foreach (range(1, 45) as $n) {
  foreach (['success: established', 'success: established', 'failure: unresolved'] as $reason) {
    $wpdb->insert($table, ['fingerprint' => hash('sha256', random_bytes(32)), 'ip' => '8.8.4.4', 'reason' => $reason, 'created_at' => sprintf('2020-01-01 01:00:%02d', $n)]);
  }
}
$logs = AuditLog::get_logs(20, 0, ['ip' => '8.8.4.4']);
gr_check(AuditLog::count(['ip' => '8.8.4.4']) === 45 && count($logs) === 20, 'Audit counts groups before pagination');
gr_check($logs[0]['event_count'] === 3 && str_contains($logs[0]['reason'], 'failure: unresolved') && str_contains($logs[0]['reason'], 'success: established'), 'Duplicate count and distinct outcomes retained');
$page = AuditLog::get_logs(20, 20, ['ip' => '8.8.4.4']);
gr_check(count($page) === 20 && $page[0]['created_at'] !== $logs[0]['created_at'], 'Audit pages have deterministic distinct groups');
gr_check(AuditLog::count(['ip' => '8.8.4.4', 'reason' => 'unresolved', 'date_from' => '2020-01-01', 'date_to' => '2020-01-01']) === 45, 'Audit IP, reason and date filters preserved');
$bulk = [];
foreach (range(1, 601) as $n) {
  $bulk[] = $wpdb->prepare('(%s,%s,%s,%s)', hash('sha256', random_bytes(32)), '9.9.9.8', 'skip: pagination fixture', gmdate('Y-m-d H:i:s', 1577836800 + $n));
}
$wpdb->query("INSERT INTO {$table} (fingerprint,ip,reason,created_at) VALUES " . implode(',', $bulk));
gr_check(AuditLog::count(['ip' => '9.9.9.8']) === 601 && count(AuditLog::get_logs(20, 600, ['ip' => '9.9.9.8'])) === 1, 'Audit pagination preserves groups beyond 500');
gr_check(AuditLog::get_logs(1, 0, ['ip' => '8.8.4.4', 'reason' => 'unresolved'])[0]['event_count'] === 3, 'Reason filtering retains the whole group event count');
$lease = new DestinationLease('1316-audit'); $lease->acquire();
try {
  AuditLog::record('9.9.9.9', 'detection', 'fixture', 42, 1);
  AuditLog::record('9.9.9.9', 'detection', 'fixture', 42, 1);
  gr_check((int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE ip='8.8.4.4'") === 135, 'Recording never prunes historical evidence');
  gr_check((int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE ip='9.9.9.9'") === 1, 'Repeated source evidence remains idempotent');
  $changed = false;
  BlockLogger::log('9.9.9.9', 'fixture', null, $changed);
  gr_check($changed, 'Initial state is a meaningful transition');
  BlockLogger::log('9.9.9.9', 'fixture', null, $changed);
  gr_check(!$changed, 'Unchanged confirmation is not a transition');
  BlockLogger::mark_failed('9.9.9.9', 'fixture');
  BlockLogger::log('9.9.9.9', 'fixture', null, $changed);
  gr_check($changed, 'Recovery from failure remains a transition');
  $before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
  try {
    AtomicFence::run(static function (): bool {
      AuditLog::record('1.0.0.1', 'failure', 'rollback fixture');
      return false;
    }, [$table]);
    throw new LogicException('Rollback did not reject');
  } catch (RuntimeException) {}
  gr_check((int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}") === $before, 'Nested audit work rolls back with failed outer transaction');
  $wpdb->query('START TRANSACTION');
  try {
    $rejected = false;
    try { AtomicFence::run(static fn () => true, [$table]); } catch (RuntimeException) { $rejected = true; }
    gr_check($rejected, 'External transaction admission fails closed');
  } finally { $wpdb->query('ROLLBACK'); }
  $unsafe = $wpdb->prefix . 'gr_unsafe';
  $wpdb->query("CREATE TABLE {$unsafe} (id INT PRIMARY KEY) ENGINE=MyISAM");
  $rejected = false;
  try { AtomicFence::run(static fn () => true, [$unsafe]); } catch (RuntimeException) { $rejected = true; }
  gr_check($rejected, 'Nontransactional table fails closed');
  $wpdb->query("DROP TABLE {$unsafe}");
} finally { $lease->release(); }
if (is_multisite()) {
  $site = wp_insert_site(['domain' => 'audit-other.test', 'path' => '/']);
  gr_check(!is_wp_error($site), 'Multisite fixture created');
  switch_to_blog((int) $site);
  try {
    AuditLog::create_table();
    gr_check(AuditLog::count(['ip' => '8.8.4.4']) === 0, 'Site audit isolation preserved');
  } finally { restore_current_blog(); }
}
// Actual Wordfence active blocks with deterministic provider responses.
$account = str_repeat('a', 32); $list = str_repeat('b', 32);
$options = ['cloudflare_mode' => 'account_list', 'cloudflare_api_token' => str_repeat('x', 40), 'cloudflare_account_id' => $account, 'cloudflare_list_id' => $list, 'schedule_method' => 'manual'];
update_option(Config::SITE_OPTION, $options);
$items = []; $writes = 0;
add_filter('pre_http_request', static function ($pre, $args, $url) use (&$items, &$writes, $account, $list) {
  $path = wp_parse_url($url, PHP_URL_PATH); $method = $args['method'] ?? 'GET';
  $base = '/client/v4/accounts/' . $account . '/rules/lists/';
  if ($path === $base . $list) { $result = ['id' => $list, 'kind' => 'ip']; }
  elseif ($path === $base . 'bulk_operations/fixture') { $result = ['id' => 'fixture', 'status' => 'completed']; }
  elseif ($path === $base . $list . '/items' && $method === 'GET') {
    $result = []; foreach ($items as $ip => $id) { $result[] = compact('ip', 'id'); }
  } elseif ($path === $base . $list . '/items' && $method === 'POST') {
    $writes++; foreach (json_decode($args['body'], true) as $item) { $items[$item['ip']] = 'fixture-item'; }
    $result = ['operation_id' => 'fixture'];
  } else { return new WP_Error('fixture', 'Unexpected outbound request denied'); }
  return ['headers' => [], 'response' => ['code' => 200, 'message' => 'fixture'], 'body' => wp_json_encode(['success' => true, 'result' => $result, 'result_info' => ['cursors' => ['after' => '']]])];
}, PHP_INT_MAX, 3);
wfBlock::createIP('1316 fixture', '8.8.8.8', wfBlock::DURATION_FOREVER);
gr_check(SyncScheduler::run_now(), 'Synchronization completes with Cloudflare mock');
$successes = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE ip='8.8.8.8' AND reason LIKE 'success:%'");
gr_check($successes === 1 && $writes === 1, 'Initial synchronization records one success');
for ($i = 0; $i < 3; $i++) { gr_check(SyncScheduler::run_now(), 'Repeated synchronization completes'); }
gr_check((int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE ip='8.8.8.8' AND reason LIKE 'success:%'") === $successes && $writes === 1, 'Unchanged repeated sync produces no duplicate successes or provider writes');
echo "REGRESSION PASS\n";
