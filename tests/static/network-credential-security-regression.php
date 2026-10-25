<?php

declare(strict_types=1);

use WPCF\FirewallSync\Admin\Fields;
use WPCF\FirewallSync\Config;

$root = dirname(__DIR__, 2);
require $root . '/src/includes/Services/CloudflareIdentifierValidator.php';
require $root . '/src/includes/Services/DnsAllowList.php';
require $root . '/src/includes/Config.php';
require $root . '/src/includes/Admin/Fields.php';
set_error_handler(static function (int $severity, string $message): never {
  throw new ErrorException($message, 0, $severity);
});
$network_context = false;
$network_privilege = false;
$site_options = ['configuration_source' => 'network'];
$network_options = ['cloudflare_api_token' => 'fixture-network-credential', 'allow_site_overrides' => '1'];
$field = null;
function is_multisite(): bool { return true; }
function is_network_admin(): bool { return $GLOBALS['network_context']; }
function current_user_can($capability): bool { return $capability === 'manage_options' || ($capability === 'manage_network_options' && $GLOBALS['network_privilege']); }
function get_option($key, $default = false) { return $GLOBALS['site_options']; }
function get_site_option($key, $default = false) { return $GLOBALS['network_options']; }
function update_option($key, $value): bool { $GLOBALS['site_options'] = $value; return true; }
function add_settings_field($id, $label, $callback, ...$rest): void { $GLOBALS['field'] = $callback; }
function esc_attr($text): string { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_html($text): string { return esc_attr($text); }
function __($text, $domain): string { return $text; }
function disabled($value, $current, $echo): string { return $value ? ' disabled' : ''; }
function sanitize_text_field($value): string { return trim(strip_tags((string) $value)); }
function credential_assert(bool $condition, string $message): void {
  if (!$condition) { throw new RuntimeException($message); }
}
$method = new ReflectionMethod(Fields::class, 'add_text_field');
$method->invoke(null, 'cloudflare_api_token', 'Token');
ob_start();
$field();
$html = ob_get_clean();
credential_assert(!str_contains($html, $network_options['cloudflare_api_token']), 'Network credential exposed in site administrator HTML.');
Config::update_site_options(['configuration_source' => 'site']);
credential_assert(empty($site_options['cloudflare_api_token']), 'Network credential copied into site option storage.');
$site_options = ['configuration_source' => 'site', 'cloudflare_api_token' => 'fixture-site-credential'];
ob_start(); $field(); $html = ob_get_clean();
credential_assert(str_contains($html, 'fixture-site-credential'), 'Site-owned token visibility changed.');
$network_context = true;
$network_privilege = false;
ob_start(); $field(); $html = ob_get_clean();
credential_assert(!str_contains($html, $network_options['cloudflare_api_token']), 'Network editor did not protect its credential boundary.');
$network_privilege = true;
ob_start(); $field(); $html = ob_get_clean();
credential_assert(str_contains($html, $network_options['cloudflare_api_token']), 'Authorized network token editor changed.');
echo "PASS: Network token stays out of inherited site HTML and new site overrides.\n";
