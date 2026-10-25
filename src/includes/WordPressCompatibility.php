<?php

declare(strict_types=1);

namespace WPCF\FirewallSync;

/** Stable-release policy; WordPress core enforces the activation minimum. */
final class WordPressCompatibility {
  public const MINIMUM = '7.0';

  public static function is_stable($version): bool {
    return is_string($version)
      && preg_match('/\A[0-9]+\.[0-9]+(?:\.[0-9]+)?\z/D', $version) === 1;
  }

  public static function is_supported($version): bool {
    return self::is_stable($version)
      && version_compare($version, self::MINIMUM, '>=');
  }

  public static function notice(): void {
    echo '<div class="notice notice-error"><p>'
      . esc_html__(
        'Grey Rock requires a stable WordPress release, version 7.0 or later. Synchronization is disabled on this installation.',
        'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
      )
      . '</p></div>';
  }
}
