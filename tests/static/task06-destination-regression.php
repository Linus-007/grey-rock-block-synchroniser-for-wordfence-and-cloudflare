<?php

declare(strict_types=1);
require_once __DIR__ . '/../fixtures/task14-unit-fence.php';

$argv[1] = '__harness_only__';
require __DIR__ . '/cloudflare-recovery-regression.php';
use WPCF\FirewallSync\Cloudflare\Client;
require __DIR__ . '/../fixtures/task06-coordination-harness.php';

// These clients represent independently configured sites using different
// credentials for the same Cloudflare account. Provider HTTP remains mocked.
$queue = [reply([], ['cursors' => ['after' => null]]), reply(['operation_id' => $operation])];
for ($i = 0; $i < 3; $i++) { $queue[] = reply(['id' => $operation, 'status' => 'pending']); }
$client = new Client('first-offline-fixture', '');
check(!$client->add_ip_to_account_list($account, $list, '8.8.8.8'), 'Pending work reported success.');
unset($client);
// A new worker must resolve the old operation before reading stale membership
// or submitting new work. The baseline incorrectly consumes this as inventory.
$queue = [];
for ($i = 0; $i < 3; $i++) { $queue[] = reply(['id' => $operation, 'status' => 'running']); }
$before = count($requests);
$next = new Client('second-offline-fixture', '');
$next->add_ip_to_account_list($account, $list, '1.1.1.1');
check(str_contains($requests[$before][1], '/bulk_operations/'), 'A new client forgot the originating pending operation.');
echo "PASS: new client resumes durable pending operation before conflicting work\n";

unset($next);
// Completed old work is resolved before a fresh inventory and insertion.
$queue = [reply(['id' => $operation, 'status' => 'completed']), reply([], ['cursors' => ['after' => null]]), reply(['operation_id' => 'new-operation']), reply(['id' => 'new-operation', 'status' => 'completed'])];
$next = new Client('third-offline-fixture', '');
check($next->add_ip_to_account_list($account, $list, '1.1.1.1'), 'Completed prior work did not allow recovery.');
check($queue === [], 'Recovery did not re-read inventory before new work.');
unset($next);
check($wpdb->rows === [], 'Completed destination lease leaked state.');
echo "PASS: durable operation completion permits a fresh inventory and mutation\n";

// An active client excludes a second site even if it uses another token/list.
$queue = [reply([], ['cursors' => ['after' => null]])];
$first_client = new Client('first-offline-fixture', '');
check($first_client->get_current_account_list_ips($account, $list) === [], 'First inventory failed.');
$count = count($requests);
$second_client = new Client('second-offline-fixture', '');
check($second_client->get_current_account_list_ips($account, str_repeat('e', 32)) === null, 'Second site entered the same account lease.');
check(count($requests) === $count, 'Contending site issued HTTP.');
unset($first_client, $second_client);
echo "PASS: account coordination spans lists and credentials; contention sends no HTTP\n";

// An uncertain dispatch survives destruction and must never expire into permission.
$queue = [reply([], ['cursors' => ['after' => null]]), new WP_Error('offline_timeout', 'Uncertain mock response')];
$first_client = new Client('first-offline-fixture', '');
check(!$first_client->add_ip_to_account_list($account, $list, '8.8.8.8'), 'Unknown acceptance reported success.');
unset($first_client);
$destination_clock = 2000005000;
$count = count($requests);
$second_client = new Client('second-offline-fixture', '');
check(!$second_client->add_ip_to_account_list($account, $list, '1.1.1.1'), 'Unknown acceptance expired into write authorization.');
check(count($requests) === $count, 'Unknown acceptance permitted conflicting HTTP.');
unset($second_client);
echo "PASS: unknown acceptance retained across client death and elapsed time\n";

