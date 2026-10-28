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
  private static array $pruned = [];

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
    $prune = !isset(self::$pruned[$table]);
    $stored = AtomicFence::run(static function () use ($table, $ip, $reason, $fingerprint, $evidence, $prune, $source_id): bool {
      global $wpdb;
      if ($prune && $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < DATE_SUB(%s, INTERVAL 30 DAY)", AtomicFence::mysql_time())) === false) { return false; }
      return $wpdb->query($wpdb->prepare(
        "INSERT INTO {$table} (fingerprint, ip, reason, created_at, evidence_at, source_id)
         VALUES (%s, %s, %s, %s, %d, %d)
         ON DUPLICATE KEY UPDATE id = id",
        $fingerprint, $ip, $reason, AtomicFence::mysql_time(), max(0, $evidence), max(0, $source_id)
      )) !== false;
    }, [$table]);
    if ($stored) { self::$pruned[$table] = true; }
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

  public static function get_logs(int $limit = 20, int $offset = 0, array $filters = [], string $orderby = 'created_at', string $order = 'DESC'): array {
    return BlockLogger::get_logs($limit, $offset, $filters, $orderby, $order, true);
  }

  public static function count(array $filters = []): int {
    return BlockLogger::count($filters, true);
  }
}
