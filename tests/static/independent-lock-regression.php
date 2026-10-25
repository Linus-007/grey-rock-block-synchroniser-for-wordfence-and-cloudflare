<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Services {
  function time(): int { return $GLOBALS['lease_clock']; }
}
namespace {
  require_once __DIR__ . '/../fixtures/task14-unit-fence.php';
  $argv[1] = '__harness_only__';
  require __DIR__ . '/synchronization-recovery-regression.php';
  use WPCF\FirewallSync\Services\SyncScheduler;
  $lease_clock = 2000000000;
  $acquire = new ReflectionMethod(SyncScheduler::class, 'acquire_lock');
  $release = new ReflectionMethod(SyncScheduler::class, 'release_lock');
  check($acquire->invoke(null), 'First worker could not acquire lock.');
  check(SyncScheduler::is_locked(), 'Active lease not visible.');
  $lease_clock += 901;
  check(!SyncScheduler::is_locked(), 'Lease expiry characterization changed; review long-worker assumptions.');
  $token = new ReflectionProperty(SyncScheduler::class, 'lockOwnerToken');
  check($token->getValue() !== '', 'Expired worker unexpectedly lost its local owner token.');
  // Model another process replacing the expired database option.
  $GLOBALS['options']['firewall_sync_is_running'] = ['owner' => 'replacement-worker', 'started_at' => $lease_clock];
  $release->invoke(null);
  check($GLOBALS['options']['firewall_sync_is_running']['owner'] === 'replacement-worker', 'Old worker released a replacement lease.');
  echo "PASS: replacement lock survives stale-worker release\n";
  $stopped = false;
  // Reacquire a separate invocation, then lose it without replacement.
  unset($GLOBALS['options']['firewall_sync_is_running']);
  check($acquire->invoke(null), 'Recovery acquisition failed.');
  $lease_clock += 900;
  try { SyncScheduler::assert_active_lease(); } catch (RuntimeException) { $stopped = true; }
  check($stopped, 'An exactly expired token was renewed into authorization.');
  $release->invoke(null);
  echo "PASS: an expired token is rejected even without a replacement worker\n";
}
