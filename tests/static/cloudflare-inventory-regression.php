<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$http_responses = [];

function inventory_fail(string $message): never {
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
}

function inventory_assert(bool $condition, string $message): void {
  if (!$condition) {
    inventory_fail($message);
  }
}

function __($text, $domain = null): string {
  return (string) $text;
}

function sanitize_text_field($value): string {
  return trim((string) $value);
}

function wp_remote_get(string $url, array $args) {
  global $http_responses;
  return array_shift($http_responses);
}

function wp_remote_retrieve_response_code($response): int {
  return (int) ($response['response']['code'] ?? 0);
}

function wp_remote_retrieve_response_message($response): string {
  return (string) ($response['response']['message'] ?? '');
}

function wp_remote_retrieve_body($response): string {
  return (string) ($response['body'] ?? '');
}

function is_wp_error($response): bool {
  return $response instanceof WP_Error;
}

final class WP_Error {
  public function __construct(private string $message) {}

  public function get_error_message(): string {
    return $this->message;
  }
}

function cf_response(array $body, int $code = 200): array {
  return [
    'response' => ['code' => $code, 'message' => 'response'],
    'body' => json_encode($body, JSON_THROW_ON_ERROR),
  ];
}

require_once $root . '/src/includes/Services/IpValidator.php';
require_once $root . '/src/includes/Services/CloudflareIdentifierValidator.php';
require_once $root . '/src/includes/Cloudflare/Client.php';

$account_id = str_repeat('a', 32);
$list_id = str_repeat('b', 32);

$http_responses = [cf_response([
  'success' => true,
  'errors' => [],
  'messages' => [],
  'result' => [],
  'result_info' => ['total_pages' => 1],
])];
$client = new \WPCF\FirewallSync\Cloudflare\Client('token', '');
inventory_assert(
  $client->get_current_account_list_ips($account_id, $list_id) === [],
  'A complete empty account list was not preserved as an empty array.'
);

$http_responses = [new WP_Error('transport failed')];
$client = new \WPCF\FirewallSync\Cloudflare\Client('token', '');
inventory_assert(
  $client->get_current_account_list_ips($account_id, $list_id) === null,
  'A transport failure was collapsed into an empty list.'
);

$http_responses = [[
  'response' => ['code' => 200, 'message' => 'OK'],
  'body' => '{malformed',
]];
$client = new \WPCF\FirewallSync\Cloudflare\Client('token', '');
inventory_assert(
  $client->get_current_account_list_ips($account_id, $list_id) === null,
  'Malformed Cloudflare JSON was accepted as complete inventory.'
);

$http_responses = [
  cf_response([
    'success' => true,
    'errors' => [],
    'messages' => [],
    'result' => [
      ['id' => 'item-one', 'ip' => '8.8.8.8'],
    ],
    'result_info' => ['total_pages' => 2],
  ]),
  new WP_Error('second page failed'),
];
$client = new \WPCF\FirewallSync\Cloudflare\Client('token', '');
inventory_assert(
  $client->get_current_account_list_ips($account_id, $list_id) === null,
  'Partial paginated inventory was accepted as complete.'
);

echo "Cloudflare inventory regression: PASS\n";
