<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Services {
  final class MigrationManager {
    public static array $sites = [];
    public static function run(?string $from): void {
      self::$sites[] = $GLOBALS['blog'];
    }
  }
  final class SyncScheduler {
    public static array $registered = [];
    public static array $stopped = [];
    public static bool $fail = false;
    public static function register(): void {
      self::$registered[] = $GLOBALS['blog'];
      if (self::$fail) { throw new \RuntimeException('Controlled site failure'); }
    }
    public static function deactivate(): void { self::$stopped[] = $GLOBALS['blog']; }
  }
}

namespace {
  use WPCF\FirewallSync\Plugin;
  use WPCF\FirewallSync\Services\MigrationManager;
  use WPCF\FirewallSync\Services\SyncScheduler;
  class WP_Site { public function __construct(public int $blog_id) {} }
  $blog = 1;
  $stack = [];
  $options = [];
  $network = [];
  function plugin_dir_path($file): string { return dirname($file) . '/'; }
  function plugin_dir_url($file): string { return 'https://example.invalid/plugin/'; }
  function plugin_basename($file): string { return 'greyrock/plugin.php'; }
  function get_file_data($file, $headers): array { return ['Version' => '1.3.10']; }
  function is_multisite(): bool { return true; }
  function get_current_network_id(): int { return 1; }

function get_sites($args): array { return [1, 2, 3]; }
  function switch_to_blog($id): void {
    $GLOBALS['stack'][] = $GLOBALS['blog'];
    $GLOBALS['blog'] = $id;
  }
  function restore_current_blog(): void { $GLOBALS['blog'] = array_pop($GLOBALS['stack']); }
  function get_option($key) { return $GLOBALS['options'][$GLOBALS['blog']][$key] ?? false; }
  function update_option($key, $value): void { $GLOBALS['options'][$GLOBALS['blog']][$key] = $value; }
  function get_site_option($key, $default = false) { return $GLOBALS['network'][$key] ?? $default; }
  function update_site_option($key, $value): void { $GLOBALS['network'][$key] = $value; }
  function lifecycle_assert(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
  }
  require __DIR__ . '/../../src/includes/Plugin.php';
  Plugin::activate(true);
  lifecycle_assert(MigrationManager::$sites === [1, 2, 3], 'Network migrations missed a site.');
  lifecycle_assert(SyncScheduler::$registered === [1, 2, 3], 'Network schedules missed a site.');
  lifecycle_assert($blog === 1 && $stack === [], 'Activation leaked site context.');
  lifecycle_assert($network['firewall_sync_network_version'] === '1.3.10', 'Network version missing.');
  SyncScheduler::$registered = [];
  Plugin::activate(false);
  lifecycle_assert(SyncScheduler::$registered === [1], 'Site-only activation crossed site boundaries.');
  Plugin::initialize_new_site(new WP_Site(5), []);
  lifecycle_assert(MigrationManager::$sites === [1, 2, 3], 'Inactive network initialized a new site.');
  $network['active_sitewide_plugins'] = ['greyrock/plugin.php' => time()];
  Plugin::initialize_new_site(new WP_Site(4), []);
  lifecycle_assert(end(MigrationManager::$sites) === 4, 'New network site not migrated.');
  lifecycle_assert($blog === 1 && $stack === [], 'New-site initialization leaked context.');
  Plugin::deactivate(true);
  lifecycle_assert(SyncScheduler::$stopped === [1, 2, 3], 'Network deactivation missed a site.');
  SyncScheduler::$fail = true;
  try {
    Plugin::activate(true);
    throw new LogicException('Expected controlled activation failure.');
  } catch (RuntimeException $error) {
    lifecycle_assert($error->getMessage() === 'Controlled site failure', 'Unexpected failure.');
  }
  lifecycle_assert($blog === 1 && $stack === [], 'Failed activation leaked site context.');
  echo "PASS: Network activation, new-site initialization, deactivation and context restoration.\n";
}
