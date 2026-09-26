<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$ownership_rows = [];
$prepared_values = [];

$fields = file_get_contents(
  $root . '/src/includes/Admin/Fields.php'
);

if ($fields === false) {
  fwrite(STDERR, "FAIL: Could not read Admin/Fields.php.\n");
  exit(1);
}

function ownership_fail(string $message): never {
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

function ownership_assert(bool $condition, string $message): void {
  if (!$condition) {
    ownership_fail($message);
  }
}

function current_time(string $type): string {
  return gmdate('Y-m-d H:i:s');
}

final class OwnershipDatabaseFake {
  public string $prefix = 'wp_';

  public function prepare(string $query, ...$values): string {
    global $prepared_values;

    $prepared_values = $values;

    return $query;
  }

  public function query(string $query): int|false {
    global $ownership_rows, $prepared_values;

    if (!str_contains($query, 'INSERT INTO')) {
      return false;
    }

    $ip = (string) ($prepared_values[0] ?? '');
    $owner = (string) ($prepared_values[1] ?? '');

    $ownership_rows[$ip][$owner] = true;

    return 1;
  }

  public function delete(
    string $table,
    array $where,
    array $formats
  ): int|false {
    global $ownership_rows;

    $ip = (string) ($where['ip'] ?? '');
    $owner = (string) ($where['owner'] ?? '');

    if (isset($ownership_rows[$ip][$owner])) {
      unset($ownership_rows[$ip][$owner]);

      if ($ownership_rows[$ip] === []) {
        unset($ownership_rows[$ip]);
      }

      return 1;
    }

    return 0;
  }

  public function get_var(string $query) {
    global $ownership_rows, $prepared_values;

    $ip = (string) ($prepared_values[0] ?? '');

    if (str_contains($query, 'AND owner = %s')) {
      $owner = (string) ($prepared_values[1] ?? '');

      return isset($ownership_rows[$ip][$owner]) ? '1' : null;
    }

    return isset($ownership_rows[$ip]) ? '1' : null;
  }

  public function get_col(string $query): array {
    global $ownership_rows, $prepared_values;

    $owner = (string) ($prepared_values[0] ?? '');
    $ips = [];

    foreach ($ownership_rows as $ip => $owners) {
      if (isset($owners[$owner])) {
        $ips[] = $ip;
      }
    }

    return $ips;
  }
}

require_once $root . '/src/includes/Services/BlockOwnership.php';

use WPCF\FirewallSync\Services\BlockOwnership;

$wpdb = new OwnershipDatabaseFake();

$ip = '8.8.4.4';

ownership_assert(
  BlockOwnership::add($ip, BlockOwnership::OWNER_WORDFENCE),
  'Could not add Wordfence ownership.'
);

ownership_assert(
  BlockOwnership::has($ip, BlockOwnership::OWNER_WORDFENCE),
  'Wordfence ownership was not recorded.'
);

ownership_assert(
  BlockOwnership::add($ip, BlockOwnership::OWNER_MANUAL),
  'Could not add manual ownership.'
);

ownership_assert(
  BlockOwnership::has($ip, BlockOwnership::OWNER_MANUAL),
  'Manual ownership was not recorded.'
);

ownership_assert(
  BlockOwnership::has_any($ip),
  'The IP was not reported as owned.'
);

ownership_assert(
  BlockOwnership::get_ips_for_owner(
    BlockOwnership::OWNER_WORDFENCE
  ) === [$ip],
  'Wordfence ownership inventory is incorrect.'
);

ownership_assert(
  BlockOwnership::get_ips_for_owner(
    BlockOwnership::OWNER_MANUAL
  ) === [$ip],
  'Manual ownership inventory is incorrect.'
);

/*
 * Removing Wordfence ownership must leave manual ownership intact.
 */
ownership_assert(
  BlockOwnership::remove($ip, BlockOwnership::OWNER_WORDFENCE),
  'Could not remove Wordfence ownership.'
);

ownership_assert(
  !BlockOwnership::has($ip, BlockOwnership::OWNER_WORDFENCE),
  'Wordfence ownership remained after removal.'
);

ownership_assert(
  BlockOwnership::has($ip, BlockOwnership::OWNER_MANUAL),
  'Removing Wordfence ownership also removed manual ownership.'
);

ownership_assert(
  BlockOwnership::has_any($ip),
  'The IP lost all ownership while manual ownership remained.'
);

/*
 * Only removing the final owner should leave the IP unowned.
 */
ownership_assert(
  BlockOwnership::remove($ip, BlockOwnership::OWNER_MANUAL),
  'Could not remove manual ownership.'
);

ownership_assert(
  !BlockOwnership::has_any($ip),
  'The IP remained owned after its final owner was removed.'
);

ownership_assert(
  !BlockOwnership::add($ip, 'invalid-owner'),
  'An invalid ownership source was accepted.'
);

ownership_assert(
  !BlockOwnership::remove($ip, 'invalid-owner'),
  'An invalid ownership source was removable.'
);



ownership_assert(
  str_contains(
    $fields,
    'BlockOwnership::remove('
  ),
  'Site-scoped manual removal does not clear manual ownership.'
);

ownership_assert(
  str_contains(
    $fields,
    'BlockOwnership::OWNER_MANUAL'
  ),
  'Site-scoped manual removal does not identify the manual owner.'
);

ownership_assert(
  str_contains(
    $fields,
    'NetworkManualOwnershipStore::remove($ip)'
  ),
  'Network-scoped manual removal does not clear network manual ownership.'
);

echo "Block ownership regression: PASS\n";
