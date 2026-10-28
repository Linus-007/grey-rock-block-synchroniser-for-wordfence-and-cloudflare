<?php

declare(strict_types=1);

require __DIR__ . '/historical-block-evidence-regression.php';

use WPCF\FirewallSync\Services\HistoricalBlockReader;

final class PagedHistoryDatabase {
  public string $last_error = '';
  public array $rows = [];
  public array $values = [];
  public int $queries = 0;
  public int $fail_page = 0;
  public bool $insert_during_scan = false;
  public bool $stuck_cursor = false;
  public function esc_like($value) { return $value; }
  public function prepare($sql, ...$values) { $this->values = $values; return $sql; }
  public function get_var($sql) { return 'wp_wfHits'; }
  public function get_results($sql, $format): array {
    maintenance_assert(str_contains($sql, 'ORDER BY id DESC LIMIT 500'), 'Every history query must use bounded primary-key pagination.');
    maintenance_assert(str_contains($sql, 'AND id < %d') && !str_contains($sql, 'OFFSET'), 'Cursor must avoid OFFSET insertion/deletion races.');
    $this->queries++;
    if ($this->queries === $this->fail_page) { $this->last_error = 'injected late SQL error'; return []; }
    $cursor = $this->values[array_key_last($this->values)];
    $rows = array_values(array_filter($this->rows, static fn ($row) => $row['event_id'] < $cursor));
    usort($rows, static fn ($a, $b) => $b['event_id'] <=> $a['event_id']);
    $rows = array_slice($rows, 0, 500);
    if ($this->insert_during_scan && $this->queries === 1) {
      $new = $rows[0]; $new['event_id'] = 99999; $this->rows[] = $new;
    }
    if ($this->stuck_cursor && count($rows) === 500) { $rows[499]['event_id'] = $cursor; }
    return $rows;
  }
}
$wpdb = new PagedHistoryDatabase();
for ($id = 1; $id <= 1201; $id++) {
  $wpdb->rows[] = ['event_id' => $id, 'ip_hex' => wf_ip_hex('8.8.8.8'),
    'event_time' => $now - ($id % 50), 'event_url' => 'https://greyscale.zone/path',
    'event_action' => 'blocked:waf', 'event_description' => ''];
}
$wpdb->insert_during_scan = true;
$result = HistoricalBlockReader::get_candidates(24, 100, ['greyscale.zone']);
maintenance_assert($wpdb->queries === 3 && count($result) === 1, 'History must span three bounded pages.');
maintenance_assert($result[0]['event_count'] === 1201 && $result[0]['latest_event'] === $now, 'Page aggregation must preserve all counts and latest time despite ID/time order differences.');
$wpdb->queries = 0; $wpdb->insert_during_scan = false;
$result = HistoricalBlockReader::get_candidates(24, 100, ['greyscale.zone']);
maintenance_assert($result[0]['event_count'] === 1202, 'Concurrent newer insert must be evaluated on the next run.');
$wpdb->queries = 0; $wpdb->fail_page = 2;
maintenance_assert(HistoricalBlockReader::get_candidates(24, 1, ['greyscale.zone']) === null, 'Late empty-array SQL error must invalidate the whole desired set.');
$wpdb->last_error = ''; $wpdb->queries = 0; $wpdb->fail_page = 0;
foreach ($wpdb->rows as &$row) { $row['event_action'] = 'blocked:wordfence'; $row['event_url'] = 'https://other.zone/path'; }
unset($row);
$wpdb->rows[0]['event_url'] = 'https://greyscale.zone/path';
maintenance_assert(HistoricalBlockReader::has_active_block_evidence('8.8.8.8', $now - 100, ['greyscale.zone']) === true && $wpdb->queries === 3, 'Active attribution must search beyond the first page.');
$wpdb->queries = 0; $wpdb->fail_page = 2;
maintenance_assert(HistoricalBlockReader::has_active_block_evidence('8.8.8.8', $now - 100, ['greyscale.zone']) === null, 'Late attribution SQL failure must remain unknown, not absent.');
$wpdb->last_error = ''; $wpdb->queries = 0; $wpdb->fail_page = 0; $wpdb->stuck_cursor = true;
maintenance_assert(HistoricalBlockReader::get_candidates(24, 1, ['greyscale.zone']) === null && $wpdb->queries === 1, 'Non-advancing cursors must fail closed rather than loop.');
echo "Historical pagination, concurrent insertion and late SQL failure regression: PASS\n";
