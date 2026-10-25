<?php

declare(strict_types=1);

namespace WPCF\FirewallSync {
  final class Config {
    public const SCHEDULER_EXTERNAL = 'external';
    public static function uses_network_options(): bool { return $GLOBALS['blog'] !== 102; }
    public static function get_network_options(): array { return ['cloudflare_mode' => 'zone_access_rules']; }
  }
}
namespace WPCF\FirewallSync\Services {
  final class BlockOwnership {
    public const OWNER_MANUAL = 'manual';
    public static function has_any($ip): bool { return $GLOBALS['blog'] === 101; }
    public static function has($ip, $owner): bool { return $GLOBALS['blog'] === 101; }
  }
  final class NetworkManualOwnershipStore { public static function has($ip): bool { return false; } }
  final class SyncScheduler {
    public const RESULT_SUCCESS = 'success'; public const RESULT_FAILURE = 'failure';
    public const RESULT_NOT_DUE = 'not_due'; public const RESULT_DISABLED = 'disabled';
    public static function run_now(): bool { return true; }
    public static function run_if_due($method): string { return 'success'; }
  }
}
namespace {
  use WPCF\FirewallSync\Services\BlockOwnershipResolver as Resolver;
  use WPCF\FirewallSync\Services\NetworkSynchronizer;
  $blog = 1; $stack = [];
  function is_multisite(): bool { return true; }
  function get_current_network_id(): int { return 1; }

function get_sites($args): array {
    if (!empty($GLOBALS['site_read_failure'])) { return []; }
    return range(1, ($args['number'] ?? 100) === 0 ? 105 : 100);
  }
  function switch_to_blog($id): void { $GLOBALS['stack'][] = $GLOBALS['blog']; $GLOBALS['blog'] = $id; }
  function restore_current_blog(): void { $GLOBALS['blog'] = array_pop($GLOBALS['stack']); }
  function get_current_blog_id(): int { return $GLOBALS['blog']; }
  function __($message, $domain = null): string { return $message; }
  function check($condition, $message): void { if (!$condition) { throw new RuntimeException($message); } }
  $root = dirname(__DIR__, 2);
  require $root . '/src/includes/Services/IpValidator.php';
  require $root . '/src/includes/Services/BlockOwnershipResolver.php';
  require $root . '/src/includes/Services/NetworkSynchronizer.php';
  foreach (['has_any_for_effective_destination', 'has_any_for_network_destination', 'has_other_owner_after_wordfence_removal'] as $method) {
    if (($argv[1] ?? '') !== '' && $argv[1] !== $method) { continue; }
    check(Resolver::$method('8.8.8.8'), 'Ownership after site 100 was omitted: ' . $method);
    check($blog === 1 && $stack === [], 'Ownership lookup leaked site context.');
    echo "PASS: Large-network {$method}\n";
  }
  if (($argv[1] ?? '') === '' || $argv[1] === 'incomplete-site-inventory') {
    $GLOBALS['site_read_failure'] = true;
    foreach (['has_any_for_effective_destination', 'has_any_for_network_destination', 'has_other_owner_after_wordfence_removal'] as $method) {
      $threw = false;
      try { Resolver::$method('8.8.8.8'); } catch (RuntimeException) { $threw = true; }
      check($threw, 'Incomplete site inventory was mistaken for no ownership: ' . $method);
    }
    $GLOBALS['site_read_failure'] = false;
    echo "PASS: Large-network incomplete-site-inventory\n";
  }
  if (($argv[1] ?? '') === '' || $argv[1] === 'network-run') {
    $result = NetworkSynchronizer::run();
    check($result['processed'] === 104 && $result['successful'] === 104, 'Network synchronization silently stopped at 100 sites.');
    check($blog === 1 && $stack === [], 'Network synchronization leaked site context.');
    echo "PASS: Large-network network-run\n";
  }
}
