<?php
// File: wp-content/autologin.php
// Mounted as: /var/www/html/wp-content/index.php during dev

define('WP_USE_THEMES', false);

require_once dirname(__DIR__) . '/wp-load.php';

if (!defined('WP_DEBUG') || WP_DEBUG !== true) {
  exit('This auto-login script is only for development purposes.');
}

$credsFile = ABSPATH . '/autologin.json';

if (!file_exists($credsFile)) {
  exit('autlogin.json file not found.' . PHP_EOL . $credsFile);
}

$data = json_decode(file_get_contents($credsFile), true);

if (json_last_error() !== JSON_ERROR_NONE) {
  exit('❌ JSON decode error: ' . json_last_error_msg());
}

$username = $data['username'] ?? null;

if (!$username) {
  exit('Username not found in autologin.json file.');
}

$user = get_user_by('login', $username);

if (!$user) {
  exit("❌ User '$username' not found.");
}

if (!is_user_logged_in()) {
  wp_set_current_user($user->ID);
  wp_set_auth_cookie($user->ID, true);
}

wp_redirect(admin_url());
exit;
