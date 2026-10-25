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
    try { $this->original->close(); } catch (\Throwable) { /* A dead handle is already unusable. */ }
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
      if ($result === false) { throw new \RuntimeException('Fenced database statement failed.'); }
      return $result instanceof \mysqli_result ? $result : mysqli_affected_rows($this->handle);
    } catch (\Throwable $error) {
      $this->last_error = 'Fenced database statement failed.';
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
