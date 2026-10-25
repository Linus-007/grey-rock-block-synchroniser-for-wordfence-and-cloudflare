<?php

declare(strict_types=1);
require_once __DIR__ . '/../../src/includes/Services/AtomicFence.php';
require __DIR__ . '/../fixtures/task06-coordination-harness.php';
use WPCF\FirewallSync\Services\DestinationLease;
function check(bool $value, string $message): void { if (!$value) { throw new RuntimeException($message); } }
function rejected(callable $operation): bool { try { $operation(); } catch (RuntimeException) { return true; } return false; }
$cases = [];
$cases['renewal-and-expiry'] = function (): void {
  global $destination_clock, $wpdb;
  $lease = new DestinationLease('account:fixture'); $lease->acquire();
  $destination_clock += 450; $lease->assert_live();
  check(unserialize(reset($wpdb->rows))['started_at'] === $destination_clock, 'Live lease was not renewed.');
  $destination_clock += 900;
  check(rejected(fn() => $lease->assert_live()), 'Expired lease was renewed.');
  $lease->release();
};
$cases['replacement-and-release'] = function (): void {
  global $destination_clock, $wpdb;
  $old = new DestinationLease('zone:fixture'); $old->acquire();
  $destination_clock += 900;
  $new = new DestinationLease('zone:fixture'); $new->acquire();
  $replacement = $wpdb->rows;
  check(rejected(fn() => $old->assert_live()), 'Old worker accepted a replacement lease.');
  $old->release(); check($wpdb->rows === $replacement, 'Stale release changed replacement ownership.');
  $new->release();
};
$cases['failed-insert'] = function (): void {
  global $wpdb;
  $wpdb->fail_write = true;
  $lease = new DestinationLease('account:fixture');
  check(rejected(fn() => $lease->acquire()) && $wpdb->rows === [], 'Failed insertion authorized a worker.');
};
$cases['failed-renewal-is-latched'] = function (): void {
  global $destination_clock, $wpdb;
  $lease = new DestinationLease('account:fixture'); $lease->acquire();
  $destination_clock += 450; $wpdb->fail_write = true;
  check(rejected(fn() => $lease->assert_live()), 'Failed renewal authorized mutation.');
  $wpdb->fail_write = false;
  check(rejected(fn() => $lease->assert_live()), 'Failed renewal later regained authorization.');
  $lease->release();
};
$cases['failed-read-is-latched'] = function (): void {
  global $wpdb;
  $lease = new DestinationLease('account:fixture'); $lease->acquire();
  $wpdb->fail_read = true;
  check(rejected(fn() => $lease->assert_live()), 'Read failure authorized mutation.');
  $wpdb->fail_read = false;
  check(rejected(fn() => $lease->assert_live()), 'Read failure later regained authorization.');
  $lease->release();
};
$cases['replacement-during-renewal'] = function (): void {
  global $destination_clock, $wpdb;
  $lease = new DestinationLease('account:fixture'); $lease->acquire();
  $destination_clock += 450;
  $wpdb->before_update = function ($db, $values): void {
    $replacement = unserialize($db->rows[$values[1]]); $replacement['owner'] = 'replacement';
    $db->rows[$values[1]] = serialize($replacement);
  };
  check(rejected(fn() => $lease->assert_live()), 'Renewal overwrote replacement ownership.');
  $lease->release();
  check(unserialize(reset($wpdb->rows))['owner'] === 'replacement', 'Stale cleanup removed replacement.');
};
$cases['interrupted-release-retains-pending'] = function (): void {
  global $destination_clock, $wpdb;
  $lease = new DestinationLease('account:fixture'); $lease->acquire(); $lease->set_pending('known-operation');
  $wpdb->fail_write = true; $lease->release(); $wpdb->fail_write = false;
  $destination_clock += 900;
  $next = new DestinationLease('account:fixture'); $next->acquire();
  check($next->pending() === 'known-operation', 'Interrupted release lost pending work.');
  $next->release();
};
$cases['renewal-paused-past-expiry'] = function (): void {
  global $destination_clock, $wpdb;
  $lease = new DestinationLease('account:fixture'); $lease->acquire();
  $destination_clock += 450;
  $wpdb->before_update = function ($db, $values): void { $GLOBALS['destination_clock'] += 500; };
  check(rejected(fn() => $lease->assert_live()), 'A worker paused before renewal revived its expired lease.');
  $lease->release();
};
$cases['scope-independence'] = function (): void {
  global $wpdb;
  $a = new DestinationLease('account:first'); $a->acquire();
  $b = new DestinationLease('account:second'); $b->acquire();
  $c = new DestinationLease('zone:first'); $c->acquire();
  check(count($wpdb->rows) === 3, 'Independent destinations were globally serialized.');
  $a->release(); $b->release(); $c->release();
};
foreach ($cases as $name => $test) {
  $destination_clock = 2000000000; $wpdb = new Task06Database();
  $test(); echo "PASS: destination fault {$name}\n";
}
