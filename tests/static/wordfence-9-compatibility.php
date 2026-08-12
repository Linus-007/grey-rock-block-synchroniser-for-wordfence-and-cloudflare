<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source_path = $root . '/src/includes/Services/SyncScheduler.php';
$source = file_get_contents($source_path);

if (!is_string($source)) {
  throw new RuntimeException('Could not read SyncScheduler.');
}

$assert = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException('Wordfence compatibility assertion failed.');
  }
};

$assert(
  str_contains($source, 'wfBlock::ipBlocks(true)')
    && str_contains($source, "method_exists('\\wfBlock', 'ipBlocks')"),
  'SyncScheduler must use the supported Wordfence 9 active-block API.'
);
$assert(
  !str_contains($source, 'wfBlock::getBlocks')
    && !str_contains($source, "method_exists('\\wfBlock', 'getBlocks')"),
  'Obsolete Wordfence getBlocks API must not be referenced.'
);
$assert(
  str_contains($source, '$block->ip')
    && str_contains($source, '$block->reason')
    && str_contains($source, '$block->expiration'),
  'Wordfence block objects must be read through supported properties.'
);
$assert(
  str_contains($source, 'Wordfence active-block API is unavailable')
    && str_contains($source, 'Wordfence 9 active-block API wfBlock::ipBlocks() is unavailable'),
  'Unsupported Wordfence APIs must produce actionable errors.'
);
$assert(
  str_contains($source, 'return null;')
    && str_contains($source, 'if ($blocks === null)'),
  'Unsupported active-block APIs must fail closed.'
);

final class wfBlock {
  public static bool $argument = false;

  public static function ipBlocks(bool $include_expired): array {
    self::$argument = $include_expired;

    return [
      (object) [
        'ip' => '8.8.8.8',
        'reason' => 'Known malicious User-Agents',
        'expiration' => 1900000000,
      ],
    ];
  }
}

require_once $root . '/src/includes/Services/SyncScheduler.php';
$method = new ReflectionMethod(
  WPCF\FirewallSync\Services\SyncScheduler::class,
  'read_active_wordfence_blocks'
);
$method->setAccessible(true);
$blocks = $method->invoke(null);

$assert(is_array($blocks) && count($blocks) === 1, 'Wordfence 9 blocks were not returned.');
$assert(wfBlock::$argument, 'ipBlocks() must be called with true.');
$assert(
  $blocks[0]->ip === '8.8.8.8'
    && $blocks[0]->reason === 'Known malicious User-Agents'
    && $blocks[0]->expiration === 1900000000,
  'Wordfence object properties were not preserved.'
);

echo "Wordfence 9 compatibility: PASS\n";
