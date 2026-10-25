<?php

declare(strict_types=1);

use WPCF\FirewallSync\Services\BlockOwnership;
use WPCF\FirewallSync\Services\BlockLogger;
final class ReadFailureDatabase {
  public string $prefix = 'wp_';
  public string $last_error = '';
  public bool $fail = true;
  public function prepare($query, ...$args): string { return $query; }
  public function get_var($query) { $this->last_error = $this->fail ? 'synthetic database failure' : ''; return null; }
  public function get_col($query): array { $this->last_error = $this->fail ? 'synthetic database failure' : ''; return []; }
}
$wpdb = new ReadFailureDatabase();
require dirname(__DIR__, 2) . '/src/includes/Services/BlockOwnership.php';
require dirname(__DIR__, 2) . '/src/includes/Services/BlockLogger.php';
$checks = [
  'has' => fn () => BlockOwnership::has('8.8.8.8', BlockOwnership::OWNER_MANUAL),
  'has-any' => fn () => BlockOwnership::has_any('8.8.8.8'),
  'owner-inventory' => fn () => BlockOwnership::get_ips_for_owner(BlockOwnership::OWNER_MANUAL),
  'log-inventory' => fn () => BlockLogger::get_all_ips(),
];
foreach ($checks as $name => $read) {
  if (($argv[1] ?? '') !== '' && $argv[1] !== $name) { continue; }
  $wpdb->fail = true;
  $threw = false;
  try { $read(); } catch (RuntimeException $error) { $threw = true; }
  if (!$threw) { throw new LogicException('Database failure was accepted as absence of ownership/state: ' . $name); }
  $wpdb->fail = false;
  $result = $read();
  if ($result !== false && $result !== []) { throw new LogicException('A genuinely empty successful query was rejected.'); }
  echo "PASS: Ownership/state read failure {$name}\n";
}
