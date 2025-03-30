<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Services;

use wpdb;
use WPCF\FirewallSync\Plugin;

final class MigrationManager {
  public static function run(?string $from_version): void {
    $to_version = Plugin::get_version();

    if ($from_version === null || version_compare($from_version, '1.0.0', '<')) {
      self::migrate_to_1_0_0();
    }

    // if (
    //   version_compare($from_version, '1.1.0', '<') &&
    //   version_compare($to_version, '1.1.0', '>=')
    // ) {
    //   self::migrate_to_1_0_1();
    // }
    // Add more migrations as needed
  }

  private static function migrate_to_1_0_0(): void {
    BlockLogger::create_table();
  }

  // For a future release
  // private static function migrate_1_1_0(): void {
  //   global $wpdb;
  //   $table = $wpdb->prefix . 'wpcf_sync_blocks';
  //   $wpdb->query("ALTER TABLE {$table} ADD COLUMN user_agent VARCHAR(255) DEFAULT NULL");
  // }
}