<?php

declare(strict_types=1);

namespace WPCF\FirewallSync {
  final class Config { public static function uses_network_options(): bool { return true; } }
}
namespace WPCF\FirewallSync\Services {
  final class ResetWatermarkStore {
    public static function clear(string $ip): bool { return true; }
  }
}
namespace {
  require_once __DIR__ . '/../fixtures/task14-unit-fence.php';
  use WPCF\FirewallSync\Services\AtomicFence;
  use WPCF\FirewallSync\Services\SyncScheduler;

  define('ARRAY_A', 'ARRAY_A');
  function __($text, $domain = null): string { return $text; }
  function is_multisite(): bool { return true; }
  function current_time($type): string { return $GLOBALS['clock']; }
  function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
  }
  // Exercise the real state comparison, state writes and append-only audit
  // identity logic. This isolated harness makes no claim about MariaDB fencing.
  final class TransitionDatabase {
    public string $prefix = 'wp_';
    public string $last_error = '';
    public array $values = [], $state = [], $history = [];
    public bool $fail_read = false, $fail_write = false, $fail_audit = false;
    public int $writes = 0;
    public function prepare($sql, ...$values): string { $this->values = $values; return $sql; }
    public function get_row($sql, $format): ?array {
      check(AtomicFence::active() && str_contains($sql, 'FOR UPDATE'), 'Comparison escaped the write fence.');
      $this->last_error = $this->fail_read ? 'injected read failure' : '';
      return $this->state[$this->prefix][$this->values[0]] ?? null;
    }
    public function delete($table, $where, $formats): int {
      unset($this->state[$this->prefix][$where['ip']]);
      return 1;
    }
    public function query($sql): int|false {
      if (str_contains($sql, 'INSERT INTO ' . $this->prefix . 'wpcf_sync_audit')) {
        if ($this->fail_audit) { return false; }
        $this->history[$this->prefix][$this->values[0]] = $this->values;
      } elseif (str_contains($sql, 'INSERT INTO ' . $this->prefix . 'wpcf_sync_blocks')) {
        if ($this->fail_write) { return false; }
        $this->writes++;
        $failed = str_contains($sql, 'LEAST(fail_count');
        $this->state[$this->prefix][$this->values[0]] = [
          'reason' => $this->values[1],
          'synced_at' => $failed ? null : $this->values[3],
          'expires_at' => $failed ? null : ($this->values[4] ?? null),
          'fail_count' => $failed ? 1 : 0,
        ];
      }
      // Retention queries are deliberately inert: historical fixtures survive.
      return 1;
    }
  }
  $clock = '2026-10-29 12:00:00';
  $wpdb = new TransitionDatabase();
  foreach (['BlockLogger', 'AuditLog', 'SyncScheduler'] as $service) {
    require dirname(__DIR__, 2) . '/src/includes/Services/' . $service . '.php';
  }
  $record = new ReflectionMethod(SyncScheduler::class, 'record_batch_results');
  $entry = ['ip' => '8.8.8.8', 'reason' => 'fixture', 'expires_at' => null, 'present' => true, 'evidence' => 100];
  $run = static function (array $overrides = [], array $failed = []) use ($record, &$entry): array {
    return $record->invoke(null, [array_replace($entry, $overrides)], $failed);
  };
  $count = static fn (): int => count($wpdb->history[$wpdb->prefix] ?? []);
  check($run() === [] && $count() === 1, 'Present membership must establish missing site state.');
  for ($cycle = 1; $cycle <= 5; $cycle++) {
    $clock = '2026-10-29 12:0' . $cycle . ':00';
    check($run(['evidence' => 100 + $cycle]) === [], 'Repeated confirmation failed.');
  }
  check($count() === 1 && $wpdb->writes === 6, 'Unchanged confirmations duplicated audit or skipped state refresh.');
  check($wpdb->state['wp_']['8.8.8.8']['synced_at'] === $clock, 'Synchronization timestamp was not refreshed.');
  $run(['reason' => " fixture\n"]);
  check($count() === 1, 'Equivalent normalized reason created a transition.');
  $entry['expires_at'] = '2026-10-30 12:00:00'; $run(); $run();
  check($count() === 2, 'Expiry transition was lost or duplicated.');
  $entry['expires_at'] = null; $run(); $run();
  check($count() === 3, 'Permanent expiry transition was lost or duplicated.');
  $entry['reason'] = 'new reason'; $run(); $run();
  check($count() === 4, 'Material reason transition was lost or duplicated.');
  $run(['ownership_changed' => true]); $run();
  check($count() === 5, 'Ownership acquisition was not audited.');
  $run(['present' => false]);
  check($count() === 6, 'Re-established remote membership was not audited.');
  $run([], ['8.8.8.8']); $run([], ['8.8.8.8']);
  check($count() === 8, 'Repeated genuine failures were suppressed.');
  $run(); $run();
  check($count() === 9, 'Failure recovery was lost or duplicated.');
  $wpdb->state['wp_']['8.8.8.8']['fail_count'] = 1;
  $run(); $run();
  check($count() === 10, 'Nonzero failure count recovery was lost.');
  $wpdb->fail_write = true;
  check($run() === ['8.8.8.8'], 'Local write failure was masked.');
  $wpdb->fail_write = false;
  $wpdb->fail_read = true;
  check($run() === ['8.8.8.8'], 'Previous-state read failure was masked.');
  $wpdb->fail_read = false; $wpdb->last_error = '';
  check($count() === 12, 'Local persistence failures were not audited.');
  $before = $count();
  $run(['record_local' => false]); $run(['record_local' => false], ['8.8.8.8']);
  check($count() === $before, 'Unattributed site received audit state.');
  // Each site sharing the IP needs its own initial success and recovery.
  $wpdb->prefix = 'wp_2_';
  $run(); $run();
  check($count() === 1, 'Shared-IP site B initial success was lost or duplicated.');
  $run([], ['8.8.8.8']); $run(); $run();
  check($count() === 3, 'Site B failure/recovery was lost or duplicated.');
  $wpdb->prefix = 'wp_'; $run();
  check($count() === $before, 'Site B state caused a false transition on site A.');
  \WPCF\FirewallSync\Services\BlockLogger::remove('8.8.8.8');
  check($count() === $before, 'State removal rewrote historical audit entries.');
  $run(); $run();
  check($count() === $before + 1, 'Recreated site state was lost or duplicated.');
  $wpdb->fail_audit = true;
  $thrown = false;
  try { $run(['present' => false]); } catch (RuntimeException) { $thrown = true; }
  check($thrown, 'Audit persistence failure was swallowed.');
  echo "Audit membership transition regression: PASS (repeated cycles, expiry, ownership, failures, recovery, multisite)\n";
}
