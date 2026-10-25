<?php

declare(strict_types=1);

use WPCF\FirewallSync\Services\DestinationLease;

function wp_generate_uuid4(): string { return 'task11-owner'; }
function maybe_serialize($value): string { return serialize($value); }
function check(bool $ok, string $message): void {
  if (!$ok) { throw new RuntimeException($message); }
}
final class Task11Database {
  public string $base_prefix = 'wp_';
  public string $last_error = '';
  public array $calls = [];
  public int $clock = 2000000000;
  public $result = 1;
  public bool $throw_prepare = false;
  public bool $throw_query = false;
  public function prepare(string $sql, ...$values): string {
    if ($this->throw_prepare) { throw new RuntimeException('Synthetic prepare failure.'); }
    $this->calls[] = [$sql, $values];
    return 'prepared-statement';
  }
  public function query(string $sql) {
    if ($this->throw_query) { throw new RuntimeException('Synthetic query failure.'); }
    check($sql === 'prepared-statement', 'Query did not use prepare result.');
    return $this->result;
  }
  public function get_var(string $sql): int {
    check($sql === 'SELECT UNIX_TIMESTAMP()', 'Unexpected clock query.');
    return $this->clock;
  }
}
require dirname(__DIR__, 2) . '/src/includes/Services/DestinationLease.php';
$replace = new ReflectionMethod(DestinationLease::class, 'replace');
$lost = new ReflectionProperty(DestinationLease::class, 'lost');
$old = ['owner' => "old'owner", 'started_at' => 2000000000, 'pending' => "old\\pending"];
$new = ['owner' => 'new-owner', 'started_at' => 2000000450, 'pending' => "new'); DROP TABLE wp_options; --"];
$key = 'firewall_sync_destination_' . hash('sha256', 'task11-scope');
foreach ([false, true] as $live) {
  $wpdb = new Task11Database();
  $lease = new DestinationLease('task11-scope');
  $replace->invoke($lease, $old, $new, $live);
  check(count($wpdb->calls) === 1, 'Expected exactly one prepare call.');
  [$sql, $values] = $wpdb->calls[0];
  $expected_sql = 'UPDATE wp_options SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s'
    . ($live ? ' AND UNIX_TIMESTAMP() < %d' : '');
  check(preg_replace('/\s+/', ' ', trim($sql)) === $expected_sql, 'CAS or expiry predicate changed.');
  preg_match_all('/%[sd]/', $sql, $matches);
  check($matches[0] === ($live ? ['%s', '%s', '%s', '%d'] : ['%s', '%s', '%s']), 'Wrong placeholder types/order.');
  $expected_values = [serialize($new), $key, serialize($old)];
  if ($live) { $expected_values[] = 2000000900; }
  check($values === $expected_values && count($values) === count($matches[0]), 'Wrong parameter order/count.');
  check(!str_contains($sql, $new['pending']) && !str_contains($sql, $key), 'Values interpolated into SQL.');
  check(!$lost->getValue($lease), 'Successful update lost lease.');
  foreach (['zero', 'database-false', 'query-throw', 'prepare-throw', 'expired', 'clock-error'] as $failure) {
    if (!$live && in_array($failure, ['expired', 'clock-error'], true)) { continue; }
    $wpdb = new Task11Database();
    if ($failure === 'zero') { $wpdb->result = 0; }
    if ($failure === 'database-false') { $wpdb->result = false; }
    if ($failure === 'query-throw') { $wpdb->throw_query = true; }
    if ($failure === 'prepare-throw') { $wpdb->throw_prepare = true; }
    if ($failure === 'expired') { $wpdb->clock = 2000000900; }
    if ($failure === 'clock-error') { $wpdb->last_error = 'Synthetic clock read error.'; }
    $lease = new DestinationLease('task11-scope'); $rejected = false;
    try { $replace->invoke($lease, $old, $new, $live); } catch (Throwable) { $rejected = true; }
    check($rejected && $lost->getValue($lease), 'Failure did not latch lost state: ' . $failure);
  }
  echo 'PASS: require_live=' . ($live ? 'true; four' : 'false; three') . " ordered parameters; CAS/expiry SQL; failure latching\n";
}
