<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Services;

/**
 * Local-only transaction admission under current lease row locks.
 * Expiry is checked after locking and again immediately before COMMIT. Commit
 * can cross that deadline; replacement is serialized after transaction outcome.
 * No strict wall-clock commit deadline is claimed.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery
 * phpcs:disable WordPress.DB.PreparedSQL
 */
final class AtomicFence {
  private static bool $active = false;
  private static bool $failed = false;
  private static array $cache = [];

  public static function active(): bool { return self::$active; }
  public static function locked(): bool { return self::$active; }

  public static function run(callable $work, array $tables = [], bool $site_only = false) {
    global $wpdb;
    if (self::$active) {
      foreach ($tables as $table) { $wpdb->require_innodb($table); }
      $result = $work();
      if ($result === false) { self::$failed = true; }
      return $result;
    }
    $leases = SyncScheduler::fencing_leases();
    $destinations = DestinationLease::fencing_leases();
    if ((!$site_only && $destinations === []) || ($site_only && $leases === [])) {
      throw new \RuntimeException('Protected mutation requires an explicit lease.');
    }
    $leases = array_merge($leases, $destinations);
    usort($leases, static fn (array $a, array $b): int => strcmp($a['table'] . '/' . $a['key'], $b['table'] . '/' . $b['key']));
    $original = $wpdb;
    $db = new AtomicConnection($original);
    // Never START TRANSACTION over caller work: it could implicitly commit it.
    if ((int) $db->get_var('SELECT @@in_transaction') !== 0 || (int) $db->get_var('SELECT @@autocommit') !== 1) {
      throw new \RuntimeException('Fencing cannot nest in an external transaction.');
    }
    if (!str_contains((string) $db->get_var('SELECT VERSION()'), 'MariaDB')) {
      throw new \RuntimeException('This fencing implementation requires MariaDB.');
    }
    $settings = [];
    foreach (['innodb_lock_wait_timeout', 'lock_wait_timeout', 'idle_transaction_timeout', 'max_statement_time'] as $setting) {
      $value = $db->get_var('SELECT @@' . $setting);
      if (!is_numeric($value) || (float) $value < 0) { throw new \RuntimeException('Fencing session settings unavailable.'); }
      $settings[$setting] = (float) $value;
    }
    $begun = false; $committed = false;
    $deny_http = static fn () => new \WP_Error('fenced_transaction', 'HTTP is prohibited during local fenced work.');
    add_filter('pre_http_request', $deny_http, PHP_INT_MAX);
    self::$failed = false; self::$cache = [];
    try {
      $db->query('SET SESSION innodb_lock_wait_timeout = 3');
      $db->query('SET SESSION lock_wait_timeout = 3');
      $db->query('SET SESSION idle_transaction_timeout = 5');
      $db->query('SET SESSION max_statement_time = 3');
      $db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
      $db->query('START TRANSACTION'); $begun = true;
      $wpdb = $db; self::$active = true;
      $deadline = PHP_INT_MAX;
      foreach ($leases as $lease) {
        $db->require_innodb($lease['table']);
        $table = $lease['table'];
        $raw = $db->get_var($db->prepare(
          "SELECT option_value FROM `{$table}` WHERE option_name = %s FOR UPDATE", $lease['key']
        ));
        $row = $raw === null ? null : maybe_unserialize($raw);
        if (!is_array($row) || !is_string($row['owner'] ?? null) || !is_int($row['started_at'] ?? null)
          || !hash_equals($lease['owner'], $row['owner'])) {
          throw new \RuntimeException('Fenced lease was replaced or is unavailable.');
        }
        $now = $db->get_var('SELECT UNIX_TIMESTAMP()');
        if (!is_numeric($now) || $row['started_at'] <= 0 || $row['started_at'] > (int) $now
          || (!empty($lease['pending']) && !is_string($row['pending'] ?? null))) {
          throw new \RuntimeException('Fenced lease has malformed time or pending state.');
        }
        $deadline = min($deadline, $row['started_at'] + $lease['ttl']);
      }
      self::check_deadline($deadline);
      foreach ($tables as $table) { $db->require_innodb($table); }
      $result = $work();
      if ($result === false || self::$failed || $db->last_error !== '') { throw new \RuntimeException('Protected mutation failed; transaction rolled back.'); }
      self::check_deadline($deadline);
      $db->query('COMMIT'); $committed = true;
      return $result;
    } finally {
      if ($begun && !$committed) {
        try { $db->query('ROLLBACK'); } catch (\Throwable) { $db->close(); }
      }
      self::$active = false; $wpdb = $original;
      remove_filter('pre_http_request', $deny_http, PHP_INT_MAX);
      foreach ($settings as $setting => $value) {
        try { $db->query('SET SESSION ' . $setting . ' = ' . $value); } catch (\Throwable) { $db->close(); break; }
      }
      // Deletion only: never publish uncommitted values, even on uncertain COMMIT.
      foreach (self::$cache as [$key, $group]) { wp_cache_delete($key, $group); }
      self::$cache = [];
    }
  }

