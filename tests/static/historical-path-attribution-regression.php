<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Services {
  final class ResetWatermarkStore {
    public static function get(string $ip): int { return 0; }
  }
}
namespace {
  define('HOUR_IN_SECONDS', 3600);
  define('ARRAY_A', 'ARRAY_A');
  function wp_parse_url($url, $component) { return parse_url($url, $component); }
  function is_multisite() { return true; }
  function get_current_blog_id() { return $GLOBALS['fixture_blog']; }
  // Model core's documented closest domain/path routing, not host-only matching.
  function get_site_by_path($host, $path) {
    if ($GLOBALS['fixture_sql_error']) { $GLOBALS['wpdb']->last_error = 'injected site SQL error'; return false; }
    if ($host !== 'shared.test') { return false; }
    return (object) ['blog_id' => str_starts_with($path, '/child/') ? 2 : 1];
  }
  final class wfDB { public static function networkTable($table) { return 'wp_wfHits'; } }
  final class PathDatabase {
    public string $last_error = '';
    public array $rows = [];
    public function esc_like($v) { return $v; }
    public function prepare($q, ...$v) { return $q; }
    public function get_var($q) { return 'wp_wfHits'; }
    public function get_results($q, $format) { return $this->rows; }
  }
  require dirname(__DIR__, 2) . '/src/includes/Services/IpValidator.php';
  require dirname(__DIR__, 2) . '/src/includes/Services/HistoricalBlockReader.php';
  use WPCF\FirewallSync\Services\HistoricalBlockReader;
  function path_check($ok, $reason) { if (!$ok) { throw new RuntimeException($reason); } echo 'PASS: ' . $reason . "\n"; }
  $fixture_sql_error = false; $fixture_blog = 1; $wpdb = new PathDatabase();
  $wpdb->rows = [['event_id' => 1, 'ip_hex' => '00000000000000000000FFFF08080808', 'event_time' => time() - 1,
    'event_url' => 'https://SHARED.test/child/request', 'event_action' => 'blocked:waf']];
  path_check(HistoricalBlockReader::get_candidates(24, 1, ['shared.test']) === [], 'Parent site must not claim child-path historical evidence.');
  path_check(HistoricalBlockReader::has_active_block_evidence('8.8.8.8', time() - 10, ['shared.test']) === false, 'Parent active attribution must exclude a child-path hit.');
  $fixture_blog = 2;
  path_check(count(HistoricalBlockReader::get_candidates(24, 1, ['shared.test'])) === 1, 'Child site must retain its own historical evidence.');
  path_check(HistoricalBlockReader::has_active_block_evidence('8.8.8.8', time() - 10, ['shared.test']) === true, 'Child active attribution must retain its own hit.');
  $wpdb->rows[0]['event_url'] = 'https://shared.test/childish/request';
  path_check(HistoricalBlockReader::get_candidates(24, 1, ['shared.test']) === [], 'Path prefixes must respect the core route rather than a string prefix collision.');
  $fixture_blog = 1;
  path_check(count(HistoricalBlockReader::get_candidates(24, 1, ['shared.test'])) === 1, 'Parent retains correctly routed requests.');
  $wpdb->rows[0]['event_url'] = 'https://alias.test/request';
  path_check(count(HistoricalBlockReader::get_candidates(24, 1, ['alias.test'])) === 1, 'Uncatalogued configured aliases preserve the prior exact-host policy.');
  path_check(HistoricalBlockReader::get_candidates(24, 1, ['unrelated.test']) === [], 'Unrelated host must remain excluded.');
  $fixture_sql_error = true;
  path_check(HistoricalBlockReader::get_candidates(24, 1, ['alias.test']) === null, 'Site-routing SQL failure must invalidate desired history, not silently skip it.');
  $wpdb->last_error = '';
  path_check(HistoricalBlockReader::has_active_block_evidence('8.8.8.8', time() - 10, ['alias.test']) === null, 'Site-routing SQL failure must invalidate active attribution.');
  echo "Historical subdirectory multisite attribution regression: PASS\n";
}
