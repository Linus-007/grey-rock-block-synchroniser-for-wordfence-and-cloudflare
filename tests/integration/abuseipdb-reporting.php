<?php
/** Real fenced InnoDB reporting tests, running only in the existing Docker matrix. */
declare(strict_types=1);
use WPCF\FirewallSync\Config;
use WPCF\FirewallSync\Cloudflare\Client;
use WPCF\FirewallSync\AbuseIPDB\Evidence;
use WPCF\FirewallSync\AbuseIPDB\Reporter;
use WPCF\FirewallSync\Services\AtomicFence;
use WPCF\FirewallSync\Services\DestinationLease;
use WPCF\FirewallSync\Services\AuditLog;
use WPCF\FirewallSync\Services\BlockLogger;
if (getenv('GREY_ROCK_TASK13_DISPOSABLE') !== 'YES' || !str_starts_with(DB_NAME, 'greyrock_task13_')) { throw new RuntimeException('Disposable DB required'); }
require __DIR__ . '/../fixtures/abuseipdb-http.php';
function ar_ok(bool $value, string $name): void { if (!$value) { throw new RuntimeException($name); } echo "PASS: {$name}\n"; }
function ar_calls(): int { global $wpdb; return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->base_prefix}wpcf_abuse_state WHERE state_key LIKE 'fixture-http:%'"); }
function ar_status(string $id): string { global $wpdb; return (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->base_prefix}wpcf_abuse_incidents WHERE incident=%s", $id)); }
function ar_entry(string $ip, string $reason = 'SQL injection attack', int $age = 0): array {
  $at = time() - $age;
  return ['ip' => $ip, 'record_local' => true, 'evidence' => $at, 'reporting' => [Evidence::incident($ip, $at, 'fixture', $reason)]];
}
function ar_submit(array $entry, array $options): void {
  $GLOBALS['gr_abuse_members'] = [$entry['ip']];
  Reporter::confirmed(new Client(str_repeat('c', 40), str_repeat('a', 32)), $options, '', [$entry]);
}
global $wpdb;
$original = get_option(Config::SITE_OPTION, []);
if (is_multisite()) {
  $fixture = wp_insert_site(['domain' => 'abuse-fixture.test', 'path' => '/']);
  switch_to_blog((int) $fixture);
  \WPCF\FirewallSync\Plugin::run_site_activation();
}
Reporter::create_tables(); AuditLog::create_table(); BlockLogger::create_table();
foreach ([Reporter::TABLE, Reporter::LINKS, Reporter::STATE] as $name) { $wpdb->query('TRUNCATE TABLE ' . $wpdb->base_prefix . $name); }
$options = ['configuration_source' => 'site', 'cloudflare_mode' => 'zone_access_rules', 'cloudflare_zone_id' => str_repeat('a',32), 'cloudflare_api_token' => str_repeat('c',40),
  'abuseipdb_api_key' => str_repeat('a',80), 'abuseipdb_enabled' => '1', 'abuseipdb_mode' => 'immediate'];
update_option(Config::SITE_OPTION, $options);
\WPCF\FirewallSync\Admin\Fields::register_settings();
global $wp_settings_fields;
ob_start(); ($wp_settings_fields['firewall-sync-settings']['firewall_sync_main_section']['abuseipdb']['callback'])(); $html=ob_get_clean();
ar_ok(!str_contains($html,$options['abuseipdb_api_key']) && str_contains($html,'Unavailable'),'Settings never render the API credential and show unavailable limits');
$entry = ar_entry('8.8.1.1'); $id = $entry['reporting'][0]['incident'];
$GLOBALS['gr_abuse_cf_failure'] = true;
ar_submit($entry, $options); ar_ok(ar_calls() === 0 && ar_status($id) === '', 'Failed or unresolved Cloudflare membership cannot queue or report');
$GLOBALS['gr_abuse_cf_failure'] = false;
$GLOBALS['gr_abuse_members'] = [];
Reporter::confirmed(new Client(str_repeat('c',40),str_repeat('a',32)),$options,'',[$entry]);
ar_ok(ar_calls() === 0, 'Absent Cloudflare membership cannot report');
foreach (['', 'Unknown', 'Wordfence historical WAF block: 10 events', 'Generic WAF block', 'Generic 404 request', 'blocked access via country blocking', 'Possible SQL injection','Potential SQL injection','Could be SQL injection','SQL injection?'] as $reason) {
  ar_ok(Evidence::classify($reason) === null, 'Unsupported reason skipped: ' . $reason);
}
foreach (['SQL injection attack' => 16, 'Remote code execution attempt' => 21, 'Web spam detected' => 10, 'Comment spam detected' => 12,
  "Exceeded the maximum number of login failures which is: 5. The last username they tried to sign in with was: 'private-user'" => 18,
  'Exceeded the maximum number of requests per minute for crawlers.' => 19] as $reason => $category) {
  ar_ok((int) Evidence::classify($reason)['categories'] === $category, 'Evidence category ' . $category);
}
$lease = new DestinationLease('abuse-fixture-log'); $lease->acquire();
try {
  BlockLogger::log($entry['ip'], 'sync: SQL injection attack');
  AuditLog::record($entry['ip'], 'success', 'Cloudflare membership confirmed', $entry['evidence']);
  AuditLog::record($entry['ip'], 'skip', 'unrelated event', $entry['evidence'] - 100);
} finally { $lease->release(); }
ar_submit($entry,$options);
ar_ok(ar_calls() === 1 && ar_status($id) === 'accepted', 'Immediate report requires explicit accepted response');
$payload = $GLOBALS['gr_abuse_requests'][0][1]['body'];
ar_ok($payload['categories'] === '16' && !str_contains($payload['comment'],'private-user') && isset($payload['timestamp']), 'Bounded fixed evidence and source timestamp; no usernames or URLs');
ar_ok(BlockLogger::count(['ip'=>'8.8.1.1'])===1 && count(BlockLogger::get_logs(20,0,['ip'=>'8.8.1.1'],'ip','ASC'))===1 && count(BlockLogger::get_logs(20,0,['ip'=>'8.8.1.1'],'ip','DESC'))===1,'Synchronization log counts and IP sorting support unset date filters under strict SQL modes');
$logs = BlockLogger::get_logs(); $log = array_values(array_filter($logs, static fn ($row) => $row['ip'] === '8.8.1.1'))[0];
ar_ok(str_starts_with(Reporter::reported($log,false),'Yes'), 'Synchronization Reported column follows the accepted incident');
$audit = AuditLog::get_logs(20,0,['ip'=>'8.8.1.1']);
ar_ok(count($audit) === 1 && $audit[0]['event_count'] === 2 && str_starts_with(Reporter::reported($audit[0],true),'Yes (1'), 'Audit aggregation intact and shows linked incident count');
ar_submit($entry,$options); Reporter::daily();
ar_ok(ar_calls() === 1, 'Persistent same-incident deduplication');
if (is_multisite()) {
  $other=wp_insert_site(['domain'=>'abuse-shared.test','path'=>'/']);
  switch_to_blog((int)$other);
  try {
    \WPCF\FirewallSync\Plugin::run_site_activation();
    update_option(Config::SITE_OPTION,$options);
    ar_submit($entry,$options);
    ar_ok(ar_calls()===1,'Shared source incident is not duplicated by another owning site');
    $unowned=ar_entry('8.8.9.1');$unowned['record_local']=false; ar_submit($unowned,$options);
    ar_ok(ar_status($unowned['reporting'][0]['incident'])==='','Unattributed shared IP evidence cannot report');
    update_site_option(Config::NETWORK_OPTION,$options);
    delete_option(Config::SITE_OPTION);
    Config::update_site_options(['configuration_source'=>'site','cloudflare_mode'=>'zone_access_rules','cloudflare_zone_id'=>str_repeat('b',32)]);
    ar_ok(empty(Config::get_site_options()['abuseipdb_api_key']),'Independent site override cannot inherit the network reporting key');
    delete_site_option(Config::NETWORK_OPTION);
  } finally { restore_current_blog(); }
}
$busy=ar_entry('8.8.9.2');
$dispatch=new DestinationLease('abuseipdb-reporting');$dispatch->acquire();
try { ar_submit($busy,$options); } finally { $dispatch->release(); }
ar_ok(ar_status($busy['reporting'][0]['incident'])==='pending','Busy dispatcher cannot drop confirmed pending evidence');
// Keep this fixture pending until the later concurrent bulk worker.
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->base_prefix}wpcf_abuse_incidents SET retry_at=%d WHERE incident=%s",time()+3600,$busy['reporting'][0]['incident']));
$options['abuseipdb_mode']='bulk'; update_option(Config::SITE_OPTION,$options); ar_submit($entry,$options); Reporter::daily();
ar_ok(ar_calls() === 1,'Mode switching preserves accepted deduplication');
$bulk = ar_entry('8.8.1.2'); ar_submit($bulk,$options);
ar_ok(ar_status($bulk['reporting'][0]['incident']) === 'pending','Bulk evidence remains pending until daily worker');
$wpdb->delete($wpdb->base_prefix.Reporter::STATE,['state_key'=>'fixture-member:8.8.1.2']);
$GLOBALS['gr_abuse_members']=[];
Reporter::daily(); ar_ok(ar_calls()===1 && ar_status($bulk['reporting'][0]['incident'])==='pending','Removed Cloudflare member is never submitted from a historical confirmation');
$GLOBALS['gr_abuse_members']=['8.8.1.2'];
Reporter::daily(); Reporter::daily();
ar_ok(ar_calls()===2 && ar_status($bulk['reporting'][0]['incident'])==='accepted','One daily official multipart bulk-report with acknowledged count');
$csv=$GLOBALS['gr_abuse_requests'][1][1]['body'];
ar_ok(str_contains($csv,'IP,Categories,ReportDate,Comment') && str_contains($csv,'name="csv"') && strlen($csv)<8*1024*1024,'Official CSV and multipart format');
$next=ar_entry('8.8.1.3'); ar_submit($next,$options); Reporter::daily();
ar_ok(ar_calls()===2 && ar_status($next['reporting'][0]['incident'])==='pending','Daily attempt persists and preserves further pending evidence');
$options['abuseipdb_mode']='immediate'; update_option(Config::SITE_OPTION,$options); Reporter::drain($options,false);
ar_ok(ar_calls()===3,'Mode switch drains eligible pending evidence exactly once');
foreach (['rejected'=>'rejected','transport'=>'uncertain','server'=>'uncertain','malformed'=>'uncertain','429'=>'pending'] as $mode=>$expected) {
  $GLOBALS['gr_abuse_response_mode']=$mode;
  $case=ar_entry('8.8.2.'.(count($GLOBALS['gr_abuse_requests'])+1));
  ar_submit($case,$options);
  ar_ok(ar_status($case['reporting'][0]['incident'])===$expected,'Mock response '.$mode.' is '.$expected.' and never accepted; actual='.ar_status($case['reporting'][0]['incident']));
  if ($expected==='uncertain') { $before=ar_calls(); Reporter::drain($options,false); ar_ok(ar_calls()===$before,'Uncertain responses are never blindly replayed'); }
}
$limits=Reporter::limits($options,false);
ar_ok($limits['limit']===1000 && $limits['remaining']===999 && $limits['reset']>time() && $limits['observed_at']>0 && $limits['retry_at']>=time()+1700,'Separate limits from real report response headers with observation and Retry-After');
$bulk_limits=Reporter::limits($options,true); ar_ok(isset($bulk_limits['observed_at']),'Bulk limit observation retained independently');
$absent=Reporter::response_limits(['headers'=>[]]); ar_ok($absent['limit']===null && $absent['remaining']===null && $absent['reset']===null,'Missing headers are Unavailable');
// Unblock the mock rate limit only in this disposable fixture to test later cases.
$state=$wpdb->base_prefix.Reporter::STATE;
$wpdb->query("DELETE FROM {$state} WHERE state_key NOT LIKE 'fixture-%'");
$GLOBALS['gr_abuse_response_mode']='accepted';
$manual_ip='8.8.3.1';$GLOBALS['gr_abuse_members']=[$manual_ip]; $client=new Client(str_repeat('c',40),str_repeat('a',32));
$before=ar_calls(); Reporter::manual($client,$options,'',$manual_ip,'SQL injection attack observed');
ar_ok(ar_calls()===$before,'Manual reporting defaults off');
$options['abuseipdb_manual']='1'; update_option(Config::SITE_OPTION,$options);
Reporter::manual($client,$options,'',$manual_ip,'manual'); ar_ok(ar_calls()===$before,'Missing manual attack evidence cannot report');
Reporter::manual($client,$options,'',$manual_ip,'SQL injection attack observed');
ar_ok(ar_calls()===$before+1,'Manual reporting requires opt-in, attack evidence and confirmed membership');
unset($client);
$expired=ar_entry('8.8.3.2','SQL injection attack',30*DAY_IN_SECONDS+1);
ar_ok($expired['reporting'][0]===null,'Evidence older than 30 days cannot be resurrected');
// Queue a second incident for an already attempted IP: global same-IP restriction.
$new=ar_entry('8.8.3.1','Remote code execution attempt'); ar_submit($new,$options);
ar_ok(ar_status($new['reporting'][0]['incident'])==='pending','Independent incident is durable pending during same-IP cooldown; actual='.ar_status($new['reporting'][0]['incident']));
// Configuration changes cannot reroute pending reporting evidence.
$options['abuseipdb_mode']='bulk'; update_option(Config::SITE_OPTION,$options);
$stale=ar_entry('8.8.3.3'); ar_submit($stale,$options);
$options['cloudflare_zone_id']=str_repeat('b',32); update_option(Config::SITE_OPTION,$options);
$before=ar_calls(); Reporter::daily(); ar_ok(ar_calls()===$before,'Pending evidence cannot cross effective destination changes');
$options['cloudflare_zone_id']=str_repeat('a',32); update_option(Config::SITE_OPTION,$options);
// Two independent PHP workers race the same durable bulk claim.
$wpdb->query("DELETE FROM {$state} WHERE state_key NOT LIKE 'fixture-%'");
ar_ok(DestinationLease::fencing_leases()===[],'Parent releases destination leases before independent workers');
$before=ar_calls(); $processes=[];
foreach ([1,2] as $worker) {
  $processes[]=proc_open([PHP_BINARY,__DIR__.'/abuseipdb-worker.php',rtrim(ABSPATH,'/'),(string)get_current_blog_id()], [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
  $worker_pipes[]=$pipes;
}
foreach ($processes as $index=>$process) {
  $stdout=stream_get_contents($worker_pipes[$index][1]);$stderr=stream_get_contents($worker_pipes[$index][2]);
  fclose($worker_pipes[$index][1]);fclose($worker_pipes[$index][2]);
  ar_ok(proc_close($process)===0,'Concurrent worker completed: '.trim($stdout.$stderr));
}
ar_ok(ar_calls()===$before+1,'Concurrent '.(is_multisite()?'multisite':'single-site').' workers submit one daily bulk attempt; actual='.(ar_calls()-$before));
Reporter::daily(); ar_ok(ar_calls()===$before+1,'Restarted worker retains daily attempt and deduplication');
// A changed old record is expired, rather than replayed, even after modes switch.
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->base_prefix}wpcf_abuse_incidents SET evidence_at=%d,status='pending' WHERE incident=%s",time()-31*DAY_IN_SECONDS,$new['reporting'][0]['incident']));
Reporter::daily(); ar_ok(ar_status($new['reporting'][0]['incident'])==='expired','Pending expiry is durable at the 30-day boundary');
if (class_exists('wfBlock')) {
  $options['abuseipdb_mode']='immediate'; update_option(Config::SITE_OPTION,$options);
  wfBlock::createIP('SQL injection attack observed', '8.8.5.1', wfBlock::DURATION_FOREVER);
  $GLOBALS['gr_abuse_members']=[];
  $GLOBALS['gr_abuse_response_mode']='transport';
  $before=ar_calls();
  ar_ok(\WPCF\FirewallSync\Services\SyncScheduler::run_now(), 'Actual Wordfence synchronization retains Cloudflare success with reporting enabled');
  ar_ok(ar_calls()>$before, 'Actual scheduler dispatches reporting only after enforcement persistence');
  ar_ok($wpdb->get_var("SELECT status FROM {$wpdb->base_prefix}wpcf_abuse_incidents WHERE ip='8.8.5.1'")==='uncertain','Reporting transport failure does not interrupt Cloudflare enforcement');
  $GLOBALS['gr_abuse_response_mode']='accepted';
  wfBlock::createIP('Remote code execution attack observed','8.8.5.2',wfBlock::DURATION_FOREVER,time()-20);
  ar_ok(\WPCF\FirewallSync\Services\SyncScheduler::run_now(), 'Independent new Wordfence incident synchronizes after reporting failure');
  ar_ok($wpdb->get_var("SELECT status FROM {$wpdb->base_prefix}wpcf_abuse_incidents WHERE ip='8.8.5.2'")==='accepted','Actual scheduler records accepted reporting for the new incident');
  $before=ar_calls();
  ar_ok(\WPCF\FirewallSync\Services\SyncScheduler::run_now() && ar_calls()===$before, 'Actual repeated Wordfence synchronization does not report unchanged evidence again');
  $enforcement_writes=$GLOBALS['gr_abuse_cf_writes'] ?? 0;
  wfBlock::unblockIP('8.8.5.2',false);
  wfBlock::createIP('SQL injection attack observed','8.8.5.2',wfBlock::DURATION_FOREVER,time()-10);
  ar_ok(\WPCF\FirewallSync\Services\SyncScheduler::run_now() && ($GLOBALS['gr_abuse_cf_writes'] ?? 0)===$enforcement_writes, 'New source incident preserves unchanged Cloudflare enforcement without another provider write');
  ar_ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->base_prefix}wpcf_abuse_incidents WHERE ip='8.8.5.2' AND categories='16' AND status='pending'")===1,'New incident for a confirmed existing Cloudflare member is durable during cooldown');
  $wpdb->delete($state,['state_key'=>'ip:'.hash('sha256','8.8.5.2')]);
  Reporter::daily();
  ar_ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->base_prefix}wpcf_abuse_incidents WHERE ip='8.8.5.2' AND status='accepted'")===2,'Independent incident can report after same-IP cooldown without another Cloudflare write');
  $original_sync=array_values(array_filter(BlockLogger::get_logs(100), static fn($row)=>$row['ip']==='8.8.5.2'))[0];
  ar_ok(Reporter::reported($original_sync,false)==='Yes (1 incident)','Older synchronization event does not borrow the new incident reporting status');
}
// Remove only this suite's own Wordfence and local fixtures before the existing
// matrix converts the same disposable installation to multisite.
if (class_exists('wfBlock')) {
  foreach (['8.8.5.1','8.8.5.2'] as $fixture_ip) { wfBlock::unblockIP($fixture_ip, false); }
}
$cleanup_lease=new DestinationLease('abuse-fixture-cleanup');$cleanup_lease->acquire();
try {
  AtomicFence::run(static function (): bool {
    global $wpdb;
    foreach (['8.8.1.1','8.8.5.1','8.8.5.2'] as $fixture_ip) {
      BlockLogger::remove($fixture_ip);
      \WPCF\FirewallSync\Services\BlockOwnership::remove($fixture_ip, \WPCF\FirewallSync\Services\BlockOwnership::OWNER_WORDFENCE);
      $wpdb->delete($wpdb->prefix.AuditLog::TABLE,['ip'=>$fixture_ip]);
    }
    return true;
  },[$wpdb->prefix.BlockLogger::TABLE,$wpdb->prefix.AuditLog::TABLE,$wpdb->prefix.\WPCF\FirewallSync\Services\BlockOwnership::TABLE]);
} finally { $cleanup_lease->release(); }

