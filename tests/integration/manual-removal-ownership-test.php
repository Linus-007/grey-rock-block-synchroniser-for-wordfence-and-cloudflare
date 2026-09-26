<?php

declare(strict_types=1);

use WPCF\FirewallSync\Admin\Fields;
use WPCF\FirewallSync\Services\BlockLogger;
use WPCF\FirewallSync\Services\BlockOwnership;
use WPCF\FirewallSync\Services\ResetWatermarkStore;

if (!defined('ABSPATH')) {
  fwrite(STDERR, "FAIL: WordPress is not loaded.\n");
  exit(1);
}

function manual_removal_fail(string $message): never {
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

function manual_removal_assert(
  bool $condition,
  string $message
): void {
  if (!$condition) {
    manual_removal_fail($message);
  }
}

$test_ip = '8.8.4.4';

/*
 * Ensure the test begins from a known local state.
 */
BlockLogger::remove($test_ip);
BlockOwnership::remove(
  $test_ip,
  BlockOwnership::OWNER_MANUAL
);
ResetWatermarkStore::clear($test_ip);

/*
 * Establish the state that exists after Grey Rock has manually synchronized
 * an address to Cloudflare.
 */
BlockLogger::log(
  $test_ip,
  'manual: integration ownership-removal test'
);

manual_removal_assert(
  BlockOwnership::add(
    $test_ip,
    BlockOwnership::OWNER_MANUAL
  ),
  'Could not establish manual ownership.'
);

manual_removal_assert(
  BlockLogger::has_synced($test_ip),
  'Synchronization provenance was not established.'
);

manual_removal_assert(
  BlockOwnership::has(
    $test_ip,
    BlockOwnership::OWNER_MANUAL
  ),
  'Manual ownership was not established.'
);

manual_removal_assert(
  ResetWatermarkStore::get($test_ip) === 0,
  'Reset watermark existed before removal.'
);

/*
 * Exercise the exact production helper used after a successful manual
 * Cloudflare removal.
 */
$method = new ReflectionMethod(
  Fields::class,
  'record_manual_removal_reset'
);

$method->setAccessible(true);

$result = $method->invoke(
  null,
  'site',
  $test_ip
);

manual_removal_assert(
  $result === true,
  'Manual-removal reset helper did not report success.'
);

manual_removal_assert(
  ResetWatermarkStore::get($test_ip) > 0,
  'Manual removal did not record the reset watermark.'
);

manual_removal_assert(
  !BlockLogger::has_synced($test_ip),
  'Manual removal retained synchronization provenance.'
);

manual_removal_assert(
  !BlockOwnership::has(
    $test_ip,
    BlockOwnership::OWNER_MANUAL
  ),
  'Manual removal retained manual ownership.'
);

manual_removal_assert(
  !BlockOwnership::has_any($test_ip),
  'Manual removal left the IP owned.'
);

echo "PASS: Site manual removal clears synchronization provenance and manual ownership while recording the reset watermark.\n";
