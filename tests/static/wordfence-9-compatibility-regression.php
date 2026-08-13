<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function fail_test(string $message): never {
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

function assert_contains(
  string $needle,
  string $haystack,
  string $message
): void {
  if (strpos($haystack, $needle) === false) {
    fail_test($message);
  }
}

function assert_not_contains(
  string $needle,
  string $haystack,
  string $message
): void {
  if (strpos($haystack, $needle) !== false) {
    fail_test($message);
  }
}

$scheduler_path =
  $root . '/src/includes/Services/SyncScheduler.php';

$reader_path =
  $root . '/src/includes/Services/HistoricalBlockReader.php';

$readme_path =
  $root . '/README.md';

$wordpress_readme_path =
  $root . '/readme.txt';

$scheduler = file_get_contents($scheduler_path);
$reader = file_get_contents($reader_path);
$readme = file_get_contents($readme_path);
$wordpress_readme = file_get_contents(
  $wordpress_readme_path
);

if (
  $scheduler === false
  || $reader === false
  || $readme === false
  || $wordpress_readme === false
) {
  fail_test(
    'Required source or documentation file could not be read.'
  );
}

assert_contains(
  '\\wfBlock::ipBlocks(true)',
  $scheduler,
  'SyncScheduler does not use wfBlock::ipBlocks(true).'
);

assert_not_contains(
  'wfBlock::getBlocks',
  $scheduler,
  'Removed wfBlock::getBlocks() remains in executable code.'
);

assert_contains(
  '$block->ip',
  $scheduler,
  'SyncScheduler does not read wfBlock->ip.'
);

assert_contains(
  '$block->reason',
  $scheduler,
  'SyncScheduler does not read wfBlock->reason.'
);

assert_contains(
  '$block->expiration',
  $scheduler,
  'SyncScheduler does not read wfBlock->expiration.'
);

assert_contains(
  '\\wfBlock::DURATION_FOREVER',
  $scheduler,
  'Permanent Wordfence block handling is missing.'
);

assert_contains(
  "\\wfDB::networkTable('wfHits')",
  $reader,
  'HistoricalBlockReader does not use Wordfence table resolution.'
);

assert_not_contains(
  "\$wpdb->base_prefix . 'wfhits'",
  $reader,
  'HistoricalBlockReader still hard-codes lowercase wfhits.'
);

assert_contains(
  'supports the current Wordfence release only',
  $readme,
  'README current-release-only support policy is missing.'
);

assert_contains(
  'supports only the current Wordfence release',
  $wordpress_readme,
  'WordPress readme current-release-only policy is missing.'
);

if (!defined('HOUR_IN_SECONDS')) {
  define('HOUR_IN_SECONDS', 3600);
}

if (!defined('ARRAY_A')) {
  define('ARRAY_A', 'ARRAY_A');
}

if (!class_exists('wfDB')) {
  final class wfDB {
    public static function networkTable(
      $table,
      $applyCaseConversion = true
    ) {
      if ($table !== 'wfHits') {
        fail_test(
          'HistoricalBlockReader requested the wrong table.'
        );
      }

      return 'wp_wfHits';
    }
  }
}

final class WordfenceDatabaseFake {
  public array $queries = [];

  public function esc_like(string $value): string {
    return $value;
  }

  public function prepare(
    string $query,
    ...$values
  ): string {
    $this->queries[] = $query;

    return $query;
  }

  public function get_var(string $query): string {
    $this->queries[] = $query;

    return 'wp_wfHits';
  }

  public function get_results(
    string $query,
    string $format
  ): array {
    $this->queries[] = $query;

    return [];
  }
}

require_once (
  $root . '/src/includes/Services/IpValidator.php'
);

require_once (
  $root
  . '/src/includes/Services/HistoricalBlockReader.php'
);

$wpdb = new WordfenceDatabaseFake();

$candidates =
  \WPCF\FirewallSync\Services\HistoricalBlockReader::
    get_candidates(
      24,
      1
    );

if ($candidates !== []) {
  fail_test(
    'HistoricalBlockReader returned unexpected candidates.'
  );
}

$query_text = implode("\n", $wpdb->queries);

assert_contains(
  'FROM wp_wfHits',
  $query_text,
  'Historical query did not use the resolved wfHits table.'
);

echo "Wordfence 9 compatibility regression: PASS\n";
