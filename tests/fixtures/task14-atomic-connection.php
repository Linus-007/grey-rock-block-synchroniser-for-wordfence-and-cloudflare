<?php
/** Deterministic transaction transport model. Never presented as InnoDB proof. */
namespace WPCF\FirewallSync\Services;
final class AtomicConnection {
  private object $db;
  private array $writes = [];
  private int $mutations = 0;
  private bool $transaction = false;
  public string $last_error = '';
  public function __construct(object $db) { $this->db = $db; }
  public function __get(string $key) { return $this->db->$key; }
  public function close(): void {}
  public static function identifier(string $name): void {
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $name)) { throw new \RuntimeException('Invalid identifier.'); }
  }
  public function prepare(string $sql, ...$args): string { return serialize([$sql, $args]); }
  public function require_innodb(string $table): void {
    self::identifier($table);
    if ($GLOBALS['model_non_innodb'] ?? false) { throw new \RuntimeException('Non-InnoDB model.'); }
  }
  public function get_var(string $sql) {
    if ($sql === 'SELECT @@in_transaction') { return ($GLOBALS['model_outer_tx'] ?? false) ? 1 : 0; }
    if ($sql === 'SELECT @@autocommit') { return 1; }
    if ($sql === 'SELECT VERSION()') { return '11.4-MariaDB-model'; }
    if (str_starts_with($sql, 'SELECT @@')) { return 50; }
    if ($sql === 'SELECT UNIX_TIMESTAMP()') { return $GLOBALS['fence_clock']; }
    [$query, $args] = unserialize($sql);
    if (str_contains($query, 'FOR UPDATE')) {
      $current = $GLOBALS['wpdb']; $GLOBALS['wpdb'] = $this->db;
      $active = new \ReflectionProperty(AtomicFence::class, 'active');
      $active->setValue(null, false);
      try { \fence_pause('atomic-before-lock'); } finally { $active->setValue(null, true); $GLOBALS['wpdb'] = $current; }
    }
    preg_match('/FROM `?([a-zA-Z0-9_]+)/', $query, $m);
    return $this->writes[$m[1]][$args[0]] ?? $this->db->rows[$m[1]][$args[0]] ?? null;
  }
  public function get_results(string $sql, $output = null): array {
    preg_match('/FROM `([a-zA-Z0-9_]+)` WHERE (.*?)(?: FOR UPDATE)?$/s', $sql, $m);
    [$where, $args] = unserialize($m[2]);
    $raw = $this->writes[$m[1]][$args[0]] ?? $this->db->rows[$m[1]][$args[0]] ?? null;
    return $raw === null ? [] : [['value' => $raw]];
  }
  public function query(string $sql) {
    if (str_starts_with($sql, 'SET ')) { return 0; }
    if ($sql === 'START TRANSACTION') { $this->transaction = true; return 0; }
    if ($sql === 'ROLLBACK') { $this->writes = []; $this->mutations = 0; $this->transaction = false; return 0; }
    if ($sql === 'COMMIT') {
      foreach ($this->writes as $table => $values) {
        foreach ($values as $key => $value) { $this->db->rows[$table][$key] = $value; }
      }
      $GLOBALS['model_committed_mutations'] += $this->mutations;
      $this->transaction = false; return 0;
    }
    [$query, $args] = unserialize($sql);
    if (($GLOBALS['model_write_error'] ?? false) || (isset($GLOBALS['model_fail_after']) && $this->mutations >= $GLOBALS['model_fail_after'])) { throw new \RuntimeException('Injected write failure.'); }
    if (str_contains($query, 'wpcf_sync_')) {
      $this->mutations++; return 1;
    }
    preg_match('/(?:INTO|UPDATE) `([a-zA-Z0-9_]+)`/', $query, $m); $table = $m[1];
    if (str_starts_with($query, 'UPDATE')) {
      preg_match('/WHERE (.*)$/s', $query, $w); [, $keys] = unserialize($w[1]);
      $this->writes[$table][$keys[0]] = $args[0];
    } else { $this->writes[$table][$args[0]] = $args[1]; }
    $this->mutations++; return 1;
  }
  public function delete(string $table, array $where, array $format = []) {
    if (($GLOBALS['model_write_error'] ?? false) || (isset($GLOBALS['model_fail_after']) && $this->mutations >= $GLOBALS['model_fail_after'])) { throw new \RuntimeException('Injected delete failure.'); }
    $this->mutations++; return 1;
  }
}
