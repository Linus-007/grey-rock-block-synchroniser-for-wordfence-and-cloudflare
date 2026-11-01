<?php
/** Child of the existing disposable database regression matrix. */
if (getenv('GREY_ROCK_TASK13_DISPOSABLE') !== 'YES') { exit(2); }
$_SERVER['HTTP_HOST'] = 'greyrock.test'; $_SERVER['REQUEST_URI'] = '/';
require ($argv[1] ?? '/var/www/html') . '/wp-load.php';
if (!str_starts_with(DB_NAME, 'greyrock_task13_')) { exit(2); }
if (is_multisite()) { switch_to_blog((int) $argv[2]); }
require __DIR__ . '/../fixtures/abuseipdb-http.php';
$GLOBALS['gr_abuse_response_mode'] = 'slow';
try { \WPCF\FirewallSync\AbuseIPDB\Reporter::drain(\WPCF\FirewallSync\Config::get_effective_options(), true); }
catch (RuntimeException $error) {
  if (!str_contains($error->getMessage(), 'Another worker')) { throw $error; }
  echo 'BUSY: ' . $error->getMessage() . "\n";
}
global $wpdb;
echo 'MOCK WORKER PASS; cf_reads=' . ($GLOBALS['gr_abuse_cf_reads'] ?? 0) . '; reports=' . count($GLOBALS['gr_abuse_requests'] ?? []) . "\n";
