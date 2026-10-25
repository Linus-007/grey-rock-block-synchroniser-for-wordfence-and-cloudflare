<?php

declare(strict_types=1);
require_once __DIR__ . '/../fixtures/task14-unit-fence.php';
require __DIR__ . '/../fixtures/task07-audit-bootstrap.php';
use WPCF\FirewallSync\Cloudflare\Client;
use WPCF\FirewallSync\Services\DestinationLease;
use WPCF\FirewallSync\Services\BlockOwnership;
$zone = str_repeat('c', 32); $first = str_repeat('1', 32); $other = str_repeat('2', 32);
$cases = [];
$cases['wrong-delete-identity'] = function () use ($zone, $first, $other): void {
  global $http_queue, $wpdb;
  $http_queue = [audit_reply([['id' => $first, 'mode' => 'block', 'configuration' => ['target' => 'ip', 'value' => '8.8.8.8']]]), audit_reply(['id' => $other])];
  $client = new Client('offline-audit-fixture', $zone);
  audit_check(!$client->delete_block('8.8.8.8'), 'Wrong deletion identity reported success.');
  unset($client);
  $state = $wpdb->rows ? unserialize(reset($wpdb->rows)) : null;
  audit_check(($state['pending'] ?? '') === 'unknown', 'Rejected deletion response discarded unknown acceptance.');
};
$cases['malformed-envelope'] = function () use ($zone, $first): void {
  global $http_queue, $wpdb;
  $http_queue = [audit_reply(['id' => $first], 200, 'invalid-errors-structure')];
  $client = new Client('offline-audit-fixture', $zone);
  audit_check(!$client->create_block('8.8.8.8'), 'Malformed envelope reported success.');
  unset($client);
  $state = $wpdb->rows ? unserialize(reset($wpdb->rows)) : null;
  audit_check(($state['pending'] ?? '') === 'unknown', 'Invalid envelope cleared the durable dispatch marker.');
};
$cases['post-update-clock-failure'] = function (): void {
  global $wpdb;
  $lease = new DestinationLease('account:audit'); $lease->acquire();
  $wpdb->fail_next_post_update_clock = true;
  $failed = false;
  try { $lease->set_pending('unknown'); } catch (RuntimeException) { $failed = true; }
  audit_check($failed, 'Injected clock failure did not stop pending update.');
  $latched = false;
  try { $lease->assert_live(); } catch (RuntimeException) { $latched = true; }
  $lease->release();
  audit_check($latched, 'Post-update database failure did not latch lease loss.');
};
$cases['cached-membership-after-replacement'] = function (): void {
  global $http_queue, $wpdb, $audit_clock;
  $account = str_repeat('a', 32); $list = str_repeat('b', 32);
  $http_queue = [audit_reply([['id' => 'fixture-item', 'ip' => '8.8.8.8']])];
  $client = new Client('offline-audit-fixture', '');
  audit_check($client->account_list_contains_ip($account, $list, '8.8.8.8') === true, 'Initial inventory missing.');
  $audit_clock += 900;
  $replacement = new DestinationLease('account:' . $account); $replacement->acquire();
  audit_check(!$client->add_ip_to_account_list($account, $list, '8.8.8.8'), 'Expired client reported cached membership as successful synchronization.');
  unset($client); $replacement->release();
};
$cases['recovery-state-matrix'] = function (): void {
  global $wpdb, $http_queue, $http_events;
  $account = str_repeat('a', 32); $list = str_repeat('b', 32);
  foreach (['completed', 'failed', 'running', 'unknown'] as $status) {
    $wpdb = new AuditDatabase(); $http_events = []; $http_queue = [];
    $origin = new DestinationLease('account:' . $account); $origin->acquire();
    $origin->set_pending($status === 'unknown' ? 'unknown' : 'audit-operation'); $origin->release();
    if ($status !== 'unknown') {
      for ($i = 0; $i < ($status === 'running' ? 3 : 1); $i++) {
        $http_queue[] = audit_reply(['id' => 'audit-operation', 'status' => $status]);
      }
    }
    if ($status === 'completed') { $http_queue[] = audit_reply([['id' => 'item', 'ip' => '8.8.8.8']]); }
    $client = new Client('offline-recovery-fixture', '');
    $result = $client->get_current_account_list_ips($account, $list);
    audit_check(($status === 'completed') === is_array($result), 'Recovery state accepted an unresolved/failed inventory.');
    audit_check(count(array_filter($http_events, fn($event) => $event[0] !== 'GET')) === 0, 'Read-only recovery submitted a mutation.');
    audit_check(count($http_events) === ['completed' => 2, 'failed' => 1, 'running' => 3, 'unknown' => 0][$status], 'Recovery status request ordering changed.');
    unset($client);
    $retained = $wpdb->rows ? unserialize(reset($wpdb->rows))['pending'] : '';
    audit_check($retained === (in_array($status, ['running', 'unknown'], true) ? ($status === 'unknown' ? 'unknown' : 'audit-operation') : ''), 'Recovery retained/cleared the wrong pending state.');
    echo "PASS: recovery matrix {$status}, GET-only, unresolved state retained\n";
  }
};
$cases['unknown-does-not-prove-dispatch'] = function () use ($zone): void {
  global $wpdb, $http_queue, $http_events;
  foreach (['before-dispatch', 'response-lost'] as $point) {
    $wpdb = new AuditDatabase(); $http_queue = []; $http_events = [];
    if ($point === 'before-dispatch') {
      $wpdb->after_update = function (): void { throw new RuntimeException('Injected interruption before HTTP.'); };
    } else { $http_queue = [new WP_Error('lost_response')]; }
    $client = new Client('offline-recovery-fixture', $zone);
    try { $client->create_block('8.8.8.8'); } catch (RuntimeException) {}
    $wpdb->after_update = null;
    unset($client);
    $state = unserialize(reset($wpdb->rows));
    audit_check($state['pending'] === 'unknown', 'Interruption lost uncertain dispatch evidence.');
    audit_check(count($http_events) === ($point === 'before-dispatch' ? 0 : 1), 'Interruption fixture did not reach the intended HTTP boundary.');
    echo "PASS: {$point} leaves indistinguishable unknown state; no completion inference permitted\n";
  }
};
$cases['characterized-local-check-use-gap'] = function (): void {
  // The historical check/use counterexample is now required to satisfy the
  // production boundary safety matrix in a fresh process (no unit double).
  $process = proc_open([PHP_BINARY, __DIR__ . '/task14-atomic-fencing-regression.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
  audit_check(is_resource($process), 'Safety regression process unavailable.');
  $output = stream_get_contents($pipes[1]);
  $errors = stream_get_contents($pipes[2]);
  fclose($pipes[1]); fclose($pipes[2]);
  audit_check(proc_close($process) === 0 && $errors === '', 'Atomic safety regression failed.');
  audit_check(str_contains($output, 'PASS: 18 stale-write regressions'), 'Incomplete atomic regression matrix.');
  echo "MODELED SAFETY: the former check/use gap now requires rejection of all 18 stale mutation paths; real InnoDB validation remains required\n";
};
$cases['delayed-renewal-rejected'] = function (): void {
  global $wpdb, $audit_clock;
  $lease = new DestinationLease('zone:delay-fixture'); $lease->acquire();
  $audit_clock += 450;
  $wpdb->after_update = function (): void { $GLOBALS['audit_clock'] += 500; };
  $rejected = false;
  try { $lease->assert_live(); } catch (RuntimeException) { $rejected = true; }
  $wpdb->after_update = null;
  audit_check($rejected, 'Statement finishing after the old deadline authorized work.');
  $lease->release();
};
$cases['lock-status-clock-failure'] = function (): void {
  global $wpdb, $audit_clock;
  require_once dirname(__DIR__, 2) . '/src/includes/Services/SyncScheduler.php';
  $wpdb->rows['firewall_sync_is_running'] = serialize(['owner' => 'status-fixture', 'started_at' => $audit_clock]);
  $wpdb->fail_clock_once = true;
  audit_check(\WPCF\FirewallSync\Services\SyncScheduler::is_locked(), 'Storage failure did not conservatively report locked.');
};
foreach ($cases as $name => $case) {
  if (($argv[1] ?? '') !== '' && $argv[1] !== $name) { continue; }
  $wpdb = new AuditDatabase(); $audit_clock = 2100000000; $http_queue = []; $http_events = [];
  $case(); echo "PASS: independent Task 7 {$name}\n";
}
