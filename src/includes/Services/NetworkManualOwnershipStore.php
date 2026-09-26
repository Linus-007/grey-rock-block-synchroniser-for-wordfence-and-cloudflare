<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Services;

/**
 * Persist manual Network Admin ownership of shared Cloudflare list entries.
 *
 * Network-scoped manual blocks do not belong to any individual WordPress
 * site, so they are stored as network state rather than in a site table.
 */
final class NetworkManualOwnershipStore {
  public const OPTION = 'firewall_sync_network_manual_ownership';

  /**
   * Record a network-level manual ownership claim.
   */
  public static function add(string $ip): bool {
    $ip = IpValidator::normalize_public_ip($ip) ?? '';

    if ($ip === '') {
      return false;
    }

    $ips = self::read();

    if (isset($ips[$ip])) {
      return true;
    }

    $ips[$ip] = true;

    return self::write($ips);
  }

  /**
   * Remove a network-level manual ownership claim.
   *
   * An absent entry is already the required final state.
   */
  public static function remove(string $ip): bool {
    $ip = IpValidator::normalize_public_ip($ip) ?? '';

    if ($ip === '') {
      return false;
    }

    $ips = self::read();

    if (!isset($ips[$ip])) {
      return true;
    }

    unset($ips[$ip]);

    return self::write($ips);
  }

  /**
   * Return true when Network Admin explicitly owns the IP.
   */
  public static function has(string $ip): bool {
    $ip = IpValidator::normalize_public_ip($ip) ?? '';

    if ($ip === '') {
      return false;
    }

    return isset(self::read()[$ip]);
  }

  /**
   * Return all current network-level manual ownership IPs.
   *
   * @return string[]
   */
  public static function get_ips(): array {
    return array_keys(self::read());
  }

  /**
   * @return array<string, true>
   */
  private static function read(): array {
    $raw = get_site_option(self::OPTION, []);

    if (!is_array($raw)) {
      return [];
    }

    $valid = [];

    foreach ($raw as $key => $value) {
      /*
       * Accept both the canonical associative representation and a simple
       * numeric list so malformed/legacy state can be normalized safely.
       */
      $candidate = is_string($key) && !is_int($key)
        ? $key
        : (string) $value;

      $ip = IpValidator::normalize_public_ip($candidate);

      if ($ip !== null) {
        $valid[$ip] = true;
      }
    }

    ksort($valid, SORT_STRING);

    return $valid;
  }

  /**
   * @param array<string, true> $ips
   */
  private static function write(array $ips): bool {
    ksort($ips, SORT_STRING);

    return update_site_option(self::OPTION, $ips);
  }
}
