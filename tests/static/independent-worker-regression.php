<?php

declare(strict_types=1);
require_once __DIR__ . '/../fixtures/task14-unit-fence.php';

// Reuse only the mock transport and WordPress shims; none of the prior cases run.
$requested = $argv[1] ?? '';
$argv[1] = '__harness_only__';
require __DIR__ . '/cloudflare-recovery-regression.php';
use WPCF\FirewallSync\Cloudflare\Client;

$checks = [];
$checks['inconsistent-counts'] = function () use ($zone, $first, $second): void {
  foreach ([
    ['page' => 1, 'per_page' => 1, 'count' => 2, 'total_count' => 2, 'total_pages' => 1],
    ['page' => 1, 'per_page' => 50, 'count' => 2, 'total_count' => 1, 'total_pages' => 1],
    ['page' => 1, 'per_page' => 50, 'count' => 2, 'total_count' => 51, 'total_pages' => 1],
  ] as $info) {
    $GLOBALS['queue'] = [reply([rule('8.8.8.8', $first), rule('1.1.1.1', $second)], $info)];
    check((new Client('offline-worker-fixture', $zone))->get_current_blocked_ips() === null,
      'Contradictory pagination was declared complete: ' . json_encode($info));
  }
};
$checks['separate-pending-workers'] = function () use ($account, $list, $operation): void {
  for ($worker = 0; $worker < 2; $worker++) {
    $GLOBALS['queue'] = [reply([], ['cursors' => ['after' => null]]), reply(['operation_id' => $operation])];
    for ($i = 0; $i < 3; $i++) { $GLOBALS['queue'][] = reply(['id' => $operation, 'status' => 'pending']); }
    $client = new Client('offline-worker-fixture', '');
    check($client->batch_add_ips_to_account_list($account, $list, [['ip' => '8.8.8.8']]) === ['8.8.8.8'], 'Pending remote work was declared successful.');
    check($client->get_retryable_failed_ips() === ['8.8.8.8'], 'Pending work was classified as permanent.');
    check(!$GLOBALS['queue'], 'Worker did not consume its bounded status checks.');
  }
  check(count($GLOBALS['requests']) === 10, 'Unexpected cross-worker request pattern.');
  // Observed limitation: operation identity is not persisted between workers.
};
$checks['shared-account-conflict'] = function () use ($account, $list): void {
  for ($worker = 0; $worker < 2; $worker++) {
    $GLOBALS['queue'] = [reply([]), reply([], [], 409)];
    $client = new Client('offline-worker-fixture', '');
    check(count($client->batch_add_ips_to_account_list($account, $list, [['ip' => '8.8.8.8'], ['ip' => '1.1.1.1']])) === 2, 'Conflicting work recorded success.');
    check(count($client->get_retryable_failed_ips()) === 2, 'Conflict consumed permanent-failure budget.');
    check(!$GLOBALS['queue'], 'Unexpected worker mutation pattern.');
  }
  check(count($GLOBALS['requests']) === 4, 'Worker continued mutating after account conflict.');
};
$checks['remote-success-local-retry'] = function () use ($account, $list, $operation): void {
  $GLOBALS['queue'] = [reply([]), reply(['operation_id' => $operation]), reply(['id' => $operation, 'status' => 'completed'])];
  check((new Client('offline-worker-fixture', ''))->add_ip_to_account_list($account, $list, '8.8.8.8'), 'Initial remote write failed.');
  // Simulate loss of local success: a fresh client must recover by observing membership.
  $GLOBALS['queue'] = [reply([['id' => 'persisted-item', 'ip' => '8.8.8.8']], ['cursors' => ['after' => null]])];
  check((new Client('offline-worker-fixture', ''))->add_ip_to_account_list($account, $list, '8.8.8.8'), 'Fresh worker could not repair local success.');
  check(count(array_filter($GLOBALS['requests'], fn($r) => $r[0] === 'POST')) === 1, 'Retry duplicated an already completed mutation.');
};
$checks['scheduler-cooldown'] = function () use ($zone, $first): void {
  $limited = reply([], [], 429); $limited['headers'] = ['retry-after' => '30'];
  $GLOBALS['queue'] = [$limited];
  check(!(new Client('offline-worker-fixture', $zone))->create_block('8.8.8.8'), '429 accepted.');
  for ($i = 0; $i < 3; $i++) { check(!(new Client('offline-worker-fixture', $zone))->create_block('8.8.8.8'), 'Separate invocation ignored cooldown.'); }
  check(count($GLOBALS['requests']) === 1, 'Cooldown caused repeated HTTP.');
  $GLOBALS['clock'] += 31; $GLOBALS['queue'] = [reply(['id' => $first])];
  check((new Client('offline-worker-fixture', $zone))->create_block('8.8.8.8'), 'Cooldown never expired.');
};
foreach ($checks as $name => $test) {
  if ($requested !== '' && $name !== $requested) { continue; }
  $queue = []; $requests = []; $transients = []; $clock = 2000000000;
  $test();
  echo "PASS: independent worker {$name}\n";
}
