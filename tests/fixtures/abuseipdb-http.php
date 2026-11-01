<?php
/** Entire HTTP boundary denied except in-memory responses. No real submissions. */
add_filter('pre_http_request', static function ($pre, $args, $url) {
  global $wpdb;
  if (str_starts_with($url, 'https://api.abuseipdb.com/api/v2/')) {
    if (!in_array($url, ['https://api.abuseipdb.com/api/v2/report', 'https://api.abuseipdb.com/api/v2/bulk-report'], true)) { throw new RuntimeException('Forbidden AbuseIPDB endpoint'); }
    $GLOBALS['gr_abuse_requests'][] = [$url, $args];
    $wpdb->insert($wpdb->base_prefix . 'wpcf_abuse_state', ['state_key' => 'fixture-http:' . bin2hex(random_bytes(16)), 'state_value' => 'mock-only']);
    $mode = (string) ($GLOBALS['gr_abuse_response_mode'] ?? 'accepted');
    if ($mode === 'transport') { echo "MOCK_ABUSEIPDB transport-uncertain; no real request\n"; return new WP_Error('mock_timeout', 'Mock uncertainty'); }
    if ($mode === 'slow') { usleep(250000); }
    $bulk = str_ends_with($url, 'bulk-report');
    $code = match ($mode) { 'rejected' => 422, '429' => 429, 'server' => 503, default => 200 };
    $data = $bulk ? ['savedReports' => substr_count($args['body'], '"Wordfence block evidence:') + substr_count($args['body'], '"Administrator evidence:'), 'invalidReports' => []]
      : ['ipAddress' => $args['body']['ip'], 'abuseConfidenceScore' => 0];
    if ($mode === 'malformed') { $data = []; }
    echo 'MOCK_ABUSEIPDB ' . wp_json_encode(['endpoint' => $bulk ? 'bulk-report' : 'report', 'http_code' => $code, 'categories' => $bulk ? 'CSV' : $args['body']['categories'], 'timestamp' => $bulk ? 'ISO8601 CSV' : $args['body']['timestamp'], 'comment' => $bulk ? 'fixed classification descriptions' : $args['body']['comment'], 'savedReports' => $bulk ? $data['savedReports'] ?? null : null]) . "\n";
    return ['headers' => $mode === 'no-headers' ? [] : ['x-ratelimit-limit' => '1000', 'x-ratelimit-remaining' => '999', 'x-ratelimit-reset' => (string) (time() + 3600), 'retry-after' => $mode === '429' ? '1800' : '0'],
      'response' => ['code' => $code], 'body' => wp_json_encode($code === 200 ? ['data' => $data] : ['errors' => [['status' => $code]]])];
  }
  if (str_starts_with($url, 'https://api.cloudflare.com/client/v4/')) {
    if (!empty($GLOBALS['gr_abuse_cf_failure'])) { return new WP_Error('mock_cf_failure', 'Mock membership unavailable'); }
    $GLOBALS['gr_abuse_cf_reads'] = ($GLOBALS['gr_abuse_cf_reads'] ?? 0) + 1;
    $method = $args['method'] ?? 'GET';
    if (str_contains($url, '/firewall/access_rules/rules') && $method === 'POST') {
      $GLOBALS['gr_abuse_cf_writes'] = ($GLOBALS['gr_abuse_cf_writes'] ?? 0) + 1;
      $body = json_decode($args['body'], true);
      $GLOBALS['gr_abuse_members'][] = $body['configuration']['value'];
      return ['headers'=>[], 'response'=>['code'=>200], 'body'=>wp_json_encode(['success'=>true,'result'=>['id'=>md5($body['configuration']['value'])]])];
    }
    foreach ($GLOBALS['gr_abuse_members'] ?? [] as $member) {
      $wpdb->replace($wpdb->base_prefix . 'wpcf_abuse_state', ['state_key'=>'fixture-member:'.$member,'state_value'=>$member]);
    }
    $members = $wpdb->get_col("SELECT state_value FROM {$wpdb->base_prefix}wpcf_abuse_state WHERE state_key LIKE 'fixture-member:%'");
    $items = array_map(static fn ($ip) => ['ip' => $ip, 'id' => str_repeat('c', 32)], $members);
    if (str_contains($url, '/firewall/access_rules/rules')) {
      $items = array_map(static fn ($ip) => ['id' => md5($ip), 'mode' => 'block', 'configuration' => ['target' => 'ip', 'value' => $ip], 'notes' => 'sync'], $members);
    }
    return ['headers' => [], 'response' => ['code' => 200], 'body' => wp_json_encode(['success' => true, 'result' => $items, 'result_info' => ['page' => 1, 'per_page' => 50, 'total_pages' => 1, 'count' => count($items), 'cursors' => ['after' => '']]])];
  }
  return new WP_Error('mock_egress_denied', 'All external HTTP prohibited');
}, PHP_INT_MAX, 3);
