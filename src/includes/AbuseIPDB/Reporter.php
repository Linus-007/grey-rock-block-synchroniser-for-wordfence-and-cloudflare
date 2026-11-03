<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\AbuseIPDB;

use WPCF\FirewallSync\Config;
use WPCF\FirewallSync\Cloudflare\Client;
use WPCF\FirewallSync\Services\AtomicFence;
use WPCF\FirewallSync\Services\DestinationLease;
use WPCF\FirewallSync\Services\IpValidator;
use WPCF\FirewallSync\Services\BlockLogger;
use WPCF\FirewallSync\Services\AuditLog;

/*
 * Durable network-wide outbox. All mutations use the existing InnoDB fence
 * under a reporting lease. HTTP is outside transactions. Claim-before-send
 * deliberately trades automatic recovery for no blind replay after a crash.
 * phpcs:disable WordPress.DB.DirectDatabaseQuery
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
final class Reporter {
  public const HOOK = 'firewall_sync_abuseipdb_daily';
  public const TABLE = 'wpcf_abuse_incidents';
  public const LINKS = 'wpcf_abuse_links';
  public const STATE = 'wpcf_abuse_state';

  private static array $deferred = [];

  public static function defer(Client $client, array $options, string $list_id, array $entries): void {
    self::$deferred[] = [$client, $options, $list_id, $entries];
  }

  /** Enforcement has already persisted its result and released its site lock. */
  public static function flush(): void {
    $work = self::$deferred;
    self::$deferred = [];
    foreach ($work as $args) {
      try {
        self::confirmed(...$args);
      } catch (\Throwable $error) {
        error_log('Grey Rock AbuseIPDB deferred failure: ' . get_class($error));
      }
    }
  }

  private static function tables(): array {
    global $wpdb;
    return array_map(static fn ($name) => $wpdb->base_prefix . $name, [self::TABLE, self::LINKS, self::STATE]);
  }

  public static function create_tables(): void {
    global $wpdb;
    [$reports, $links, $state] = self::tables();
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta("CREATE TABLE {$reports} (
      incident CHAR(64) NOT NULL,
      api_scope CHAR(64) NOT NULL,
      site_id BIGINT UNSIGNED NOT NULL,
      network_id BIGINT UNSIGNED NOT NULL,
      destination CHAR(64) NOT NULL,
      configuration CHAR(64) NOT NULL,
      ip VARCHAR(45) NOT NULL,
      evidence_at BIGINT UNSIGNED NOT NULL,
      categories VARCHAR(40) NOT NULL,
      comment VARCHAR(255) NOT NULL,
      status VARCHAR(16) NOT NULL DEFAULT 'pending',
      retry_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
      accepted_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
      PRIMARY KEY (incident),
      KEY pending (api_scope,status,evidence_at)
    ) ENGINE=InnoDB {$charset};");
    dbDelta("CREATE TABLE {$links} (
      association CHAR(64) NOT NULL,
      incident CHAR(64) NOT NULL,
      PRIMARY KEY (association,incident)
    ) ENGINE=InnoDB {$charset};");
    dbDelta("CREATE TABLE {$state} (
      state_key VARCHAR(100) NOT NULL,
      state_value LONGTEXT NOT NULL,
      PRIMARY KEY (state_key)
    ) ENGINE=InnoDB {$charset};");
    foreach (self::tables() as $table) {
      if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
        throw new \RuntimeException('AbuseIPDB storage migration failed.');
      }
    }
  }

  public static function register(): void {
    add_action(self::HOOK, [self::class, 'daily']);
    if (!wp_next_scheduled(self::HOOK)) { wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', self::HOOK); }
  }

  public static function destination(array $options, string $list_id = ''): string {
    return hash('sha256', ($options['cloudflare_mode'] ?? 'zone_access_rules') === 'account_list'
      ? 'account:' . strtolower((string) ($options['cloudflare_account_id'] ?? '')) . ':' . strtolower($list_id)
      : 'zone:' . strtolower((string) ($options['cloudflare_zone_id'] ?? '')));
  }

  private static function scope(array $options): string { return hash('sha256', (string) ($options['abuseipdb_api_key'] ?? '')); }

  /** Called only after enforcement; independently verify actual membership. */
  public static function confirmed(Client $client, array $options, string $list_id, array $entries): void {
    if (empty($options['abuseipdb_enabled']) || empty($options['abuseipdb_api_key'])) { return; }
    try {
      $incidents = [];
      foreach ($entries as $entry) {
        if (empty($entry['record_local'])) { continue; }
        foreach ($entry['reporting'] ?? [] as $incident) {
          if ($incident !== null) { $incidents[] = [$incident, $entry]; }
        }
      }
      if ($incidents === []) { self::drain($options, false, $client); return; }
      $membership = ($options['cloudflare_mode'] ?? '') === 'account_list'
        ? $client->get_current_account_list_ips((string) $options['cloudflare_account_id'], $list_id, true)
        : $client->get_current_blocked_ips();
      if ($membership === null) { return; }
      // Membership reads hold the existing Cloudflare destination lease.
      // Admission must not compete with the dispatch lease: another worker's
      // HTTP request must never cause confirmed evidence to be dropped.
      foreach ($incidents as [$incident, $entry]) {
        if (!in_array($incident['ip'], $membership, true)) { continue; }
        self::enqueue($incident, $options, self::destination($options, $list_id), $entry);
      }
      self::drain($options, false, $client);
    } catch (\Throwable $error) {
      // Preserve Cloudflare success without exposing credentials or API bodies.
      error_log('Grey Rock AbuseIPDB reporting failure: ' . get_class($error));
    }
  }

  public static function manual(Client $client, array $options, string $list_id, string $ip, string $reason, bool $link_log = true): void {
    if (empty($options['abuseipdb_manual'])) { return; }
    // Identical repeated manual requests are one incident, not new evidence.
    $incident = Evidence::incident($ip, time(), 'manual', $reason, true);
    if ($incident === null) { return; }
    $incident['incident'] = hash('sha256', 'manual' . "\0" . $ip . "\0" . $reason);
    self::confirmed($client, $options, $list_id, [['ip' => $ip, 'record_local' => true, 'evidence' => 0, 'link_log' => $link_log, 'reporting' => [$incident]]]);
  }

  private static function enqueue(array $incident, array $options, string $destination, array $entry): void {
    global $wpdb;
    [$reports, $links] = self::tables();
    $log = $wpdb->prefix . BlockLogger::TABLE;
    $audit = $wpdb->prefix . AuditLog::TABLE;
    AtomicFence::run(static function () use ($incident, $options, $destination, $entry, $reports, $links, $log, $audit): bool {
      global $wpdb;
      if ($incident['evidence_at'] <= time() - 30 * DAY_IN_SECONDS || !IpValidator::validate_public_ip($incident['ip'])) { return true; }

      // Preserve legacy receipts and shared-account deduplication.
      // When an existing incident belongs to another AbuseIPDB credential,
      // derive an independent incident identity for the current credential.
      $api_scope = self::scope($options);
      $existing_scope = $wpdb->get_var(
        $wpdb->prepare(
          "SELECT api_scope FROM {$reports} WHERE incident=%s",
          $incident['incident']
        )
      );
      if ($wpdb->last_error !== '') {
        throw new \RuntimeException('AbuseIPDB incident lookup failed.');
      }
      if (is_string($existing_scope) && $existing_scope !== $api_scope) {
        $incident['incident'] = hash(
          'sha256',
          'credential-scoped' . "\0" . $incident['incident'] . "\0" . $api_scope
        );
        $collision_scope = $wpdb->get_var(
          $wpdb->prepare(
            "SELECT api_scope FROM {$reports} WHERE incident=%s",
            $incident['incident']
          )
        );
        if ($wpdb->last_error !== '' ||
          ($collision_scope !== null && $collision_scope !== $api_scope)) {
          throw new \RuntimeException('AbuseIPDB credential isolation failed.');
        }
      }

      $wpdb->query($wpdb->prepare("INSERT INTO {$reports}
        (incident,api_scope,site_id,network_id,destination,configuration,ip,evidence_at,categories,comment)
        VALUES (%s,%s,%d,%d,%s,%s,%s,%d,%s,%s) ON DUPLICATE KEY UPDATE incident=incident",
        $incident['incident'], self::scope($options), !empty($options['abuseipdb_origin_network']) ? 0 : get_current_blog_id(), get_current_network_id(), $destination, self::configuration_identity($options),
        $incident['ip'], $incident['evidence_at'], $incident['categories'], $incident['comment']));
      $row = $wpdb->get_row($wpdb->prepare("SELECT ip,reason,created_at FROM {$log} WHERE ip=%s AND synced_at IS NOT NULL AND fail_count=0", $incident['ip']), ARRAY_A);
      if (($entry['link_log'] ?? true) && is_array($row)) { self::link(self::association($row, false), $incident['incident']); }
      // Link this source incident's decisions, including Cloudflare no-op
      // decisions. Unknown timestamps and unrelated IP history never qualify.
      $rows = (int) ($entry['evidence'] ?? 0) > 0 ? $wpdb->get_results($wpdb->prepare("SELECT ip,created_at FROM {$audit} WHERE ip=%s AND evidence_at=%d", $incident['ip'], (int) $entry['evidence']), ARRAY_A) : [];
      foreach ($rows as $row) { self::link(self::association($row, true), $incident['incident']); }
      return true;
    }, [$reports, $links, $log, $audit]);
  }

  private static function association(array $row, bool $audit): string {
    return hash('sha256', get_current_blog_id() . ':' . (int) $audit . ':' . $row['ip'] . ':' . $row['created_at'] . ':' . ($audit ? '' : $row['reason']));
  }

  private static function link(string $association, string $incident): void {
    global $wpdb;
    [, $links] = self::tables();
    $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$links} (association,incident) VALUES (%s,%s)", $association, $incident));
  }

  public static function reported(array $row, bool $audit): string {
    global $wpdb;
    [$reports, $links] = self::tables();

    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT r.status, COUNT(*) AS total
       FROM {$links} l
       INNER JOIN {$reports} r ON r.incident = l.incident
       WHERE l.association = %s
       GROUP BY r.status",
      self::association($row, $audit)
    ), ARRAY_A);

    if ($wpdb->last_error !== '') {
      throw new \RuntimeException('Reporting receipts unavailable.');
    }

    $counts = [];
    foreach ($rows as $result) {
      $counts[(string) $result['status']] = (int) $result['total'];
    }

    if (!empty($counts['accepted'])) {
      $count = $counts['accepted'];
      return sprintf(
        'Yes (%d incident%s)',
        $count,
        $count === 1 ? '' : 's'
      );
    }

    if (!empty($counts['duplicate'])) {
      return 'Duplicate (covered by accepted report)';
    }

    foreach ([
      'pending' => 'Pending',
      'uncertain' => 'Uncertain',
      'rejected' => 'Rejected',
      'expired' => 'Expired'
    ] as $status => $label) {
      if (!empty($counts[$status])) {
        return $label;
      }
    }

    return 'No reporting incident';
  }

  public static function daily(): void {
    try { self::drain(Config::get_effective_options(), true); } catch (\Throwable) {}
  }

  /** Read current configuration for each originating site before draining. */
  private static function allowed_destinations(array $options): array {
    $allowed = [];
    $ids = is_multisite() ? get_sites(['fields' => 'ids', 'number' => 0, 'network_id' => get_current_network_id()]) : [get_current_blog_id()];
    foreach ($ids as $id) {
      if (is_multisite()) { switch_to_blog((int) $id); }
      try {
        $current = Config::get_effective_options();
        if (!empty($current['abuseipdb_enabled']) && self::scope($current) === self::scope($options)
          && ($current['abuseipdb_mode'] ?? 'immediate') === ($options['abuseipdb_mode'] ?? 'immediate')) {
          // Resolved list ID is stored with membership; list-name settings are
          // separately bound through a configuration signature below.
          $allowed[(int) $id] = ['configuration' => self::configuration_identity($current), 'options' => $current];
        }
      } finally { if (is_multisite()) { restore_current_blog(); } }
    }
    $network = Config::get_network_options();
    if (!empty($network['abuseipdb_enabled']) && self::scope($network) === self::scope($options)
      && ($network['abuseipdb_mode'] ?? 'immediate') === ($options['abuseipdb_mode'] ?? 'immediate')) {
      $allowed[0] = ['configuration' => self::configuration_identity($network), 'options' => $network];
    }
    return $allowed;
  }

  private static function configuration_identity(array $options): string {
    return hash('sha256', wp_json_encode(array_intersect_key($options, array_flip(['cloudflare_mode','cloudflare_zone_id','cloudflare_account_id','cloudflare_list_name','cloudflare_list_id']))));
  }

  private static function read_state(string $key): array {
    global $wpdb;
    [, , $table] = self::tables();
    $raw = $wpdb->get_var($wpdb->prepare("SELECT state_value FROM {$table} WHERE state_key=%s", $key));
    if (($wpdb->last_error ?? '') !== '') { throw new \RuntimeException('AbuseIPDB state unavailable.'); }
    if ($raw === null) { return []; }
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) { throw new \RuntimeException('AbuseIPDB state is malformed.'); }
    return $decoded;
  }

  private static function write_state(string $key, array $value): void {
    global $wpdb;
    [, , $table] = self::tables();
    $wpdb->query($wpdb->prepare("INSERT INTO {$table} (state_key,state_value) VALUES (%s,%s) ON DUPLICATE KEY UPDATE state_value=VALUES(state_value)", $key, wp_json_encode($value)));
  }

  public static function limits(array $options, bool $bulk): array {
    return self::read_state(self::scope($options) . ($bulk ? ':bulk' : ':individual'));
  }

  private static function cloudflare_scope(array $options): string {
    return ($options['cloudflare_mode'] ?? '') === 'account_list'
      ? 'account:' . strtolower((string) ($options['cloudflare_account_id'] ?? ''))
      : 'zone:' . strtolower((string) ($options['cloudflare_zone_id'] ?? ''));
  }

  /** Fresh membership at dispatch; keep clients alive to retain their leases. */
  private static function confirmations(array $options, ?Client $seed): array {
    $clients = $seed === null ? [] : [self::cloudflare_scope($options) => $seed];
    $confirmed = []; $snapshots = [];
    foreach (self::allowed_destinations($options) as $site => $origin) {
      $current = $origin['options'];
      $signature = $origin['configuration'];
      try {
        if (!isset($snapshots[$signature])) {
          $scope = self::cloudflare_scope($current);
          $clients[$scope] ??= new Client((string) ($current['cloudflare_api_token'] ?? ''), (string) ($current['cloudflare_zone_id'] ?? ''));
          $client = $clients[$scope];
          $list_id = '';
          if (($current['cloudflare_mode'] ?? '') === 'account_list') {
            $list_id = $client->resolve_account_list_id((string) ($current['cloudflare_account_id'] ?? ''), (string) ($current['cloudflare_list_name'] ?? ''), (string) ($current['cloudflare_list_id'] ?? ''));
            if ($list_id === null) { continue; }
            $members = $client->get_current_account_list_ips((string) $current['cloudflare_account_id'], $list_id, true);
          } else { $members = $client->get_current_blocked_ips(); }
          if ($members === null) { continue; }
          $snapshots[$signature] = ['configuration' => $signature, 'destination' => self::destination($current, $list_id), 'members' => array_fill_keys($members, true)];
        }
        $confirmed[$site] = $snapshots[$signature];
      } catch (\Throwable) {
        // Busy/unresolved destinations retain their pending evidence.
      }
    }
    return [$confirmed, $clients];
  }

  /**
   * Reconcile durable accepted reports before any dispatch decision.
   * This operation makes no external API requests.
   */
  private static function reconcile_duplicates(array $options): void {
    global $wpdb;
    [$reports] = self::tables();

    AtomicFence::run(static function () use ($reports, $options): void {
      global $wpdb;

      $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$reports} AS p
         INNER JOIN {$reports} AS a
           ON a.api_scope = p.api_scope
          AND a.network_id = p.network_id
          AND a.destination = p.destination
          AND a.configuration = p.configuration
          AND a.ip = p.ip
          AND a.categories = p.categories
          AND a.status = 'accepted'
          AND a.incident <> p.incident
          AND ABS(
            CAST(a.evidence_at AS SIGNED) -
            CAST(p.evidence_at AS SIGNED)
          ) <= 3600
         SET p.status = 'duplicate'
         WHERE p.status = 'pending'
           AND p.api_scope = %s
           AND p.network_id = %d",
        self::scope($options),
        get_current_network_id()
      ));

      if ($updated === false || $wpdb->last_error !== '') {
        throw new \RuntimeException(
          'AbuseIPDB duplicate reconciliation failed.'
        );
      }
    }, [$reports]);
  }

  public static function drain(array $options, bool $daily, ?Client $seed = null): void {
    if (empty($options['abuseipdb_enabled']) || empty($options['abuseipdb_api_key'])) { return; }
    // Reconcile accepted incidents independently of API availability.
    self::reconcile_duplicates($options);
    if (($options['abuseipdb_mode'] ?? '') === 'bulk' && !$daily) { return; }
    [$allowed, $clients] = self::confirmations($options, $seed);
    if ($allowed === []) { return; }
    // Each attempt obtains its own fenced claim; quota responses stop work.
    while (true) {
      if (!self::drain_once($options, $allowed)) { return; }
    }
  }

  private static function drain_once(array $options, array $allowed): bool {
    $bulk = ($options['abuseipdb_mode'] ?? 'immediate') === 'bulk';
    $lease = new DestinationLease('abuseipdb-reporting');
    $lease->acquire();
    try {
      global $wpdb;
      [$reports] = self::tables();
      $state_key = self::scope($options) . ($bulk ? ':bulk' : ':individual');
      $rows = AtomicFence::run(static function () use ($reports, $options, $bulk, $state_key, $allowed): array {
        global $wpdb;
        $now = time();
        $wpdb->query($wpdb->prepare("UPDATE {$reports} SET status='expired',comment='' WHERE status IN ('pending','uncertain') AND evidence_at<=%d", $now - 30 * DAY_IN_SECONDS));
        $state = self::read_state($state_key);
        if ((int) ($state['retry_at'] ?? 0) > $now || ($bulk && (int) ($state['attempt_at'] ?? 0) > $now - DAY_IN_SECONDS)) { return []; }
        $candidates = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$reports} WHERE api_scope=%s AND network_id=%d AND status='pending' AND retry_at<=%d ORDER BY evidence_at,incident LIMIT 9999", self::scope($options), get_current_network_id(), $now), ARRAY_A);
        $selected = []; $ips = [];
        foreach ($candidates as $row) {
          // Configuration signature stored alongside destination confirmation.
          if (!isset($allowed[(int) $row['site_id']]) || $row['configuration'] !== $allowed[(int) $row['site_id']]['configuration']
            || $row['destination'] !== $allowed[(int) $row['site_id']]['destination']
            || !isset($allowed[(int) $row['site_id']]['members'][$row['ip']]) || isset($ips[$row['ip']])) { continue; }
          $cooldown = self::read_state('ip:' . hash('sha256', $row['ip']));
          if ((int) ($cooldown['until'] ?? 0) > $now) { continue; }
          $ips[$row['ip']] = true;
          $selected[] = $row;
          if (!$bulk) { break; }
        }
        if ($selected === []) { return []; }
        $state['attempt_at'] = $now;
        self::write_state($state_key, $state);
        foreach ($selected as $row) {
          $wpdb->query($wpdb->prepare("UPDATE {$reports} SET status='uncertain' WHERE incident=%s AND status='pending'", $row['incident']));
          self::write_state('ip:' . hash('sha256', $row['ip']), ['until' => $now + 900]);
        }
        return $selected;
      }, self::tables());
      if ($rows === []) { return false; }
      $lease->assert_live();
      $response = self::request($options, $rows, $bulk);
      $lease->assert_live();
      $continue = AtomicFence::run(static function () use ($response, $rows, $bulk, $state_key, $reports): array {
        global $wpdb;
        $state = self::read_state($state_key);
        if (!is_wp_error($response)) { $state = array_merge($state, self::response_limits($response)); }
        $code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
        $body = is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response), true);
        if (!is_wp_error($response) && self::retry_after($response) > time()) { $state['retry_at'] = max((int) ($state['retry_at'] ?? 0), self::retry_after($response)); }
        $statuses = array_fill(0, count($rows), 'uncertain');
        if ($code === 429) {
          $state['retry_at'] = max(time() + 900, (int) ($state['reset'] ?? 0), self::retry_after($response));
          $statuses = array_fill(0, count($rows), 'pending');
        } elseif ($code >= 400 && $code < 500) {
          $statuses = array_fill(0, count($rows), 'rejected');
        } elseif ($code >= 200 && $code < 300 && empty($body['errors']) && is_array($body['data'] ?? null)) {
          if (!$bulk && IpValidator::normalize_public_ip((string) ($body['data']['ipAddress'] ?? '')) === $rows[0]['ip']) { $statuses[0] = 'accepted'; }
          elseif ($bulk) { $statuses = self::bulk_statuses($rows, $body['data']); }
        }
        if (($state['remaining'] ?? null) === 0 && (int) ($state['reset'] ?? 0) > time()) { $state['retry_at'] = max((int) ($state['retry_at'] ?? 0), (int) $state['reset']); }
        self::write_state($state_key, $state);
        foreach ($rows as $index => $row) {
          self::write_state('ip:' . hash('sha256', $row['ip']), ['until' => time() + 900]);
          $wpdb->query($wpdb->prepare("UPDATE {$reports} SET status=%s,accepted_at=%d,retry_at=%d WHERE incident=%s AND status='uncertain'",
            $statuses[$index], $statuses[$index] === 'accepted' ? time() : 0, (int) ($state['retry_at'] ?? 0), $row['incident']));
        }
        return ['continue' => !in_array('uncertain', $statuses, true) && $code !== 429];
      }, self::tables());
      return !$bulk && $continue['continue'];
    } finally { $lease->release(); }
  }

  private static function bulk_statuses(array $rows, array $data): array {
    $unknown = array_fill(0, count($rows), 'uncertain');
    if (!is_int($data['savedReports'] ?? null) || !is_array($data['invalidReports'] ?? null)) { return $unknown; }
    $invalid = [];
    foreach ($data['invalidReports'] as $entry) {
      // Correlate by canonical IP (one row per IP), avoiding undocumented
      // header/zero-based row-number conventions. Never store the error text.
      $ip = IpValidator::normalize_public_ip((string) ($entry['input'] ?? ''));
      if ($ip === null || isset($invalid[$ip]) || !in_array($ip, array_column($rows, 'ip'), true)) { return $unknown; }
      $invalid[$ip] = true;
    }
    if ($data['savedReports'] !== count($rows) - count($invalid)) { return $unknown; }
    return array_map(static fn ($row) => isset($invalid[$row['ip']]) ? 'rejected' : 'accepted', $rows);
  }

  private static function request(array $options, array $rows, bool $bulk) {
    foreach ($rows as $row) {
      if ((int) $row['evidence_at'] <= time() - 30 * DAY_IN_SECONDS) { throw new \RuntimeException('Evidence expired before HTTP submission.'); }
    }
    $args = ['headers' => ['Key' => $options['abuseipdb_api_key'], 'Accept' => 'application/json'], 'timeout' => 30, 'redirection' => 0, 'limit_response_size' => 1048576];
    if ($bulk) {
      $csv = "IP,Categories,ReportDate,Comment\r\n";
      foreach ($rows as $row) {
        $fields = [$row['ip'], $row['categories'], gmdate('c', (int) $row['evidence_at']), $row['comment']];
        $csv .= implode(',', array_map(static fn ($s) => '"' . str_replace('"', '""', $s) . '"', $fields)) . "\r\n";
      }
      if (strlen($csv) >= 8 * 1024 * 1024) { throw new \RuntimeException('Bulk payload exceeds provider limit.'); }
      $boundary = 'GreyRock' . bin2hex(random_bytes(16));
      $args['headers']['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;
      $args['body'] = '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"csv\"; filename=\"reports.csv\"\r\nContent-Type: text/csv\r\n\r\n" . $csv . '--' . $boundary . "--\r\n";
    } else {
      $row = $rows[0];
      $args['body'] = ['ip' => $row['ip'], 'categories' => $row['categories'], 'comment' => $row['comment'], 'timestamp' => gmdate('c', (int) $row['evidence_at'])];
    }
    return wp_remote_post('https://api.abuseipdb.com/api/v2/' . ($bulk ? 'bulk-report' : 'report'), $args);
  }

  public static function response_limits($response): array {
    $result = ['observed_at' => time(), 'limit' => null, 'remaining' => null, 'reset' => null];
    if (is_wp_error($response)) { return $result; }
    foreach (['limit' => 'x-ratelimit-limit', 'remaining' => 'x-ratelimit-remaining', 'reset' => 'x-ratelimit-reset'] as $name => $header) {
      $value = wp_remote_retrieve_header($response, $header);
      if (is_scalar($value) && preg_match('/^[0-9]{1,12}$/D', (string) $value)) { $result[$name] = (int) $value; }
    }
    return $result;
  }

  private static function retry_after($response): int {
    $value = wp_remote_retrieve_header($response, 'retry-after');
    if (!is_scalar($value)) { return 0; }
    return ctype_digit((string) $value) ? time() + min((int) $value, 365 * DAY_IN_SECONDS) : max(0, (int) strtotime((string) $value));
  }
}
