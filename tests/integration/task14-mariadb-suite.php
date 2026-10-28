<?php
/** Orchestrate two PHP processes in the existing disposable Compose CLI image. */
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');
if (!extension_loaded('mysqli')) { fwrite(STDERR, "UNVERIFIED: mysqli unavailable.\n"); exit(77); }
if (getenv('GREY_ROCK_TASK13_DISPOSABLE') !== 'YES') { exit(2); }
$root = $argv[1] ?? '/var/www/html';
$scenario = $argv[2] ?? 'all';
$expiry = $argv[3] ?? 'forced';
$cases = ['ownership-add-manual', 'ownership-add-scheduled', 'ownership-remove',
  'log-permanent-success', 'log-expiring-success', 'log-permanent-failure',
  'log-expiring-transient', 'log-remove', 'site-reset-set-existing',
  'site-reset-create', 'site-reset-clear', 'network-reset-set', 'network-reset-clear',
  'network-manual-add', 'network-manual-remove', 'scheduler-status',
  'cleanup-final-row-delete', 'reconcile-reset'];
// Run independent progress first: shared-lock regressions should fail early.
$scenarios = ['independent', 'stale', 'locked', 'rollback', 'expiry', 'unknown', 'unknown-rollback', 'unknown-expiry', 'connection-loss', 'unknown-connection-loss'];
if (!in_array($expiry, ['forced', 'real'], true) || ($scenario !== 'all' && !in_array($scenario, $scenarios, true))) { exit(2); }
if (isset($argv[4])) {
  if (!in_array($argv[4], $cases, true)) { exit(2); }
  $cases = [$argv[4]];
}
$failed = false;
foreach ($scenario === 'all' ? $scenarios : [$scenario] as $mode) {
  foreach ($cases as $case) {
    $dir = sys_get_temp_dir() . '/greyrock-task14-' . bin2hex(random_bytes(12));
    if (!mkdir($dir, 0700)) { exit(2); }
    $processes = []; $codes = [];
    try {
      foreach (['A', 'B'] as $role) {
        $process = proc_open([PHP_BINARY, __DIR__ . '/task13-mariadb-worker.php', $root, $dir, $case, $role, $expiry, $mode],
          [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $dir . '/' . $role . '.error', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Process unavailable.'); }
        $processes[] = $process;
      }
      $limit = microtime(true) + ($expiry === 'real' ? 970 : 65);
      do {
        $running = false;
        foreach ($processes as $index => $process) {
          if (isset($codes[$index])) { continue; }
          $status = proc_get_status($process);
          if (!$status['running']) { $codes[$index] = $status['exitcode']; }
          else { $running = true; }
        }
        if (microtime(true) >= $limit) { throw new RuntimeException('Worker timeout.'); }
        usleep(10000);
      } while ($running);
      ksort($codes);
      $ok = array_values($codes) === [0, 0];
      $failed = $failed || !$ok;
      if (!$ok) {
        foreach (['A', 'B'] as $role) {
          $error_file = $dir . '/' . $role . '.error';
          if (is_file($error_file)) {
            echo 'Worker ' . $role . ': ' . trim((string) file_get_contents($error_file)) . "\n";
          }
        }
      }
      echo ($ok ? 'PASS' : 'FAIL') . " {$mode}/{$case}; exits=" . json_encode($codes) . "; expiry={$expiry}\n";
    } catch (Throwable) {
      $failed = true; echo "FAIL {$mode}/{$case}; bounded process failure\n";
    } finally {
      foreach ($processes as $process) { proc_terminate($process); proc_close($process); }
      foreach (glob($dir . '/*') as $file) { unlink($file); }
      rmdir($dir);
    }
  }
}
exit($failed ? 1 : 0);
