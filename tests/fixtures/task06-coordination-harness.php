<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Services {
  function time(): int {
    return isset($GLOBALS['task06_clock_path']) ? (int) file_get_contents($GLOBALS['task06_clock_path']) : ($GLOBALS['destination_clock'] ?? 2000000000);
  }
}
namespace WPCF\FirewallSync\Cloudflare {
  function wp_remote_post($url, $args) {
    $response = \wp_remote_post($url, $args);
    if (isset($GLOBALS['task06_after_post'])) { ($GLOBALS['task06_after_post'])(); }
    return $response;
  }
}
namespace {
  if (!class_exists('WPCF\\FirewallSync\\Services\\AtomicFence', false)) {
    require_once dirname(__DIR__, 2) . '/src/includes/Services/AtomicFence.php';
  }
  function wp_generate_uuid4(): string { return bin2hex(random_bytes(16)); }
  function maybe_serialize($value): string { return serialize($value); }
  function maybe_unserialize($value) { return unserialize($value); }
  final class Task06Database {
    public string $base_prefix = 'wp_';
    public string $prefix = 'wp_';
    public string $last_error = '';
    public array $rows = [];
    private array $values = [];
    public ?string $state_file = null;
    private function storage(callable $operation) {
      if ($this->state_file === null) { return $operation(); }
      $file = fopen($this->state_file, 'c+');
      if ($file === false || !flock($file, LOCK_EX)) { throw new RuntimeException('Fixture storage unavailable.'); }
      try {
        $raw = stream_get_contents($file); $this->rows = $raw === '' ? [] : unserialize($raw);
        $result = $operation();
        rewind($file); ftruncate($file, 0); fwrite($file, serialize($this->rows)); fflush($file);
        return $result;
      } finally { flock($file, LOCK_UN); fclose($file); }
    }
    public bool $fail_write = false;
    public bool $fail_read = false;
    public $before_update = null;
    public function prepare(string $sql, ...$values): string { $this->values = $values; return $sql; }
    public function get_var(string $sql) { return $this->storage(fn() => $this->read_value($sql)); }
    private function read_value(string $sql) {
      $this->last_error = $this->fail_read ? 'synthetic read failure' : '';
      if ($sql === 'SELECT UNIX_TIMESTAMP()') { return \WPCF\FirewallSync\Services\time(); }
      return $this->rows[$this->values[0]] ?? null;
    }
    public function query(string $sql) { return $this->storage(fn() => $this->execute($sql)); }
    private function execute(string $sql) {
      if ($this->fail_write) { return false; }
      $v = $this->values;
      if (str_starts_with($sql, 'INSERT IGNORE')) {
        if (isset($this->rows[$v[0]])) { return 0; }
        $this->rows[$v[0]] = $v[1]; return 1;
      }
      if (str_starts_with($sql, 'UPDATE')) {
        $statement_time = \WPCF\FirewallSync\Services\time();
        if ($this->before_update !== null) { $hook = $this->before_update; $this->before_update = null; $hook($this, $v); }
        if (isset($v[3]) && $statement_time >= $v[3]) { return 0; }
        if (($this->rows[$v[1]] ?? null) !== $v[2]) { return 0; }
        $this->rows[$v[1]] = $v[0]; return 1;
      }
      if (str_starts_with($sql, 'DELETE')) {
        if (($this->rows[$v[0]] ?? null) !== $v[1]) { return 0; }
        unset($this->rows[$v[0]]); return 1;
      }
      throw new RuntimeException('Unexpected coordination query.');
    }
  }
  $wpdb = new Task06Database();
  require dirname(__DIR__, 2) . '/src/includes/Services/DestinationLease.php';
}
