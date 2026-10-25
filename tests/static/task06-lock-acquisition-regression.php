<?php

declare(strict_types=1);
$argv[1] = '__harness_only__';
require __DIR__ . '/synchronization-recovery-regression.php';
use WPCF\FirewallSync\Services\SyncScheduler;
$acquire = new ReflectionMethod(SyncScheduler::class, 'acquire_lock');
// Model both core callers observing a missing cached option before core's
// INSERT ... ON DUPLICATE KEY UPDATE. A successful API call is not proof that
// the caller inserted rather than overwrote an existing owner's unique row.
$options['firewall_sync_is_running'] = ['owner' => 'existing-worker', 'started_at' => time()];
$simulate_core_duplicate_update = true;
check(!$acquire->invoke(null), 'A duplicate-key option update authorized a second worker.');
check($options['firewall_sync_is_running']['owner'] === 'existing-worker', 'Acquisition overwrote the existing owner.');
echo "PASS: lock acquisition does not rely on duplicate-updating add_option semantics\n";
