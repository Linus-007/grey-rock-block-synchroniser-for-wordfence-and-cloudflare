<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Services;

use WPCF\FirewallSync\Config;

final class DnsAllowList {

  private const SITE_STATE_OPTION = 'firewall_sync_ddns_state';

  private const NETWORK_STATE_OPTION =
    'firewall_sync_network_ddns_state';

  private const REFRESH_INTERVAL_SECONDS = 300;

  private const FAILURE_GRACE_SECONDS = 86400;

  /**
   * Sanitize and validate a DDNS hostname.
   */
  public static function sanitize_hostname(string $hostname): string {
    $hostname = strtolower(trim($hostname));
    $hostname = rtrim($hostname, '.');

    if (
      $hostname === ''
      || strlen($hostname) > 253
      || strpos($hostname, '.') === false
      || strpos($hostname, '://') !== false
      || strpos($hostname, '/') !== false
      || strpos($hostname, ':') !== false
      || filter_var($hostname, FILTER_VALIDATE_IP) !== false
    ) {
      return '';
    }

    $valid = filter_var(
      $hostname,
      FILTER_VALIDATE_DOMAIN,
      FILTER_FLAG_HOSTNAME
    );

    return $valid !== false ? $hostname : '';
  }

  /**
   * Return the DNS state displayed in the current admin context.
   */
  public static function get_admin_state(): array {
    $network = is_multisite()
      && (
        is_network_admin()
        || Config::uses_network_options()
      );

    return self::get_state($network);
  }

  /**
   * Refresh the configuration represented by an admin settings form.
   */
  public static function refresh_scope(string $scope): array {
    $network = $scope === 'network';

    if (
      !$network
      && is_multisite()
      && Config::uses_network_options()
    ) {
      $network = true;
    }

    $options = $network
      ? Config::get_network_options()
      : Config::get_site_options();

    return self::refresh($network, $options);
  }

  /**
   * Return exact resolved addresses currently used by the allow list.
   *
   * A lookup is refreshed first when the stored result is old or belongs
   * to a different hostname.
   */
  public static function get_effective_allowed_ips(): array {
    $options = Config::get_effective_options();
    $network = is_multisite() && Config::uses_network_options();

    self::refresh_if_due($network, $options);

    if (empty($options['ddns_allow_enabled'])) {
      return [];
    }

    $hostname = self::sanitize_hostname(
      (string) ($options['ddns_hostname'] ?? '')
    );

    if ($hostname === '') {
      return [];
    }

    $state = self::get_state($network);

    if (
      $state['hostname'] !== $hostname
      || $state['resolved_at'] < time() - self::FAILURE_GRACE_SECONDS
    ) {
      return [];
    }

    return $state['ips'];
  }

  /**
   * Refresh a configured hostname when its lookup is due.
   */
  private static function refresh_if_due(
    bool $network,
    array $options
  ): void {
    $hostname = self::sanitize_hostname(
      (string) ($options['ddns_hostname'] ?? '')
    );

    $state = self::get_state($network);

    if ($hostname === '') {
      if (
        $state['hostname'] !== ''
        || !empty($state['ips'])
        || $state['status'] !== 'not_configured'
      ) {
        self::save_state(
          $network,
          self::empty_state()
        );
      }

      return;
    }

    $lookup_due = (
      $state['hostname'] !== $hostname
      || $state['last_attempt']
        < time() - self::REFRESH_INTERVAL_SECONDS
    );

    if ($lookup_due) {
      self::refresh($network, $options);
    }
  }

