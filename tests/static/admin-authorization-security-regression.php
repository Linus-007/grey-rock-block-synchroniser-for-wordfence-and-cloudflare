<?php

declare(strict_types=1);

use WPCF\FirewallSync\Admin\Fields;
require dirname(__DIR__, 2) . '/src/includes/Admin/Fields.php';
final class PermissionDenied extends RuntimeException {}
final class NonceDenied extends RuntimeException {}
$authorized = false;
$network_authorized = false;
$nonce_valid = true;
function current_user_can($capability): bool { return $capability === 'manage_network_options' ? $GLOBALS['network_authorized'] : $GLOBALS['authorized']; }
function is_multisite(): bool { return true; }
function check_admin_referer(...$args): void {
  if (!$GLOBALS['nonce_valid']) { throw new NonceDenied('nonce'); }
}
function wp_die($message): never { throw new PermissionDenied('permission'); }
function esc_html__($message, $domain): string { return $message; }
function sanitize_key($value): string { return (string) $value; }
function wp_unslash($value) { return $value; }
$handlers = ['handle_save_settings', 'handle_validate', 'handle_test_block', 'handle_manual_list_ip', 'handle_network_sync_now', 'handle_sync_now', 'handle_cleanup_now', 'handle_reconcile', 'handle_manual_block'];
foreach ($handlers as $handler) {
  foreach (['site', 'network'] as $scope) {
    $_POST = ['firewall_sync_scope' => $scope];
    foreach ([false, true] as $test_nonce) {
      $authorized = $test_nonce;
      $network_authorized = $test_nonce;
      $nonce_valid = !$test_nonce;
      try {
        Fields::$handler();
        throw new LogicException('Unauthorized handler reached a side effect.');
      } catch (PermissionDenied $error) {
        if ($test_nonce) { throw $error; }
      } catch (NonceDenied $error) {
        if (!$test_nonce) { throw $error; }
      }
    }
  }
}
$authorized = true;
$network_authorized = false;
$nonce_valid = true;
$_POST = ['firewall_sync_scope' => 'network'];
foreach (['handle_save_settings', 'handle_validate', 'handle_test_block', 'handle_manual_list_ip', 'handle_network_sync_now'] as $handler) {
  try {
    Fields::$handler();
    throw new LogicException('Site administrator crossed the network authorization boundary.');
  } catch (PermissionDenied $error) {
    // Expected: a valid site nonce does not grant network capabilities.
  }
}
echo "PASS: All nine privileged handlers reject missing capability or invalid nonce before side effects.\n";
