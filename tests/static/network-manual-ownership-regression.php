<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$network_options = [];

function network_manual_fail(string $message): never {
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

function network_manual_assert(
  bool $condition,
  string $message
): void {
  if (!$condition) {
    network_manual_fail($message);
  }
}

function get_site_option(
  string $option,
  mixed $default = false
): mixed {
  global $network_options;

  return $network_options[$option] ?? $default;
}

function update_site_option(
  string $option,
  mixed $value
): bool {
  global $network_options;

  $network_options[$option] = $value;

  return true;
}

require_once $root . '/src/includes/Services/IpValidator.php';
require_once $root . '/src/includes/Services/NetworkManualOwnershipStore.php';

use WPCF\FirewallSync\Services\NetworkManualOwnershipStore;

$ip = '8.8.4.4';

network_manual_assert(
  !NetworkManualOwnershipStore::has($ip),
  'Unexpected network ownership existed before the test.'
);

network_manual_assert(
  NetworkManualOwnershipStore::add($ip),
  'Could not add network manual ownership.'
);

network_manual_assert(
  NetworkManualOwnershipStore::has($ip),
  'Network manual ownership was not recorded.'
);

network_manual_assert(
  NetworkManualOwnershipStore::get_ips() === [$ip],
  'Network manual ownership inventory is incorrect.'
);

/*
 * Re-adding the same IP must remain idempotent.
 */
network_manual_assert(
  NetworkManualOwnershipStore::add($ip),
  'Re-adding existing network ownership failed.'
);

network_manual_assert(
  NetworkManualOwnershipStore::get_ips() === [$ip],
  'Duplicate network ownership was created.'
);

/*
 * IPv6 must be normalized before storage.
 */
$ipv6_input = '2001:4860:4860:0:0:0:0:8888';
$ipv6_normalized = '2001:4860:4860::8888';

network_manual_assert(
  NetworkManualOwnershipStore::add($ipv6_input),
  'Could not add IPv6 network ownership.'
);

network_manual_assert(
  NetworkManualOwnershipStore::has($ipv6_normalized),
  'Normalized IPv6 network ownership was not found.'
);

$ips = NetworkManualOwnershipStore::get_ips();
sort($ips, SORT_STRING);

$expected = [
  $ipv6_normalized,
  $ip,
];
sort($expected, SORT_STRING);

network_manual_assert(
  $ips === $expected,
  'Network ownership inventory did not preserve normalized IPs.'
);

/*
 * Removing one IP must leave unrelated network ownership intact.
 */
network_manual_assert(
  NetworkManualOwnershipStore::remove($ip),
  'Could not remove network manual ownership.'
);

network_manual_assert(
  !NetworkManualOwnershipStore::has($ip),
  'Removed network ownership still exists.'
);

network_manual_assert(
  NetworkManualOwnershipStore::has($ipv6_normalized),
  'Removing one network owner removed another IP.'
);

/*
 * Removing an already absent valid IP is an idempotent success.
 */
network_manual_assert(
  NetworkManualOwnershipStore::remove($ip),
  'Removing an already absent network ownership failed.'
);

network_manual_assert(
  !NetworkManualOwnershipStore::add('192.0.2.1'),
  'A non-public documentation address was accepted.'
);

network_manual_assert(
  !NetworkManualOwnershipStore::remove('192.0.2.1'),
  'A non-public documentation address was removable.'
);

echo "Network manual ownership regression: PASS\n";
