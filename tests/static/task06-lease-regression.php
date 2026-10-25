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
  use WPCF\FirewallSync\Cloudflare\Client;
  $lease_clock = 2000000000;
  $acquire = new ReflectionMethod(SyncScheduler::class, 'acquire_lock');
  $release = new ReflectionMethod(SyncScheduler::class, 'release_lock');
  $record = new ReflectionMethod(SyncScheduler::class, 'record_batch_results');
  check($acquire->invoke(null), 'Initial acquisition failed.');
  $lease_clock += 450;
  SyncScheduler::assert_active_lease();
  check($GLOBALS['options']['firewall_sync_is_running']['started_at'] === $lease_clock, 'Live site lease did not renew.');
  $lease_clock += 901;
  $GLOBALS['options']['firewall_sync_is_running'] = ['owner' => 'replacement', 'started_at' => $lease_clock];
  $stopped = false;
  try { $record->invoke(null, [['ip' => '8.8.8.8', 'reason' => 'fixture', 'expires_at' => null]], []); }
  catch (RuntimeException $error) { $stopped = true; }
  check($stopped && $GLOBALS['success_ips'] === [], 'Expired worker recorded local success after replacement.');
  $stopped = false;
  try { (new Client('fixture-credential-value', str_repeat('c', 32)))->delete_block('8.8.8.8'); }
  catch (RuntimeException $error) { $stopped = true; }
  check($stopped && $GLOBALS['requests'] === 0, 'Expired worker issued Cloudflare HTTP after replacement.');
  $release->invoke(null);
  check($GLOBALS['options']['firewall_sync_is_running']['owner'] === 'replacement', 'Stale release deleted replacement.');
  unset($GLOBALS['options']['firewall_sync_is_running']);
  $GLOBALS['lease_write_error'] = true;
  check(!$acquire->invoke(null), 'Failed insertion authorized a scheduler.');
  $GLOBALS['lease_write_error'] = false;
  check($acquire->invoke(null), 'Recovery after insertion failure failed.');
  $lease_clock += 450; $GLOBALS['lease_write_error'] = true;
  $stopped = false;
  try { SyncScheduler::assert_active_lease(); } catch (RuntimeException) { $stopped = true; }
  check($stopped, 'Site renewal failure authorized work.');
  $GLOBALS['lease_write_error'] = false;
  $stopped = false;
  try { SyncScheduler::assert_active_lease(); } catch (RuntimeException) { $stopped = true; }
  check($stopped, 'Site renewal failure was not latched.');
  $release->invoke(null);
  $GLOBALS['options'] = [];
  $GLOBALS['replace_site_during_http'] = true;
  $result = SyncScheduler::run_cleanup();
  check(!$result['complete'] && $GLOBALS['watermarks'] === [], 'HTTP replacement permitted local cleanup.');
  check($GLOBALS['options']['firewall_sync_is_running']['owner'] === 'replacement-during-http', 'Interrupted cleanup released replacement.');
  check($GLOBALS['options']['firewall_sync_last_result'] === 'replacement-result', 'Interrupted cleanup overwrote replacement status.');
  unset($GLOBALS['replace_site_during_http']);
  echo "PASS: replacement during cleanup HTTP blocks local changes and status overwrite\n";
  echo "PASS: stale worker stopped before local recording and HTTP; replacement retained\n";
}
