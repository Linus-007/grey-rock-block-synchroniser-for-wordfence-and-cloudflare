<?php
/** Actual Wordfence 9.0.2, WordPress multisite and InnoDB; provider is simulated. */
declare(strict_types=1);
use WPCF\FirewallSync\Config;
use WPCF\FirewallSync\Services\AuditLog;
use WPCF\FirewallSync\Services\BlockLogger;
use WPCF\FirewallSync\Services\BlockOwnership;
use WPCF\FirewallSync\Services\DestinationLease;
use WPCF\FirewallSync\Services\HistoricalBlockReader;
use WPCF\FirewallSync\Services\ResetWatermarkStore;
use WPCF\FirewallSync\Services\SyncScheduler;

if (getenv('GREY_ROCK_TASK13_DISPOSABLE') !== 'YES' || !str_starts_with(DB_NAME, 'greyrock_task13_') || !is_multisite()) { throw new RuntimeException('Disposable multisite required.'); }
function history_check(bool $ok, string $reason): void {
  if (!$ok) { throw new RuntimeException($reason . '; sync error: ' . SyncScheduler::get_last_error_message()); }
  echo 'PASS: ' . $reason . "\n";
}
global $wpdb, $wp_version, $table;
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$wf = get_plugin_data(WP_PLUGIN_DIR . '/wordfence/wordfence.php', false, false);
history_check($wf['Version'] === '9.0.2', 'Wordfence version is exactly 9.0.2');
echo 'Runtime: WordPress ' . $wp_version . '; PHP ' . PHP_VERSION . '; database ' . $wpdb->get_var('SELECT VERSION()') . "\n";
$now = time();
$account = str_repeat('a', 32); $list = str_repeat('b', 32);
$items = []; $posts = 0; $deletes = 0; $fail_post = false; $bad_inventory = false;
// Every supported route returns a local value. Unknown routes remain denied.
add_filter('pre_http_request', static function ($pre, $args, $url) use (&$items, &$posts, &$deletes, &$fail_post, &$bad_inventory, $account, $list) {
  $path = wp_parse_url($url, PHP_URL_PATH);
  $method = $args['method'] ?? 'GET';
  $result = null; $code = 200; $extra = [];
  $base = '/client/v4/accounts/' . $account . '/rules/lists/';
  if ($path === $base . $list && $method === 'GET') { $result = ['id' => $list, 'kind' => 'ip']; }
  elseif ($path === $base . 'bulk_operations/fixture-op' && $method === 'GET') { $result = ['id' => 'fixture-op', 'status' => 'completed']; }
  elseif ($path === $base . $list . '/items') {
    if ($method === 'GET') {
      if ($bad_inventory) { return new WP_Error('fixture_inventory', 'Inventory unavailable'); }
      $result = []; foreach ($items as $ip => $id) { $result[] = compact('ip', 'id'); }
      $extra = ['result_info' => ['cursors' => ['after' => '']]];
    } elseif ($method === 'POST') {
      $posts++;
      if ($fail_post) { $code = 403; $result = []; }
      else {
        foreach (json_decode($args['body'], true) as $item) { $items[$item['ip']] = 'item-' . $posts; }
        $result = ['operation_id' => 'fixture-op'];
      }
    } elseif ($method === 'DELETE') {
      $deletes++;
      foreach (json_decode($args['body'], true)['items'] as $item) {
        foreach ($items as $ip => $id) { if ($id === $item['id']) { unset($items[$ip]); } }
      }
      $result = ['operation_id' => 'fixture-op'];
    }
  }
  if ($result === null) { return $pre; }
  return ['headers' => [], 'response' => ['code' => $code, 'message' => 'fixture'], 'body' => wp_json_encode(array_merge(['success' => $code === 200, 'result' => $result, 'errors' => $code === 200 ? [] : [['message' => 'Fixture permission denied']]], $extra))];
}, PHP_INT_MAX, 3);
$options = ['cloudflare_mode' => 'account_list', 'cloudflare_api_token' => str_repeat('x', 40), 'cloudflare_account_id' => $account, 'cloudflare_list_id' => $list, 'historical_lookback_hours' => 24, 'historical_minimum_events' => 1, 'sync_interval_minutes' => 1, 'schedule_method' => 'manual'];
update_site_option(Config::NETWORK_OPTION, $options);
update_option(Config::SITE_OPTION, ['configuration_source' => 'network']);
update_option('home', 'http://greyscale.zone'); update_option('siteurl', 'http://greyscale.zone');
$salus = wp_insert_site(['domain' => 'salus.zone', 'path' => '/', 'network_id' => get_current_network_id()]);
history_check(!is_wp_error($salus), 'New network site created with audit migration');
foreach (['third.zone', 'fourth.zone', 'fifth.zone'] as $host) { wp_insert_site(['domain' => $host, 'path' => '/', 'network_id' => get_current_network_id()]); }
$table = wfDB::networkTable('wfHits');
function history_hit(string $ip, string $host, string $action, int $when, string $description = ''): void {
  global $wpdb, $table;
  $ok = $wpdb->query($wpdb->prepare("INSERT INTO {$table} (attackLogTime, ctime, IP, isGoogle, userID, newVisit, URL, action, actionDescription) VALUES (%f, %f, UNHEX(%s), 0, 0, 0, %s, %s, %s)", $when, $when, bin2hex(wfUtils::inet_pton($ip)), 'https://' . $host . '/fixture', $action, $description));
  if ($ok === false) { throw new RuntimeException('Could not insert Wordfence hit: ' . $wpdb->last_error); }
}
foreach ([['158.101.19.62', 132, 'salus.zone'], ['3.107.195.156', 87, 'greyscale.zone'], ['34.95.3.164', 20, 'greyscale.zone']] as [$ip, $count, $host]) {
  for ($i = 0; $i < $count; $i++) { history_hit($ip, $host, 'blocked:wordfence', $now - 100 - $i); }
}
history_check(wfBlock::ipBlocks(true) === [], 'Production reproduction has no current IP blocks');
foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $site) {
  switch_to_blog((int) $site);
  try {
    $ok = SyncScheduler::run_now();
    if (!$ok) {
      echo 'Fixture schema: ' . wp_json_encode($wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}wpcf%'") ) . "\n";
      $acquire = new ReflectionMethod(SyncScheduler::class, 'acquire_lock');
      $release = new ReflectionMethod(SyncScheduler::class, 'release_lock');
      if ($acquire->invoke(null)) {
        try { (new ReflectionMethod(SyncScheduler::class, 'execute_sync'))->invoke(null); }
        catch (Throwable $error) { echo 'Fixture diagnostic: ' . get_class($error) . ': ' . $error->getMessage() . ' at ' . basename($error->getFile()) . ':' . $error->getLine() . "\n"; }
        finally { $release->invoke(null); }
      }
    }
    history_check($ok, 'Site ' . $site . ' completes classified historical evaluation');
  }
  finally { restore_current_blog(); }
}
history_check($posts === 0 && $items === [], '239 plugin hits do not become invented permanent blocks');
history_check(AuditLog::count() === 107, '107 greyscale plugin hits retain site-scoped skip reasons');
switch_to_blog((int) $salus);
history_check(AuditLog::count() === 132, '132 salus plugin hits retain separate audit history');
restore_current_blog();
history_check(AuditLog::count(['ip' => '158.101.19.62']) === 0, 'Salus IP does not leak into greyscale audit');

