<?php

declare(strict_types=1);

$managed = [];
$site_owners = [
  1 => [],
  2 => [],
  3 => [],
];
$inheriting_sites = [1, 2];
$network_manual = [];

function stale_fail(string $message): never {
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

function stale_assert(bool $condition, string $message): void {
  if (!$condition) {
    stale_fail($message);
  }
}

/**
 * Reproduce the NetworkSynchronizer ownership-set semantics:
 * network manual ownership plus ownership from inheriting sites only.
 *
 * @param array<string, true> $managed
 * @param array<int, array<string, array<string, true>>> $site_owners
 * @param array<int> $inheriting_sites
 * @param array<string, true> $network_manual
 *
 * @return array<string, true>
 */
function stale_managed_set(
  array $managed,
  array $site_owners,
  array $inheriting_sites,
  array $network_manual
): array {
  $ownership = $network_manual;

  foreach ($inheriting_sites as $blog_id) {
    foreach ($site_owners[$blog_id] ?? [] as $ip => $owners) {
      if ($owners !== []) {
        $ownership[$ip] = true;
      }
    }
  }

  return array_diff_key($managed, $ownership);
}

$ip = '8.8.4.4';
$managed[$ip] = true;

/*
 * Grey Rock provenance with no current owner must become stale.
 */
$stale = stale_managed_set(
  $managed,
  $site_owners,
  $inheriting_sites,
  $network_manual
);

stale_assert(
  isset($stale[$ip]),
  'Managed IP with no current owner was not stale.'
);

/*
 * Ownership on another inheriting site protects the shared Cloudflare IP.
 */
$site_owners[2][$ip]['wordfence'] = true;

$stale = stale_managed_set(
  $managed,
  $site_owners,
  $inheriting_sites,
  $network_manual
);

stale_assert(
  !isset($stale[$ip]),
  'Inheriting site ownership did not protect the managed IP.'
);

unset($site_owners[2][$ip]);

/*
 * Ownership on a non-inheriting site must not protect the Network Admin
 * destination.
 */
$site_owners[3][$ip]['manual'] = true;

$stale = stale_managed_set(
  $managed,
  $site_owners,
  $inheriting_sites,
  $network_manual
);

stale_assert(
  isset($stale[$ip]),
  'Non-inheriting site ownership protected the network destination.'
);

unset($site_owners[3][$ip]);

/*
 * Network Admin manual ownership protects the shared Cloudflare IP.
 */
$network_manual[$ip] = true;

$stale = stale_managed_set(
  $managed,
  $site_owners,
  $inheriting_sites,
  $network_manual
);

stale_assert(
  !isset($stale[$ip]),
  'Network manual ownership did not protect the managed IP.'
);

echo "Network stale-managed regression: PASS\n";
