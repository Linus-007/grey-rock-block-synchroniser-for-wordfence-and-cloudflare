<?php

declare(strict_types=1);
require __DIR__ . '/../fixtures/task08-local-fencing-bootstrap.php';
require __DIR__ . '/../fixtures/task14-atomic-connection.php';
require __DIR__ . '/../../src/includes/Services/AtomicFence.php';
define('ARRAY_A', 'ARRAY_A');
define('OBJECT', 'OBJECT');
function add_filter(...$args): void {}
function remove_filter(...$args): void {}
$GLOBALS['model_committed_mutations'] = 0;
use WPCF\FirewallSync\Services\DestinationLease;
use WPCF\FirewallSync\Services\SyncScheduler;
use WPCF\FirewallSync\Services\BlockOwnership;
use WPCF\FirewallSync\Services\BlockLogger;
use WPCF\FirewallSync\Services\ResetWatermarkStore;
use WPCF\FirewallSync\Services\NetworkManualOwnershipStore;
use WPCF\FirewallSync\Services\Reconciler;
function fence_check(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function invoke_private(string $class, string $method, ...$args) { return (new ReflectionMethod($class, $method))->invoke(null, ...$args); }
function site_state(?array $restore = null): array {
  $state = [];
  foreach (['lockOwnerToken', 'lockTable', 'leaseLost'] as $field) {
    $property = new ReflectionProperty(SyncScheduler::class, $field); $state[$field] = $property->getValue();
    if ($restore !== null) { $property->setValue(null, $restore[$field]); }
  }
  return $state;
}
$cases = [
  'ownership-add-manual' => ['data-query', false, false, fn() => BlockOwnership::add('8.8.8.8', 'manual')],
  'ownership-add-scheduled' => ['data-query', false, true, fn() => BlockOwnership::add('8.8.8.8', 'wordfence')],
  'ownership-remove' => ['data-delete', false, true, fn() => BlockOwnership::remove('8.8.8.8', 'wordfence')],
  'log-permanent-success' => ['data-query', false, true, fn() => BlockLogger::log('8.8.8.8', 'fixture', null)],
  'log-expiring-success' => ['data-query', false, true, fn() => BlockLogger::log('8.8.8.8', 'fixture', '2040-01-01 00:00:00')],
  'log-permanent-failure' => ['data-query', false, true, fn() => BlockLogger::mark_failed('8.8.8.8', 'fixture', null)],
  'log-expiring-transient' => ['data-query', false, true, fn() => BlockLogger::mark_failed('8.8.8.8', 'fixture', '2040-01-01 00:00:00', false)],
  'log-remove' => ['data-delete', false, true, fn() => BlockLogger::remove('8.8.8.8')],
  'site-reset-set-existing' => ['option', false, true, fn() => ResetWatermarkStore::set('8.8.8.8', $GLOBALS['fence_clock'])],
  'site-reset-create' => ['option', false, false, fn() => ResetWatermarkStore::set('8.8.8.8', $GLOBALS['fence_clock'])],
  'site-reset-clear' => ['option', false, true, fn() => ResetWatermarkStore::clear('8.8.8.8')],
  'network-reset-set' => ['network-option', true, false, fn() => ResetWatermarkStore::set('8.8.8.8', $GLOBALS['fence_clock'])],
  'network-reset-clear' => ['network-option', true, false, fn() => ResetWatermarkStore::clear('8.8.8.8')],
  'network-manual-add' => ['network-option', true, false, fn() => NetworkManualOwnershipStore::add('8.8.8.8')],
  'network-manual-remove' => ['network-option', true, false, fn() => NetworkManualOwnershipStore::remove('8.8.8.8')],
  'scheduler-status' => ['option', false, true, fn() => invoke_private(SyncScheduler::class, 'persist_last_result', true)],
  'cleanup-final-row-delete' => ['data-delete', false, true, fn() => invoke_private(SyncScheduler::class, 'finalize_expired_local_cleanup', 'wp_2_wpcf_sync_blocks', 1, '8.8.8.8', $GLOBALS['fence_clock'])],
  'reconcile-reset' => ['option', false, false, fn() => invoke_private(Reconciler::class, 'purge_missing_local_records', ['8.8.8.8'], $GLOBALS['fence_clock'])],
];
$safe_cases = 0;
foreach ($cases as $name => [$kind, $network, $site, $operation]) {
  $wpdb = new FencingDatabase(); $fence_clock = 2200000000; $fence_network = $network;
  $network_values = [ResetWatermarkStore::NETWORK_OPTION => ['8.8.8.8' => $fence_clock - 1], NetworkManualOwnershipStore::OPTION => []];
  if ($name === 'network-manual-remove') { $network_values[NetworkManualOwnershipStore::OPTION] = ['8.8.8.8' => true]; }
  if ($name !== 'site-reset-create') { $wpdb->rows[$wpdb->options][ResetWatermarkStore::SITE_OPTION] = serialize(['8.8.8.8' => $fence_clock - 1]); }
  if ($site) { fence_check(invoke_private(SyncScheduler::class, 'acquire_lock'), 'Worker A could not acquire site lease.'); }
  $a = new DestinationLease('zone:' . str_repeat('c', 32)); $a->acquire(); $a->assert_live(); $a->set_pending('preserved-operation');
  $b = null; $b_site = null; $a_site = null; $paused = false; $stale_writes = 0; $pause_kind = 'atomic-before-lock';
  $pause_write = function () use ($site, &$b, &$a_site, &$b_site): void {
    $GLOBALS['fence_clock'] += 900;
    if ($site) {
      $a_site = site_state(['lockOwnerToken' => '', 'lockTable' => '', 'leaseLost' => false]);
      fence_check(invoke_private(SyncScheduler::class, 'acquire_lock'), 'Worker B could not take expired site lease.');
      $b_site = site_state($a_site);
    }
    $b = new DestinationLease('zone:' . str_repeat('c', 32)); $b->acquire();
  };
  $result = null;
  try { $result = $operation(); } catch (RuntimeException) { $result = 'stopped-after-write'; }
  fence_check($paused && $stale_writes === 0 && $result === 'stopped-after-write', 'Stale operation was not rejected before mutation: ' . $name);
  fence_check($GLOBALS['model_committed_mutations'] === 0 && $b->pending() === 'preserved-operation', 'Stale data committed or durable pending state lost.');
  $safe_cases++;
  echo "MODELED SAFETY: {$name}; stale mutation rejected; pending preserved\n";
  $a->release();
  if ($site) {
    invoke_private(SyncScheduler::class, 'release_lock');
    $retained = unserialize($wpdb->rows[$wpdb->options]['firewall_sync_is_running']);
    fence_check($retained['owner'] === $b_site['lockOwnerToken'], 'Stale worker release removed site replacement.');
    site_state($b_site); invoke_private(SyncScheduler::class, 'release_lock');
  }
  $b->release(); $pause_write = null; $paused = false;
}
// In contrast, the existing exact-value CAS must reject replacement during a
// pending-state update. This control asserts modeled safety, not InnoDB proof.
$wpdb = new FencingDatabase(); $fence_clock = 2200000000;
$a = new DestinationLease('account:' . str_repeat('a', 32)); $a->acquire(); $a->set_pending('preserved-operation');
$pause_kind = 'lease-update'; $b = null;
$pause_write = function () use (&$b): void {
  $GLOBALS['fence_clock'] += 900;
  $b = new DestinationLease('account:' . str_repeat('a', 32)); $b->acquire();
};
$rejected = false;
try { $a->set_pending('unknown'); } catch (RuntimeException) { $rejected = true; }
fence_check($rejected && $b->pending() === 'preserved-operation', 'Pending CAS overwrote the replacement.');
$a->release(); $b->release();
echo "MODELED CONTROL: pending CAS rejects an expired/replaced owner; not InnoDB verification\n";
fence_check($safe_cases === 18, 'Incomplete stale-worker safety matrix.');
// Exercise the actual boundary success, rollback and expiry paths, using the
// modeled transport. Separate MariaDB tests must establish real lock behavior.
$wpdb = new FencingDatabase(); $fence_clock = 2200000000;
$a = new DestinationLease('atomic-controls'); $a->acquire(); $a->set_pending('durable-receipt');
$before = $GLOBALS['model_committed_mutations'];
fence_check(BlockOwnership::add('8.8.8.8', 'manual'), 'Authorized mutation failed.');
fence_check($GLOBALS['model_committed_mutations'] === $before + 1, 'Authorized write did not commit.');
foreach (['throw', 'expiry', 'false', 'engine', 'outer', 'write-error'] as $failure) {
  $before = $GLOBALS['model_committed_mutations']; $rejected = false;
  $GLOBALS['model_non_innodb'] = $failure === 'engine';
  $GLOBALS['model_outer_tx'] = $failure === 'outer';
  $GLOBALS['model_write_error'] = $failure === 'write-error';
  try {
    \WPCF\FirewallSync\Services\AtomicFence::run(static function () use ($failure) {
      BlockOwnership::add('8.8.4.4', 'manual');
      if ($failure === 'expiry') { $GLOBALS['fence_clock'] += 900; }
      if ($failure === 'throw') { throw new RuntimeException('Injected after first mutation.'); }
      if ($failure === 'false') { return false; }
      return true;
    });
  } catch (RuntimeException) { $rejected = true; }
  fence_check($rejected && $GLOBALS['model_committed_mutations'] === $before, 'Incomplete work committed: ' . $failure);
  $GLOBALS['fence_clock'] = 2200000000;
  $GLOBALS['model_non_innodb'] = $GLOBALS['model_outer_tx'] = $GLOBALS['model_write_error'] = false;
  fence_check($a->pending() === 'durable-receipt', 'Rollback erased durable pending state.');
  echo "MODELED SAFETY: {$failure} rejects and rolls back; pending preserved\n";
}
// Exercise option RMW through the production raw option implementation.
fence_check(ResetWatermarkStore::set('8.8.8.8', $fence_clock), 'Authorized reset option creation failed.');
fence_check(unserialize($wpdb->rows[$wpdb->options][ResetWatermarkStore::SITE_OPTION])['8.8.8.8'] === $fence_clock, 'Reset creation did not commit.');
fence_check(ResetWatermarkStore::set('8.8.8.8', $fence_clock + 1), 'Authorized reset update failed.');
fence_check(ResetWatermarkStore::clear('8.8.8.8'), 'Authorized reset clear failed.');
fence_check(unserialize($wpdb->rows[$wpdb->options][ResetWatermarkStore::SITE_OPTION]) === [], 'Reset clear did not commit.');
fence_check(invoke_private(SyncScheduler::class, 'acquire_lock'), 'Status control site lease unavailable.');
$wpdb->rows[$wpdb->options]['firewall_sync_last_result'] = serialize('before');
$wpdb->rows[$wpdb->options]['firewall_sync_last_error'] = serialize('before');
$GLOBALS['model_fail_after'] = 1; $rejected = false;
try { invoke_private(SyncScheduler::class, 'persist_last_result', true); } catch (RuntimeException) { $rejected = true; }
unset($GLOBALS['model_fail_after']);
fence_check($rejected && unserialize($wpdb->rows[$wpdb->options]['firewall_sync_last_result']) === 'before'
  && unserialize($wpdb->rows[$wpdb->options]['firewall_sync_last_error']) === 'before', 'Partial status pair escaped rollback.');
invoke_private(SyncScheduler::class, 'persist_last_result', true);
fence_check(unserialize($wpdb->rows[$wpdb->options]['firewall_sync_last_result']) === 'success', 'Authorized status pair did not commit.');
invoke_private(SyncScheduler::class, 'release_lock');
echo "MODELED SAFETY: reset create/update/clear and atomic status pair rollback verified\n";
$a->release();
$rejected = false;
try { BlockOwnership::add('8.8.8.8', 'manual'); } catch (RuntimeException) { $rejected = true; }
fence_check($rejected, 'Empty authorization set allowed a protected mutation.');
echo "PASS: 18 stale-write regressions and transaction controls; MODEL ONLY, real InnoDB validation required\n";
$connection_source = file_get_contents(__DIR__ . '/../../src/includes/Services/AtomicConnection.php');
fence_check(str_contains($connection_source, 'remove_placeholder_escape($this->original->prepare('), 'Raw transport must remove wpdb placeholder escaping without query hooks.');
