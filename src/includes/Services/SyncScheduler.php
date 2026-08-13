<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Services;

/*
 * Direct database access is intentional for synchronization-state queries
 * against the plugin's own operational log table. These values must remain
 * current and therefore are not object-cached.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */

use WPCF\FirewallSync\Cloudflare\Client;
use WPCF\FirewallSync\Config;

final class SyncScheduler {
  public const RESULT_SUCCESS = 'success';
  public const RESULT_FAILURE = 'failure';
  public const RESULT_NOT_DUE = 'not_due';
  public const RESULT_DISABLED = 'disabled';

  private const HOOK = 'firewall_sync_cron_event';
  private const CLEANUP_HOOK = 'firewall_sync_cleanup_event';
  private const DELETE_BATCH_SIZE = 100;

  private const LOCK_OPTION = 'firewall_sync_is_running';
  private const LOCK_TTL_SECONDS = 900;

  private static string $lockOwnerToken = '';
  private const LAST_ATTEMPT_OPTION =
    'firewall_sync_last_attempt_timestamp';

  private static string $lastErrorMessage = '';
  private static ?array $lastReconciliationResult = null;

  public static function register(): void {
    add_action(
      self::HOOK,
      [self::class, 'run_scheduled_sync']
    );

    add_action(
      self::CLEANUP_HOOK,
      [self::class, 'run_cleanup']
    );

    add_filter(
      'cron_schedules',
      [self::class, 'custom_intervals']
    );

    self::schedule_events();
  }

  /**
   * Create or correct the synchronization and cleanup schedules.
   *
   * Synchronization follows the selected method and interval. Cleanup is
   * separate maintenance and remains hourly in every scheduling mode.
   */
  private static function schedule_events(): void {
    $options = Config::get_effective_options();
    $method = Config::get_schedule_method($options);

    if ($method === Config::SCHEDULER_WP_CRON) {
      self::ensure_event(
        self::HOOK,
        self::interval_key(
          Config::get_sync_interval_minutes($options)
        )
      );
    } else {
      wp_clear_scheduled_hook(self::HOOK);
    }

    self::ensure_event(self::CLEANUP_HOOK, 'hourly');
  }

  private static function ensure_event(
    string $hook,
    string $recurrence
  ): void {
    $next = wp_next_scheduled($hook);
    $current_recurrence = wp_get_schedule($hook);

    if (
      $next !== false
      && $current_recurrence !== $recurrence
    ) {
      wp_clear_scheduled_hook($hook);
      $next = false;
    }

    if ($next === false) {
      wp_schedule_event(time(), $recurrence, $hook);
    }
  }

  private static function interval_key(int $minutes): string {
    return match ($minutes) {
      1 => 'every_minute',
      5 => 'every_5_minutes',
      15 => 'every_15_minutes',
      default => 'hourly',
    };
  }

  public static function custom_intervals(array $schedules): array {
    $schedules['every_minute'] = [
      'interval' => MINUTE_IN_SECONDS,
      'display' => __(
        'Every Minute',
        'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
      ),
    ];

    $schedules['every_5_minutes'] = [
      'interval' => 5 * MINUTE_IN_SECONDS,
      'display' => __(
        'Every 5 Minutes',
        'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
      ),
    ];

    $schedules['every_15_minutes'] = [
      'interval' => 15 * MINUTE_IN_SECONDS,
      'display' => __(
        'Every 15 Minutes',
        'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
      ),
    ];

    return $schedules;
  }

  /**
   * Run a WP-Cron synchronization only when WP-Cron is selected.
   */
  public static function run_scheduled_sync(): void {
    self::run_if_due(Config::SCHEDULER_WP_CRON);
  }