// Cross-credential regression: identical evidence, independent API accounts.
// All requests are intercepted by the existing mock HTTP fixture.
$cross_ip = '8.8.6.9';
$cross_entry = ar_entry($cross_ip);
$cross_original = $cross_entry['reporting'][0]['incident'];

$cross_a = $options;
$cross_a['abuseipdb_api_key'] = str_repeat('d', 80);
$cross_a['abuseipdb_mode'] = 'immediate';

$cross_b = $cross_a;
$cross_b['abuseipdb_api_key'] = str_repeat('e', 80);

$cross_derived = hash(
  'sha256',
  'credential-scoped' . "\0" . $cross_original . "\0" .
  hash('sha256', $cross_b['abuseipdb_api_key'])
);

$GLOBALS['gr_abuse_response_mode'] = 'accepted';

$cross_lease = new DestinationLease('abuse-cross-credential');
$cross_lease->acquire();
try {
  BlockLogger::log($cross_ip, 'sync: SQL injection attack');
  AuditLog::record(
    $cross_ip,
    'success',
    'Cloudflare membership confirmed',
    $cross_entry['evidence']
  );
} finally {
  $cross_lease->release();
}

$cross_before = ar_calls();

update_option(Config::SITE_OPTION, $cross_a);
ar_submit($cross_entry, $cross_a);

