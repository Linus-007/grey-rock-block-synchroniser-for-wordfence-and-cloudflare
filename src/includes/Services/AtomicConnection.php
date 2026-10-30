<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Services;

/**
 * A connection-bound subset of wpdb for local fenced work. No reconnect, query
 * filters, option hooks, or statement replay. Unsupported wpdb drop-ins fail shut.
 *
 * phpcs:disable WordPress.DB.RestrictedFunctions
 * phpcs:disable WordPress.DB.DirectDatabaseQuery
 * phpcs:disable WordPress.DB.PreparedSQL
 */
final class AtomicConnection {
  private object $original;
  private \mysqli $handle;
  private array $engines = [];
  private bool $dedicated = false;
  private int $connection_id = 0;
  private array $backend = [];
  public string $last_error = '';

  public function __construct(object $original) {
    if (get_class($original) !== 'wpdb' || !extension_loaded('mysqli')) {
      throw new \RuntimeException('Fencing requires the standard mysqli wpdb connection.');
    }
    $handle = (\Closure::bind(static function ($db) { return $db->dbh; }, null, \wpdb::class))($original);
    if (!$handle instanceof \mysqli) {
      throw new \RuntimeException('Fencing connection unavailable.');
    }
    $this->original = $original;
    $this->handle = $handle;
  }

  public function close(): void {
    try {
      if ($this->dedicated) { mysqli_close($this->handle); }
      else { $this->original->close(); }
    } catch (\Throwable) { /* A dead handle is already unusable. */ }
  }

  public function __destruct() {
    if ($this->dedicated) { $this->close(); }
  }

  /** MySQL lacks MariaDB's DML deadline; configure a fresh bounded transport. */
  public function isolate_mysql(): void {
    // Admission already rejected caller transactions. Consume its next-
    // transaction isolation setting without changing the caller's defaults.
    $this->query('START TRANSACTION');
    $this->query('ROLLBACK');
    $this->backend = [(string) $this->get_var('SELECT @@server_uuid'), (string) $this->get_var('SELECT DATABASE()')];
    $mode = (string) $this->get_var('SELECT @@sql_mode');
    $collation = (string) $this->get_var('SELECT @@collation_connection');
    $timezone = (string) $this->get_var('SELECT @@time_zone');
    $charset = mysqli_character_set_name($this->handle);
    if (!preg_match('/^[A-Z0-9_,]*$/D', $mode)
      || !preg_match('/^[a-zA-Z0-9_]+$/D', $collation)
      || !preg_match('/^[a-zA-Z0-9_+\/:\-]+$/D', $timezone)) {
      throw new \RuntimeException('Unsupported fenced connection configuration.');
    }
    $handle = $this->connect(3);
    try {
      if (!mysqli_set_charset($handle, $charset)
        || !mysqli_query($handle, "SET SESSION sql_mode = '{$mode}'")
        || !mysqli_query($handle, "SET SESSION collation_connection = '{$collation}'")
        || !mysqli_query($handle, "SET SESSION time_zone = '{$timezone}'")) {
        throw new \RuntimeException('Fenced connection configuration unavailable.');
      }
    } catch (\Throwable) {
      mysqli_close($handle);
      throw new \RuntimeException('Fenced connection configuration unavailable.');
    }
    $this->handle = $handle;
    $this->dedicated = true;
    $this->connection_id = mysqli_thread_id($handle);
  }

  /** A new session must lock the same primary and schema as lease admission. */
  public function assert_mysql_backend(): void {
    $actual = [(string) $this->get_var('SELECT @@server_uuid'), (string) $this->get_var('SELECT DATABASE()')];
    if ($this->backend === [] || in_array('', $this->backend, true) || $actual !== $this->backend) {
      throw new \RuntimeException('Fenced database backend changed.');
    }
  }

  /** No reconnect: this creates a new session before protected work begins. */
  private function connect(int $read_timeout): \mysqli {
    $configuration = (\Closure::bind(static function ($db): array {
      return [$db->parse_db_host($db->dbhost), $db->dbuser, $db->dbpassword, $db->dbname];
    }, null, \wpdb::class))($this->original);
    [$address, $user, $password, $database] = $configuration;
    if (!is_array($address)) { throw new \RuntimeException('Database address unavailable.'); }
    [$host, $port, $socket, $ipv6] = $address;
    if ($ipv6) { $host = '[' . $host . ']'; }
    $handle = mysqli_init();
    try {
      if (!mysqli_options($handle, MYSQLI_OPT_CONNECT_TIMEOUT, 1)
        || !mysqli_options($handle, MYSQLI_OPT_READ_TIMEOUT, $read_timeout)
        || !mysqli_real_connect($handle, $host, $user, $password, $database, $port, $socket,
          defined('MYSQL_CLIENT_FLAGS') ? MYSQL_CLIENT_FLAGS : 0)) {
        throw new \RuntimeException('Fenced connection unavailable.');
      }
      return $handle;
    } catch (\Throwable) {
      try { mysqli_close($handle); } catch (\Throwable) {}
      throw new \RuntimeException('Fenced connection unavailable.');
    }
  }