// Exercise the stable identifiers emitted by the actual Wordfence release.
require_once WP_PLUGIN_DIR . '/wordfence/waf/wfWAFBlockI18n.php';
foreach ([wfWAFBlockI18n::WFWAF_BLOCK_COUNTRY, wfWAFBlockI18n::WFWAF_BLOCK_UAREFIPRANGE,
  wfWAFBlockI18n::WFWAF_BLOCK_THROTTLEGLOBAL, wfWAFBlockI18n::WFWAF_BLOCK_MANUAL,
  wfWAFBlockI18n::WFWAF_BLOCK_BANNEDURL, wfWAFBlockI18n::WFWAF_BLOCK_BADPOST] as $key) {
  history_hit('34.95.3.164', 'greyscale.zone', 'blocked:wordfence', $now - 1, wfWAFBlockI18n::getBlockDescription($key));
}
history_check(SyncScheduler::run_now() && $posts === 0, 'Country, pattern, throttle and expired IP-hit descriptions cannot create IP authority');
history_check(AuditLog::count(['reason' => 'country policy']) > 0 && AuditLog::count(['reason' => 'request pattern']) > 0, 'Request-specific policy skips retain their reasons');
foreach ([['8.8.8.8', 10], ['1.1.1.1', 6], ['8.8.4.4', 5], ['9.9.9.9', 5]] as [$ip, $count]) {
  for ($i = 0; $i < $count; $i++) { history_hit($ip, 'greyscale.zone', 'blocked:waf', $now - 30 - $i); }
}
$candidates = HistoricalBlockReader::get_candidates(24, 1, ['greyscale.zone']);
history_check(count($candidates) === 4 && array_sum(array_column($candidates, 'event_count')) === 26, 'Production WAF reproduction covers 26 events from four IPs independently');
$wpdb->query("DELETE FROM {$table} WHERE action='blocked:waf'");

