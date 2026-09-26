<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$current_blog_id = 1;
$site_ownership = [
  1 => [],
  2 => [],
  3 => [],
];
$network_manual = [];
$network_inheriting = [
  1 => true,
  2 => true,
  3 => false,
];

function resolver_fail(string $message): never {
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

function resolver_assert(bool $condition, string $message): void {
  if (!$condition) {
    resolver_fail($message);
  }
}

function is_multisite(): bool {
  return true;
}

function get_sites(array $args): array {
  return [1, 2, 3];
}

function switch_to_blog(int $blog_id): void {
  global $current_blog_id;

  $current_blog_id = $blog_id;
}

function restore_current_blog(): void {
  global $current_blog_id;

  $current_blog_id = 1;
}

function get_current_blog_id(): int {
  global $current_blog_id;

  return $current_blog_id;
}

require_once $root . '/src/includes/Services/IpValidator.php';

eval('
namespace WPCF\FirewallSync;

final class Config {
  public static function uses_network_options(): bool {
    global $current_blog_id, $network_inheriting;

    return (bool) ($network_inheriting[$current_blog_id] ?? false);
  }
}
');

eval('
namespace WPCF\FirewallSync\Services;

final class BlockOwnership {
  public const OWNER_MANUAL = "manual";
  public const OWNER_WORDFENCE = "wordfence";

  public static function has_any(string $ip): bool {
    global $current_blog_id, $site_ownership;

    return !empty($site_ownership[$current_blog_id][$ip] ?? []);
  }

  public static function has(string $ip, string $owner): bool {
    global $current_blog_id, $site_ownership;

    return !empty(
      $site_ownership[$current_blog_id][$ip][$owner] ?? false
    );
  }
}

final class NetworkManualOwnershipStore {
  public static function has(string $ip): bool {
    global $network_manual;

    return !empty($network_manual[$ip] ?? false);
  }
}
');

require_once $root . '/src/includes/Services/BlockOwnershipResolver.php';

use WPCF\FirewallSync\Services\BlockOwnership;
use WPCF\FirewallSync\Services\BlockOwnershipResolver;

$ip = '8.8.4.4';

/*
 * No owners anywhere.
 */
resolver_assert(
  !BlockOwnershipResolver::has_any_for_effective_destination($ip),
  'Resolver reported ownership when none existed.'
);

resolver_assert(
  !BlockOwnershipResolver::has_other_owner_after_wordfence_removal($ip),
  'Resolver reported another owner when none existed.'
);

/*
 * Current site's Wordfence owner alone must not count as another owner
 * after that ownership is being removed.
 */
$site_ownership[1][$ip][BlockOwnership::OWNER_WORDFENCE] = true;

resolver_assert(
  BlockOwnershipResolver::has_any_for_effective_destination($ip),
  'Current site Wordfence ownership was not found.'
);

resolver_assert(
  !BlockOwnershipResolver::has_other_owner_after_wordfence_removal($ip),
  'Current site Wordfence ownership incorrectly counted as another owner.'
);

/*
 * Manual ownership on the current site must protect the Cloudflare block.
 */
$site_ownership[1][$ip][BlockOwnership::OWNER_MANUAL] = true;

resolver_assert(
  BlockOwnershipResolver::has_other_owner_after_wordfence_removal($ip),
  'Current site manual ownership did not protect the IP.'
);

unset($site_ownership[1][$ip][BlockOwnership::OWNER_MANUAL]);

/*
 * Any ownership on another inheriting site must protect the shared block.
 */
$site_ownership[2][$ip][BlockOwnership::OWNER_WORDFENCE] = true;

resolver_assert(
  BlockOwnershipResolver::has_other_owner_after_wordfence_removal($ip),
  'Another inheriting site did not protect the shared IP.'
);

unset($site_ownership[2][$ip]);

/*
 * A non-inheriting site's ownership must not protect this shared destination.
 */
$site_ownership[3][$ip][BlockOwnership::OWNER_MANUAL] = true;

resolver_assert(
  !BlockOwnershipResolver::has_other_owner_after_wordfence_removal($ip),
  'Non-inheriting site ownership protected the wrong destination.'
);

unset($site_ownership[3][$ip]);

/*
 * Clear the current site's earlier Wordfence test ownership so the
 * network-destination cases begin with no owners anywhere.
 */
unset($site_ownership[1][$ip]);

/*
 * Network Admin destination checks must consider inheriting sites only.
 */
resolver_assert(
  !BlockOwnershipResolver::has_any_for_network_destination($ip),
  'Network destination reported ownership when none existed.'
);

$site_ownership[2][$ip][BlockOwnership::OWNER_WORDFENCE] = true;

resolver_assert(
  BlockOwnershipResolver::has_any_for_network_destination($ip),
  'Inheriting site ownership was not found for the network destination.'
);

unset($site_ownership[2][$ip]);

$site_ownership[3][$ip][BlockOwnership::OWNER_MANUAL] = true;

resolver_assert(
  !BlockOwnershipResolver::has_any_for_network_destination($ip),
  'Non-inheriting site ownership protected the network destination.'
);

unset($site_ownership[3][$ip]);

/*
 * Network Admin manual ownership must protect the shared block.
 */
$network_manual[$ip] = true;

resolver_assert(
  BlockOwnershipResolver::has_other_owner_after_wordfence_removal($ip),
  'Network manual ownership did not protect the shared IP.'
);

resolver_assert(
  BlockOwnershipResolver::has_any_for_network_destination($ip),
  'Network manual ownership was not found for the network destination.'
);

echo "Block ownership resolver regression: PASS\n";
