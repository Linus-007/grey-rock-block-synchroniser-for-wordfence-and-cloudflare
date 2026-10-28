<?php
/** Exercise the actual fenced read boundary without a database transport. */
declare(strict_types=1);
use WPCF\FirewallSync\Services\AtomicFence;
function is_multisite(): bool { return true; }
function get_current_network_id(): int { return 1; }
function maybe_unserialize($value) { return unserialize($value); }
define('ARRAY_A', 'ARRAY_A');
define('OBJECT', 'OBJECT');
function configuration_check(bool $ok, string $reason): void {
  if (!$ok) { throw new RuntimeException($reason); }
}
final class ConfigurationReadDatabase {
  public string $options = 'wp_options';
  public string $sitemeta = 'wp_sitemeta';
  public string $last_error = '';
  public array $arguments = [];
  public array $queries = [];
  public array $values = [
    'firewall_sync_options' => ['configuration_source' => 'network'],
    'firewall_sync_network_options' => ['allow_site_overrides' => false],
    'timezone_string' => 'America/Boise',
    'gmt_offset' => 5.75,
    'firewall_sync_reset_watermarks' => ['8.8.8.8' => 123],
  ];
  public bool $duplicate = false;
  public function require_innodb(string $table): void {}
  public function prepare(string $query, ...$args): string { $this->arguments = $args; return $query; }
  public function get_results(string $query, $format): array {
    $this->queries[] = $query;
    $key = $this->arguments[count($this->arguments) - 1];
    $row = ['value' => serialize($this->values[$key] ?? [])];
    return $this->duplicate ? [$row, $row] : [$row];
  }
  public function query(string $sql) { throw new RuntimeException('A configuration read attempted a mutation.'); }
}
require __DIR__ . '/../../src/includes/Services/AtomicConnection.php';
require __DIR__ . '/../../src/includes/Services/AtomicFence.php';
$wpdb = new ConfigurationReadDatabase();
$active = new ReflectionProperty(AtomicFence::class, 'active');
$configuration = new ReflectionProperty(AtomicFence::class, 'configuration');
try {
  AtomicFence::read_configuration('timezone_string', false);
  throw new LogicException('Protected read accepted an unfenced call.');
} catch (RuntimeException) {}
$active->setValue(null, true);
try {
  foreach (['firewall_sync_options' => false, 'firewall_sync_network_options' => true, 'timezone_string' => false, 'gmt_offset' => false] as $key => $network) {
    configuration_check(AtomicFence::read_configuration($key, $network) === $wpdb->values[$key], 'Configuration read lost its value or scope.');
  }
  foreach (['firewall_sync_reset_watermarks' => false, 'firewall_sync_network_reset_watermarks' => true, 'firewall_sync_network_manual_ownership' => true, 'firewall_sync_options' => true] as $key => $network) {
    $before = count($wpdb->queries); $rejected = false;
    try { AtomicFence::read_configuration($key, $network); } catch (RuntimeException) { $rejected = true; }
    configuration_check($rejected && count($wpdb->queries) === $before, 'Mutable state or incorrect scope bypassed the locked option reader.');
  }
  foreach ($wpdb->queries as $query) {
    configuration_check(str_starts_with($query, 'SELECT ') && !str_contains($query, 'FOR UPDATE'), 'Configuration-only reads blocked independent destinations.');
  }
  AtomicFence::option('firewall_sync_reset_watermarks', false);
  configuration_check(str_contains(end($wpdb->queries), 'FOR UPDATE'), 'Mutable reset reads lost their row lock.');
  $original = AtomicFence::read_configuration('firewall_sync_options', false);
  $wpdb->values['firewall_sync_options'] = ['configuration_source' => 'site'];
  configuration_check(AtomicFence::read_configuration('firewall_sync_options', false) === $original, 'Configuration scope changed within a transaction.');
  $configuration->setValue(null, []);
  $wpdb->duplicate = true; $rejected = false;
  try { AtomicFence::read_configuration('timezone_string', false); } catch (RuntimeException) { $rejected = true; }
  configuration_check($rejected, 'Ambiguous configuration was accepted.');
  $wpdb->duplicate = false; $wpdb->last_error = 'injected'; $rejected = false;
  try { AtomicFence::read_configuration('timezone_string', false); } catch (RuntimeException) { $rejected = true; }
  configuration_check($rejected, 'Failed configuration read was accepted.');
  $wpdb->last_error = '';
  foreach ([['America/Boise', new DateTimeZone('America/Boise')], ['', new DateTimeZone('+05:45')]] as [$zone, $expected]) {
    $configuration->setValue(null, []); // A fresh transaction snapshot.
    $wpdb->values['timezone_string'] = $zone;
    configuration_check(abs((new DateTimeImmutable(AtomicFence::mysql_time(), $expected))->getTimestamp() - time()) <= 1, 'Fenced time changed site-timezone semantics.');
  }
} finally { $active->setValue(null, false); $configuration->setValue(null, []); }
echo "PASS: Read-only configuration permits independent scopes; mutable state remains locked and scope-restricted.\n";
