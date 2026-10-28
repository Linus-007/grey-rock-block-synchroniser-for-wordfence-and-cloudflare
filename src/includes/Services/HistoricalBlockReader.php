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
  private const PAGE_SIZE = 500;

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
    array $watermarks = [],
    array $active_ips = [],
    ?callable $audit = null
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

    $rows = self::read_rows(
      $table,
      'action IN (%s, %s) AND ctime >= %f',
      [self::WORDFENCE_ACTION, self::WORDFENCE_ACTIVE_ACTION, (float) $cutoff]
    );

    $hosts = self::normalize_hosts($site_hosts);
    $events_by_ip = [];

    try {
      foreach ($rows as $row) {
        if (!self::is_site_event((string) ($row['event_url'] ?? ''), $hosts)) {
          continue;
        }

        $ip = self::decode_wordfence_ip(
          (string) ($row['ip_hex'] ?? '')
        );

        if ($ip === null || !IpValidator::validate_public_ip($ip)) {
          if ($audit !== null) {
            $audit('', 'skip', 'invalid or non-public historical address', (int) ($row['event_time'] ?? 0), (int) ($row['event_id'] ?? 0));
          }
          continue;
        }

        $action = (string) ($row['event_action'] ?? self::WORDFENCE_ACTION);
        // wfHits does not preserve a plugin block's type, duration or scope.
        // Country, UA/referrer, throttle and expired IP blocks cannot safely
        // be converted into a new address-wide block from a hit alone.
        if ($action === self::WORDFENCE_ACTIVE_ACTION && !isset($active_ips[$ip])) {
          if ($audit !== null) {
            $audit($ip, 'skip', self::plugin_skip_reason((string) ($row['event_description'] ?? '')), (int) ($row['event_time'] ?? 0), (int) ($row['event_id'] ?? 0));
          }
          continue;
        }
        if (!in_array($action, [self::WORDFENCE_ACTION, self::WORDFENCE_ACTIVE_ACTION], true)) {
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
          if ($audit !== null) {
            $audit($ip, 'skip', 'evidence suppressed by explicit reset watermark', $event_time, (int) ($row['event_id'] ?? 0));
          }
          continue;
        }

        if ($action === self::WORDFENCE_ACTION) {
          $events_by_ip[$ip] ??= ['count' => 0, 'latest' => 0];
          $events_by_ip[$ip]['count']++;
          $events_by_ip[$ip]['latest'] = max($events_by_ip[$ip]['latest'], $event_time);
        } elseif ($audit !== null) {
          $audit($ip, 'detection', 'blocked:wordfence covered by current IP-block authority', $event_time, (int) ($row['event_id'] ?? 0));
        }
      }
    } catch (\UnexpectedValueException) {
      return null;
    }

    $candidates = [];

    foreach ($events_by_ip as $ip => $event_times) {
      $event_count = $event_times['count'];

      if ($event_count < $minimum_events) {
        if ($audit !== null) {
          $audit($ip, 'skip', sprintf('historical event threshold not met (%d of %d)', $event_count, $minimum_events), $event_times['latest']);
        }
        continue;
      }

      if ($audit !== null) {
        $audit($ip, 'detection', sprintf('qualifying blocked:waf evidence (%d events)', $event_count), $event_times['latest']);
      }
      $candidates[] = [
        'ip' => $ip,
        'event_count' => $event_count,
        'latest_event' => $event_times['latest'],
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

    $rows = self::read_rows(
      $table,
      'action = %s AND HEX(IP) = %s AND ctime >= %f',
      [self::WORDFENCE_ACTIVE_ACTION, $ip_hex, (float) $blocked_time]
    );

    try {
      foreach ($rows as $row) {
        if (self::is_site_event((string) ($row['event_url'] ?? ''), $hosts)) {
          return true;
        }
      }
    } catch (\UnexpectedValueException) {
      return null;
    }

    return false;
  }

  /**
   * Descending primary-key pagination bounds each result without OFFSET races.
   * Newer concurrent inserts are evaluated on the next run, not interleaved
   * into an already-started scan. Never reconcile a partially failed scan.
   *
   * @return \Generator<int, array>
   */
  private static function read_rows(string $table, string $where, array $values): \Generator {
    global $wpdb;
    $cursor = PHP_INT_MAX;
    $page_size = self::PAGE_SIZE;
    do {
      $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id AS event_id, HEX(IP) AS ip_hex, ctime AS event_time,
          URL AS event_url, action AS event_action, actionDescription AS event_description
         FROM {$table} WHERE {$where} AND id < %d
         ORDER BY id DESC LIMIT {$page_size}",
        ...array_merge($values, [$cursor])
      ), ARRAY_A);
      if (!is_array($rows) || ($wpdb->last_error ?? '') !== '') {
        throw new \UnexpectedValueException('Historical Wordfence page could not be read completely.');
      }
      foreach ($rows as $row) { yield $row; }
      if (count($rows) < $page_size) { return; }
      $next = (int) ($rows[array_key_last($rows)]['event_id'] ?? 0);
      if ($next <= 0 || $next >= $cursor) {
        throw new \UnexpectedValueException('Historical Wordfence cursor did not advance.');
      }
      $cursor = $next;
    } while (true);
  }

  private static function plugin_skip_reason(string $description): string {
    // These are stable Wordfence 9.0.2 identifiers, not translated UI text.
    if (str_starts_with($description, 'blocked access via country blocking')) {
      return 'blocked:wordfence country policy cannot authorise an address-wide block';
    }
    if ($description === 'UA/Referrer/IP Range not allowed') {
      return 'blocked:wordfence request pattern cannot authorise an address-wide block';
    }
    if (str_starts_with($description, 'Exceeded the maximum') && str_contains($description, 'per minute')) {
      return 'blocked:wordfence rate policy requires current IP-block authority';
    }
    return 'blocked:wordfence requires current IP-block authority; type and expiry absent from hit';
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

  /** Use WordPress's domain/path routing to separate same-host subsites. */
  private static function is_site_event(string $url, array $hosts): bool {
    $host = self::host_from_url($url);
    if ($host === null || !isset($hosts[$host])) { return false; }
    if (is_multisite() && function_exists('get_site_by_path')) {
      global $wpdb;
      $path = wp_parse_url($url, PHP_URL_PATH);
      $site = get_site_by_path($host, is_string($path) && $path !== '' ? $path : '/');
      if (($wpdb->last_error ?? '') !== '') {
        throw new \UnexpectedValueException('WordPress site attribution could not be read completely.');
      }
      if ($site !== false) { return (int) $site->blog_id === get_current_blog_id(); }
      // Mapped home/siteurl aliases absent from the core site-domain catalog
      // retain the existing exact-host policy; do not invent alias routing.
    }
    return true;
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
