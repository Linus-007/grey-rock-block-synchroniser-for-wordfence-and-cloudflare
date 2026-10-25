<?php

declare(strict_types=1);

namespace WPCF\FirewallSync {
  // Exercise the real bootstrap without running migrations or network services.
  final class Plugin {
    public static int $initializations = 0;
    public static array $activations = [];
    public static function init(): void { self::$initializations++; }
    public static function activate(bool $network_wide = false): void {
      self::$activations[] = $network_wide;
    }
  }
}

namespace {
  use WPCF\FirewallSync\Plugin;
  use WPCF\FirewallSync\WordPressCompatibility;

  define('ABSPATH', __DIR__ . '/');
  require_once __DIR__ . '/../../src/includes/WordPressCompatibility.php';

  $actions = [];
  $activation = null;
  $deactivation = null;
  $current_version = '7.0';
  function add_action($hook, $callback): void {
    $GLOBALS['actions'][$hook][] = $callback;
  }
  function register_activation_hook($file, $callback): void {
    $GLOBALS['activation'] = $callback;
  }
  function register_deactivation_hook($file, $callback): void {
    $GLOBALS['deactivation'] = $callback;
  }
  function get_bloginfo($show) { return $GLOBALS['current_version']; }
  function esc_html__($message, $domain): string {
    return htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
  }
  function wp_die($message): never { throw new RuntimeException($message); }
  function policy_assert(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
  }

  foreach (['6.0', '6.9.99', '7.0-RC1', '7.1-beta2', '8.0-alpha', '9.0-dev', '', 'latest', "7.0\n", '7.0;echo unsafe', '<script>', null, [], 7] as $version) {
    policy_assert(!WordPressCompatibility::is_supported($version), 'Unsafe/unsupported version accepted.');
    $current_version = $version;
    $actions = [];
    $before = Plugin::$initializations;
    require __DIR__ . '/../../src/grey-rock-block-synchroniser-for-wordfence-and-cloudflare.php';
    ($actions['plugins_loaded'][0])();
    policy_assert(Plugin::$initializations === $before, 'Unsupported runtime initialized.');
    policy_assert(isset($actions['admin_notices'], $actions['network_admin_notices']), 'Missing compatibility notices.');
    policy_assert(is_array($deactivation), 'Deactivation hook lost.');
    if (!WordPressCompatibility::is_stable($version)) {
      $before = count(Plugin::$activations);
      try {
        $activation(true);
        throw new LogicException('Prerelease activation did not stop.');
      } catch (RuntimeException $error) {
        policy_assert(str_contains($error->getMessage(), 'stable WordPress'), 'Wrong activation failure.');
      }
      policy_assert(count(Plugin::$activations) === $before, 'Invalid activation reached migrations.');
    }
  }
  foreach (['7.0', '7.0.0', '7.1', '7.99.12', '8.0', '10.0.1'] as $version) {
    policy_assert(WordPressCompatibility::is_supported($version), 'Stable future release rejected.');
    $current_version = $version;
    $actions = [];
    $before = Plugin::$initializations;
    require __DIR__ . '/../../src/grey-rock-block-synchroniser-for-wordfence-and-cloudflare.php';
    ($actions['plugins_loaded'][0])();
    policy_assert(Plugin::$initializations === $before + 1, 'Supported runtime did not initialize.');
    $activation(false);
    $activation(true);
    policy_assert(array_slice(Plugin::$activations, -2) === [false, true], 'Network activation flag changed.');
  }
  policy_assert(WordPressCompatibility::MINIMUM === '7.0', 'Minimum policy regressed.');
  echo "PASS: WordPress stable 7.0+ policy, runtime guard and activation forwarding.\n";
}