  /**
   * Run only when the required scheduling method is selected and the
   * configured synchronization interval has elapsed.
   */
  public static function run_if_due(
    string $required_method = Config::SCHEDULER_EXTERNAL
  ): string {
    self::$lastErrorMessage = '';

    $options = Config::get_effective_options();

    if (Config::get_schedule_method($options) !== $required_method) {
      return self::RESULT_DISABLED;
    }

    if (!self::is_due($options)) {
      return self::RESULT_NOT_DUE;
    }

    return self::run_now()
      ? self::RESULT_SUCCESS
      : self::RESULT_FAILURE;
  }

  public static function is_due(?array $options = null): bool {
    $options = $options ?? Config::get_effective_options();
    $last_attempt = self::get_last_attempt_timestamp();

    if ($last_attempt <= 0) {
      return true;
    }

    $interval = (
      Config::get_sync_interval_minutes($options)
      * MINUTE_IN_SECONDS
    );

    return time() >= ($last_attempt + $interval);
  }

  public static function seconds_until_due(
    ?array $options = null
  ): int {
    $options = $options ?? Config::get_effective_options();
    $last_attempt = self::get_last_attempt_timestamp();

    if ($last_attempt <= 0) {
      return 0;
    }

    $interval = (
      Config::get_sync_interval_minutes($options)
      * MINUTE_IN_SECONDS
    );

    return max(
      0,
      ($last_attempt + $interval) - time()
    );
  }

  public static function get_last_attempt_timestamp(): int {
    return (int) get_option(self::LAST_ATTEMPT_OPTION, 0);
  }

  /**
   * Force an immediate synchronization regardless of scheduling mode.
   */
  public static function run_now(): bool {
    self::$lastErrorMessage = '';
    self::$lastReconciliationResult = null;

    if (!self::acquire_lock()) {
      return false;
    }

    update_option(
      self::LAST_ATTEMPT_OPTION,
      time(),
      false
    );

    try {
      return self::execute_sync();
    } finally {
      self::release_lock();
    }
  }

  public static function is_locked(): bool {
    $lock = self::normalize_lock(
      get_option(self::LOCK_OPTION, null)
    );

    return (
      $lock !== null
      && !self::is_stale_lock($lock)
    );
  }

  private static function acquire_lock(): bool {
    global $wpdb;

    $started_at = time();
    $owner_token = wp_generate_uuid4();

    $new_lock = [
      'owner' => $owner_token,
      'started_at' => $started_at,
    ];

    if (
      add_option(
        self::LOCK_OPTION,
        $new_lock,
        '',
        false
      )
    ) {
      self::$lockOwnerToken = $owner_token;
      return true;
    }

    $existing_raw = get_option(
      self::LOCK_OPTION,
      null
    );

    $existing_lock = self::normalize_lock($existing_raw);

    if (
      $existing_lock !== null
      && !self::is_stale_lock($existing_lock)
    ) {
      self::set_already_running_error();
      return false;
    }

    /*
     * Replace the stale value only if it is still identical to the value
     * read above. This prevents two processes from both taking ownership.
     */
    $updated = $wpdb->query(
      $wpdb->prepare(
        "UPDATE {$wpdb->options}
         SET option_value = %s
         WHERE option_name = %s
           AND option_value = %s",
        maybe_serialize($new_lock),
        self::LOCK_OPTION,
        maybe_serialize($existing_raw)
      )
    );

    if ($updated === 1) {
      wp_cache_delete(self::LOCK_OPTION, 'options');
      self::$lockOwnerToken = $owner_token;
      return true;
    }

    /*
     * The option may have disappeared between the read and atomic update.
     * One final add_option() remains atomic because option_name is unique.
     */
    if (
      add_option(
        self::LOCK_OPTION,
        $new_lock,
        '',
        false
      )
    ) {
      self::$lockOwnerToken = $owner_token;
      return true;
    }

    self::set_already_running_error();
    return false;
  }

