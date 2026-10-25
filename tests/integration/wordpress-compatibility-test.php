<?php

declare(strict_types=1);

use WPCF\FirewallSync\WordPressCompatibility;

if (!defined('ABSPATH')) {
  throw new RuntimeException('WordPress must be loaded.');
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$plugin = 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare/grey-rock-block-synchroniser-for-wordfence-and-cloudflare.php';
$data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin, false, false);
if ($data['RequiresWP'] !== '7.0' || !WordPressCompatibility::is_supported(get_bloginfo('version'))) {
  throw new RuntimeException('WordPress runtime or minimum metadata violates stable 7.0+ policy.');
}
$result = validate_plugin_requirements($plugin);
if (is_wp_error($result)) {
  throw new RuntimeException('WordPress core rejected the installed plugin requirements.');
}
echo "PASS: Actual WordPress runtime and native plugin requirements meet stable 7.0+ policy.\n";
