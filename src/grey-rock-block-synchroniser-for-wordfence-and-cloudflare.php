<?php
/**
 * Plugin Name: Grey Rock Block Synchroniser for Wordfence and Cloudflare
 * Plugin URI: https://github.com/Linus-007/grey-rock-block-synchroniser-for-wordfence-and-cloudflare
 * Description: Synchronises current and historical Wordfence firewall blocks with Cloudflare Zone Access Rules or an account-level IP list.
 * Version: 1.3.14
 * Requires at least: 7.0
 * Tested up to: 7.1
 * Requires PHP: 8.2
 * Author: Greyscale Zone
 * Author URI: https://greyscale.zone/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: grey-rock-block-synchroniser-for-wordfence-and-cloudflare
 * Domain Path: /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

spl_autoload_register(function (string $class): void {
  $prefix = 'WPCF\\FirewallSync\\';
  $base_dir = __DIR__ . '/includes/';

  if (strpos($class, $prefix) !== 0) return;

  $relative_class = str_replace($prefix, '', $class);
  $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

  if (file_exists($file)) {
    require $file;
  }
});

add_action('plugins_loaded', static function (): void {
  // Active plugins also load after a core downgrade; activation metadata cannot
  // protect that path. Do not initialize migrations, cron, admin actions or CLI.
  if (!\WPCF\FirewallSync\WordPressCompatibility::is_supported(get_bloginfo('version'))) {
    add_action('admin_notices', ['WPCF\\FirewallSync\\WordPressCompatibility', 'notice']);
    add_action('network_admin_notices', ['WPCF\\FirewallSync\\WordPressCompatibility', 'notice']);
    return;
  }

  if (class_exists('WPCF\\FirewallSync\\Plugin')) {
    \WPCF\FirewallSync\Plugin::init();
  }
});

register_activation_hook(__FILE__, static function (bool $network_wide = false): void {
  // Core checks Requires at least. Only add the stable-release constraint that
  // core deliberately does not enforce when comparing prerelease versions.
  if (!\WPCF\FirewallSync\WordPressCompatibility::is_stable(get_bloginfo('version'))) {
    wp_die(esc_html__(
      'Grey Rock requires a stable WordPress release, version 7.0 or later.',
      'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
    ));
    return;
  }
  \WPCF\FirewallSync\Plugin::activate($network_wide);
});
register_deactivation_hook(__FILE__, ['WPCF\\FirewallSync\\Plugin', 'deactivate']);
