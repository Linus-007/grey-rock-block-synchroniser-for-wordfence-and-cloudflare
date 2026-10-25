<?php

declare(strict_types=1);
namespace WPCF\FirewallSync\Cloudflare {
  function time(): int { return $GLOBALS['audit_clock']; }
  function usleep(int $microseconds): void {}
}
namespace {
  if (!class_exists('WPCF\\FirewallSync\\Services\\AtomicFence', false)) {
    require_once dirname(__DIR__, 2) . '/src/includes/Services/AtomicFence.php';
  }
  $audit_clock = 2100000000; $http_queue = []; $http_events = []; $audit_hook = null;
  function __($s, $domain = null): string { return $s; }
  function sanitize_text_field($s): string { return (string) $s; }
  function wp_generate_uuid4(): string { return bin2hex(random_bytes(16)); }
  function maybe_serialize($v): string { return serialize($v); }
  function maybe_unserialize($v) { return unserialize($v); }
  function wp_json_encode($v): string { return json_encode($v, JSON_THROW_ON_ERROR); }
  function current_time($format): string {
    if ($GLOBALS['audit_hook'] !== null) { ($GLOBALS['audit_hook'])(); }
    return '2036-07-18 13:20:00';
  }
  final class WP_Error {
    public function __construct(private string $code, private string $message = '') {}
    public function get_error_message(): string { return $this->message ?: $this->code; }
  }
  function is_wp_error($v): bool { return $v instanceof WP_Error; }
  function get_transient($key) { return false; }
  function set_transient(...$args): bool { return true; }
  function wp_remote_get($url, $args) { return audit_http('GET', $url); }
  function wp_remote_post($url, $args) { return audit_http('POST', $url); }
  function wp_remote_request($url, $args) { return audit_http($args['method'] ?? '', $url); }
  function audit_http($method, $url) {
    $GLOBALS['http_events'][] = [$method, $url];
    if (!$GLOBALS['http_queue']) { throw new RuntimeException('No queued mock; network prohibited.'); }
    $response = array_shift($GLOBALS['http_queue']);
    return is_callable($response) ? $response() : $response;
  }
  function wp_remote_retrieve_body($r): string { return $r['body'] ?? ''; }
  function wp_remote_retrieve_response_code($r): int { return $r['response']['code'] ?? 0; }
  function wp_remote_retrieve_response_message($r): string { return 'offline fixture'; }
  function wp_remote_retrieve_header($r, $name): string { return ''; }
  function audit_reply($result, int $code = 200, $errors = []): array {
    return ['response' => ['code' => $code], 'body' => wp_json_encode(['success' => $code < 300, 'result' => $result, 'errors' => $errors])];
  }
  function audit_check(bool $value, string $message): void { if (!$value) { throw new RuntimeException($message); } }
  final class AuditDatabase {
    public string $prefix = 'wp_';
    public string $base_prefix = 'wp_';
    public string $options = 'wp_options';
    public string $last_error = '';
    public array $rows = [];
    public ?string $state_file = null;
    private function storage(callable $command) {
      if ($this->state_file === null) { return $command(); }
      $stream = fopen($this->state_file, 'c+');
      if ($stream === false || !flock($stream, LOCK_EX)) { throw new RuntimeException('Audit fixture storage unavailable.'); }
      try {
        $contents = stream_get_contents($stream);
        $this->rows = $contents === '' ? [] : unserialize($contents);
        $result = $command();
        rewind($stream); ftruncate($stream, 0); fwrite($stream, serialize($this->rows)); fflush($stream);
        return $result;
      } finally { flock($stream, LOCK_UN); fclose($stream); }
    }
    public int $ownership_writes = 0;
    public bool $fail_clock_once = false;
    public bool $fail_next_post_update_clock = false;
    public $after_update = null;
    public function prepare(string $sql, ...$values): string { return serialize([$sql, $values]); }
    public function get_var(string $prepared) { return $this->storage(fn() => $this->memory_read($prepared)); }
    private function memory_read(string $prepared) {
      $this->last_error = '';
      if ($prepared === 'SELECT UNIX_TIMESTAMP()') {
        if ($this->fail_clock_once) { $this->fail_clock_once = false; $this->last_error = 'synthetic clock failure'; return null; }
        return $GLOBALS['audit_clock'];
      }
      [$sql, $values] = unserialize($prepared);
      return $this->rows[$values[0]] ?? null;
    }
    public function query(string $prepared) { return $this->storage(fn() => $this->memory_write($prepared)); }
    private function memory_write(string $prepared) {
      $this->last_error = '';
      [$sql, $values] = unserialize($prepared);
      if (str_contains($sql, 'wpcf_sync_ownership')) { $this->ownership_writes++; return 1; }
      if (str_starts_with($sql, 'INSERT IGNORE')) {
        if (isset($this->rows[$values[0]])) { return 0; }
        $this->rows[$values[0]] = $values[1]; return 1;
      }
      if (str_starts_with($sql, 'UPDATE')) {
        if (isset($values[3]) && $GLOBALS['audit_clock'] >= $values[3]) { return 0; }
        if (($this->rows[$values[1]] ?? null) !== $values[2]) { return 0; }
        $this->rows[$values[1]] = $values[0];
        if ($this->fail_next_post_update_clock) { $this->fail_next_post_update_clock = false; $this->fail_clock_once = true; }
        if ($this->after_update !== null) { ($this->after_update)(); }
        return 1;
      }
      if (str_starts_with($sql, 'DELETE')) {
        if (($this->rows[$values[0]] ?? null) !== $values[1]) { return 0; }
        unset($this->rows[$values[0]]); return 1;
      }
      throw new RuntimeException('Unsupported audit fixture SQL.');
    }
  }
  $wpdb = new AuditDatabase();
  foreach (['Services/IpValidator.php', 'Services/CloudflareIdentifierValidator.php', 'Services/DestinationLease.php', 'Cloudflare/Client.php', 'Services/BlockOwnership.php'] as $file) {
    require dirname(__DIR__, 2) . '/src/includes/' . $file;
  }
}
