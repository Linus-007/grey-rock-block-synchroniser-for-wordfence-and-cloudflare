<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Services;

use WPCF\FirewallSync\Config;

/**
 * Resolve ownership for the currently effective Cloudflare destination.
 *
 * Site-specific configurations own only their local records. Sites inheriting
 * Network Admin configuration share one destination, so every inheriting site
 * and the network-level manual ownership store must be considered before a
 * Cloudflare block can be removed.
 */
final class BlockOwnershipResolver {
  public static function has_any_for_effective_destination(
    string $ip
  ): bool {
    $ip = IpValidator::normalize_public_ip($ip) ?? '';

    if ($ip === '') {
      return false;
    }

    if (
      !is_multisite()
      || !Config::uses_network_options()
    ) {
      return BlockOwnership::has_any($ip);
    }

    if (NetworkManualOwnershipStore::has($ip)) {
      return true;
    }

    foreach (get_sites(['fields' => 'ids']) as $blog_id) {
      switch_to_blog((int) $blog_id);

      try {
        if (
          Config::uses_network_options()
          && BlockOwnership::has_any($ip)
        ) {
          return true;
        }
      } finally {
        restore_current_blog();
      }
    }

    return false;
  }

  /**
   * Return true when any owner still requires an IP in the shared
   * Network Admin destination.
   */
  public static function has_any_for_network_destination(
    string $ip
  ): bool {
    $ip = IpValidator::normalize_public_ip($ip) ?? '';

    if ($ip === '') {
      return false;
    }

    if (NetworkManualOwnershipStore::has($ip)) {
      return true;
    }

    foreach (get_sites(['fields' => 'ids']) as $blog_id) {
      switch_to_blog((int) $blog_id);

      try {
        if (
          Config::uses_network_options()
          && BlockOwnership::has_any($ip)
        ) {
          return true;
        }
      } finally {
        restore_current_blog();
      }
    }

    return false;
  }

  public static function has_other_owner_after_wordfence_removal(
    string $ip
  ): bool {
    $ip = IpValidator::normalize_public_ip($ip) ?? '';

    if ($ip === '') {
      return false;
    }

    if (
      !is_multisite()
      || !Config::uses_network_options()
    ) {
      return BlockOwnership::has(
        $ip,
        BlockOwnership::OWNER_MANUAL
      );
    }

    if (NetworkManualOwnershipStore::has($ip)) {
      return true;
    }

    $current_blog_id = get_current_blog_id();

    foreach (get_sites(['fields' => 'ids']) as $blog_id) {
      $blog_id = (int) $blog_id;

      switch_to_blog($blog_id);

      try {
        if (!Config::uses_network_options()) {
          continue;
        }

        if (
          $blog_id === $current_blog_id
          && BlockOwnership::has(
            $ip,
            BlockOwnership::OWNER_MANUAL
          )
        ) {
          return true;
        }

        if (
          $blog_id !== $current_blog_id
          && BlockOwnership::has_any($ip)
        ) {
          return true;
        }
      } finally {
        restore_current_blog();
      }
    }

    return false;
  }
}
