<?php

declare(strict_types=1);
require __DIR__ . '/task07-audit-bootstrap.php';
use WPCF\FirewallSync\Services\DestinationLease;
$wpdb->state_file = $argv[1];
$audit_clock = (int) file_get_contents($argv[2]);
$lease = new DestinationLease('account:' . str_repeat('a', 32));
try { $lease->acquire(); echo "ACQUIRED\n"; }
catch (RuntimeException) { echo "BUSY\n"; exit(0); }
flush();
while (($line = fgets(STDIN)) !== false) {
  $audit_clock = (int) file_get_contents($argv[2]);
  switch (trim($line)) {
    case 'unknown': $lease->set_pending('unknown'); echo "STORED\n"; break;
    case 'state': echo $lease->pending() . "\n"; break;
    case 'guard':
      try { $lease->assert_live(); echo "LIVE\n"; } catch (RuntimeException) { echo "LOST\n"; }
      break;
    case 'release': $lease->release(); echo "RELEASED\n"; flush(); exit(0);
    default: throw new RuntimeException('Invalid audit fixture command.');
  }
  flush();
}
$lease->release();