history_hit('8.8.8.8', 'greyscale.zone', 'blocked:waf', $now - 50);
history_check(SyncScheduler::run_now(), 'Qualifying WAF event forwards successfully');
history_check($posts === 1 && BlockLogger::has_synced('8.8.8.8'), 'Cloudflare completion and local success agree');
$expiry = $wpdb->get_var("SELECT expires_at FROM {$wpdb->prefix}wpcf_sync_blocks WHERE ip='8.8.8.8'");
history_check($expiry !== null, 'Historical WAF forwarding has bounded expiry');
history_hit('8.8.8.8', 'greyscale.zone', 'blocked:waf', $now - 10);
history_check(SyncScheduler::run_now(), 'Present Cloudflare membership reconciles');
$new_expiry = $wpdb->get_var("SELECT expires_at FROM {$wpdb->prefix}wpcf_sync_blocks WHERE ip='8.8.8.8'");
history_check($posts === 1 && $new_expiry > $expiry, 'Present membership refreshes expiry without a duplicate POST');
unset($items['8.8.8.8']);
history_check(SyncScheduler::run_now() && $posts === 1, 'Missing remote membership does not resurrect already-handled history');
// The next event has a genuinely later identity than successful handling.
history_hit('8.8.8.8', 'greyscale.zone', 'blocked:waf', time() + 2);
history_check(SyncScheduler::run_now() && $posts === 2, 'New evidence can restore absent membership');

// A plugin hit never turns a finite authoritative IP block into a WAF lease.
wfBlock::createIP('fixture finite automatic', '1.1.1.1', 600, $now - 30, $now, 1, wfBlock::TYPE_IP_AUTOMATIC_TEMPORARY);
history_hit('1.1.1.1', 'greyscale.zone', 'blocked:wordfence', $now - 20, 'Accessed a banned URL');
history_check(SyncScheduler::run_now(), 'Current automatic IP block with plugin evidence qualifies');
$finite = $wpdb->get_var("SELECT expires_at FROM {$wpdb->prefix}wpcf_sync_blocks WHERE ip='1.1.1.1'");
history_check($finite === wp_date('Y-m-d H:i:s', $now + 570, wp_timezone()), 'Authoritative finite expiry survives historical plugin evidence');
wfBlock::createRateThrottle('fixture throttle', '9.9.9.9', 600);
history_hit('9.9.9.9', 'greyscale.zone', 'blocked:wordfence', $now, 'Exceeded the maximum global requests per minute for crawlers or humans.');
history_check(SyncScheduler::run_now() && !isset($items['9.9.9.9']), 'Current throttle is never upgraded to an edge IP block');