  private static function release_lock(): void {
    global $wpdb;

    if (self::$lockOwnerToken === '') {
      return;
    }

    $existing_raw = get_option(
      self::LOCK_OPTION,
      null
    );

    $existing_lock = self::normalize_lock($existing_raw);

    if (
      $existing_lock === null
      || !hash_equals(
        (string) ($existing_lock['owner'] ?? ''),
        self::$lockOwnerToken
      )
    ) {
      self::$lockOwnerToken = '';
      return;
    }

    /*
     * Delete only the exact lock value owned by this process. An older
     * process therefore cannot delete a newer process's replacement lock.
     */
    $wpdb->query(
      $wpdb->prepare(
        "DELETE FROM {$wpdb->options}
         WHERE option_name = %s
           AND option_value = %s",
        self::LOCK_OPTION,
        maybe_serialize($existing_raw)
      )
    );

    wp_cache_delete(self::LOCK_OPTION, 'options');
    self::$lockOwnerToken = '';
  }

  /**
   * Normalize both the new token lock and the legacy timestamp-only lock.
   *
   * @param mixed $raw_lock Raw option value.
   * @return array{owner:string,started_at:int}|null
   */
  private static function normalize_lock($raw_lock): ?array {
    if (is_array($raw_lock)) {
      $owner = (string) ($raw_lock['owner'] ?? '');
      $started_at = (int) ($raw_lock['started_at'] ?? 0);

      if ($owner === '' || $started_at <= 0) {
        return null;
      }

      return [
        'owner' => $owner,
        'started_at' => $started_at,
      ];
    }

    /*
     * Version 1.1.12 stored only a Unix timestamp. Treat it as a legacy
     * lock so an active pre-upgrade process remains protected.
     */
    $legacy_started_at = (int) $raw_lock;

    if ($legacy_started_at <= 0) {
      return null;
    }

    return [
      'owner' => 'legacy',
      'started_at' => $legacy_started_at,
    ];
  }

  /**
   * @param array{owner:string,started_at:int} $lock
   */
  private static function is_stale_lock(array $lock): bool {
    return (
      $lock['started_at'] <= 0
      || (time() - $lock['started_at'])
        > self::LOCK_TTL_SECONDS
    );
  }

  private static function set_already_running_error(): void {
    self::$lastErrorMessage = __(
      'Synchronization is already running.',
      'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
    );
  }