  private static function check_deadline(int $deadline): void {
    global $wpdb;
    $now = $wpdb->get_var('SELECT UNIX_TIMESTAMP()');
    if (!is_numeric($now) || (int) $now <= 0 || (int) $now >= $deadline) {
      throw new \RuntimeException('Lease expired before local transaction completion.');
    }
  }

  /** Authoritative options; bypass hooks/caches and serialize absent network rows. */
  public static function option(string $key, bool $network, bool $write = false, $value = null) {
    global $wpdb;
    $network = $network && is_multisite();
    $table = $network ? $wpdb->sitemeta : $wpdb->options;
    if (self::$active) { $wpdb->require_innodb($table); }
    AtomicConnection::identifier($table);
    $network_id = $network ? get_current_network_id() : 0;
    // A unique options sentinel solves missing/duplicate sitemeta races without
    // imposing a shared lock on unrelated option keys or networks.
    if (self::$active && $network) {
      $sentinel_table = $wpdb->base_prefix . 'options';
      $wpdb->require_innodb($sentinel_table);
      $sentinel = 'firewall_sync_state_' . hash('sha256', $network_id . ':' . $key);
      $wpdb->query($wpdb->prepare("INSERT IGNORE INTO `{$sentinel_table}` (option_name, option_value, autoload) VALUES (%s, '', 'no')", $sentinel));
      $wpdb->get_var($wpdb->prepare("SELECT option_value FROM `{$sentinel_table}` WHERE option_name = %s FOR UPDATE", $sentinel));
    }
    $where = $network ? $wpdb->prepare('site_id = %d AND meta_key = %s', $network_id, $key) : $wpdb->prepare('option_name = %s', $key);
    $column = $network ? 'meta_value' : 'option_value';
    $rows = $wpdb->get_results("SELECT {$column} AS value FROM `{$table}` WHERE {$where}" . ($network ? ' ORDER BY meta_id' : '') . (self::$active ? ' FOR UPDATE' : ''), ARRAY_A);
    if (($wpdb->last_error ?? '') !== '' || count($rows) > 1) { throw new \RuntimeException('Ambiguous or unavailable protected option.'); }
    if (!$write) { return $rows === [] ? [] : maybe_unserialize($rows[0]['value']); }
    if (!self::$active) { throw new \RuntimeException('Protected option write outside transaction.'); }
    $serialized = maybe_serialize($value);
    if ($rows !== []) {
      $wpdb->query($wpdb->prepare("UPDATE `{$table}` SET {$column} = %s WHERE {$where}", $serialized));
    } elseif ($network) {
      $wpdb->query($wpdb->prepare("INSERT INTO `{$table}` (site_id, meta_key, meta_value) VALUES (%d, %s, %s)", $network_id, $key, $serialized));
    } else {
      $wpdb->query($wpdb->prepare("INSERT INTO `{$table}` (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $key, $serialized));
    }
    self::$cache[] = $network ? [$network_id . ':' . $key, 'site-options'] : [$key, 'options'];
    self::$cache[] = $network ? [$network_id . ':notoptions', 'site-options'] : ['notoptions', 'options'];
    if (!$network) { self::$cache[] = ['alloptions', 'options']; }
    return true;
  }
}