ar_ok(
  ar_status($cross_original) === 'accepted' &&
  ar_calls() === $cross_before + 1,
  'First credential receives accepted incident receipt'
);

update_option(Config::SITE_OPTION, $cross_b);
ar_submit($cross_entry, $cross_b);

ar_ok(
  ar_status($cross_derived) === 'pending' &&
  ar_status($cross_original) === 'accepted',
  'Second credential retains an independent pending receipt during IP cooldown'
);

// Simulate passage of the cooldown without waiting or changing production data.
$cross_state = $wpdb->base_prefix . Reporter::STATE;
$wpdb->delete(
  $cross_state,
  ['state_key' => 'ip:' . hash('sha256', $cross_ip)]
);

Reporter::drain($cross_b, false);

ar_ok(
  ar_status($cross_derived) === 'accepted' &&
  ar_calls() === $cross_before + 2,
  'Second credential independently receives accepted receipt'
);

$cross_table = $wpdb->base_prefix . Reporter::TABLE;
$cross_rows = $wpdb->get_results(
  $wpdb->prepare(
    "SELECT incident,api_scope,status FROM {$cross_table} WHERE ip=%s",
    $cross_ip
  ),
  ARRAY_A
);

ar_ok(
  count($cross_rows) === 2 &&
  count(array_unique(array_column($cross_rows, 'api_scope'))) === 2 &&
  count(array_filter(
    $cross_rows,
    static fn($row) => $row['status'] === 'accepted'
  )) === 2,
  'Different API credentials retain two independent accepted receipts'
);

$cross_links = $wpdb->base_prefix . Reporter::LINKS;
$cross_linked = $wpdb->get_col(
  $wpdb->prepare(
    "SELECT DISTINCT incident FROM {$cross_links}
     WHERE incident IN (%s,%s)",
    $cross_original,
    $cross_derived
  )
);

ar_ok(
  count(array_unique($cross_linked)) === 2,
  'Both credential-specific receipts retain log associations'
);

$cross_before_repeat = ar_calls();

ar_submit($cross_entry, $cross_b);
update_option(Config::SITE_OPTION, $cross_a);
ar_submit($cross_entry, $cross_a);

ar_ok(
  ar_calls() === $cross_before_repeat &&
  (int) $wpdb->get_var(
    $wpdb->prepare(
      "SELECT COUNT(*) FROM {$cross_table} WHERE ip=%s",
      $cross_ip
    )
  ) === 2,
  'Repeated submissions under either credential do not duplicate incidents'
);

echo "CROSS_CREDENTIAL_REGRESSION_PASS\n";

echo 'ABUSEIPDB MOCK-ONLY SUITE PASS; no real reporting; HTTP requests='.ar_calls()."\n";
if (is_multisite()) { restore_current_blog(); } else { update_option(Config::SITE_OPTION,$original); }