  /**
   * Perform a DNS lookup and store the result.
   */
  private static function refresh(
    bool $network,
    array $options
  ): array {
    $hostname = self::sanitize_hostname(
      (string) ($options['ddns_hostname'] ?? '')
    );

    if ($hostname === '') {
      $state = self::empty_state();
      self::save_state($network, $state);

      return $state;
    }

    $previous = self::get_state($network);
    $now = time();
    $ips = self::resolve_hostname($hostname);

    if (!empty($ips)) {
      $state = [
        'hostname' => $hostname,
        'ips' => $ips,
        'resolved_at' => $now,
        'last_attempt' => $now,
        'status' => 'resolved',
        'error' => '',
      ];

      self::save_state($network, $state);

      return $state;
    }

    $retain_previous = (
      $previous['hostname'] === $hostname
      && !empty($previous['ips'])
      && $previous['resolved_at']
        >= $now - self::FAILURE_GRACE_SECONDS
    );

    $state = [
      'hostname' => $hostname,
      'ips' => $retain_previous ? $previous['ips'] : [],
      'resolved_at' =>
        $retain_previous ? $previous['resolved_at'] : 0,
      'last_attempt' => $now,
      'status' => $retain_previous ? 'stale' : 'failed',
      'error' => $retain_previous
        ? __(
          'The DNS lookup failed. The last successful addresses are being retained temporarily.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        )
        : __(
          'The DDNS domain did not resolve to a public IPv4 or IPv6 address.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        ),
    ];

    self::save_state($network, $state);

    return $state;
  }

  /**
   * Resolve public A and AAAA records.
   */
  private static function resolve_hostname(string $hostname): array {
    $ips = [];

    if (function_exists('dns_get_record')) {
      $records = @dns_get_record(
        $hostname,
        DNS_A | DNS_AAAA
      );

      if (is_array($records)) {
        foreach ($records as $record) {
          if (
            isset($record['ip'])
            && is_string($record['ip'])
          ) {
            $ips[] = $record['ip'];
          }

          if (
            isset($record['ipv6'])
            && is_string($record['ipv6'])
          ) {
            $ips[] = $record['ipv6'];
          }
        }
      }
    }

    if (function_exists('gethostbynamel')) {
      $ipv4_addresses = @gethostbynamel($hostname);

      if (is_array($ipv4_addresses)) {
        $ips = array_merge($ips, $ipv4_addresses);
      }
    }

    $validated = [];

    foreach ($ips as $ip) {
      $ip = self::normalize_ip((string) $ip);

      if (
        $ip !== ''
        && IpValidator::validate_public_ip($ip)
      ) {
        $validated[$ip] = true;
      }
    }

    $validated = array_keys($validated);
    sort($validated, SORT_STRING);

    return $validated;
  }

  /**
   * Return a canonical textual representation of an IP address.
   */
  private static function normalize_ip(string $ip): string {
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
      return '';
    }

    $packed = @inet_pton($ip);

    if ($packed === false) {
      return '';
    }

    $normalized = @inet_ntop($packed);

    return is_string($normalized) ? $normalized : '';
  }

  /**
   * Read and normalize stored lookup state.
   */
  private static function get_state(bool $network): array {
    $state = $network
      ? get_site_option(self::NETWORK_STATE_OPTION, [])
      : get_option(self::SITE_STATE_OPTION, []);

    if (!is_array($state)) {
      return self::empty_state();
    }

    $ips = [];

    if (
      isset($state['ips'])
      && is_array($state['ips'])
    ) {
      foreach ($state['ips'] as $ip) {
        $ip = self::normalize_ip((string) $ip);

        if (
          $ip !== ''
          && IpValidator::validate_public_ip($ip)
        ) {
          $ips[$ip] = true;
        }
      }
    }

    $status = (string) ($state['status'] ?? 'not_configured');

    if (
      !in_array(
        $status,
        [
          'not_configured',
          'resolved',
          'stale',
          'failed',
        ],
        true
      )
    ) {
      $status = 'failed';
    }

    return [
      'hostname' => self::sanitize_hostname(
        (string) ($state['hostname'] ?? '')
      ),
      'ips' => array_keys($ips),
      'resolved_at' => max(
        0,
        (int) ($state['resolved_at'] ?? 0)
      ),
      'last_attempt' => max(
        0,
        (int) ($state['last_attempt'] ?? 0)
      ),
      'status' => $status,
      'error' => sanitize_text_field(
        (string) ($state['error'] ?? '')
      ),
    ];
  }

  /**
   * Store normalized lookup state.
   */
  private static function save_state(
    bool $network,
    array $state
  ): void {
    if ($network) {
      update_site_option(
        self::NETWORK_STATE_OPTION,
        $state
      );

      return;
    }

    update_option(
      self::SITE_STATE_OPTION,
      $state
    );
  }

  /**
   * Return the default state.
   */
  private static function empty_state(): array {
    return [
      'hostname' => '',
      'ips' => [],
      'resolved_at' => 0,
      'last_attempt' => 0,
      'status' => 'not_configured',
      'error' => '',
    ];
  }
}
