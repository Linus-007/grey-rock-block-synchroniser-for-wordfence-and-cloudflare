<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Services;

use wpdb;

final class BlockLogger {
  private const TABLE = 'wpcf_sync_blocks';

  public static function create_table(): void {
    global $wpdb;

    $table_name = $wpdb->prefix . self::TABLE;
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$table_name} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      ip VARCHAR(45) NOT NULL,
      reason VARCHAR(255) DEFAULT 'sync',
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      synced_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      expires_at DATETIME DEFAULT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ip (ip),
      KEY expires_at (expires_at),
      KEY created_at (created_at)
    ) {$charset_collate};";

    dbDelta($sql);
  }

  public static function log(string $ip, string $reason = 'sync', ?string $expires_at = null): void {
    global $wpdb;

    $wpdb->insert(
      $wpdb-prefix . self::TABLE,
      [
        'ip' => $ip,
        'reason' => $reason,
        'created_at' => current_time('mysql'),
        'synced_at' => current_time('mysql'),
        'expires_at' => $expires_at
      ],
      ['%s', '%s', '%s', '%s', '%s']
    );
  }

  public static function get_logs(int $limit = 20, int $offset = 0): array {
    global $wpdb;

    $table = $wpdb->prefix . self::TABLE;

    return $wpdb->get_results(
      $wpdb->prepare("SELECT ip, reason, created_at FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d", $limit, $offset),
      ARRAY_A
    );
  }

  public static function count(): int {
    global $wpdb;

    $table = $wpdb->prefix . self::TABLE;

    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
  }

  public static function has_synced(string $ip): bool {
    global $wbdb;

    $table = $wpdb->prefix . self::TABLE;

    $result = $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$table} WHERE ip = %s",
      $ip
    ));

    return (int) $result > 0;
  }
}