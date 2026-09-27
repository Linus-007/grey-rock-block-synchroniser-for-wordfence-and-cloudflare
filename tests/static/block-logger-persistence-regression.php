<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$prepared_query = '';
$prepared_values = [];
$query_result = 1;

function block_logger_fail(string $message): never {
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

function block_logger_assert(
  bool $condition,
  string $message
): void {
  if (!$condition) {
    block_logger_fail($message);
  }
}

function current_time(string $type): string {
  return '2026-09-27 12:00:00';
}

final class BlockLoggerDatabaseFake {
  public string $prefix = 'wp_';

  public function prepare(string $query, ...$values): string {
    global $prepared_query, $prepared_values;

    $prepared_query = $query;
    $prepared_values = $values;

    return $query;
  }

  public function query(string $query): int|false {
    global $query_result;

    return $query_result;
  }
}

require_once $root . '/src/includes/Services/BlockLogger.php';

use WPCF\FirewallSync\Services\BlockLogger;

$wpdb = new BlockLoggerDatabaseFake();

$ip = '176.31.182.86';

/*
 * Permanent successful synchronizations must store a genuine SQL NULL.
 * Passing PHP null through a %s placeholder causes WordPress to prepare an
 * empty string, which MariaDB may coerce to 0000-00-00 00:00:00.
 */
$result = BlockLogger::log(
  $ip,
  'sync: Test - Malicious IP',
  null
);

block_logger_assert(
  $result === true,
  'A successful permanent synchronization write was reported as failed.'
);

block_logger_assert(
  str_contains(
    $prepared_query,
    '(%s, %s, %s, %s, NULL, 0)'
  ),
  'Permanent synchronization does not insert SQL NULL for expires_at.'
);

block_logger_assert(
  str_contains(
    $prepared_query,
    'expires_at = NULL'
  ),
  'Permanent synchronization does not preserve SQL NULL on update.'
);

block_logger_assert(
  count($prepared_values) === 4,
  'Permanent synchronization still binds expires_at as a placeholder.'
);

/*
 * Expiring successful synchronizations must continue to bind the actual
 * expiration timestamp.
 */
$expiry = '2026-09-28 12:00:00';

$result = BlockLogger::log(
  $ip,
  'sync: Temporary block',
  $expiry
);

block_logger_assert(
  $result === true,
  'An expiring synchronization write was reported as failed.'
);

block_logger_assert(
  str_contains(
    $prepared_query,
    '(%s, %s, %s, %s, %s, 0)'
  ),
  'Expiring synchronization no longer binds expires_at.'
);

block_logger_assert(
  ($prepared_values[4] ?? null) === $expiry,
  'Expiring synchronization did not bind the expected expiration.'
);

/*
 * Failed permanent synchronization records have the same nullable-expiry
 * requirement.
 */
$result = BlockLogger::mark_failed(
  $ip,
  'sync: Test - Malicious IP',
  null
);

block_logger_assert(
  $result === true,
  'A failed permanent synchronization record was reported as unwritable.'
);

block_logger_assert(
  str_contains(
    $prepared_query,
    '(%s, %s, %s, NULL, NULL, 1)'
  ),
  'Failed permanent synchronization does not insert SQL NULL for expires_at.'
);

block_logger_assert(
  str_contains(
    $prepared_query,
    'expires_at = NULL'
  ),
  'Failed permanent synchronization does not preserve SQL NULL on update.'
);

block_logger_assert(
  count($prepared_values) === 4,
  'Failed permanent synchronization still binds expires_at as a placeholder.'
);

/*
 * Database failures must be visible to callers rather than silently discarded.
 */
$query_result = false;

block_logger_assert(
  BlockLogger::log(
    $ip,
    'sync: simulated database failure',
    null
  ) === false,
  'BlockLogger::log() concealed a database write failure.'
);

block_logger_assert(
  BlockLogger::mark_failed(
    $ip,
    'sync: simulated database failure',
    null
  ) === false,
  'BlockLogger::mark_failed() concealed a database write failure.'
);

echo "BlockLogger persistence regression: PASS\n";
