<?php
/** Disposable test installation only; executed with WP-CLI --skip-wordpress. */
declare(strict_types=1);

ini_set('zend.exception_ignore_args', '1');

// Fixed diagnostic identifiers only. Never disclose exception messages,
// arguments, stack traces, or credentials from the disposable installer.
$installationStage = 'input';
register_shutdown_function(static function () use (&$installationStage): void {
  $error = error_get_last();
  if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    fwrite(STDERR, "GREYROCK_INSTALL_FATAL_STAGE={$installationStage}\\n");
  }
});
set_exception_handler(static function (Throwable $error) use (&$installationStage): void {
  fwrite(STDERR, "GREYROCK_INSTALL_EXCEPTION_STAGE={$installationStage}\\n");
  exit(1);
});


// WP-CLI core install may print its reconstructed command, including prompted
// passwords. Call the installer directly; never construct a command with secrets.
if (!isset($args[0]) || !filter_var($args[0], FILTER_VALIDATE_URL)) {
  throw new RuntimeException('Disposable installation URL is unavailable.');
}
define('WP_INSTALLING', true);
define('WP_SITEURL', $args[0]);
define('WP_HOME', $args[0]);
$installationStage = 'bootstrap';
require getcwd() . '/wp-load.php';
$installationStage = 'upgrade';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
$installationStage = 'credentials';
$password = trim((string) file_get_contents('/run/greyrock-admin-password'));
if (!preg_match('/\A[0-9a-f]{48}\z/D', $password) || !isset($args[0])) {
  throw new RuntimeException('Disposable installation input is unavailable.');
}
$installationStage = 'database-check';
if (is_blog_installed()) {
  throw new RuntimeException('Disposable WordPress must be uninstalled.');
}
add_filter('pre_wp_mail', static fn () => true); // Preserve --skip-email.
$installationStage = 'wp-install';
$result = wp_install('Grey Rock Automated Integration Test', 'greyrock-test-admin',
  'greyrock-test@example.invalid', true, '', $password);
unset($password);
$installationStage = 'options';
update_option('siteurl', $args[0]);
update_option('home', $args[0]);
$installationStage = 'verify';
if (is_wp_error($result) || !is_blog_installed()) {
  throw new RuntimeException('Disposable installation failed.');
}
unset($result);
