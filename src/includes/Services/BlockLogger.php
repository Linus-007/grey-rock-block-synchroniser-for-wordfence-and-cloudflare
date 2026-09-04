<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Services;


/*
 * Direct database access is intentional in this repository class.
 * It owns a dedicated plugin table and must return current log data.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
final class BlockLogger {
  public const TABLE = 'wpcf_sync_blocks';
  public const MAX_FAILURES = 3;

  private const MAX_REASON_LENGTH = 200;

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
      synced_at DATETIME DEFAULT NULL,
      expires_at DATETIME DEFAULT NULL,
      fail_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
      PRIMARY KEY (id),
      UNIQUE KEY ip (ip),
      KEY expires_at (expires_at),
      KEY created_at (created_at),
      KEY synchronization_state (synced_at, fail_count)
    ) {$charset_collate};";

    dbDelta($sql);
  }

  /**
   * Record a successful synchronization.
   *
   * Existing failed rows are converted into successful rows so a retry can
   * recover without violating the unique IP constraint.
   */
  public static function log(
    string $ip,
    string $reason = 'sync',
    ?string $expires_at = null
  ): void {
    global $wpdb;

    $table = $wpdb->prefix . self::TABLE;
    $now = current_time('mysql');
    $reason = self::normalize_reason($reason);

    $wpdb->query(
      $wpdb->prepare(
        "INSERT INTO {$table}
          (ip, reason, created_at, synced_at, expires_at, fail_count)
         VALUES
          (%s, %s, %s, %s, %s, 0)
         ON DUPLICATE KEY UPDATE
          reason = VALUES(reason),
          created_at = VALUES(created_at),
          synced_at = VALUES(synced_at),
          expires_at = VALUES(expires_at),
          fail_count = 0",
        $ip,
        $reason,
        $now,
        $now,
        $expires_at
      )
    );
  }

  public static function get_logs(
    int $limit = 20,
    int $offset = 0,
    array $filters = [],
    string $orderby = 'created_at',
    string $order = 'DESC'
  ): array {
    global $wpdb;

    $table = $wpdb->prefix . self::TABLE;

    $ip = isset($filters['ip'])
      ? trim((string) $filters['ip'])
      : '';

    $reason = isset($filters['reason'])
      ? trim((string) $filters['reason'])
      : '';

    $reason_like = $reason === ''
      ? ''
      : '%' . $wpdb->esc_like($reason) . '%';

    $date_from = isset($filters['date_from'])
      && self::is_log_date((string) $filters['date_from'])
        ? (string) $filters['date_from'] . ' 00:00:00'
        : '';

    $date_to = isset($filters['date_to'])
      && self::is_log_date((string) $filters['date_to'])
        ? (string) $filters['date_to'] . ' 23:59:59'
        : '';

    $allowed_orderby = [
      'ip' => 'ip',
      'reason' => 'reason',
      'created_at' => 'created_at',
    ];

    $orderby_sql = $allowed_orderby[$orderby] ?? 'created_at';
    $ascending = 'ASC' === strtoupper($order);

    /*
     * IP addresses must not be sorted as strings. INET6_ATON() produces
     * binary address values for both IPv4 and IPv6. The first ORDER BY
     * expression keeps the two address families grouped predictably.
     */
    if ('ip' === $orderby_sql) {
      if ($ascending) {
        return $wpdb->get_results(
          $wpdb->prepare(
            "SELECT ip, reason, created_at
             FROM %i
             WHERE (%s = '' OR ip = %s)
               AND (%s = '' OR reason LIKE %s)
               AND (%s = '' OR created_at >= %s)
               AND (%s = '' OR created_at <= %s)
             ORDER BY (LOCATE(':', ip) > 0) ASC, INET6_ATON(ip) ASC
             LIMIT %d OFFSET %d",
            $table,
            $ip,
            $ip,
            $reason,
            $reason_like,
            $date_from,
            $date_from,
            $date_to,
            $date_to,
            $limit,
            $offset
          ),
          ARRAY_A
        );
      }

      return $wpdb->get_results(
        $wpdb->prepare(
          "SELECT ip, reason, created_at
           FROM %i
           WHERE (%s = '' OR ip = %s)
             AND (%s = '' OR reason LIKE %s)
             AND (%s = '' OR created_at >= %s)
             AND (%s = '' OR created_at <= %s)
           ORDER BY (LOCATE(':', ip) > 0) DESC, INET6_ATON(ip) DESC
           LIMIT %d OFFSET %d",
          $table,
          $ip,
          $ip,
          $reason,
          $reason_like,
          $date_from,
          $date_from,
          $date_to,
          $date_to,
          $limit,
          $offset
        ),
        ARRAY_A
      );
    }

    if ($ascending) {
      return $wpdb->get_results(
        $wpdb->prepare(
          "SELECT ip, reason, created_at
           FROM %i
           WHERE (%s = '' OR ip = %s)
             AND (%s = '' OR reason LIKE %s)
             AND (%s = '' OR created_at >= %s)
             AND (%s = '' OR created_at <= %s)
           ORDER BY %i ASC
           LIMIT %d OFFSET %d",
          $table,
          $ip,
          $ip,
          $reason,
          $reason_like,
          $date_from,
          $date_from,
          $date_to,
          $date_to,
          $orderby_sql,
          $limit,
          $offset
        ),
        ARRAY_A
      );
    }

    return $wpdb->get_results(
      $wpdb->prepare(
        "SELECT ip, reason, created_at
         FROM %i
         WHERE (%s = '' OR ip = %s)
           AND (%s = '' OR reason LIKE %s)
           AND (%s = '' OR created_at >= %s)
           AND (%s = '' OR created_at <= %s)
         ORDER BY %i DESC
         LIMIT %d OFFSET %d",
        $table,
        $ip,
        $ip,
        $reason,
        $reason_like,
        $date_from,
        $date_from,
        $date_to,
        $date_to,
        $orderby_sql,
        $limit,
        $offset
      ),
      ARRAY_A
    );
  }

  public static function count(array $filters = []): int {
    global $wpdb;

    $table = $wpdb->prefix . self::TABLE;

    $ip = isset($filters['ip'])
      ? trim((string) $filters['ip'])
      : '';

    $reason = isset($filters['reason'])
      ? trim((string) $filters['reason'])
      : '';

    $reason_like = $reason === ''
      ? ''
      : '%' . $wpdb->esc_like($reason) . '%';

    $date_from = isset($filters['date_from'])
      && self::is_log_date((string) $filters['date_from'])
        ? (string) $filters['date_from'] . ' 00:00:00'
        : '';

    $date_to = isset($filters['date_to'])
      && self::is_log_date((string) $filters['date_to'])
        ? (string) $filters['date_to'] . ' 23:59:59'
        : '';

    return (int) $wpdb->get_var(
      $wpdb->prepare(
        "SELECT COUNT(*)
         FROM %i
         WHERE (%s = '' OR ip = %s)
           AND (%s = '' OR reason LIKE %s)
           AND (%s = '' OR created_at >= %s)
           AND (%s = '' OR created_at <= %s)",
        [
          $table,
          $ip,
          $ip,
          $reason,
          $reason_like,
          $date_from,
          $date_from,
          $date_to,
          $date_to,
        ]
      )
    );
  }

  private static function is_log_date(string $value): bool {
    $date = \DateTimeImmutable::createFromFormat(
      '!Y-m-d',
      $value
    );

    return $date instanceof \DateTimeImmutable
      && $date->format('Y-m-d') === $value;
  }

  /**
   * Return true only when the IP completed synchronization successfully.
   */
  public static function has_synced(string $ip): bool {
    global $wpdb;

    $table = $wpdb->prefix . self::TABLE;

    return (bool) $wpdb->get_var(
      $wpdb->prepare(
        "SELECT 1
         FROM {$table}
         WHERE ip = %s
           AND synced_at IS NOT NULL
           AND fail_count = 0
         LIMIT 1",
        $ip
      )
    );
  }

  /**
   * Return the Unix timestamp of the latest successful synchronization.
   */
  public static function get_synced_timestamp(string $ip): int {
    global $wpdb;

    $table = $wpdb->prefix . self::TABLE;
    $synced_at = $wpdb->get_var(
      $wpdb->prepare(
        "SELECT synced_at
         FROM {$table}
         WHERE ip = %s
           AND synced_at IS NOT NULL
           AND fail_count = 0
         LIMIT 1",
        $ip
      )
    );

    if (!is_string($synced_at) || $synced_at === '') {
      return 0;
    }

    try {
      $date = new \DateTimeImmutable($synced_at, wp_timezone());
    } catch (\Exception $exception) {
      return 0;
    }

    return $date->getTimestamp();
  }

  /**
   * Return only IPs that were successfully synchronized.
   */
  public static function get_all_ips(): array {
    global $wpdb;

    $table = $wpdb->prefix . self::TABLE;

    return $wpdb->get_col(
      "SELECT ip
       FROM {$table}
       WHERE synced_at IS NOT NULL
         AND fail_count = 0"
    );
  }

  /**
   * Record a failed synchronization attempt.
   */
  public static function mark_failed(
    string $ip,
    string $reason = 'sync',
    ?string $expires_at = null
  ): void {
    global $wpdb;

    $table = $wpdb->prefix . self::TABLE;
    $now = current_time('mysql');
    $reason = self::normalize_reason($reason);

    $wpdb->query(
      $wpdb->prepare(
        "INSERT INTO {$table}
          (ip, reason, created_at, synced_at, expires_at, fail_count)
         VALUES
          (%s, %s, %s, NULL, %s, 1)
         ON DUPLICATE KEY UPDATE
          reason = VALUES(reason),
          created_at = VALUES(created_at),
          synced_at = NULL,
          expires_at = VALUES(expires_at),
          fail_count = LEAST(fail_count + 1, %d)",
        $ip,
        $reason,
        $now,
        $expires_at,
        self::MAX_FAILURES
      )
    );
  }

  /**
   * Stop automatic retries after the configured failure limit.
   */
  public static function is_blacklisted(string $ip): bool {
    global $wpdb;

    $table = $wpdb->prefix . self::TABLE;

    return (bool) $wpdb->get_var(
      $wpdb->prepare(
        "SELECT 1
         FROM {$table}
         WHERE ip = %s
           AND synced_at IS NULL
           AND fail_count >= %d
         LIMIT 1",
        $ip,
        self::MAX_FAILURES
      )
    );
  }

  /**
   * Remove the synchronization record for an exact IP address.
   *
   * An absent record is already the required final state.
   */
  public static function remove(string $ip): bool {
    global $wpdb;

    $table = $wpdb->prefix . self::TABLE;

    return $wpdb->delete(
      $table,
      ['ip' => $ip],
      ['%s']
    ) !== false;
  }

  /**
   * Normalise text stored in the synchronization-state table.
   */
  private static function normalize_reason(string $reason): string {
    $reason = preg_replace(
      '/[\x00-\x1F\x7F]+/u',
      ' ',
      $reason
    );

    if (!is_string($reason)) {
      return 'sync';
    }

    $reason = preg_replace('/\s+/u', ' ', $reason);

    if (!is_string($reason)) {
      return 'sync';
    }

    $reason = trim($reason);

    if ($reason === '') {
      return 'sync';
    }

    if (function_exists('mb_substr')) {
      return mb_substr(
        $reason,
        0,
        self::MAX_REASON_LENGTH,
        'UTF-8'
      );
    }

    return substr($reason, 0, self::MAX_REASON_LENGTH);
  }
}
