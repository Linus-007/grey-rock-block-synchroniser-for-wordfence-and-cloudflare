<?php
/**
 * Real MariaDB/InnoDB controls, NOT a test of a production fencing implementation.
 * Requires a pre-provisioned disposable schema (greyrock_task13_*), no services
 * are started. Config file path is supplied in GREY_ROCK_TASK13_DB_FILE, mode
 * 0600, JSON {host,user,password,database,port}. Never print this configuration.
 */
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');
if (!extension_loaded('mysqli')) {
  fwrite(STDERR, "UNVERIFIED: PHP mysqli unavailable.\n"); exit(77);
}
function check13(bool $value): void {
  if (!$value) { throw new RuntimeException('Assertion failed.'); }
}
function wait13(string $file): void {
  $until = microtime(true) + 6;
  while (!is_file($file)) {
    check13(microtime(true) < $until); usleep(10000);
  }
}
function connect13(): mysqli {
  $file = getenv('GREY_ROCK_TASK13_DB_FILE');
  check13(is_string($file) && is_file($file) && (fileperms($file) & 0777) === 0600);
  $config = json_decode(file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
  check13(str_starts_with($config['database'], 'greyrock_task13_'));
  $db = new mysqli($config['host'], $config['user'], $config['password'],
    $config['database'], (int) ($config['port'] ?? 3306));
  check13(str_contains($db->query('SELECT VERSION()')->fetch_row()[0], 'MariaDB'));
  $db->query('SET SESSION innodb_lock_wait_timeout=1');
  return $db;
}
function child13(string $mode, string $table, string $dir): array {
  $process = proc_open([PHP_BINARY, __FILE__, $mode, $table, $dir],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
  check13(is_resource($process));
  return [$process, $dir . '/result'];
}
function finish13(array $child): int {
  [$process, $result] = $child;
  try { wait13($result); return (int) file_get_contents($result); }
  finally { proc_terminate($process); proc_close($process); }
}
$db = null; $table = null; $dir = null;
try {
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
  $db = connect13();
  if (isset($argv[1])) {
    [$script, $mode, $table, $dir] = $argv;
    check13((bool) preg_match('/\Agr_task13_[a-f0-9]{16}\z/D', $table));
    $code = 2;
    try {
      $db->begin_transaction();
      if ($mode === 'acquire') {
        file_put_contents($dir . '/attempting', '1');
        $row = $db->query("SELECT owner, expires FROM `$table` WHERE id=1 FOR UPDATE")->fetch_assoc();
        $now = (float) $db->query('SELECT UNIX_TIMESTAMP(NOW(6))')->fetch_row()[0];
        check13($row['owner'] === 'A' && (float) $row['expires'] > $now);
        $db->query("UPDATE `$table` SET value=99 WHERE id=1");
      } elseif ($mode === 'replace') {
        $db->query("UPDATE `$table` SET owner='B' WHERE id=1");
      } elseif ($mode === 'independent') {
        $db->query("SELECT id FROM `$table` WHERE id=2 FOR UPDATE");
        $db->query("UPDATE `$table` SET value=77 WHERE id=2");
      } else { throw new RuntimeException('Invalid mode.'); }
      $db->commit(); $code = 0;
    } catch (Throwable $error) {
      $db->rollback();
      $code = $mode === 'replace' && $error->getCode() === 1205 ? 3 : 4;
    }
    file_put_contents($dir . '/result', (string) $code);
    exit(0);
  }
  $table = 'gr_task13_' . bin2hex(random_bytes(8));
  $dir = sys_get_temp_dir() . '/' . $table;
  check13(mkdir($dir, 0700));
  $db->query("CREATE TABLE `$table` (id INT PRIMARY KEY, owner CHAR(1), expires DOUBLE, value INT, pending VARCHAR(64)) ENGINE=InnoDB");
  $db->query("INSERT INTO `$table` VALUES (1,'A',UNIX_TIMESTAMP(NOW(6))+60,0,'durable-receipt'),(2,'A',UNIX_TIMESTAMP(NOW(6))+60,0,'')");
  $db->begin_transaction();
  $db->query("SELECT id FROM `$table` WHERE id=1 FOR UPDATE");
  check13(finish13(child13('replace', $table, $dir)) === 3);
  unlink($dir . '/result');
  echo "PASS: replacement waits on exact leased row and times out within bound.\n";
  check13(finish13(child13('independent', $table, $dir)) === 0);
  unlink($dir . '/result');
  $db->rollback();
  check13((int) $db->query("SELECT value FROM `$table` WHERE id=2")->fetch_row()[0] === 77);
  echo "PASS: independent scope row progresses while another scope is locked.\n";
  // Lease deadline elapses while a real locking read waits for acquisition.
  $db->query("UPDATE `$table` SET expires=UNIX_TIMESTAMP(NOW(6))+0.3 WHERE id=1");
  $db->begin_transaction();
  $db->query("SELECT id FROM `$table` WHERE id=1 FOR UPDATE");
  $child = child13('acquire', $table, $dir);
  wait13($dir . '/attempting');
  usleep(500000); $db->commit();
  check13(finish13($child) === 4);
  unlink($dir . '/result'); unlink($dir . '/attempting');
  check13((int) $db->query("SELECT value FROM `$table` WHERE id=1")->fetch_row()[0] === 0);
  echo "PASS: fresh server-clock check rejects expiry during lock acquisition.\n";
  foreach (['read', 'write'] as $failure) {
    $db->begin_transaction();
    try {
      $db->query("UPDATE `$table` SET value=88 WHERE id=1");
      if ($failure === 'read') { $db->query("SELECT absent_column FROM `$table`"); }
      else { $db->query("INSERT INTO `$table` (id) VALUES (1)"); }
      throw new RuntimeException('Expected failure missing.');
    } catch (mysqli_sql_exception) { $db->rollback(); }
    check13((int) $db->query("SELECT value FROM `$table` WHERE id=1")->fetch_row()[0] === 0);
    check13($db->query("SELECT pending FROM `$table` WHERE id=1")->fetch_row()[0] === 'durable-receipt');
    echo "PASS: failed $failure rolls back data and preserves prior pending receipt.\n";
  }
  // Deliberate counterexample: an earlier live check cannot constrain COMMIT.
  $db->query("UPDATE `$table` SET expires=UNIX_TIMESTAMP(NOW(6))+0.2 WHERE id=1");
  $db->begin_transaction();
  $db->query("SELECT id FROM `$table` WHERE id=1 FOR UPDATE");
  check13((int) $db->query("SELECT expires>UNIX_TIMESTAMP(NOW(6)) FROM `$table` WHERE id=1")->fetch_row()[0] === 1);
  $db->query("UPDATE `$table` SET value=1 WHERE id=1");
  usleep(300000); $db->commit();
  check13((int) $db->query("SELECT value=1 AND expires<UNIX_TIMESTAMP(NOW(6)) FROM `$table` WHERE id=1")->fetch_row()[0] === 1);
  echo "COUNTEREXAMPLE: lock plus precommit deadline check alone does not enforce expiry at commit.\n";
  $db->query("DROP TABLE `$table`"); $table = null;
  rmdir($dir); $dir = null;
  echo "CONTROLS ONLY: production atomic fencing is not certified.\n";
} catch (Throwable) {
  if ($db instanceof mysqli && $table && !isset($argv[1])) {
    try { $db->rollback(); $db->query("DROP TABLE `$table`"); } catch (Throwable) {}
  }
  fwrite(STDERR, "FAIL: MariaDB control or prerequisite failed; details withheld.\n"); exit(1);
}
