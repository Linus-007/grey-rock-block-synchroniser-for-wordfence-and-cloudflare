<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Services;

use WPCF\FirewallSync\Config;

/**
 * Store evidence watermarks separately from user configuration.
 */
final class ResetWatermarkStore {
  public const SITE_OPTION = 'firewall_sync_reset_watermarks';
  public const NETWORK_OPTION =
    'firewall_sync_network_reset_watermarks';

  public const MAX_ENTRIES = 2000;
  public const SAFETY_RETENTION = 48 * HOUR_IN_SECONDS;

  public static function get(string $ip): int {
    $ip = IpValidator::normalize_public_ip($ip) ?? '';

    if ($ip === '') {
      return 0;
    }

    $watermarks = self::read();

    return (int) ($watermarks[$ip] ?? 0);
  }

  public static function set(string $ip, int $timestamp): bool {
    if (!AtomicFence::active()) {
      global $wpdb;
      return AtomicFence::run(static fn () => self::set($ip, $timestamp));
    }

    if (class_exists(SyncScheduler::class)) {
      SyncScheduler::assert_active_lease();
    }
    if (class_exists(DestinationLease::class)) {
      DestinationLease::assert_held_leases();
    }
    $ip = IpValidator::normalize_public_ip($ip) ?? '';

    if (
      $ip === ''
      || $timestamp <= 0
    ) {
      return false;
    }

    $normalized_changed = false;
    $watermarks = self::read($normalized_changed);

    if (($watermarks[$ip] ?? null) === $timestamp) {
      return $normalized_changed
        ? self::write($watermarks)
        : true;
    }

    if (
      !array_key_exists($ip, $watermarks)
      && count($watermarks) >= self::MAX_ENTRIES
    ) {
      return false;
    }

    $watermarks[$ip] = $timestamp;

    return self::write($watermarks);
  }

  public static function clear(string $ip): bool {
    if (!AtomicFence::active()) {
      global $wpdb;
      return AtomicFence::run(static fn () => self::clear($ip));
    }

    if (class_exists(SyncScheduler::class)) {
      SyncScheduler::assert_active_lease();
    }
    if (class_exists(DestinationLease::class)) {
      DestinationLease::assert_held_leases();
    }
    $ip = IpValidator::normalize_public_ip($ip) ?? '';

    if ($ip === '') {
      return false;
    }

    $watermarks = self::read();

    if (!array_key_exists($ip, $watermarks)) {
      return true;
    }

    unset($watermarks[$ip]);

    return self::write($watermarks);
  }

  /**
   * @return array<string, int>
   */
  private static function read(?bool &$normalized_changed = null): array {
    $raw = AtomicFence::option(
      self::is_network_scope() ? self::NETWORK_OPTION : self::SITE_OPTION,
      self::is_network_scope()
    );

    if (!is_array($raw)) {
      return [];
    }

    $valid = [];

    foreach ($raw as $ip => $timestamp) {
      $normalized_ip = is_string($ip)
        ? IpValidator::normalize_public_ip($ip)
        : null;

      if (
        $normalized_ip !== null
        && is_int($timestamp)
        && $timestamp > 0
      ) {
        $valid[$normalized_ip] = max(
          $timestamp,
          (int) ($valid[$normalized_ip] ?? 0)
        );
      }
    }

    $valid = self::prune($valid);
    $normalized_changed = $raw !== $valid;

    return $valid;
  }

  /**
   * @param array<string, int> $watermarks
   */
  private static function write(array $watermarks): bool {
    if (class_exists(SyncScheduler::class)) { SyncScheduler::assert_active_lease(); }
    if (class_exists(DestinationLease::class)) { DestinationLease::assert_held_leases(); }
    return AtomicFence::option(
      self::is_network_scope() ? self::NETWORK_OPTION : self::SITE_OPTION,
      self::is_network_scope(), true, $watermarks
    );
  }

  /**
   * @param array<string, int> $watermarks
   * @return array<string, int>
   */
  private static function prune(array $watermarks): array {
    /*
     * Never evict a watermark that can still exclude evidence from the
     * longest supported historical lookback. Capacity is enforced by
     * refusing a new reset in set(), not by discarding a relevant entry.
     */
    $cutoff = time() - self::SAFETY_RETENTION;

    $watermarks = array_filter(
      $watermarks,
      static fn (int $timestamp): bool => $timestamp > $cutoff
    );

    arsort($watermarks, SORT_NUMERIC);

    return $watermarks;
  }

  private static function is_network_scope(): bool {
    return is_multisite() && Config::uses_network_options();
  }
}
