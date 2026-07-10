<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Services;

use WPCF\FirewallSync\Config;
use WPCF\FirewallSync\Plugin;

final class MigrationManager {
  public static function run(?string $from_version): void {
    $to_version = Plugin::get_version();

    if (
      $from_version === null
      || version_compare($from_version, '1.0.0', '<')
    ) {
      self::migrate_to_1_0_0();
    }

    if (
      version_compare($to_version, '1.1.0', '>=')
      && (
        $from_version === null
        || version_compare($from_version, '1.1.0', '<')
      )
    ) {
      self::migrate_to_1_1_0();
    }
  }

  private static function migrate_to_1_0_0(): void {
    BlockLogger::create_table();
  }

  /**
   * Preserve configurations created before network inheritance existed.
   *
   * Existing site credentials remain site-specific. Sites without an
   * existing configuration continue to inherit Network Admin defaults.
   */
  private static function migrate_to_1_1_0(): void {
    $options = Config::get_site_options();

    if (!self::has_existing_configuration($options)) {
      return;
    }

    if (!isset($options['configuration_source'])) {
      $options['configuration_source'] = Config::SOURCE_SITE;
      update_option(Config::SITE_OPTION, $options);
    }
  }

  private static function has_existing_configuration(array $options): bool {
    foreach ([
      'cloudflare_api_token',
      'cloudflare_zone_id',
      'cloudflare_account_id',
      'cloudflare_list_id',
      'cloudflare_list_name',
      'cloudflare_mode',
      'sync_interval',
    ] as $key) {
      if (isset($options[$key]) && $options[$key] !== '') {
        return true;
      }
    }

    return false;
  }
}