  private static function execute_sync(): bool {
    $options = Config::get_effective_options();
    $token = $options['cloudflare_api_token'] ?? '';
    $zone = $options['cloudflare_zone_id'] ?? '';
    $mode = $options['cloudflare_mode'] ?? 'zone_access_rules';
    $account_id = $options['cloudflare_account_id'] ?? '';
    $list_name = $options['cloudflare_list_name'] ?? '';
    $legacy_list_id = $options['cloudflare_list_id'] ?? '';

    if (empty($token)) {
      self::$lastErrorMessage = __(
        'Cloudflare API Token is required.',
        'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
      );

      return false;
    }

    if ($mode === 'account_list') {
      if (
        empty($account_id)
        || (empty($list_name) && empty($legacy_list_id))
      ) {
        self::$lastErrorMessage = __(
          'Cloudflare Account ID and List Name are required.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        );

        return false;
      }
    } elseif (empty($zone)) {
      self::$lastErrorMessage = __(
        'Cloudflare Zone ID is required.',
        'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
      );

      return false;
    }

    $client = new Client($token, $zone);
    $list_id = '';
    $account_inventory = null;

    if ($mode === 'account_list') {
      $resolved_list_id = $client->resolve_account_list_id(
        $account_id,
        $list_name,
        $legacy_list_id
      );

      if ($resolved_list_id === null) {
        self::$lastErrorMessage =
          $client->get_last_error_message();

        return false;
      }

      $list_id = $resolved_list_id;
    }

    $allowed_ips = array_fill_keys(
      DnsAllowList::get_effective_allowed_ips(),
      true
    );

    if (
      !self::remove_allowed_ips_from_cloudflare(
        $client,
        $mode,
        $account_id,
        $list_id,
        array_keys($allowed_ips)
      )
    ) {
      return false;
    }

    if ($mode === 'account_list') {
      $account_inventory = $client->get_current_account_list_ips(
        $account_id,
        $list_id
      );

      if ($account_inventory === null) {
        self::$lastErrorMessage = $client->get_last_error_message();
        return false;
      }
    }

    $cloudflare_set = array_fill_keys(
      $account_inventory ?? [],
      true
    );

    /*
     * Grey Rock supports the current Wordfence release only.
     *
     * Current Wordfence active IP blocks are retrieved through
     * wfBlock::ipBlocks(true). Removed Wordfence interfaces are
     * intentionally unsupported.
     */
    if (
      !class_exists('\\wfBlock')
      || !method_exists('\\wfBlock', 'ipBlocks')
      || !class_exists('\\wfDB')
      || !method_exists('\\wfDB', 'networkTable')
    ) {
      self::$lastErrorMessage = __(
        'Grey Rock requires the current Wordfence release. The required Wordfence interface is unavailable.',
        'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
      );

      return false;
    }

    $blocks = \wfBlock::ipBlocks(true);

    if (!is_array($blocks)) {
      self::$lastErrorMessage = __(
        'Wordfence returned an unexpected active-block response.',
        'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
      );

      return false;
    }

    /*
     * Key the batch by IP so active blocks and historical WAF events cannot
     * create duplicate Cloudflare operations.
     */
    $batch_by_ip = [];

    foreach ($blocks as $block) {
      if (!$block instanceof \wfBlock) {
        self::$lastErrorMessage = __(
          'Wordfence returned an unexpected active-block entry.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        );

        return false;
      }

      $ip = IpValidator::normalize_public_ip(
        (string) $block->ip
      ) ?? '';
      $reason = (string) $block->reason;
      $expiration = (int) $block->expiration;
      $blocked_time = (int) $block->blockedTime;
      $is_permanent = (
        $expiration === \wfBlock::DURATION_FOREVER
      );
      $evidence_watermark = max(
        BlockLogger::get_synced_timestamp($ip),
        ResetWatermarkStore::get($ip)
      );

      if ($reason === '') {
        $reason = __(
          'Unknown',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        );
      }

      if (
        $ip === ''
        || !IpValidator::validate_public_ip($ip)
        || (
          !$is_permanent
          && $expiration > 0
          && time() > $expiration
        )
        || isset($allowed_ips[$ip])
        || (
          $mode === 'account_list'
            ? (
              self::should_skip_present_account_list_ip(
                isset($cloudflare_set[$ip]),
                BlockLogger::has_synced($ip)
              )
              || (
                $evidence_watermark > 0
                && !self::active_evidence_is_newer(
                  $blocked_time,
                  $evidence_watermark
                )
              )
            )
            : BlockLogger::has_synced($ip)
        )
        || BlockLogger::is_blacklisted($ip)
      ) {
        continue;
      }

      $expires_at = null;

      if (!$is_permanent && $expiration > 0) {
        $expires_at = wp_date(
          'Y-m-d H:i:s',
          $expiration,
          wp_timezone()
        );
      }

      $batch_by_ip[$ip] = [
        'ip' => $ip,
        'reason' => (string) $reason,
        'expires_at' => $expires_at,
      ];
    }

    $lookback_hours = (int) (
      $options['historical_lookback_hours'] ?? 24
    );

    $minimum_events = (int) (
      $options['historical_minimum_events'] ?? 1
    );

    $historical_blocks = HistoricalBlockReader::get_candidates(
      $lookback_hours,
      $minimum_events,
      self::get_site_hosts()
    );

    if ($historical_blocks === null) {
      self::$lastErrorMessage = __(
        'Wordfence historical WAF evidence could not be read completely.',
        'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
      );
      return false;
    }

    foreach ($historical_blocks as $historical_block) {
      $ip = (string) ($historical_block['ip'] ?? '');
      $event_count = (int) (
        $historical_block['event_count'] ?? 0
      );
      $latest_event = (int) (
        $historical_block['latest_event'] ?? 0
      );

      if (
        $ip === ''
        || isset($batch_by_ip[$ip])
        || isset($allowed_ips[$ip])
        || (
          $mode === 'account_list'
            ? self::should_skip_present_account_list_ip(
              isset($cloudflare_set[$ip]),
              BlockLogger::has_synced($ip)
            )
            : BlockLogger::has_synced($ip)
        )
        || BlockLogger::is_blacklisted($ip)
      ) {
        continue;
      }

      $expires_at = null;

      if ($latest_event > 0) {
        $expires_at = wp_date(
          'Y-m-d H:i:s',
          $latest_event + (
            $lookback_hours * HOUR_IN_SECONDS
          ),
          wp_timezone()
        );
      }

      $batch_by_ip[$ip] = [
        'ip' => $ip,
        'reason' => sprintf(
          /* translators: %d: number of blocked WAF events */
          _n(
            'Wordfence historical WAF block: %d event',
            'Wordfence historical WAF block: %d events',
            $event_count,
            'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
          ),
          $event_count
        ),
        'expires_at' => $expires_at,
      ];
    }

    $batch = array_values($batch_by_ip);

    if ($mode === 'account_list') {
      $failed = self::synchronize_account_list_batch(
        $client,
        $account_id,
        $list_id,
        $batch
      );
    } else {
      $cloudflare_batch = array_map(
        static function (array $entry): array {
          return [
            'ip' => $entry['ip'],
            'reason' => $entry['reason'],
          ];
        },
        $batch
      );

      $failed = $client->batch_block($cloudflare_batch);
    }

    if ($mode !== 'account_list') {
      self::record_batch_results($batch, $failed);
    }

    update_option(
      'firewall_sync_last_run',
      current_time('mysql')
    );

    if (!empty($failed)) {
      $client_error = $client->get_last_error_message();

      self::$lastErrorMessage = $client_error !== ''
        ? $client_error
        : sprintf(
          /* translators: %d: number of failed IP addresses */
          __(
            '%d IP address could not be synchronized.',
            'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
          ),
          count($failed)
        );

      return false;
    }

    if (
      $mode === 'account_list'
      && (!is_multisite() || !Config::uses_network_options())
    ) {
      $post_inventory = $client->get_current_account_list_ips(
        $account_id,
        $list_id
      );

      if ($post_inventory === null) {
        self::$lastErrorMessage = $client->get_last_error_message();
        return false;
      }

      $reconciliation = Reconciler::run(
        $client,
        $options,
        $post_inventory
      );
      self::$lastReconciliationResult = $reconciliation;

      if (empty($reconciliation['complete'])) {
        self::$lastErrorMessage = (string) (
          $reconciliation['error']
          ?? __('Reconciliation failed.', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare')
        );
        return false;
      }
    }

    return true;
  }

  /** @return array<int, string> */
  private static function get_site_hosts(): array {
    $hosts = [];

    foreach ([home_url('/'), site_url('/')] as $url) {
      $host = wp_parse_url($url, PHP_URL_HOST);

      if (is_string($host) && $host !== '') {
        $hosts[] = strtolower(rtrim($host, '.'));
      }
    }

    return array_values(array_unique($hosts));
  }

  private static function active_evidence_is_newer(
    int $blocked_time,
    int $watermark
  ): bool {
    return $blocked_time > 0 && $blocked_time > $watermark;
  }

  private static function should_skip_present_account_list_ip(
    bool $cloudflare_present,
    bool $site_has_synced
  ): bool {
    /*
     * Shared-list membership may have been created by another site. Only a
     * site that already owns an attributable successful row suppresses a
     * repeat episode while that shared Cloudflare entry remains present.
     */
    return $cloudflare_present && $site_has_synced;
  }

  private static function synchronize_account_list_batch(
    Client $client,
    string $account_id,
    string $list_id,
    array $batch
  ): array {
    $failed = $client->batch_add_ips_to_account_list(
      $account_id,
      $list_id,
      $batch
    );

    self::record_batch_results($batch, $failed);

    return $failed;
  }

  private static function record_batch_results(
    array $batch,
    array $failed
  ): void {
    foreach ($batch as $entry) {
      $log_reason = 'sync: ' . $entry['reason'];

      if (in_array($entry['ip'], $failed, true)) {
        BlockLogger::mark_failed(
          $entry['ip'],
          $log_reason,
          $entry['expires_at']
        );

        continue;
      }

      BlockLogger::log(
        $entry['ip'],
        $log_reason,
        $entry['expires_at']
      );

      if (!is_multisite() || !Config::uses_network_options()) {
        ResetWatermarkStore::clear($entry['ip']);
      }
    }
  }

  /**
   * Remove exact trusted addresses from the configured Cloudflare
   * block destination before processing new block candidates.
   *
   * @param array<int, string> $allowed_ips Exact public addresses.
   */
  private static function remove_allowed_ips_from_cloudflare(
    Client $client,
    string $mode,
    string $account_id,
    string $list_id,
    array $allowed_ips
  ): bool {
    foreach ($allowed_ips as $ip) {
      if (!IpValidator::validate_public_ip($ip)) {
        continue;
      }

      $removed = $mode === 'account_list'
        ? $client->remove_ip_from_account_list(
          $account_id,
          $list_id,
          $ip
        )
        : $client->delete_block($ip);

      if (!$removed) {
        $client_error = $client->get_last_error_message();

        self::$lastErrorMessage = $client_error !== ''
          ? $client_error
          : sprintf(
            /* translators: %s: trusted IP address. */
            __(
              'Trusted address %s could not be removed from the Cloudflare block destination.',
              'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
            ),
            $ip
          );

        return false;
      }

      if (!self::remove_allowed_ip_from_logs($ip)) {
        self::$lastErrorMessage = sprintf(
          /* translators: %s: trusted IP address. */
          __(
            'Cloudflare removed trusted address %s, but Grey Rock could not clear its local synchronization record.',
            'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
          ),
          $ip
        );

        return false;
      }
    }

    return true;
  }

  /**
   * Clear local synchronization records for a trusted address.
   *
   * Network configuration is shared by every inheriting site.
   * All site-local records must therefore be removed when the
   * shared allow-list entry becomes effective.
   */
  private static function remove_allowed_ip_from_logs(
    string $ip
  ): bool {
    if (
      !is_multisite()
      || !Config::uses_network_options()
    ) {
      return BlockLogger::remove($ip);
    }

    $removed = true;

    foreach (get_sites(['fields' => 'ids']) as $blog_id) {
      switch_to_blog((int) $blog_id);

      try {
        if (!BlockLogger::remove($ip)) {
          $removed = false;
        }
      } finally {
        restore_current_blog();
      }
    }

    return $removed;
  }

  public static function get_last_error_message(): string {
    return self::$lastErrorMessage;
  }

  public static function get_last_reconciliation_result(): ?array {
    return self::$lastReconciliationResult;
  }

  public static function run_cleanup(): array {
    global $wpdb;

    /*
     * A site inheriting Network Admin settings may share its Cloudflare
     * destination with other sites. Its local log cannot determine whether
     * another site still requires an address, so it must not delete entries
     * from that shared destination.
     */
    if (is_multisite() && Config::uses_network_options()) {
      return self::cleanup_result(
        false,
        [],
        __('Shared inherited cleanup is unavailable.', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare')
      );
    }

    $options = Config::get_effective_options();
    $token = $options['cloudflare_api_token'] ?? '';
    $zone = $options['cloudflare_zone_id'] ?? '';
    $mode = $options['cloudflare_mode'] ?? 'zone_access_rules';
    $account_id = $options['cloudflare_account_id'] ?? '';
    $list_name = $options['cloudflare_list_name'] ?? '';
    $legacy_list_id = $options['cloudflare_list_id'] ?? '';

    if (empty($token)) {
      return self::cleanup_result(false, [], __('Cloudflare API Token is required.', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'));
    }

    if ($mode === 'account_list') {
      if (
        empty($account_id)
        || (empty($list_name) && empty($legacy_list_id))
      ) {
        return self::cleanup_result(false, [], __('Cloudflare Account ID and List Name are required.', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'));
      }
    } elseif (empty($zone)) {
      return self::cleanup_result(false, [], __('Cloudflare Zone ID is required.', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'));
    }

    $client = new Client($token, $zone);
    $list_id = '';

    if ($mode === 'account_list') {
      $resolved_list_id = $client->resolve_account_list_id(
        $account_id,
        $list_name,
        $legacy_list_id
      );

      if ($resolved_list_id === null) {
        return self::cleanup_result(false, [], $client->get_last_error_message());
      }

      $list_id = $resolved_list_id;
    }

    $table = $wpdb->prefix . BlockLogger::TABLE;
    $current_time = current_time('mysql');
    $last_id = 0;
    $removed_ips = [];
    $cleanup_error = '';

    do {
      $rows = $wpdb->get_results(
        $wpdb->prepare(
          "SELECT id, ip
           FROM {$table}
           WHERE id > %d
             AND synced_at IS NOT NULL
             AND fail_count = 0
             AND expires_at IS NOT NULL
             AND expires_at < %s
           ORDER BY id ASC
           LIMIT %d",
          $last_id,
          $current_time,
          self::DELETE_BATCH_SIZE
        ),
        ARRAY_A
      );

      foreach ($rows as $row) {
        $row_id = (int) ($row['id'] ?? 0);
        $ip = IpValidator::normalize_public_ip(
          (string) ($row['ip'] ?? '')
        );

        if ($row_id > $last_id) {
          $last_id = $row_id;
        }

        if ($ip === null) {
          continue;
        }

        $deleted = $mode === 'account_list'
          ? $client->remove_ip_from_account_list(
            $account_id,
            $list_id,
            $ip
          )
          : $client->delete_block($ip);

        /*
         * Retain the ownership record when deletion fails so a later cleanup
         * can retry instead of losing track of the Cloudflare entry.
         */
        if (!$deleted) {
          $cleanup_error = $client->get_last_error_message();
          break 2;
        }

        $local_cleanup = self::finalize_expired_local_cleanup(
          $table,
          $row_id,
          $ip,
          time()
        );

        if (!$local_cleanup['complete']) {
          $cleanup_error = (string) $local_cleanup['error'];
          break 2;
        }

        $removed_ips[] = $ip;
      }
    } while (count($rows) === self::DELETE_BATCH_SIZE);

    return self::cleanup_result(
      $cleanup_error === '',
      $removed_ips,
      $cleanup_error
    );
  }

  private static function finalize_expired_local_cleanup(
    string $table,
    int $row_id,
    string $ip,
    int $reset_at
  ): array {
    global $wpdb;

    if (!ResetWatermarkStore::set($ip, $reset_at)) {
      return self::cleanup_result(
        false,
        [],
        'Cloudflare cleanup removed an address, but local cleanup stopped because its reset watermark could not be stored. The local synchronization record was retained.'
      );
    }

    if ($wpdb->delete(
      $table,
      ['id' => $row_id],
      ['%d']
    ) === false) {
      return self::cleanup_result(
        false,
        [],
        'A reset watermark was stored, but the expired local synchronization record could not be removed.'
      );
    }

    return self::cleanup_result(true, [$ip], '');
  }

  private static function cleanup_result(
    bool $complete,
    array $removed_ips,
    string $error
  ): array {
    return [
      'complete' => $complete,
      'removed' => $removed_ips,
      'error' => $error,
    ];
  }

  /**
   * Replace schedules using the currently effective configuration.
   */
  public static function reschedule(): void {
    self::deactivate();
    self::schedule_events();
  }

  public static function deactivate(): void {
    wp_clear_scheduled_hook(self::HOOK);
    wp_clear_scheduled_hook(self::CLEANUP_HOOK);
  }
}
