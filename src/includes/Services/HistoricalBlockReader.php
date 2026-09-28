<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Services;


/*
 * Direct database access is required to read Wordfence WAF history.
 * Wordfence resolves the network table prefix and table-name case
 * through wfDB::networkTable('wfHits'); it is never supplied by a user.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
 */
final class HistoricalBlockReader {
  private const WORDFENCE_ACTION = 'blocked:waf';
  private const WORDFENCE_ACTIVE_ACTION = 'blocked:wordfence';

  /**
   * Read historical Wordfence WAF blocks from the shared hits table.
   *
   * @return array<int, array{
   *   ip: string,
   *   event_count: int,
   *   latest_event: int
   * }>|null Null means Wordfence evidence could not be read completely.
   */
  public static function get_candidates(
    int $lookback_hours,
    int $minimum_events,
    array $site_hosts = [],
    array $watermarks = []
  ): ?array {
    global $wpdb;

    $lookback_hours = self::validated_lookback_hours(
      $lookback_hours
    );

    $minimum_events = self::validated_minimum_events(
      $minimum_events
    );

    $table = \wfDB::networkTable('wfHits');

    $table_exists = $wpdb->get_var(
      $wpdb->prepare(
        'SHOW TABLES LIKE %s',
        $wpdb->esc_like($table)
      )
    );

    if ($table_exists !== $table) {
      return null;
    }

    $cutoff = time() - ($lookback_hours * HOUR_IN_SECONDS);

    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT
          HEX(IP) AS ip_hex,
          ctime AS event_time,
          URL AS event_url
        FROM {$table}
        WHERE action = %s
          AND ctime >= %f
        ORDER BY ctime DESC",
        self::WORDFENCE_ACTION,
        (float) $cutoff
      ),
      ARRAY_A
    );

    if (!is_array($rows)) {
      return null;
    }

    $hosts = self::normalize_hosts($site_hosts);
    $events_by_ip = [];

    foreach ($rows as $row) {
      $ip = self::decode_wordfence_ip(
        (string) ($row['ip_hex'] ?? '')
      );

      if ($ip === null || !IpValidator::validate_public_ip($ip)) {
        continue;
      }

      $event_host = self::host_from_url(
        (string) ($row['event_url'] ?? '')
      );

      if ($event_host === null || !isset($hosts[$event_host])) {
        continue;
      }

      $event_time = (int) floor(
        (float) ($row['event_time'] ?? 0)
      );
      $watermark = max(
        0,
        (int) ($watermarks[$ip] ?? 0),
        ResetWatermarkStore::get($ip)
      );

      if ($event_time <= $watermark) {
        continue;
      }

      if (!isset($events_by_ip[$ip])) {
        $events_by_ip[$ip] = [];
      }

      $events_by_ip[$ip][] = $event_time;
    }

    $candidates = [];

    foreach ($events_by_ip as $ip => $event_times) {
      $event_count = count($event_times);

      if ($event_count < $minimum_events) {
        continue;
      }

      $candidates[] = [
        'ip' => $ip,
        'event_count' => $event_count,
        'latest_event' => max($event_times),
      ];
    }

    usort(
      $candidates,
      static fn (array $left, array $right): int =>
        $right['latest_event'] <=> $left['latest_event']
    );

    return $candidates;
  }

  /**
   * Determine whether an active network-wide Wordfence block has request
   * evidence attributable to the current WordPress site.
   *
   * Wordfence stores active blocks in a network table on multisite. The
   * corresponding wfHits rows retain the request URL and therefore the
   * originating site hostname.
   *
   * Null means Wordfence evidence could not be read completely.
   */
  public static function has_active_block_evidence(
    string $ip,
    int $blocked_time,
    array $site_hosts = []
  ): ?bool {
    global $wpdb;

    $ip = IpValidator::normalize_public_ip($ip) ?? '';

    if ($ip === '' || $blocked_time <= 0) {
      return false;
    }

    $hosts = self::normalize_hosts($site_hosts);

    if (empty($hosts)) {
      return false;
    }

    $table = \wfDB::networkTable('wfHits');

    $table_exists = $wpdb->get_var(
      $wpdb->prepare(
        'SHOW TABLES LIKE %s',
        $wpdb->esc_like($table)
      )
    );

    if ($table_exists !== $table) {
      return null;
    }

    $ip_hex = self::encode_wordfence_ip_hex($ip);

    if ($ip_hex === null) {
      return false;
    }

    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT
          URL AS event_url
        FROM {$table}
        WHERE action = %s
          AND HEX(IP) = %s
          AND ctime >= %f
        ORDER BY ctime DESC",
        self::WORDFENCE_ACTIVE_ACTION,
        $ip_hex,
        (float) $blocked_time
      ),
      ARRAY_A
    );

    if (!is_array($rows)) {
      return null;
    }

    foreach ($rows as $row) {
      $event_host = self::host_from_url(
        (string) ($row['event_url'] ?? '')
      );

      if ($event_host !== null && isset($hosts[$event_host])) {
        return true;
      }
    }

    return false;
  }

  /** @return array<string, bool> */
  private static function normalize_hosts(array $hosts): array {
    $normalized = [];

    foreach ($hosts as $host) {
      $host = strtolower(rtrim(trim((string) $host), '.'));

      if ($host !== '') {
        $normalized[$host] = true;
      }
    }

    return $normalized;
  }

  private static function host_from_url(string $url): ?string {
    $host = wp_parse_url($url, PHP_URL_HOST);

    if (!is_string($host) || $host === '') {
      return null;
    }

    return strtolower(rtrim($host, '.'));
  }

  private static function encode_wordfence_ip_hex(
    string $ip
  ): ?string {
    $binary = inet_pton($ip);

    if ($binary === false) {
      return null;
    }

    if (strlen($binary) === 4) {
      $binary = str_repeat("\0", 10) . "\xff\xff" . $binary;
    }

    return strtoupper(bin2hex($binary));
  }

  private static function decode_wordfence_ip(
    string $hex
  ): ?string {
    if (
      $hex === ''
      || strlen($hex) !== 32
      || !ctype_xdigit($hex)
    ) {
      return null;
    }

    $binary = hex2bin($hex);

    if ($binary === false) {
      return null;
    }

    $ip = inet_ntop($binary);

    if ($ip === false) {
      return null;
    }

    if (stripos($ip, '::ffff:') === 0) {
      $mapped_ipv4 = substr($ip, 7);

      return filter_var(
        $mapped_ipv4,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_IPV4
      )
        ? $mapped_ipv4
        : null;
    }

    return filter_var($ip, FILTER_VALIDATE_IP)
      ? $ip
      : null;
  }

  private static function validated_lookback_hours(
    int $hours
  ): int {
    return in_array($hours, [1, 3, 6, 12, 24], true)
      ? $hours
      : 24;
  }

  private static function validated_minimum_events(
    int $minimum_events
  ): int {
    if ($minimum_events < 1 || $minimum_events > 100) {
      return 1;
    }

    return $minimum_events;
  }
}
