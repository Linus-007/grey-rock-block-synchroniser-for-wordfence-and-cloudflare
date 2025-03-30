<?php

/**
 * Dev-only WordPress cleanup — disable tracking, updates, emojis, etc.
 * Safe for local development only.
 */

if (!defined('WP_DEBUG') || WP_DEBUG !== true) {
  return;
}

// 🧼 Disable Emojis, oEmbeds, REST API output, prefetching
add_action('init', function () {
  remove_action('wp_head', 'print_emoji_detection_script', 7);
  remove_action('admin_print_scripts', 'print_emoji_detection_script');
  remove_action('wp_print_styles', 'print_emoji_styles');
  remove_action('admin_print_styles', 'print_emoji_styles');
  remove_filter('the_content_feed', 'wp_staticize_emoji');
  remove_filter('comment_text_rss', 'wp_staticize_emoji');
  remove_filter('wp_mail', 'wp_staticize_emoji_for_email');

  remove_action('wp_head', 'wp_oembed_add_discovery_links');
  remove_action('wp_head', 'wp_oembed_add_host_js');
  remove_action('wp_head', 'rest_output_link_wp_head');
  remove_action('wp_head', 'rsd_link');
  remove_action('wp_head', 'wlwmanifest_link');
  remove_action('wp_head', 'wp_shortlink_wp_head');
  remove_action('wp_head', 'wp_resource_hints', 2);
}, 1);

// 🛑 Disable Heartbeat API
add_action('init', function () {
  wp_deregister_script('heartbeat');
}, 1);

// ❌ Remove version output
remove_action('wp_head', 'wp_generator');
add_filter('the_generator', '__return_empty_string');

// 🚫 Disable all update checks
remove_action('init', 'wp_version_check');
remove_action('admin_init', '_maybe_update_core');
remove_action('admin_init', '_maybe_update_plugins');
remove_action('admin_init', '_maybe_update_themes');

// 🔒 Block external requests to WordPress.org (plugin/theme updates)
add_filter('pre_http_request', function ($false, $args, $url) {
  $block = [
    'api.wordpress.org',
    'downloads.wordpress.org',
    'w.org',
    'jetpack.com',
  ];

  foreach ($block as $domain) {
    if (strpos($url, $domain) !== false) {
      return new WP_Error('blocked_remote', 'Blocked request to: ' . $url);
    }
  }

  return false;
}, 10, 3);

// 🚫 Disable admin notices (optional, dev-only)
add_action('admin_init', function () {
  remove_all_actions('admin_notices');
  remove_all_actions('all_admin_notices');
});