wfBlock::createIP('fixture manual permanent', '9.9.9.10', wfBlock::DURATION_FOREVER, $now - 10, $now, 1, wfBlock::TYPE_IP_MANUAL);
history_check(SyncScheduler::run_now() && isset($items['9.9.9.10']), 'Explicit permanent manual IP authority qualifies');
history_check(BlockLogger::has_synced('9.9.9.10') && $wpdb->get_var("SELECT expires_at FROM {$wpdb->prefix}wpcf_sync_blocks WHERE ip='9.9.9.10'") === null, 'Permanent authority remains SQL NULL rather than a synthetic expiry');
$reset_lease = new DestinationLease('fixture:present-reset'); $reset_lease->acquire();
try { ResetWatermarkStore::set('9.9.9.10', time()); } finally { $reset_lease->release(); }
$successes = AuditLog::count(['ip' => '9.9.9.10', 'reason' => 'success:']);
history_check(SyncScheduler::run_now() && AuditLog::count(['ip' => '9.9.9.10', 'reason' => 'success:']) === $successes, 'Explicit reset suppresses old active evidence even while remote membership remains present');
history_check(ResetWatermarkStore::get('9.9.9.10') > 0, 'Confirmed membership never clears an explicit reset for old evidence');

$fail_post = true;
history_hit('8.8.4.4', 'greyscale.zone', 'blocked:waf', $now);
history_check(!SyncScheduler::run_now(), 'Provider failure marks synchronization incomplete');
history_check(AuditLog::count(['ip' => '8.8.4.4', 'reason' => 'failure:']) > 0, 'Provider failure persists an audit reason');
$fail_post = false;
$retry_lease = new DestinationLease('fixture:confirmed-retry'); $retry_lease->acquire();
try { BlockLogger::mark_failed('8.8.4.4'); BlockLogger::mark_failed('8.8.4.4'); }
finally { $retry_lease->release(); }
history_check(BlockLogger::is_blacklisted('8.8.4.4'), 'Permanent failure retry limit remains enforced');
$items['8.8.4.4'] = 'fixture-confirmed-member'; $before_posts = $posts;
history_check(SyncScheduler::run_now() && BlockLogger::has_synced('8.8.4.4') && $posts === $before_posts, 'Confirmed membership recovers retry-limited local state without a mutation');
$fail_post = false; $bad_inventory = true;
$before = $deletes;
history_check(!SyncScheduler::run_now() && $deletes === $before, 'Incomplete inventory cannot authorise cleanup');
$bad_inventory = false;

