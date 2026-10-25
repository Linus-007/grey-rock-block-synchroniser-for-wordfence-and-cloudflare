<?php

declare(strict_types=1);

namespace WPCF\FirewallSync {
  final class Config { public static function uses_network_options(): bool { return false; } }
}
namespace WPCF\FirewallSync\Services {
  final class ResetWatermarkStore { public static function clear($ip): bool { return true; } }
}
namespace {
  require_once __DIR__ . '/../fixtures/task14-unit-fence.php';
  use WPCF\FirewallSync\Cloudflare\Client;
  use WPCF\FirewallSync\Services\BlockLogger;
  use WPCF\FirewallSync\Services\SyncScheduler;
  function __($text, $domain = null): string { return $text; }
  function sanitize_text_field($text): string { return $text; }
  function current_time($type): string { return '2030-01-01 00:00:00'; }
  function is_multisite(): bool { return false; }
  function is_wp_error($value): bool { return false; }
  function wp_json_encode($value): string { return json_encode($value); }
  function wp_remote_post($url, $args): array { return ['response' => ['code' => 429], 'body' => '{"success":false,"errors":[{"message":"Rate limited"}]}']; }
  function wp_remote_retrieve_response_code($response): int { return $response['response']['code']; }
  function wp_remote_retrieve_body($response): string { return $response['body']; }
  final class RetryDatabase {
    public string $prefix = 'wp_';
    public int $failures = 0;
    public function prepare($sql, ...$values): string { return $sql; }
    public function query($sql): int {
      preg_match('/LEAST\(fail_count \+ ([01]),/', $sql, $match);
      $this->failures += (int) ($match[1] ?? 0);
      return 1;
    }
    public function get_var($sql) { return $this->failures >= 3 ? '1' : null; }
  }
  $wpdb = new RetryDatabase();
  $root = dirname(__DIR__, 2);
  foreach (['Services/IpValidator', 'Services/CloudflareIdentifierValidator', 'Cloudflare/Client', 'Services/BlockLogger', 'Services/SyncScheduler'] as $name) { require $root . '/src/includes/' . $name . '.php'; }
  $record = new ReflectionMethod(SyncScheduler::class, 'record_batch_results');
  $batch = [['ip' => '8.8.8.8', 'reason' => 'fixture', 'expires_at' => null]];
  for ($attempt = 0; $attempt < 3; $attempt++) {
    $client = new Client('fixture', str_repeat('c', 32));
    $failed = $client->batch_block($batch);
    $retryable = method_exists($client, 'get_retryable_failed_ips') ? $client->get_retryable_failed_ips() : [];
    $record->invoke(null, $batch, $failed, $retryable);
  }
  if (BlockLogger::is_blacklisted('8.8.8.8')) { throw new RuntimeException('Transient rate limiting permanently disabled synchronization after three attempts.'); }
  for ($attempt = 0; $attempt < 3; $attempt++) { BlockLogger::mark_failed('8.8.8.8', 'permanent error'); }
  if (!BlockLogger::is_blacklisted('8.8.8.8')) { throw new RuntimeException('Permanent-failure retry bound was lost.'); }
  echo "PASS: Transient failures remain retryable; permanent failures retain bounded retries.\n";
}
