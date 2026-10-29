<?php

declare(strict_types=1);

// Reuse the existing reset, IP-decoding and attribution harness and assertions.
require __DIR__ . '/historical-block-evidence-regression.php';
require $root . '/src/includes/Services/AuditLog.php';

use WPCF\FirewallSync\Services\AuditLog;
use WPCF\FirewallSync\Services\BlockLogger;
use WPCF\FirewallSync\Services\HistoricalBlockReader;
use WPCF\FirewallSync\Services\SyncScheduler;

final class CoverageDatabase {
  public string $prefix = 'wp_';
  public string $last_error = '';
  public array $rows = [];
  public array $values = [];
  public array $history = [];
  public array $state = [];
  public bool $fail_audit = false;
  public bool $read_failure = false;
  public function esc_like($value) { return $value; }
  public function prepare($sql, ...$values) { $this->values = $values; return $sql; }
  public function get_var($sql) { return str_contains($sql, 'SHOW TABLES') ? 'wp_wfHits' : null; }
  public function get_row($sql, $format) {
    $values = $this->state[$this->values[0]] ?? null;
    return $values === null ? null : [
      'reason' => $values[1], 'synced_at' => $values[3],
      'expires_at' => $values[4] ?? null, 'fail_count' => 0,
    ];
  }
  public function get_results($sql, $format) {
    maintenance_assert(str_contains($sql, 'action IN (%s, %s)'), 'Both Wordfence actions must be selected.');
    maintenance_assert(array_slice($this->values, 0, 2) === ['blocked:waf', 'blocked:wordfence'], 'Wrong historical actions.');
    if ($this->read_failure) { $this->last_error = 'injected SQL failure'; return []; }
    return array_values(array_filter($this->rows, fn ($r) => in_array($r['event_action'], array_slice($this->values, 0, 2), true) && $r['event_time'] >= $this->values[2]));
  }
  public function query($sql) {
    if (str_contains($sql, 'INSERT INTO wp_wpcf_sync_audit')) {
      if ($this->fail_audit) { return false; }
      $this->history[$this->values[0]] = $this->values;
    } elseif (str_contains($sql, 'INSERT INTO wp_wpcf_sync_blocks')) {
      $this->state[$this->values[0]] = $this->values;
    }
    return 1;
  }
  public function delete($table, $where, $formats) { unset($this->state[$where['ip']]); return 1; }
}
function current_time($type) { return gmdate('Y-m-d H:i:s'); }
function __(string $text, ?string $domain = null): string { return $text; }

$wpdb = new CoverageDatabase();
$test_options = [];
$observations = [];
$audit = static function ($ip, $outcome, $reason, $evidence) use (&$observations): void {
  $observations[] = compact('ip', 'outcome', 'reason', 'evidence');
};
foreach ([['158.101.19.62', 132, 'salus.zone'], ['3.107.195.156', 87, 'greyscale.zone'], ['34.95.3.164', 20, 'greyscale.zone']] as [$address, $count, $host]) {
  for ($i = 0; $i < $count; $i++) {
    $wpdb->rows[] = ['ip_hex' => wf_ip_hex($address), 'event_time' => $now - 100 - $i, 'event_url' => 'https://' . $host . '/private-path', 'event_action' => 'blocked:wordfence'];
  }
}
$read = HistoricalBlockReader::get_candidates(24, 1, ['greyscale.zone'], [], [], $audit);
maintenance_assert($read === [] && count($observations) === 107, 'Production reproduction must audit all 107 attributable plugin hits without inventing blocks.');
maintenance_assert(!in_array('158.101.19.62', array_column($observations, 'ip')), 'Another site leaked into the audit.');
$observations = [];
HistoricalBlockReader::get_candidates(24, 1, ['salus.zone'], [], [], $audit);
maintenance_assert(count($observations) === 132, 'All 132 salus events must receive explicit skips.');
$observations = [];
HistoricalBlockReader::get_candidates(24, 1, ['greyscale.zone'], [], ['3.107.195.156' => true], $audit);
maintenance_assert(count(array_filter($observations, fn ($r) => $r['outcome'] === 'detection')) === 87, 'Current IP authority must recognise plugin events without an independent historical expiry.');

$wpdb->rows[] = ['ip_hex' => wf_ip_hex('8.8.8.8'), 'event_time' => $now - 2, 'event_url' => 'https://greyscale.zone/path', 'event_action' => 'blocked:waf'];
$wpdb->rows[] = ['ip_hex' => wf_ip_hex('1.1.1.1'), 'event_time' => $now - 2, 'event_url' => 'https://greyscale.zone/path', 'event_action' => 'logged:waf'];
$read = HistoricalBlockReader::get_candidates(24, 1, ['greyscale.zone'], [], [], $audit);
maintenance_assert(array_column($read, 'ip') === ['8.8.8.8'], 'Only qualifying WAF evidence may create a historical lease.');
maintenance_assert(HistoricalBlockReader::get_candidates(24, 2, ['greyscale.zone']) === [], 'Threshold was lost.');
$wpdb->read_failure = true;
maintenance_assert(HistoricalBlockReader::get_candidates(24, 1, ['greyscale.zone']) === null, 'wpdb empty-array SQL failure must fail closed.');
$wpdb->read_failure = false; $wpdb->last_error = '';

AuditLog::record('8.8.8.8', 'detection', 'qualifying evidence', $now);
AuditLog::record('8.8.8.8', 'detection', 'qualifying evidence', $now);
maintenance_assert(count($wpdb->history) === 1, 'Repeated detection evidence must be idempotent.');
$record = new ReflectionMethod(SyncScheduler::class, 'record_batch_results');
$batch = [['ip' => '8.8.8.8', 'reason' => 'fixture', 'expires_at' => null, 'present' => true, 'evidence' => $now]];
maintenance_assert($record->invoke(null, $batch, []) === [], 'Confirmed membership should establish local state.');
maintenance_assert(isset($wpdb->state['8.8.8.8']) && count($wpdb->history) === 2, 'State and successful audit were not persisted separately.');
$record->invoke(null, $batch, ['8.8.8.8']);
maintenance_assert(count($wpdb->history) === 3, 'Cloudflare failure must remain in history.');
BlockLogger::remove('8.8.8.8');
maintenance_assert(count($wpdb->history) === 3 && $wpdb->state === [], 'Removing state must preserve audit history.');
$wpdb->prefix = 'wp_2_';
AuditLog::record('8.8.8.8', 'detection', 'another site', $now);
$wpdb->prefix = 'wp_';
$wpdb->fail_audit = true;
try { AuditLog::record('8.8.8.8', 'failure', 'fixture', $now); maintenance_fail('Audit write failure was swallowed.'); }
catch (RuntimeException $expected) {}
echo "Historical Wordfence coverage and audit regression: PASS\n";
