<?php

declare(strict_types=1);
require __DIR__ . '/task06-coordination-harness.php';
use WPCF\FirewallSync\Services\DestinationLease;
$wpdb->state_file = $argv[1];
$task06_clock_path = $argv[2];
$lease = new DestinationLease('account:process-fixture');
try { $lease->acquire(); }
catch (RuntimeException) { echo "BUSY\n"; exit(0); }
echo "ACQUIRED\n"; flush();
while (($command = fgets(STDIN)) !== false) {
  $command = trim($command);
  if ($command === 'guard') {
    try { $lease->assert_live(); echo "LIVE\n"; } catch (RuntimeException) { echo "LOST\n"; }
  } elseif ($command === 'pending') {
    $lease->set_pending('process-operation'); echo "STORED\n";
  } elseif ($command === 'read') {
    echo $lease->pending() . "\n";
  } elseif ($command === 'release') {
    $lease->release(); echo "RELEASED\n"; flush(); exit(0);
  } else { throw new RuntimeException('Unknown fixture command.'); }
  flush();
}
$lease->release();
