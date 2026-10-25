<?php
/** Disposable test installation only; executed with WP-CLI --skip-wordpress. */
declare(strict_types=1);

ini_set('zend.exception_ignore_args', '1');

// WP-CLI core install may print its reconstructed command, including prompted
// passwords. Call the installer directly; never construct a command with secrets.
if (!isset($args[0]) || !filter_var($args[0], FILTER_VALIDATE_URL)) {
  throw new RuntimeException('Disposable installation URL is unavailable.');
}
define('WP_INSTALLING', true);
define('WP_SITEURL', $args[0]);
define('WP_HOME', $args[0]);
require getcwd() . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
$password = trim((string) file_get_contents('/run/greyrock-admin-password'));
if (!preg_match('/\A[0-9a-f]{48}\z/D', $password) || !isset($args[0])) {
  throw new RuntimeException('Disposable installation input is unavailable.');
}
if (is_blog_installed()) {
  throw new RuntimeException('Disposable WordPress must be uninstalled.');
}
add_filter('pre_wp_mail', static fn () => true); // Preserve --skip-email.
$result = wp_install('Grey Rock Automated Integration Test', 'greyrock-test-admin',
  'greyrock-test@example.invalid', true, '', $password);
unset($password);
update_option('siteurl', $args[0]);
update_option('home', $args[0]);
if (is_wp_error($result) || !is_blog_installed()) {
  throw new RuntimeException('Disposable installation failed.');
}
unset($result);
