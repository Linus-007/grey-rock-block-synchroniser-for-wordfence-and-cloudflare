<?php

declare(strict_types=1);

use WPCF\FirewallSync\Plugin;
use WPCF\FirewallSync\Services\BlockOwnership;

if (!defined('ABSPATH')) {
  fwrite(STDERR, "FAIL: WordPress is not loaded.\n");
  exit(1);
}

function ownership_migration_fail(string $message): never {
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

function ownership_migration_assert(
  bool $condition,
  string $message
): void {
  if (!$condition) {
    ownership_migration_fail($message);
  }
}

global $wpdb;

$version = Plugin::get_version();
$stored_version = get_option('firewall_sync_version');

ownership_migration_assert(
  is_string($stored_version) && $stored_version === $version,
  "Stored Grey Rock migration version {$stored_version} does not match installed version {$version}."
);

$table = $wpdb->prefix . BlockOwnership::TABLE;

$found = $wpdb->get_var(
  $wpdb->prepare(
    'SHOW TABLES LIKE %s',
    $table
  )
);

ownership_migration_assert(
  $found === $table,
  "Ownership table was not created: {$table}"
);

$columns = $wpdb->get_col(
  "SHOW COLUMNS FROM `{$table}`",
  0
);

foreach ([
  'ip',
  'owner',
  'created_at',
] as $required_column) {
  ownership_migration_assert(
    in_array($required_column, $columns, true),
    "Ownership table is missing column: {$required_column}"
  );
}

$indexes = $wpdb->get_results(
  "SHOW INDEX FROM `{$table}`",
  ARRAY_A
);

$primary_columns = [];

foreach ($indexes as $index) {
  if (($index['Key_name'] ?? '') !== 'PRIMARY') {
    continue;
  }

  $sequence = (int) ($index['Seq_in_index'] ?? 0);
  $column = (string) ($index['Column_name'] ?? '');

  if ($sequence > 0 && $column !== '') {
    $primary_columns[$sequence] = $column;
  }
}

ksort($primary_columns);

ownership_migration_assert(
  array_values($primary_columns) === ['ip', 'owner'],
  'Ownership table does not have the required composite primary key (ip, owner).'
);

echo "PASS: Grey Rock migration state matches the installed version and includes the expected ownership table.\n";
