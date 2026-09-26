<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Services;

/*
 * Direct database access is intentional in this repository class.
 * It owns the plugin's synchronization-ownership table.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
final class BlockOwnership {
  public const TABLE = 'wpcf_sync_ownership';

  public const OWNER_MANUAL = 'manual';
  public const OWNER_WORDFENCE = 'wordfence';

  /**
   * Create or update the ownership table.
   *
   * One IP may have more than one owner. The composite primary key prevents
   * duplicate ownership rows without collapsing independent owners together.
   */
  public static function create_table(): void {
    global $wpdb;

    $table_name = $wpdb->prefix . self::TABLE;
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$table_name} (
      ip VARCHAR(45) NOT NULL,
      owner VARCHAR(32) NOT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (ip, owner),
      KEY owner (owner)
    ) {$charset_collate};";

    dbDelta($sql);
  }

  /**
   * Add ownership for an IP address.
   */
  public static function add(string $ip, string $owner): bool {
    global $wpdb;

    if (!self::is_valid_owner($owner)) {
      return false;
    }

    $table = $wpdb->prefix . self::TABLE;
    $now = current_time('mysql');

    $result = $wpdb->query(
      $wpdb->prepare(
        "INSERT INTO {$table}
          (ip, owner, created_at)
         VALUES
          (%s, %s, %s)
         ON DUPLICATE KEY UPDATE
          created_at = created_at",
        $ip,
        $owner,
        $now
      )
    );

    return $result !== false;
  }

  /**
   * Remove one ownership claim for an IP address.
   *
   * An absent ownership row is already the required final state.
   */
  public static function remove(string $ip, string $owner): bool {
    global $wpdb;

    if (!self::is_valid_owner($owner)) {
      return false;
    }

    $table = $wpdb->prefix . self::TABLE;

    return $wpdb->delete(
      $table,
      [
        'ip' => $ip,
        'owner' => $owner,
      ],
      [
        '%s',
        '%s',
      ]
    ) !== false;
  }

  /**
   * Return true when the specified owner currently owns the IP.
   */
  public static function has(string $ip, string $owner): bool {
    global $wpdb;

    if (!self::is_valid_owner($owner)) {
      return false;
    }

    $table = $wpdb->prefix . self::TABLE;

    return (bool) $wpdb->get_var(
      $wpdb->prepare(
        "SELECT 1
         FROM {$table}
         WHERE ip = %s
           AND owner = %s
         LIMIT 1",
        $ip,
        $owner
      )
    );
  }

  /**
   * Return true when any owner still requires the IP to remain synchronized.
   */
  public static function has_any(string $ip): bool {
    global $wpdb;

    $table = $wpdb->prefix . self::TABLE;

    return (bool) $wpdb->get_var(
      $wpdb->prepare(
        "SELECT 1
         FROM {$table}
         WHERE ip = %s
         LIMIT 1",
        $ip
      )
    );
  }

  /**
   * Return all IPs currently owned by one source.
   *
   * @return string[]
   */
  public static function get_ips_for_owner(string $owner): array {
    global $wpdb;

    if (!self::is_valid_owner($owner)) {
      return [];
    }

    $table = $wpdb->prefix . self::TABLE;

    return array_values(array_filter(array_map(
      'strval',
      $wpdb->get_col(
        $wpdb->prepare(
          "SELECT ip
           FROM {$table}
           WHERE owner = %s",
          $owner
        )
      )
    )));
  }

  private static function is_valid_owner(string $owner): bool {
    return in_array(
      $owner,
      [
        self::OWNER_MANUAL,
        self::OWNER_WORDFENCE,
      ],
      true
    );
  }
}
