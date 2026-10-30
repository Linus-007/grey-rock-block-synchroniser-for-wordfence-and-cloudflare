<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Services;

/*
 * Dedicated, site-scoped append-only decision history. It is never authority
 * for forwarding, retries, ownership or deletion.
 * phpcs:disable WordPress.DB.DirectDatabaseQuery
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
final class AuditLog {
  public const TABLE = 'wpcf_sync_audit';

  public static function create_table(): void {
    global $wpdb;
    $table = $wpdb->prefix . self::TABLE;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta("CREATE TABLE {$table} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      fingerprint CHAR(64) NOT NULL,
      ip VARCHAR(45) NOT NULL,
      evidence_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
      source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      reason VARCHAR(255) NOT NULL,
      created_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY fingerprint (fingerprint),
      KEY created_at (created_at),
      KEY ip (ip)
    ) ENGINE=InnoDB {$charset};");
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
      throw new \RuntimeException('Grey Rock could not create its historical audit table.');
    }
  }

  public static function record(string $ip, string $outcome, string $reason, int $evidence = 0, int $source_id = 0): void {
    global $wpdb;
    $table = $wpdb->prefix . self::TABLE;
    // Only internal reason codes belong here: never URLs, usernames, hit
    // payloads, provider response bodies or credentials.
    $reason = substr($outcome . ': ' . $reason, 0, 200);
    $identity = $ip . "\0" . $reason . "\0" . $evidence . "\0" . $source_id;
    if (in_array($outcome, ['success', 'failure'], true)) { $identity .= random_bytes(16); }
    $fingerprint = hash('sha256', $identity);
    $stored = AtomicFence::run(static function () use ($table, $ip, $reason, $fingerprint, $evidence, $source_id): bool {
      global $wpdb;
      return $wpdb->query($wpdb->prepare(
        "INSERT INTO {$table} (fingerprint, ip, reason, created_at, evidence_at, source_id)
         VALUES (%s, %s, %s, %s, %d, %d)
         ON DUPLICATE KEY UPDATE id = id",
        $fingerprint, $ip, $reason, AtomicFence::mysql_time(), max(0, $evidence), max(0, $source_id)
      )) !== false;
    }, [$table]);
    if (!$stored) {
      throw new \RuntimeException('Grey Rock could not persist historical audit evidence.');
    }
  }

  /** Bound transaction size while amortising lease admission over history. */
  public static function record_many(array $entries): void {
    global $wpdb;
    foreach (array_chunk($entries, 50) as $chunk) {
      AtomicFence::run(static function () use ($chunk): bool {
        foreach ($chunk as $entry) { self::record(...$entry); }
        return true;
      }, [$wpdb->prefix . self::TABLE]);
    }
  }

  /** Aggregate at read time; original evidence rows remain untouched. */
  public static function get_logs(int $limit = 20, int $offset = 0, array $filters = [], string $orderby = 'created_at', string $order = 'DESC'): array {
    global $wpdb;
    $table = $wpdb->prefix . self::TABLE;
    AtomicConnection::identifier($table);
    $where = self::where($filters);
    $direction = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
    $sort = match ($orderby) {
      'ip' => "(LOCATE(':', ip) > 0) {$direction}, INET6_ATON(ip)",
      'reason' => 'MIN(reason)',
      'event_count' => 'COUNT(*)',
      default => 'created_at',
    };
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT ip, created_at, COUNT(*) AS event_count FROM `{$table}` WHERE {$where}
       GROUP BY ip, created_at ORDER BY {$sort} {$direction}, created_at {$direction}, ip {$direction}
       LIMIT %d OFFSET %d", max(1, $limit), max(0, $offset)
    ), ARRAY_A);
    foreach ($rows as &$row) {
      // Avoid GROUP_CONCAT/JSON aggregation limits silently truncating outcomes.
      $reasons = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT reason FROM `{$table}` WHERE ip = %s AND created_at = %s ORDER BY reason",
        $row['ip'], $row['created_at']
      ));
      $row['reason'] = implode("; ", $reasons);
      $row['event_count'] = (int) $row['event_count'];
    }
    return $rows;
  }

  public static function count(array $filters = []): int {
    global $wpdb;
    $table = $wpdb->prefix . self::TABLE;
    AtomicConnection::identifier($table);
    $where = self::where($filters);
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM (SELECT ip, created_at FROM `{$table}` WHERE {$where} GROUP BY ip, created_at) AS audit_groups");
  }

  private static function where(array $filters): string {
    global $wpdb;
    $parts = ['1=1'];
    if (!empty($filters['ip'])) { $parts[] = $wpdb->prepare('ip = %s', trim((string) $filters['ip'])); }
    if (!empty($filters['reason'])) {
      $table = $wpdb->prefix . self::TABLE;
      AtomicConnection::identifier($table);
      $parts[] = $wpdb->prepare("EXISTS (SELECT 1 FROM `{$table}` AS matched WHERE matched.ip = `{$table}`.ip AND matched.created_at = `{$table}`.created_at AND matched.reason LIKE %s)", '%' . $wpdb->esc_like(trim((string) $filters['reason'])) . '%');
    }
    foreach (['date_from' => ['>=', '00:00:00'], 'date_to' => ['<=', '23:59:59']] as $key => [$operator, $time]) {
      $value = (string) ($filters[$key] ?? '');
      $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
      if ($date && $date->format('Y-m-d') === $value) {
        $parts[] = $wpdb->prepare("created_at {$operator} %s", $value . ' ' . $time);
      }
    }
    return implode(' AND ', $parts);
  }
}
