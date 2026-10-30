<?php
/** Failure injection against the production AtomicFence and real InnoDB. */
declare(strict_types=1);
use WPCF\FirewallSync\Services\AtomicFence;
use WPCF\FirewallSync\Services\DestinationLease;
if (getenv('GREY_ROCK_TASK13_DISPOSABLE') !== 'YES' || !str_starts_with(DB_NAME, 'greyrock_task13_')) { throw new RuntimeException('Disposable database required.'); }
function fault_check(bool $ok, string $message): void {
  if (!$ok) { throw new RuntimeException($message); }
  echo 'PASS: ' . $message . "\n";
}
global $wpdb;
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$other = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$table = $wpdb->prefix . 'gr_faults';
$wpdb->query("CREATE TABLE {$table} (id INT PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB");
foreach (range(1, 20) as $id) { $wpdb->query("INSERT INTO {$table} VALUES ({$id},0)"); }
$lease = new DestinationLease('1316-faults'); $lease->acquire();
try {
  $other->begin_transaction();
  $other->query("UPDATE {$table} SET value=1 WHERE id=2");
  $rejected = false; $start = microtime(true);
  try {
    AtomicFence::run(static function () use ($table): bool {
      global $wpdb;
      $wpdb->query("UPDATE {$table} SET value=5 WHERE id=1");
      $wpdb->query("UPDATE {$table} SET value=5 WHERE id=2");
      return true;
    }, [$table]);
  } catch (RuntimeException) { $rejected = true; }
  $other->rollback();
  $wpdb->check_connection(false);
  fault_check($rejected && microtime(true) - $start < 8, 'Lock timeout rejects fenced mutation within bound');
  fault_check((int) $other->query("SELECT SUM(value) FROM {$table}")->fetch_row()[0] === 0, 'Lock timeout rolls back earlier writes');

  // The competing transaction has more undo work, making the fence the
  // inexpensive deadlock victim. Both sides hold rows before crossing locks.
  $other->begin_transaction();
  $other->query("UPDATE {$table} SET value=1 WHERE id>=2");
  $rejected = false; $deadlock_work = false;
  try {
    AtomicFence::run(static function () use ($other, $table, &$deadlock_work): bool {
      $deadlock_work = true;
      global $wpdb;
      $wpdb->query("UPDATE {$table} SET value=7 WHERE id=1");
      $other->query("UPDATE {$table} SET value=1 WHERE id=1", MYSQLI_ASYNC);
      usleep(100000);
      $wpdb->query("UPDATE {$table} SET value=7 WHERE id=2");
      return true;
    }, [$table]);
  } catch (RuntimeException) { $rejected = true; }
  $other->reap_async_query();
  $other->rollback();
  fault_check($rejected && $deadlock_work, 'Real InnoDB deadlock rejects fenced transaction');
  fault_check((int) $other->query("SELECT SUM(value) FROM {$table}")->fetch_row()[0] === 0, 'Deadlock rolls back all fenced writes');

  foreach (["SELECT 1 FROM {$table} WHERE SLEEP(10)", "UPDATE {$table} SET value=9 + SLEEP(10) WHERE id=1"] as $statement) {
    $rejected = false; $statement_work = false; $start = microtime(true);
    try {
      AtomicFence::run(static function () use ($table, $statement, &$statement_work): bool {
        global $wpdb;
        $statement_work = true;
        $wpdb->query("UPDATE {$table} SET value=8 WHERE id=1");
        // Standalone SLEEP has success-on-interruption semantics in MySQL.
        $wpdb->query($statement);
        $handle = (new ReflectionProperty($wpdb, 'handle'))->getValue($wpdb);
        echo 'Unexpected successful timeout query: errno=' . mysqli_errno($handle) . '; warnings=' . mysqli_warning_count($handle) . '; state=' . mysqli_sqlstate($handle) . "\n";
        return true;
      }, [$table]);
    } catch (RuntimeException) { $rejected = true; }
    $wpdb->check_connection(false);
    // Wait for rollback to release the row; observing an old MVCC value alone
    // would not prove that the timed-out server transaction actually ended.
    $other->query("UPDATE {$table} SET value=value WHERE id=1");
    $elapsed = microtime(true) - $start;
    fault_check($rejected && $statement_work && $elapsed < 6, 'Statement deadline rejects slow fenced work and releases locks; rejected=' . (int) $rejected . '; entered=' . (int) $statement_work . '; seconds=' . round($elapsed, 3));
    fault_check((int) $other->query("SELECT SUM(value) FROM {$table}")->fetch_row()[0] === 0, 'Statement timeout rolls back earlier writes');
  }

  $rejected = false;
  try {
    AtomicFence::run(static function () use ($other, $table): bool {
      global $wpdb;
      $wpdb->query("UPDATE {$table} SET value=9 WHERE id=1");
      $connection_id = (int) $wpdb->get_var('SELECT CONNECTION_ID()');
      $other->query('KILL CONNECTION ' . $connection_id);
      $wpdb->query('SELECT 1');
      return true;
    }, [$table]);
  } catch (RuntimeException) { $rejected = true; }
  fault_check($rejected && !AtomicFence::active(), 'Connection loss fails closed without replay');
  fault_check((int) $other->query("SELECT SUM(value) FROM {$table}")->fetch_row()[0] === 0, 'Connection loss rolls back uncommitted writes');
} finally {
  $other->rollback();
  // wpdb may reconnect only after the failed production fence has ended.
  $wpdb->check_connection(false);
  $lease->release();
  $other->query("DROP TABLE {$table}");
  $other->close();
}
echo "FAULTS PASS\n";
