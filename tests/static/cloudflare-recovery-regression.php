<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Cloudflare {
  function time(): int { return $GLOBALS['clock']; }
  function usleep(int $microseconds): void { $GLOBALS['sleeps']++; }
}
namespace {
  require_once __DIR__ . '/../fixtures/task14-unit-fence.php';
  use WPCF\FirewallSync\Cloudflare\Client;
  $root = dirname(__DIR__, 2);
  $queue = [];
  $requests = [];
  $transients = [];
  $clock = 2000000000;
  $sleeps = 0;
  function __($text, $domain = null): string { return $text; }
  function sanitize_text_field($text): string { return trim(strip_tags((string) $text)); }
  function wp_json_encode($value): string { return json_encode($value, JSON_THROW_ON_ERROR); }
  final class WP_Error {
    public function __construct(private string $code, private string $message = '') {}
    public function get_error_message(): string { return $this->message ?: $this->code; }
  }
  function is_wp_error($value): bool { return $value instanceof WP_Error; }
  function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
  function set_transient($key, $value, $ttl): bool { $GLOBALS['transients'][$key] = $value; return true; }
  function wp_remote_get($url, $args) { return mocked_request('GET', $url, $args); }
  function wp_remote_post($url, $args) { return mocked_request('POST', $url, $args); }
  function wp_remote_request($url, $args) { return mocked_request($args['method'] ?? '', $url, $args); }
  function mocked_request($method, $url, $args) {
    $GLOBALS['requests'][] = [$method, $url, $args];
    if (!$GLOBALS['queue']) { throw new RuntimeException('Unexpected mock HTTP request; live HTTP is forbidden.'); }
    return array_shift($GLOBALS['queue']);
  }
  function wp_remote_retrieve_response_code($response): int { return (int) ($response['response']['code'] ?? 0); }
  function wp_remote_retrieve_response_message($response): string { return 'mock response'; }
  function wp_remote_retrieve_body($response): string { return (string) ($response['body'] ?? ''); }
  function wp_remote_retrieve_header($response, $header) { return $response['headers'][strtolower($header)] ?? ''; }
  function reply($result, array $info = [], int $code = 200): array {
    return ['response' => ['code' => $code], 'body' => wp_json_encode(['success' => $code < 300, 'result' => $result, 'errors' => $code < 300 ? [] : [['message' => 'mock failure']], 'result_info' => $info])];
  }
  function rule($ip, $id, $mode = 'block'): array { return ['id' => $id, 'mode' => $mode, 'configuration' => ['target' => 'ip', 'value' => $ip]]; }
  function check(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
  require $root . '/src/includes/Services/IpValidator.php';
  require $root . '/src/includes/Services/CloudflareIdentifierValidator.php';
  require $root . '/src/includes/Cloudflare/Client.php';
  $account = str_repeat('a', 32);
  $list = str_repeat('b', 32);
  $zone = str_repeat('c', 32);
  $operation = str_repeat('d', 32);
  $first = str_repeat('1', 32);
  $second = str_repeat('2', 32);
  $cases = [];
  $cases['cursor'] = function () use ($account, $list): void {
    $GLOBALS['queue'] = [reply([['id' => 'one', 'ip' => '8.8.8.8']], ['cursors' => ['after' => 'next/+?']]), reply([['id' => 'two', 'ip' => '1.1.1.1']], ['cursors' => ['after' => null]])];
    $client = new Client('fixture-credential-value', '');
    check($client->get_current_account_list_ips($account, $list) === ['8.8.8.8', '1.1.1.1'], 'Cursor inventory was truncated.');
    check(!str_contains($GLOBALS['requests'][0][1], '&page='), 'Cursor endpoint received an unsupported page argument.');
    check(str_contains($GLOBALS['requests'][1][1], 'cursor=next%2F%2B%3F'), 'Opaque cursor not encoded.');
  };
  $cases['cursor-loop'] = function () use ($account, $list): void {
    $GLOBALS['queue'] = [reply([], ['cursors' => ['after' => 'again']]), reply([], ['cursors' => ['after' => 'again']])];
    check((new Client('fixture-credential-value', ''))->get_current_account_list_ips($account, $list) === null, 'Repeated cursor accepted as complete.');
    check(count($GLOBALS['requests']) === 2, 'Cursor loop not bounded.');
  };
  $cases['incomplete-item'] = function () use ($account, $list): void {
    $GLOBALS['queue'] = [reply([['ip' => '8.8.8.8']], ['total_pages' => 1])];
    check((new Client('fixture-credential-value', ''))->get_current_account_list_ips($account, $list) === null, 'Missing item ID silently omitted from complete inventory.');
  };
  $cases['conflicting-items'] = function () use ($account, $list): void {
    $GLOBALS['queue'] = [reply([['id' => 'one', 'ip' => '8.8.8.8'], ['id' => 'two', 'ip' => '::ffff:8.8.8.8']], ['total_pages' => 1])];
    check((new Client('fixture-credential-value', ''))->get_current_account_list_ips($account, $list) === null, 'Conflicting canonical entries accepted as complete.');
  };
  $cases['async-failure'] = function () use ($account, $list, $operation): void {
    $GLOBALS['queue'] = [reply([]), reply(['operation_id' => $operation]), reply(['id' => $operation, 'status' => 'failed', 'error' => 'mock asynchronous failure'])];
    check(!(new Client('fixture-credential-value', ''))->add_ip_to_account_list($account, $list, '8.8.8.8'), 'Accepted but failed operation reported success.');
  };
  $cases['async-success'] = function () use ($account, $list, $operation): void {
    $GLOBALS['queue'] = [reply([]), reply(['operation_id' => $operation]), reply(['id' => $operation, 'status' => 'pending']), reply(['id' => $operation, 'status' => 'completed'])];
    check((new Client('fixture-credential-value', ''))->add_ip_to_account_list($account, $list, '8.8.8.8'), 'Completed operation failed.');
    check(count($GLOBALS['requests']) === 4, 'Asynchronous completion was not checked.');
  };
  $cases['async-pending'] = function () use ($account, $list, $operation): void {
    $GLOBALS['queue'] = [reply([]), reply(['operation_id' => $operation])];
    for ($i = 0; $i < 3; $i++) { $GLOBALS['queue'][] = reply(['id' => $operation, 'status' => 'running']); }
    $failed = (new Client('fixture-credential-value', ''))->batch_add_ips_to_account_list($account, $list, [['ip' => '8.8.8.8'], ['ip' => '1.1.1.1']]);
    check($failed === ['8.8.8.8', '1.1.1.1'], 'Pending work reported complete.');
    check(count($GLOBALS['requests']) === 5, 'Pending operation caused unbounded polling or a conflicting second mutation.');
  };
  $cases['zone-malformed-delete'] = function () use ($zone): void {
    $GLOBALS['queue'] = [reply([['configuration' => ['target' => 'ip', 'value' => '8.8.8.8'], 'mode' => 'block']])];
    check(!(new Client('fixture-credential-value', $zone))->delete_block('8.8.8.8'), 'Malformed matching rule reported absent.');
  };
  $cases['zone-delete-target'] = function () use ($zone, $first): void {
    $GLOBALS['queue'] = [reply([rule('1.1.1.1', $first)])];
    check((new Client('fixture-credential-value', $zone))->delete_block('8.8.8.8'), 'Absent target was not idempotent.');
    check(count($GLOBALS['requests']) === 1, 'Unrelated remote rule deleted.');
  };
  $cases['zone-duplicates'] = function () use ($zone, $first, $second): void {
    $GLOBALS['queue'] = [reply([rule('8.8.8.8', $first)], ['total_pages' => 2]), reply([rule('8.8.8.8', $second)], ['total_pages' => 2]), reply(['id' => $first]), reply(['id' => $second])];
    check((new Client('fixture-credential-value', $zone))->delete_block('8.8.8.8'), 'Duplicate block removal failed.');
    check(count($GLOBALS['requests']) === 4, 'Only the first duplicate/page was removed.');
  };
  $cases['zone-canonical'] = function () use ($zone, $first, $second): void {
    $GLOBALS['queue'] = [reply([rule('2606:4700:4700:0000:0000:0000:0000:1111', $first), rule('::ffff:8.8.8.8', $second)])];
    check((new Client('fixture-credential-value', $zone))->get_current_blocked_ips() === ['2606:4700:4700::1111', '8.8.8.8'], 'Remote inventory was not canonicalized.');
  };
  $cases['conflict-recovery'] = function () use ($zone, $first): void {
    $existing = rule('8.8.8.8', $first); $existing['notes'] = 'Wordfence Sync Block';
    $GLOBALS['queue'] = [reply([], [], 409), reply([$existing])];
    check((new Client('fixture-credential-value', $zone))->create_block('8.8.8.8'), 'Previously successful remote write could not recover from duplicate/conflict.');
  };
  $cases['rate-limit'] = function () use ($zone): void {
    $limited = reply([], [], 429); $limited['headers'] = ['retry-after' => '120'];
    $GLOBALS['queue'] = [$limited, reply([], [], 429)];
    $failed = (new Client('fixture-credential-value', $zone))->batch_block([['ip' => '8.8.8.8'], ['ip' => '1.1.1.1']]);
    check($failed === ['8.8.8.8', '1.1.1.1'], 'Rate-limited IP reported synchronized.');
    check(count($GLOBALS['requests']) === 1, 'Batch ignored rate-limit cooldown.');
    check(!(new Client('fixture-credential-value', $zone))->create_block('9.9.9.9'), 'New client ignored shared cooldown.');
    check(count($GLOBALS['requests']) === 1, 'Retry-After was not retained across client instances.');
    $GLOBALS['clock'] += 121;
    $GLOBALS['queue'] = [reply(['id' => str_repeat('3', 32)])];
    check((new Client('fixture-credential-value', $zone))->create_block('9.9.9.9'), 'Rate-limited operation did not resume after deadline.');
  };
  $cases['failure-diagnostic'] = function () use ($zone, $first): void {
    $GLOBALS['queue'] = [reply([], [], 401), reply(['id' => $first])];
    $client = new Client('fixture-credential-value', $zone);
    check($client->batch_block([['ip' => '8.8.8.8'], ['ip' => '1.1.1.1']]) === ['8.8.8.8'], 'Partial batch success recorded incorrectly.');
    check(str_contains($client->get_last_error_message(), '401'), 'Later success erased the first batch failure diagnostic.');
  };
  $cases['malformed-zone-write'] = function () use ($zone): void {
    $GLOBALS['queue'] = [reply([])];
    check(!(new Client('fixture-credential-value', $zone))->create_block('8.8.8.8'), 'Empty mutation result accepted as a successful write.');
  };
  $cases['conflict-unmanaged'] = function () use ($zone, $first): void {
    $existing = rule('8.8.8.8', $first); $existing['notes'] = 'Independent operator rule';
    $GLOBALS['queue'] = [reply([], [], 409), reply([$existing])];
    check(!(new Client('fixture-credential-value', $zone))->create_block('8.8.8.8'), 'Conflict recovery adopted an unrelated operator rule.');
  };
  $cases['object-inventory'] = function () use ($account, $list): void {
    $GLOBALS['queue'] = [reply(new stdClass())];
    check((new Client('fixture-credential-value', ''))->get_current_account_list_ips($account, $list) === null, 'Object result was accepted as an empty list.');
  };
  $cases['invalid-pagination'] = function () use ($account, $list): void {
    $GLOBALS['queue'] = [reply([], ['total_count' => -1, 'per_page' => 50])];
    check((new Client('fixture-credential-value', ''))->get_current_account_list_ips($account, $list) === null, 'Negative pagination count was accepted as a complete empty inventory.');
  };
  $cases['fresh-inventory'] = function () use ($account, $list, $operation): void {
    $GLOBALS['queue'] = [reply([['id' => 'original', 'ip' => '1.1.1.1']]), reply(['operation_id' => $operation]), reply(['id' => $operation, 'status' => 'completed']), reply([['id' => 'original', 'ip' => '1.1.1.1'], ['id' => 'new', 'ip' => '8.8.8.8'], ['id' => 'external', 'ip' => '9.9.9.9']])];
    $client = new Client('fixture-credential-value', '');
    check($client->add_ip_to_account_list($account, $list, '8.8.8.8'), 'Confirmed list write failed.');
    check($client->get_current_account_list_ips($account, $list, true) === ['1.1.1.1', '8.8.8.8', '9.9.9.9'], 'Post-write reconciliation used a stale optimistic cache.');
  };
  $cases['delete-page-failure'] = function () use ($zone, $first): void {
    $GLOBALS['queue'] = [reply([rule('8.8.8.8', $first)], ['total_pages' => 2]), new WP_Error('transport', 'second page failed')];
    check(!(new Client('fixture-credential-value', $zone))->delete_block('8.8.8.8'), 'Incomplete rule lookup was reported successful.');
    check(count($GLOBALS['requests']) === 2 && $GLOBALS['requests'][1][0] === 'GET', 'Deletion occurred before all pages were validated.');
  };
  $cases['partial-delete-recovery'] = function () use ($zone, $first, $second): void {
    $GLOBALS['queue'] = [reply([rule('8.8.8.8', $first), rule('8.8.8.8', $second)]), reply(['id' => $first]), reply([], [], 503), reply([rule('8.8.8.8', $second)]), reply(['id' => $second])];
    $client = new Client('fixture-credential-value', $zone);
    check(!$client->delete_block('8.8.8.8'), 'Partial deletion was reported complete.');
    check($client->delete_block('8.8.8.8'), 'A retry did not finish the remaining duplicate.');
  };
  $cases['error-matrix'] = function () use ($zone): void {
    foreach ([400, 401, 403, 404, 409, 429, 500, 503] as $code) {
      $GLOBALS['transients'] = [];
      $GLOBALS['queue'] = [reply([], [], $code)];
      if ($code === 400 || $code === 409) { $GLOBALS['queue'][] = reply([]); }
      $client = new Client('fixture-credential-value', $zone);
      check(!$client->create_block('8.8.8.8'), 'HTTP failure was accepted: ' . $code);
      check(str_contains($client->get_last_error_message(), (string) $code), 'HTTP status was lost: ' . $code);
    }
    foreach ([new WP_Error('transport', 'timeout'), ['response' => ['code' => 200], 'body' => '{bad JSON'], reply(null), reply(['id' => '../unsafe'])] as $response) {
      $GLOBALS['transients'] = [];
      $GLOBALS['queue'] = [$response];
      check(!(new Client('fixture-credential-value', $zone))->create_block('8.8.8.8'), 'Transport/malformed response reported success.');
    }
  };
  $cases['async-delete-failure'] = function () use ($account, $list, $operation): void {
    $GLOBALS['queue'] = [reply([['id' => 'one', 'ip' => '8.8.8.8']]), reply(['operation_id' => $operation]), reply(['id' => $operation, 'status' => 'failed']), reply([['id' => 'one', 'ip' => '8.8.8.8']])];
    $client = new Client('fixture-credential-value', '');
    check(!$client->remove_ip_from_account_list($account, $list, '8.8.8.8'), 'Failed asynchronous deletion reported success.');
    check($client->get_current_account_list_ips($account, $list) === ['8.8.8.8'], 'Failed deletion removed actual membership from the cache.');
  };
  $cases['async-id-mismatch'] = function () use ($account, $list, $operation): void {
    $GLOBALS['queue'] = [reply([]), reply(['operation_id' => $operation]), reply(['id' => 'wrong', 'status' => 'completed'])];
    check(!(new Client('fixture-credential-value', ''))->add_ip_to_account_list($account, $list, '8.8.8.8'), 'Completion of a different operation was accepted.');
  };
  $cases['rate-limit-date'] = function () use ($zone, $first): void {
    $limited = reply([], [], 429); $limited['headers'] = ['retry-after' => gmdate(DATE_RFC7231, $GLOBALS['clock'] + 120)];
    $GLOBALS['queue'] = [$limited];
    check(!(new Client('fixture-credential-value', $zone))->create_block('8.8.8.8'), '429 was accepted.');
    $GLOBALS['clock'] += 60;
    check(!(new Client('fixture-credential-value', $zone))->create_block('8.8.8.8') && count($GLOBALS['requests']) === 1, 'HTTP-date Retry-After was ignored.');
    $GLOBALS['clock'] += 61;
    $GLOBALS['queue'] = [reply(['id' => $first])];
    check((new Client('fixture-credential-value', $zone))->create_block('8.8.8.8'), 'HTTP-date cooldown did not expire.');
  };
  $cases['service-retry-after'] = function () use ($zone): void {
    $limited = reply([], [], 503); $limited['headers'] = ['retry-after' => '120'];
    $GLOBALS['queue'] = [$limited, reply([], [], 503)];
    $failed = (new Client('fixture-credential-value', $zone))->batch_block([['ip' => '8.8.8.8'], ['ip' => '1.1.1.1']]);
    check(count($failed) === 2 && count($GLOBALS['requests']) === 1, '503 Retry-After was ignored.');
  };
  $cases['async-conflict-batch'] = function () use ($account, $list): void {
    $GLOBALS['queue'] = [reply([]), reply([], [], 409), reply([], [], 409)];
    $failed = (new Client('fixture-credential-value', ''))->batch_add_ips_to_account_list($account, $list, [['ip' => '8.8.8.8'], ['ip' => '1.1.1.1']]);
    check(count($failed) === 2 && count($GLOBALS['requests']) === 2, 'List conflict caused another mutation behind unresolved account work.');
  };
  $cases['zone-repeated-page'] = function () use ($zone, $first): void {
    $GLOBALS['queue'] = [reply([rule('8.8.8.8', $first)], ['total_pages' => 2]), reply([rule('8.8.8.8', $first)], ['total_pages' => 2])];
    check((new Client('fixture-credential-value', $zone))->get_current_blocked_ips() === null, 'Repeated zone page was accepted as complete inventory.');
  };
  $selected = $argv[1] ?? '';
  foreach ($cases as $name => $case) {
    if ($selected !== '' && $name !== $selected) { continue; }
    $queue = []; $requests = []; $transients = []; $clock = 2000000000; $sleeps = 0;
    $case();
    echo "PASS: Cloudflare recovery {$name}\n";
  }
}