  /** Kill only the failed dedicated session; never replay protected work. */
  private function cancel_statement(): void {
    $control = null;
    try {
      $control = $this->connect(1);
      mysqli_query($control, 'KILL CONNECTION ' . $this->connection_id);
    } catch (\Throwable) {
      // Failure still closes the mutation session and cannot turn into COMMIT.
      // Server cleanup may take longer when cancellation is unavailable.
    } finally {
      if ($control instanceof \mysqli) { try { mysqli_close($control); } catch (\Throwable) {} }
      $this->close();
    }
  }

  public function __get(string $name) { return $this->original->$name; }
  public function prepare(string $sql, ...$args): string {
    // wpdb normally removes these tokens in its query filter, which this
    // connection deliberately bypasses. Preserve literal percent signs.
    return $this->original->remove_placeholder_escape($this->original->prepare($sql, ...$args));
  }

  public function query(string $sql) {
    try {
      if (preg_match('/^\s*(?:INSERT(?: IGNORE)? INTO|UPDATE|DELETE FROM)\s+`?([a-zA-Z0-9_]+)`?/i', $sql, $match)) {
        $this->require_innodb($match[1]);
      }
      $result = mysqli_query($this->handle, $sql);
      if ($result === false || mysqli_errno($this->handle) !== 0) {
        if ($result instanceof \mysqli_result) { mysqli_free_result($result); }
        throw new \RuntimeException('Fenced database statement failed.');
      }
      return $result instanceof \mysqli_result ? $result : mysqli_affected_rows($this->handle);
    } catch (\Throwable $error) {
      $this->last_error = 'Fenced database statement failed.';
      $errno = 0;
      try { $errno = mysqli_errno($this->handle); } catch (\Throwable) {}
      if ($this->dedicated && in_array($errno, [2006, 2013], true)) { $this->cancel_statement(); }
      throw new \RuntimeException('Fenced database statement failed.');
    }
  }

  public function get_var(string $sql) {
    $result = $this->query($sql);
    if (!$result instanceof \mysqli_result) { throw new \RuntimeException('Expected database result.'); }
    $row = mysqli_fetch_row($result);
    mysqli_free_result($result);
    return $row[0] ?? null;
  }

  public function get_results(string $sql, $output = OBJECT): array {
    $result = $this->query($sql);
    if (!$result instanceof \mysqli_result) { throw new \RuntimeException('Expected database result.'); }
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) { $rows[] = $output === ARRAY_A ? $row : (object) $row; }
    mysqli_free_result($result);
    return $rows;
  }

  public function get_row(string $sql, $output = OBJECT) {
    $rows = $this->get_results($sql, $output);
    return $rows[0] ?? null;
  }

  public function get_col(string $sql): array {
    return array_map(static fn (array $row) => array_values($row)[0], $this->get_results($sql, ARRAY_A));
  }

  public function esc_like(string $value): string { return $this->original->esc_like($value); }

  public function delete(string $table, array $where, array $formats = []) {
    $parts = []; $values = [];
    foreach ($where as $key => $value) {
      self::identifier($key);
      $parts[] = "`{$key}` = " . ($formats[count($values)] ?? '%s');
      $values[] = $value;
    }
    self::identifier($table);
    return $this->query($this->prepare("DELETE FROM `{$table}` WHERE " . implode(' AND ', $parts), ...$values));
  }

  public static function identifier(string $name): void {
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $name)) { throw new \RuntimeException('Unsupported database identifier.'); }
  }

  public function require_innodb(string $table): void {
    self::identifier($table);
    if (isset($this->engines[$table])) { return; }
    if ($this->get_var($this->prepare(
      'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table
    )) !== 'InnoDB') { throw new \RuntimeException('Fencing requires InnoDB tables.'); }
    $this->engines[$table] = true;
  }
}
