<?php
/** Two-process local-state safety oracle. Disposable WordPress database ONLY. */
declare(strict_types=1);

use WPCF\FirewallSync\Config;
use WPCF\FirewallSync\Services\BlockLogger;
use WPCF\FirewallSync\Services\BlockOwnership;
use WPCF\FirewallSync\Services\DestinationLease;
use WPCF\FirewallSync\Services\NetworkManualOwnershipStore;
use WPCF\FirewallSync\Services\Reconciler;
use WPCF\FirewallSync\Services\ResetWatermarkStore;
use WPCF\FirewallSync\Services\SyncScheduler;

ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');
if (!extension_loaded('mysqli')) {
  fwrite(STDERR, "UNVERIFIED: PHP mysqli is unavailable.\n"); exit(77);
}
if (($argv[1] ?? '') === '--probe') { echo "mysqli available\n"; exit(0); }
if (getenv('GREY_ROCK_TASK13_DISPOSABLE') !== 'YES') {
  fwrite(STDERR, "Refusing non-disposable database test.\n"); exit(2);
}
[$script, $root, $directory, $case, $role, $expiry] = $argv;
$scenario = $argv[6] ?? 'stale';
$receipt = str_starts_with($scenario, 'unknown') ? 'unknown' : 'task13-preserved-receipt';
$scenario = match ($scenario) { 'unknown-rollback' => 'rollback', 'unknown-expiry' => 'expiry', 'unknown-connection-loss' => 'connection-loss', default => $scenario };
function t13_wait(string $path, int $seconds = 30): void {
  $deadline = microtime(true) + $seconds;
  while (!is_file($path)) {
    if (microtime(true) >= $deadline) { throw new RuntimeException('Barrier timeout.'); }
    usleep(10000);
  }
}
function t13_call(string $class, string $method, ...$args) {
  return (new ReflectionMethod($class, $method))->invoke(null, ...$args);
}
function t13_ok(bool $condition): void {
  if (!$condition) {
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
    $line = (int) ($trace[0]['line'] ?? 0);
    throw new RuntimeException(
      'Test assertion failed at worker line ' . $line
    );
  }
}
function t13_snapshot(): string {
  global $wpdb;
  $rows = [];
  foreach ([BlockLogger::TABLE, BlockOwnership::TABLE] as $suffix) {
    $rows[$suffix] = $wpdb->get_results("SELECT * FROM `{$wpdb->prefix}{$suffix}` ORDER BY ip", ARRAY_A);
    t13_ok($wpdb->last_error === '');
  }
  $names = [ResetWatermarkStore::SITE_OPTION, 'firewall_sync_last_result', 'firewall_sync_last_error'];
  foreach ($names as $name) {
    $rows[$name] = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $name));
    t13_ok($wpdb->last_error === '');
  }
  if (is_multisite()) {
    $rows['network'] = $wpdb->get_results($wpdb->prepare(
      "SELECT meta_key, meta_value FROM {$wpdb->sitemeta} WHERE site_id=%d AND meta_key IN (%s,%s) ORDER BY meta_id",
      get_current_network_id(), ResetWatermarkStore::NETWORK_OPTION, NetworkManualOwnershipStore::OPTION
    ), ARRAY_A);
    t13_ok($wpdb->last_error === '');
  }
  return hash('sha256', serialize($rows));
}
$diagnostic_stage = 'bootstrap';
try {
  // Install the HTTP denial before WordPress or any plugin bootstrap runs.
  $GLOBALS['wp_filter']['pre_http_request'][PHP_INT_MIN][] = [
    'function' => static function () { throw new RuntimeException('HTTP prohibited in fencing tests.'); },
    'accepted_args' => 3,
  ];
  define('DISABLE_WP_CRON', true);
  require rtrim($root, '/') . '/wp-load.php';
  $diagnostic_stage = 'environment';
  t13_ok(wp_get_environment_type() === 'local' && str_starts_with(DB_NAME, 'greyrock_task13_'));
  t13_ok(class_exists(DestinationLease::class));
  $diagnostic_stage = 'database';
  global $wpdb;
  $wpdb->hide_errors();
  $wpdb->suppress_errors(true);
  t13_ok(str_contains((string) $wpdb->get_var('SELECT VERSION()'), 'MariaDB'));
  t13_ok($wpdb->query('SET SESSION innodb_lock_wait_timeout=3') !== false);
  foreach (array_unique([$wpdb->base_prefix . 'options', $wpdb->options,
    $wpdb->prefix . BlockOwnership::TABLE, $wpdb->prefix . BlockLogger::TABLE,
    ... (is_multisite() ? [$wpdb->sitemeta] : [])]) as $table) {
    t13_ok($wpdb->get_var($wpdb->prepare(
      'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table
    )) === 'InnoDB');
  }
  $diagnostic_stage = 'scenario';
  $network = str_starts_with($case, 'network-');
  t13_ok(!$network || is_multisite());
  $scope = 'task13:' . hash('sha256', $directory . $case);
  $key = 'firewall_sync_destination_' . hash('sha256', $scope);
  $table = $wpdb->base_prefix . 'options';
  $site = !in_array($case, ['ownership-add-manual', 'site-reset-create', 'network-reset-set',
    'network-reset-clear', 'network-manual-add', 'network-manual-remove', 'reconcile-reset'], true);
  $lease = new DestinationLease($scope);
  if ($role === 'B' && in_array($scenario, ['rollback', 'expiry', 'connection-loss'], true)) { exit(0); }
  if ($role === 'B' && in_array($scenario, ['locked', 'independent'], true)) {
    t13_wait($directory . '/paused');
    if ($scenario === 'independent') {
      $independent = new DestinationLease($scope . ':independent'); $independent->acquire();
      t13_ok(BlockOwnership::add('8.8.4.4', 'manual'));
      $independent->release();
    } else {
      // Exact lease row is locked by the production boundary in process A.
      $old = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM `$table` WHERE option_name=%s", $key));
      $new = maybe_unserialize($old); $new['owner'] = 'forbidden-replacement';
      $started = microtime(true);
      $updated = $wpdb->query($wpdb->prepare("UPDATE `$table` SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s", maybe_serialize($new), $key, $old));
      t13_ok($updated === false && microtime(true) - $started < 5);
    }
    file_put_contents($directory . '/contender-done', '1');
    t13_wait($directory . '/done');
    echo "Production boundary serializes replacement / permits independent scope.\n"; exit(0);
  }
  if ($role === 'B') {
    t13_wait($directory . '/paused');
    foreach ([[$table, $key], ... ($site ? [[$wpdb->options, 'firewall_sync_is_running']] : [])] as [$lt, $lk]) {
      $old = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM `$lt` WHERE option_name=%s", $lk));
      t13_ok($wpdb->last_error === '' && is_string($old));
      $row = maybe_unserialize($old);
      t13_ok(is_array($row) && isset($row['started_at']));
      if ($expiry === 'real') {
        $limit = microtime(true) + 920;
        while ((int) $wpdb->get_var('SELECT UNIX_TIMESTAMP()') < $row['started_at'] + 900) {
          t13_ok($wpdb->last_error === '' && microtime(true) < $limit); usleep(100000);
        }
      } else {
        // Explicit forced-expiry fixture, never reported as real elapsed expiry.
        $row['started_at'] = (int) $wpdb->get_var('SELECT UNIX_TIMESTAMP()') - 901;
        t13_ok($wpdb->query($wpdb->prepare(
          "UPDATE `$lt` SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s",
          maybe_serialize($row), $lk, $old
        )) === 1);
      }
    }
    if ($site) { t13_ok(t13_call(SyncScheduler::class, 'acquire_lock')); }
    $lease->acquire();
    t13_ok($lease->pending() === $receipt);
    file_put_contents($directory . '/replaced', '1');
    t13_wait($directory . '/done');
    t13_ok($lease->pending() === $receipt);
    $lease->release();
    if ($site) { t13_call(SyncScheduler::class, 'release_lock'); }
    echo "Replacement owner and pending receipt preserved.\n"; exit(0);
  }
  // Setup is deliberately outside protected work, in this disposable schema.
  update_option(Config::SITE_OPTION, ['configuration_source' => $network ? 'network' : 'site']);
  $ip = '8.8.8.8'; $now = time();
  $wpdb->query("DELETE FROM {$wpdb->prefix}" . BlockLogger::TABLE);
  $wpdb->query("DELETE FROM {$wpdb->prefix}" . BlockOwnership::TABLE);
  t13_ok($wpdb->last_error === '');
  $seed = new DestinationLease($scope . ':seed'); $seed->acquire();
  if (in_array($case, ['ownership-remove'], true)) { t13_ok(BlockOwnership::add($ip, 'wordfence')); }
  if (in_array($case, ['log-remove', 'cleanup-final-row-delete'], true)) { t13_ok(BlockLogger::log($ip, 'seed')); }
  $seed->release();
  delete_option(ResetWatermarkStore::SITE_OPTION);
  if ($case !== 'site-reset-create') { add_option(ResetWatermarkStore::SITE_OPTION, [$ip => $now - 1], '', false); }
  update_option('firewall_sync_last_result', 'before');
  update_option('firewall_sync_last_error', 'before');
  if (is_multisite()) {
    update_site_option(ResetWatermarkStore::NETWORK_OPTION, [$ip => $now - 1]);
    update_site_option(NetworkManualOwnershipStore::OPTION, $case === 'network-manual-remove' ? [$ip => true] : []);
  }
  if ($site) { t13_ok(t13_call(SyncScheduler::class, 'acquire_lock')); }
  $lease->acquire(); $lease->set_pending($receipt);
  $logtable = $wpdb->prefix . BlockLogger::TABLE;
  $id = (int) $wpdb->get_var("SELECT id FROM `$logtable` WHERE ip='8.8.8.8'");
  $operations = [
    'ownership-add-manual' => fn() => BlockOwnership::add($ip, 'manual'),
    'ownership-add-scheduled' => fn() => BlockOwnership::add($ip, 'wordfence'),
    'ownership-remove' => fn() => BlockOwnership::remove($ip, 'wordfence'),
    'log-permanent-success' => fn() => BlockLogger::log($ip),
    'log-expiring-success' => fn() => BlockLogger::log($ip, 'fixture', '2040-01-01 00:00:00'),
    'log-permanent-failure' => fn() => BlockLogger::mark_failed($ip),
    'log-expiring-transient' => fn() => BlockLogger::mark_failed($ip, 'fixture', '2040-01-01 00:00:00', false),
    'log-remove' => fn() => BlockLogger::remove($ip),
    'site-reset-set-existing' => fn() => ResetWatermarkStore::set($ip, $now),
    'site-reset-create' => fn() => ResetWatermarkStore::set($ip, $now),
    'site-reset-clear' => fn() => ResetWatermarkStore::clear($ip),
    'network-reset-set' => fn() => ResetWatermarkStore::set($ip, $now),
    'network-reset-clear' => fn() => ResetWatermarkStore::clear($ip),
    'network-manual-add' => fn() => NetworkManualOwnershipStore::add($ip),
    'network-manual-remove' => fn() => NetworkManualOwnershipStore::remove($ip),
    'scheduler-status' => fn() => t13_call(SyncScheduler::class, 'persist_last_result', true),
    'cleanup-final-row-delete' => fn() => t13_call(SyncScheduler::class, 'finalize_expired_local_cleanup', $logtable, $id, $ip, $now),
    'reconcile-reset' => fn() => t13_call(Reconciler::class, 'purge_missing_local_records', [$ip], $now),
  ];
  t13_ok(isset($operations[$case]));
  $before = t13_snapshot();
  $rejected = false;
  if (in_array($scenario, ['stale', 'unknown'], true)) {
    // Pause before admission. Raw pinned SQL intentionally bypasses WP query
    // filters; the locked scenario below covers the former check/write window.
    file_put_contents($directory . '/paused', '1');
    t13_wait($directory . '/replaced', $expiry === 'real' ? 940 : 30);
    try { $result = $operations[$case](); $rejected = $result === false; }
    catch (Throwable) { $rejected = true; }
  } else {
    if ($scenario === 'expiry') {
      foreach ([[$table, $key], ... ($site ? [[$wpdb->options, 'firewall_sync_is_running']] : [])] as [$lt, $lk]) {
        $old = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM `$lt` WHERE option_name=%s", $lk));
        $row = maybe_unserialize($old);
        $row['started_at'] = (int) $wpdb->get_var('SELECT UNIX_TIMESTAMP()') - 899;
        t13_ok($wpdb->query($wpdb->prepare("UPDATE `$lt` SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s", maybe_serialize($row), $lk, $old)) === 1);
      }
    }
    $entered = false;
    try {
      \WPCF\FirewallSync\Services\AtomicFence::run(static function () use ($operations, $case, $scenario, $directory, &$entered) {
        $operation_result = $operations[$case]();
        t13_ok($operation_result !== false);
        $entered = true;
        if ($scenario === 'connection-loss') {
          global $wpdb;
          $connection_id = (int) $wpdb->get_var('SELECT CONNECTION_ID()');
          t13_ok($connection_id > 0);
          // Kill only this disposable worker's own session, after its mutation.
          // The pinned adapter cannot reconnect/replay the transaction.
          $wpdb->query('KILL CONNECTION ' . $connection_id);
          throw new RuntimeException('Session terminated.');
        }
        if ($scenario === 'rollback') { throw new RuntimeException('Intentional rollback after mutation.'); }
        if ($scenario === 'expiry') { usleep(2100000); }
        else {
          file_put_contents($directory . '/paused', '1');
          t13_wait($directory . '/contender-done', 4);
        }
        return true;
      });
    } catch (Throwable) { $rejected = true; }
    // A forced-expiry fixture may expire before transaction admission.
    // Rejecting before callback entry is safe, provided the state is unchanged
    // and the pending receipt is preserved by the assertions below.
    if ($scenario !== 'expiry') {
      t13_ok($entered);
    }
    if ($scenario === 'connection-loss') {
      // A fresh observer after the settled/failed transaction; no data replay.
      t13_ok($wpdb->db_connect());
    }
    if (!in_array($scenario, ['rollback', 'expiry', 'connection-loss'], true)) { t13_ok(!$rejected); }
  }
  $unchanged = hash_equals($before, t13_snapshot());
  $pending_row = maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM `$table` WHERE option_name=%s", $key)));
  t13_ok(($pending_row['pending'] ?? null) === $receipt);
  file_put_contents($directory . '/done', '1');
  $lease->release();
  if ($site) { t13_call(SyncScheduler::class, 'release_lock'); }
  $safe = in_array($scenario, ['locked', 'independent'], true) ? !$rejected : ($unchanged && $rejected);
  echo ($safe ? 'SAFE' : 'UNSAFE') . ': ' . $case . "\n";
  exit($safe ? 0 : 1);
} catch (Throwable $error) {
  fwrite(STDERR, "Worker failure stage: {$diagnostic_stage}\n");
  // Only report locations within this disposable test file.
  // Never print exception messages, arguments, SQL, configuration or credentials.
  if ($error->getFile() === __FILE__) {
    $line = $error->getLine();
    if (preg_match('/^Test assertion failed at worker line ([0-9]+)$/', $error->getMessage(), $matches)) {
      $line = (int) $matches[1];
    }
    fwrite(STDERR, "Worker test failure line: {$line}\n");
  }
  fwrite(STDERR, "Database scenario failed or prerequisites were not satisfied.\n"); exit(2);
}
