<?php


use WPCF\FirewallSync\Admin\Settings;
use WPCF\FirewallSync\Services\SyncScheduler;

if (!function_exists('submit_button')) {
    require_once ABSPATH . 'wp-admin/includes/template.php';
}

function greyrock_render_action_button(string $action, string $label, string $disabled = ''): string {
    ob_start();
    Settings::render_action_button($action, $label, 'secondary', $disabled);
    return (string) ob_get_clean();
}

function greyrock_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
}

$enabled_sync = greyrock_render_action_button(
    'firewall_sync_now',
    'Sync Now'
);

$enabled_cleanup = greyrock_render_action_button(
    'firewal_sync_cleanup_now',
    'Run Cleanup Now'
);

$enabled_reconcile = greyrock_render_action_button(
    'firewall_sync_reconcile',
    'Run Reconciliation Now'
);

$locked_sync = greyrock_render_action_button(
    'firewall_sync_now',
    'Sync Now',
    'disabled'
);

greyrock_assert(
    stripos($enabled_sync, ' disabled') === false,
    'Sync Now rendered disabled without a synchronization lock.'
);

greyrock_assert(
    stripos($enabled_cleanup, ' disabled') === false,
    'Run Cleanup Now rendered disabled.'
);

greyrock_assert(
    stripos($enabled_reconcile, ' disabled') === false,
    'Run Reconciliation Now rendered disabled.'
);

greyrock_assert(
    stripos($locked_sync, ' disabled') !== false,
    'Sync Now did not render disabled when a lock was requested.'
);

echo 'PASS: Site Action buttons render the expected enabled/disabled state.' . PHP_EOL;

delete_option('firewall_sync_last_result');
delete_option('firewall_sync_last_error');

$persist_method = new ReflectionMethod(
    SyncScheduler::class,
    'persist_last_result'
);
$error_property = new ReflectionProperty(
    SyncScheduler::class,
    'lastErrorMessage'
);

$error_property->setValue(
    null,
    'Cloudflare test operation failed: restricted source address (HTTP 403)'
);
$persist_method->invoke(null, false);

greyrock_assert(
    SyncScheduler::get_last_persisted_result()
        === SyncScheduler::RESULT_FAILURE,
    'Failed synchronization result was not persisted.'
);

greyrock_assert(
    SyncScheduler::get_last_persisted_error()
        === 'Cloudflare test operation failed: restricted source address (HTTP 403)',
    'Failed synchronization error was not persisted.'
);

$persist_method->invoke(null, true);

greyrock_assert(
    SyncScheduler::get_last_persisted_result()
        === SyncScheduler::RESULT_SUCCESS,
    'Successful synchronization result was not persisted.'
);

greyrock_assert(
    SyncScheduler::get_last_persisted_error() === '',
    'Successful synchronization did not clear the previous persisted error.'
);

delete_option('firewall_sync_last_result');
delete_option('firewall_sync_last_error');

echo 'PASS: Synchronization result/error persistence behaves correctly.' . PHP_EOL;