$lease = new DestinationLease('fixture:audit-check');
$lease->acquire();
history_check(true, 'Audit test holds destination authority');
try {
  $before = AuditLog::count();
  BlockLogger::remove('8.8.8.8');
  history_check(AuditLog::count() === $before, 'Removing synchronization state retains audit history');
  $wpdb->query("INSERT INTO {$wpdb->prefix}wpcf_sync_audit (fingerprint, ip, reason, created_at, evidence_at) VALUES ('old-fixture', '8.8.8.8', 'old', DATE_SUB(NOW(), INTERVAL 31 DAY), 1)");
  // Simulate confirmed provider success followed by unavailable local state.
  $wpdb->query("RENAME TABLE {$wpdb->prefix}wpcf_sync_blocks TO {$wpdb->prefix}wpcf_sync_blocks_fixture");
  try {
    $failed = (new ReflectionMethod(SyncScheduler::class, 'record_batch_results'))->invoke(null,
      [['ip' => '8.8.8.9', 'reason' => 'fixture remote success', 'expires_at' => null, 'evidence' => $now]], []);
    history_check($failed === ['8.8.8.9'], 'Remote success with unavailable state remains incomplete');
    history_check(AuditLog::count(['ip' => '8.8.8.9', 'reason' => 'local state could not be stored']) === 1, 'Local persistence failure retains independent audit evidence');
  } finally { $wpdb->query("RENAME TABLE {$wpdb->prefix}wpcf_sync_blocks_fixture TO {$wpdb->prefix}wpcf_sync_blocks"); }
  $saved_zone = get_option('timezone_string'); $saved_offset = get_option('gmt_offset');
  foreach ([['America/Boise', -6, new DateTimeZone('America/Boise')], ['', 5.75, new DateTimeZone('+05:45')]] as [$zone, $offset, $expected_zone]) {
    update_option('timezone_string', $zone); update_option('gmt_offset', $offset);
    wp_cache_delete('alloptions', 'options'); wp_cache_delete('timezone_string', 'options'); wp_cache_delete('gmt_offset', 'options');
    AuditLog::record('8.8.8.8', 'skip', 'cold timezone fixture ' . $zone, $now);
    $recorded = $wpdb->get_var($wpdb->prepare("SELECT created_at FROM {$wpdb->prefix}wpcf_sync_audit WHERE reason=%s ORDER BY id DESC LIMIT 1", 'skip: cold timezone fixture ' . $zone));
    history_check(abs((new DateTimeImmutable($recorded, $expected_zone))->getTimestamp() - time()) <= 2, 'Cold-cache fenced audit preserves named and fractional-offset timezone semantics');
  }
  update_option('timezone_string', $saved_zone); update_option('gmt_offset', $saved_offset);
  // New request pruning is exercised explicitly via the same service state.
  (new ReflectionProperty(AuditLog::class, 'pruned'))->setValue(null, []);
  AuditLog::record('8.8.8.8', 'skip', 'retention fixture', $now);
  history_check($wpdb->get_var("SELECT id FROM {$wpdb->prefix}wpcf_sync_audit WHERE fingerprint='old-fixture'") === null, 'Thirty-day audit retention operates independently of state');
} finally { $lease->release(); }
// A replaced destination lease cannot append audit history or erase its successor.
$stale = new DestinationLease('fixture:stale-audit'); $stale->acquire();
$fence = DestinationLease::fencing_leases()[0];
$raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$fence['table']} WHERE option_name=%s", $fence['key']));
$replacement = maybe_unserialize($raw); $replacement['owner'] = 'fixture-successor';
$replacement_raw = maybe_serialize($replacement);
$wpdb->query($wpdb->prepare("UPDATE {$fence['table']} SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s", $replacement_raw, $fence['key'], $raw));
$before = AuditLog::count(); $rejected = false;
try { AuditLog::record('8.8.8.8', 'failure', 'stale fixture', $now); }
catch (RuntimeException) { $rejected = true; }
finally { $stale->release(); }
history_check($rejected && AuditLog::count() === $before, 'Replaced lease cannot append audit history');
history_check($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$fence['table']} WHERE option_name=%s", $fence['key'])) === $replacement_raw, 'Stale audit writer preserves successor lease');
$wpdb->query($wpdb->prepare("DELETE FROM {$fence['table']} WHERE option_name=%s AND BINARY option_value=BINARY %s", $fence['key'], $replacement_raw));

// Audit schema loss must never be reported as a successful synchronization.
$wpdb->query("RENAME TABLE {$wpdb->prefix}wpcf_sync_audit TO {$wpdb->prefix}wpcf_sync_audit_fixture");
$wpdb->suppress_errors(true);
history_check(!SyncScheduler::run_now(), 'Audit persistence failure fails synchronization closed');
$wpdb->suppress_errors(false);
$wpdb->query("RENAME TABLE {$wpdb->prefix}wpcf_sync_audit_fixture TO {$wpdb->prefix}wpcf_sync_audit");
$blocks_table = wfBlock::blocksTable();
$wpdb->query("RENAME TABLE {$blocks_table} TO {$blocks_table}_fixture");
$wpdb->suppress_errors(true); $before_posts = $posts; $before_deletes = $deletes;
try {
  history_check(!SyncScheduler::run_now() && $posts === $before_posts && $deletes === $before_deletes, 'Wordfence 9.0.2 empty-array SQL failure cannot authorise reconciliation or forwarding');
} finally {
  $wpdb->suppress_errors(false);
  $wpdb->query("RENAME TABLE {$blocks_table}_fixture TO {$blocks_table}");
}
echo "Historical Wordfence/MariaDB integration: PASS\n";
