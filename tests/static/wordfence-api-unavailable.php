<?php

declare(strict_types=1);

if (!function_exists('__')) {
  function __(string $text, string $domain = ''): string {
    return $text;
  }
}

$root = dirname(__DIR__, 2);
require_once $root . '/src/includes/Services/SyncScheduler.php';

$method = new ReflectionMethod(
  WPCF\FirewallSync\Services\SyncScheduler::class,
  'read_active_wordfence_blocks'
);
$method->setAccessible(true);
$blocks = $method->invoke(null);
$error = WPCF\FirewallSync\Services\SyncScheduler::get_last_error_message();

if ($blocks !== null) {
  throw new RuntimeException('Unavailable Wordfence API did not fail closed.');
}
if (strpos($error, 'Wordfence') === false || strpos($error, 'supported') === false) {
  throw new RuntimeException('Unavailable Wordfence API error was not actionable.');
}

echo "Wordfence unsupported API handling: PASS\n";
