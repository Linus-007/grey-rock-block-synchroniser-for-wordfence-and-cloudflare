<?php

declare(strict_types=1);

use WPCF\FirewallSync\Services\DnsAllowList;

$root = dirname(__DIR__, 2);

require_once $root
  . '/src/includes/Services/DnsAllowList.php';

function fail_test(string $message): void {
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

function assert_contains(
  string $needle,
  string $haystack,
  string $message
): void {
  if (strpos($haystack, $needle) === false) {
    fail_test($message);
  }
}

if (
  DnsAllowList::sanitize_hostname(
    'admin.example.com.'
  ) !== 'admin.example.com'
) {
  fail_test('A valid DDNS hostname was not normalized.');
}

foreach (
  [
    'https://admin.example.com',
    'admin.example.com/path',
    'admin.example.com:443',
    '127.0.0.1',
    'localhost',
    '-invalid.example',
  ] as $invalid_hostname
) {
  if (
    DnsAllowList::sanitize_hostname(
      $invalid_hostname
    ) !== ''
  ) {
    fail_test(
      "Invalid hostname was accepted: {$invalid_hostname}"
    );
  }
}

$config = file_get_contents(
  $root . '/src/includes/Config.php'
);
$fields = file_get_contents(
  $root . '/src/includes/Admin/Fields.php'
);
$scheduler = file_get_contents(
  $root . '/src/includes/Services/SyncScheduler.php'
);
$service = file_get_contents(
  $root . '/src/includes/Services/DnsAllowList.php'
);
$uninstall = file_get_contents(
  $root . '/src/uninstall.php'
);

foreach (
  [
    'ddns_hostname',
    'ddns_allow_enabled',
  ] as $setting
) {
  assert_contains(
    $setting,
    $config,
    "Configuration setting is missing: {$setting}"
  );
}

assert_contains(
  'DDNS domain',
  $fields,
  'The DDNS domain field is missing.'
);

assert_contains(
  'Resolved addresses',
  $fields,
  'The read-only resolved-address display is missing.'
);

assert_contains(
  'Administrator allow list',
  $fields,
  'The administrator allow-list checkbox is missing.'
);

assert_contains(
  'DnsAllowList::refresh_scope',
  $fields,
  'Settings saves do not refresh the DNS lookup.'
);

assert_contains(
  'DnsAllowList::get_effective_allowed_ips()',
  $scheduler,
  'Scheduled synchronization does not load allowed addresses.'
);

if (
  substr_count(
    $scheduler,
    'isset($allowed_ips[$ip])'
  ) !== 2
) {
  fail_test(
    'Both current and historical candidate paths must exclude allowed addresses.'
  );
}

assert_contains(
  'REFRESH_INTERVAL_SECONDS = 300',
  $service,
  'The five-minute refresh threshold is missing.'
);

assert_contains(
  'FAILURE_GRACE_SECONDS = 86400',
  $service,
  'The 24-hour failure grace period is missing.'
);

assert_contains(
  'DNS_A | DNS_AAAA',
  $service,
  'A and AAAA lookups are not both enabled.'
);

assert_contains(
  'IpValidator::validate_public_ip',
  $service,
  'Resolved addresses are not validated as public.'
);

assert_contains(
  'firewall_sync_ddns_state',
  $uninstall,
  'Site lookup state is not removed during uninstall.'
);

assert_contains(
  'firewall_sync_network_ddns_state',
  $uninstall,
  'Network lookup state is not removed during uninstall.'
);

if (
  preg_match(
    '/\b(?:white|black)[ -]?list/i',
    $service
  ) === 1
) {
  fail_test(
    'Disallowed allow/block terminology was introduced.'
  );
}

$block_logger = file_get_contents(
  $root . '/src/includes/Services/BlockLogger.php'
);
$live_test = file_get_contents(
  $root . '/tests/integration/cloudflare-live-test.php'
);

assert_contains(
  'remove_allowed_ips_from_cloudflare',
  $scheduler,
  'Trusted addresses are not removed from Cloudflare.'
);

assert_contains(
  'remove_ip_from_account_list',
  $scheduler,
  'Account IP List removal is missing.'
);

assert_contains(
  'delete_block',
  $scheduler,
  'Zone Access Rules removal is missing.'
);

assert_contains(
  'BlockLogger::remove',
  $scheduler,
  'Trusted-address synchronization records are not cleared.'
);

assert_contains(
  'public static function remove(string $ip): bool',
  $block_logger,
  'BlockLogger does not provide exact-IP record removal.'
);

assert_contains(
  'automatically removes the resolved addresses',
  $fields,
  'The administrator description does not explain automatic removal.'
);

assert_contains(
  'SyncScheduler::run_now()',
  $live_test,
  'The live test does not invoke automatic trusted-address removal.'
);

assert_contains(
  'automatically removed 8.8.8.8',
  $live_test,
  'The live test does not verify automatic trusted-address removal.'
);

$github_readme = file_get_contents(
  $root . '/README.md'
);
$wordpress_readme = file_get_contents(
  $root . '/readme.txt'
);

if (
  substr_count(
    $github_readme,
    '## DDNS-resolved administrator allow list'
  ) !== 1
) {
  fail_test(
    'The GitHub DDNS allow-list guide must appear exactly once.'
  );
}

if (
  substr_count(
    $wordpress_readme,
    '= DDNS-resolved administrator allow list ='
  ) !== 1
) {
  fail_test(
    'The WordPress.org DDNS allow-list guide must appear exactly once.'
  );
}

foreach (
  [
    'DNS only',
    'five minutes',
    '24 hours',
    'removed automatically',
    'does not create or update DNS records',
    'does not widen an IPv6 address to a `/64`',
    'Multisite behaviour',
    'The DDNS domain resolves to no address',
    'A trusted address remains blocked',
  ] as $documentation_contract
) {
  assert_contains(
    $documentation_contract,
    $github_readme,
    "GitHub documentation is missing: {$documentation_contract}"
  );
}

foreach (
  [
    'DNS only',
    'five minutes',
    '24 hours',
    'removes those addresses',
    'does not create or update DNS records',
    'Does Grey Rock update my DDNS record?',
    'When is a trusted address removed from Cloudflare?',
    'Does Grey Rock create an IPv6 /64 allow-list entry?',
  ] as $documentation_contract
) {
  assert_contains(
    $documentation_contract,
    $wordpress_readme,
    "WordPress.org documentation is missing: {$documentation_contract}"
  );
}

assert_contains(
  'https://img.shields.io/badge/version-1.3.1-blue',
  $github_readme,
  'The GitHub README version badge is not 1.3.1.'
);

assert_contains(
  '### 1.3.1',
  $github_readme,
  'The GitHub 1.3.1 changelog entry is missing.'
);

assert_contains(
  '= 1.3.1 =',
  $wordpress_readme,
  'The WordPress.org 1.3.1 changelog entry is missing.'
);

echo "PASS: DDNS lookup, automatic allow-list removal and user documentation contracts are present.\n";
