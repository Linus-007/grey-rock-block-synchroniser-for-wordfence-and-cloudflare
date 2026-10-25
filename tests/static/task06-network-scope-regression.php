<?php

declare(strict_types=1);
namespace WPCF\FirewallSync {
  final class Config { public static function uses_network_options(): bool { return ($GLOBALS['blog'] ?? 1) !== 3; } }
}
namespace WPCF\FirewallSync\Services {
  final class NetworkManualOwnershipStore { public static function has(string $ip): bool { return false; } }
  final class BlockLogger {
    public static function remove(string $ip): bool { $GLOBALS['removed_blogs'][] = $GLOBALS['blog']; return true; }
  }
  final class BlockOwnership {
    public static function has_any(string $ip): bool { return $GLOBALS['blog'] === 2; }
  }
}
namespace {
  function is_multisite(): bool { return true; }
  function get_current_network_id(): int { return 1; }
  function get_sites(array $args): array { return ($args['network_id'] ?? 0) === 1 ? [1, 3] : [1, 2, 3]; }
  $blog = 1; $stack = [];
  function switch_to_blog(int $id): void { $GLOBALS['stack'][] = $GLOBALS['blog']; $GLOBALS['blog'] = $id; }
  function restore_current_blog(): void { $GLOBALS['blog'] = array_pop($GLOBALS['stack']); }
  require dirname(__DIR__, 2) . '/src/includes/Services/IpValidator.php';
  require dirname(__DIR__, 2) . '/src/includes/Services/BlockOwnershipResolver.php';
  if (\WPCF\FirewallSync\Services\BlockOwnershipResolver::has_any_for_network_destination('8.8.8.8')) {
    throw new RuntimeException('An unrelated network supplied ownership to this network destination.');
  }
  if ($blog !== 1 || $stack !== []) { throw new RuntimeException('Blog context was not restored.'); }
  require dirname(__DIR__, 2) . '/src/includes/Services/SyncScheduler.php';
  $removed_blogs = [];
  (new ReflectionMethod(\WPCF\FirewallSync\Services\SyncScheduler::class, 'remove_allowed_ip_from_logs'))->invoke(null, '8.8.8.8');
  if ($removed_blogs !== [1]) { throw new RuntimeException('Shared allow-list cleanup changed a site using independent configuration.'); }
  echo "PASS: network ownership excludes other networks and restores blog context\n";
}