// Expiry while an HTTP mutation is in flight must not authorize local ownership.
$wpdb->rows = []; $destination_clock = 2000010000;
$queue = [reply([], ['cursors' => ['after' => null]]), reply(['operation_id' => $operation])];
$task06_after_post = function (): void { $GLOBALS['destination_clock'] += 900; };
$client = new Client('first-offline-fixture', '');
check(!$client->add_ip_to_account_list($account, $list, '8.8.8.8'), 'An expired HTTP worker reported success.');
unset($task06_after_post);
require dirname(__DIR__, 2) . '/src/includes/Services/BlockOwnership.php';
$stopped = false;
try { \WPCF\FirewallSync\Services\BlockOwnership::add('8.8.8.8', 'manual'); } catch (RuntimeException) { $stopped = true; }
check($stopped, 'Expired HTTP worker mutated actual local ownership.');
unset($client);
check(unserialize(reset($wpdb->rows))['pending'] === 'unknown', 'Expired HTTP worker discarded uncertain dispatch.');
echo "PASS: expiry during HTTP blocks actual local ownership and retains uncertain acceptance\n";

// Zone destinations also serialize independent clients; unrelated zones proceed.
$wpdb->rows = [];
$queue = [reply([]), reply(['id' => $first])];
$client = new Client('first-offline-fixture', $zone);
check($client->get_current_blocked_ips() === [], 'First zone inventory failed.');
$count = count($requests);
$contender = new Client('second-offline-fixture', $zone);
check(!$contender->create_block('8.8.8.8'), 'A second site wrote to a held zone.');
check(count($requests) === $count, 'Contending zone client issued HTTP.');
$independent = new Client('third-offline-fixture', str_repeat('e', 32));
check($independent->create_block('8.8.8.8'), 'Unrelated zone was serialized.');
unset($client, $contender, $independent);
echo "PASS: shared-zone contention blocks writes while independent zones proceed\n";

// Losing durable response persistence must leave the pre-dispatch marker.
$wpdb->rows = [];
$queue = [reply([], ['cursors' => ['after' => null]]), reply(['operation_id' => $operation])];
$task06_after_post = function (): void { $GLOBALS['wpdb']->fail_write = true; };
$client = new Client('first-offline-fixture', '');
check(!$client->add_ip_to_account_list($account, $list, '8.8.8.8'), 'Failed operation-ID persistence reported success.');
unset($task06_after_post, $client);
$wpdb->fail_write = false;
check(unserialize(reset($wpdb->rows))['pending'] === 'unknown', 'Operation-ID failure discarded pre-dispatch evidence.');
$destination_clock += 900;
$count = count($requests);
$client = new Client('second-offline-fixture', '');
check(!$client->add_ip_to_account_list($account, $list, '1.1.1.1') && count($requests) === $count, 'Operation-ID write failure permitted conflicting work after takeover.');
unset($client);
echo "PASS: response persistence failure retains uncertain dispatch across takeover\n";

// Official contract documents 429 for an outstanding asynchronous write.
$wpdb->rows = []; $transients = [];
$queue = [reply([], ['cursors' => ['after' => null]]), reply([], [], 429)];
$count = count($requests);
$client = new Client('first-offline-fixture', '');
check($client->batch_add_ips_to_account_list($account, $list, [['ip' => '8.8.8.8'], ['ip' => '1.1.1.1']]) === ['8.8.8.8', '1.1.1.1'], '429 recorded successful batch entries.');
check(count($requests) === $count + 2, 'Batch continued HTTP during a 429 cooldown.');
unset($client);
check($wpdb->rows === [], 'Explicit provider rejection left an unknown accepted mutation.');
echo "PASS: provider 429 blocks additional batch HTTP and remains retryable\n";

// A synchronous zone request can also outlive its worker's lease or process.
$wpdb->rows = []; $transients = [];
$queue = [new WP_Error('offline_timeout', 'Uncertain zone acceptance')];
$client = new Client('first-offline-fixture', $zone);
check(!$client->create_block('8.8.8.8'), 'Uncertain zone mutation reported success.');
unset($client);
$destination_clock += 900;
$count = count($requests);
$client = new Client('second-offline-fixture', $zone);
check(!$client->delete_block('8.8.8.8') && count($requests) === $count, 'Unknown zone dispatch permitted a conflicting deletion after takeover.');
unset($client);
echo "PASS: uncertain zone dispatch prevents conflicting work after worker death\n";
