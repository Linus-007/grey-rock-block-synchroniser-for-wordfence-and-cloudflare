<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Cloudflare;

use WPCF\FirewallSync\Services\CloudflareIdentifierValidator;

use WPCF\FirewallSync\Services\IpValidator;

use WPCF\FirewallSync\Plugin;

final class Client {
  private const RESULT_LIST_FLAG = '__grey_rock_result_is_list';
  private const MAX_INVENTORY_PAGES = 10000;
  private int $retryUntil = 0;
  private array $retryableFailedIps = [];
  private bool $pendingListOperation = false;
  private array $destinationLeases = [];
  private bool $resumingListOperation = false;

  public function __destruct() {
    foreach ($this->destinationLeases as $lease) { $lease->release(); }
  }

  /** Hold a destination before scheduler ownership reads/writes begin. */
  public function coordinate_destination(string $mode, string $account_id = ''): bool {
    if (!class_exists('WPCF\\FirewallSync\\Services\\DestinationLease')) { return true; }
    $scope = $mode === 'account_list'
      ? 'account:' . strtolower($account_id)
      : 'zone:' . strtolower($this->zone);
    try {
      if (!isset($this->destinationLeases[$scope])) {
        $lease = new \WPCF\FirewallSync\Services\DestinationLease($scope);
        $lease->acquire();
        $this->destinationLeases[$scope] = $lease;
      }
      $this->destinationLeases[$scope]->assert_live();
      return true;
    } catch (\RuntimeException $error) {
      return $this->fail('coordinate_destination', $error->getMessage());
    }
  }

  private const MAX_RESPONSE_BYTES = 1048576;
  private const MAX_COMMENT_LENGTH = 200;
  private const HTTP_TIMEOUT_SECONDS = 30;
  private const HTTP_REDIRECTION_LIMIT = 3;

  private string $token;
  private string $zone;
  private string $apiBase = 'https://api.cloudflare.com/client/v4';

  /**
   * Account-list items cached for the lifetime of this Client instance.
   *
   * Keys identify the Cloudflare account and list. Each value maps an IP
   * address to its Cloudflare list-item ID.
   *
   * @var array<string, array<string, string>>
   */
  private array $accountListItemCache = [];

  /**
   * Account-list IDs resolved from their visible Cloudflare names.
   *
   * @var array<string, string>
   */
  private array $accountListIdCache = [];

  /**
   * Most recent Cloudflare or local validation error.
   *
   * @var array{operation: string, message: string, http_code: int|null}|null
   */
  private ?array $lastError = null;

  public function __construct(string $token, string $zone) {
    $this->token =
      CloudflareIdentifierValidator::normalize_api_token($token);
    $this->zone =
      CloudflareIdentifierValidator::normalize_zone_id($zone);
  }

  public function validate(): bool {
    if (
      !CloudflareIdentifierValidator::validate_api_token(
        $this->token
      )
    ) {
      return $this->fail(
        'validate_token',
        __(
          'Cloudflare API Token is missing or malformed.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        )
      );
    }

    if (
      !CloudflareIdentifierValidator::validate_zone_id(
        $this->zone
      )
    ) {
      return $this->fail(
        'validate_zone',
        __(
          'Cloudflare Zone ID must contain exactly 32 hexadecimal characters.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        )
      );
    }

    $url = $this->apiBase . "/zones/{$this->zone}";
    $response = $this->request('GET', $url, $this->get_request_args());

    return $this->response_succeeded(
      'validate_zone',
      $response,
      [200]
    );
  }

  public function validate_account_list(
    string $account_id,
    string $list_name,
    string $legacy_list_id = ''
  ): bool {
    return $this->resolve_account_list_id(
      $account_id,
      $list_name,
      $legacy_list_id
    ) !== null;
  }

  /**
   * Resolve a visible Cloudflare account-list name to its internal API ID.
   *
   * Cloudflare displays a list variable such as
   * $wordfence_hot_blocklist, but the ordinary dashboard does not expose
   * the internal list UUID required by the API.
   *
   * The legacy List ID parameter preserves compatibility with existing
   * installations that already stored the internal ID.
   */
  public function resolve_account_list_id(
    string $account_id,
    string $list_name,
    string $legacy_list_id = ''
  ): ?string {
    if (
      !CloudflareIdentifierValidator::validate_api_token(
        $this->token
      )
    ) {
      $this->fail(
        'validate_token',
        __(
          'Cloudflare API Token is missing or malformed.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        )
      );

      return null;
    }

    $account_id =
      CloudflareIdentifierValidator::normalize_account_id(
        $account_id
      );

    $list_name =
      CloudflareIdentifierValidator::normalize_list_name(
        $list_name
      );

    $legacy_list_id =
      CloudflareIdentifierValidator::normalize_list_id(
        $legacy_list_id
      );

    if (
      !CloudflareIdentifierValidator::validate_account_id(
        $account_id
      )
    ) {
      $this->fail(
        'resolve_account_list',
        __(
          'Cloudflare Account ID must contain exactly 32 hexadecimal characters.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        )
      );

      return null;
    }

    if ($list_name === '') {
      if ($legacy_list_id === '') {
        $this->fail(
          'resolve_account_list',
          __('Cloudflare List Name is required.', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare')
        );

        return null;
      }

      $cache_key = $account_id . ':legacy:' . $legacy_list_id;

      if (isset($this->accountListIdCache[$cache_key])) {
        return $this->accountListIdCache[$cache_key];
      }

      $url = $this->apiBase
        . "/accounts/{$account_id}/rules/lists/{$legacy_list_id}";

      $response = $this->request('GET', $url, $this->get_request_args());

      $body = null;

      if (
        !$this->response_succeeded(
          'validate_legacy_account_list',
          $response,
          [200],
          $body
        )
      ) {
        return null;
      }

      $result = $body['result'] ?? null;

      if (
        !is_array($result)
        || (string) ($result['kind'] ?? '') !== 'ip'
      ) {
        $this->fail(
          'validate_legacy_account_list',
          __(
            'The stored Cloudflare list is not an IP list.',
            'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
          )
        );

        return null;
      }

      $this->accountListIdCache[$cache_key] = $legacy_list_id;
      $this->clear_last_error();

      return $legacy_list_id;
    }

    if (
      !CloudflareIdentifierValidator::validate_list_name(
        $list_name
      )
    ) {
      $this->fail(
        'resolve_account_list',
        __(
          'Cloudflare List Name may contain only lowercase letters, numbers and underscores.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        )
      );

      return null;
    }

    $cache_key = $account_id . ':name:' . $list_name;

    if (isset($this->accountListIdCache[$cache_key])) {
      return $this->accountListIdCache[$cache_key];
    }

    $matches = [];
    $page = 1;

    do {
      $url = $this->apiBase
        . "/accounts/{$account_id}/rules/lists"
        . "?page={$page}&per_page=50";

      $response = $this->request('GET',
        $url,
        $this->get_request_args()
      );

      $body = null;

      if (
        !$this->response_succeeded(
          'list_account_lists',
          $response,
          [200],
          $body
        )
      ) {
        return null;
      }

      $result = $this->response_result_array(
        'list_account_lists',
        $body
      );

      if ($result === null) {
        return null;
      }

      foreach ($result as $list) {
        if (!is_array($list)) {
          $this->fail(
            'list_account_lists',
            __(
              'Cloudflare returned an invalid account-list item.',
              'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
            )
          );

          return null;
        }
        if ((string) ($list['name'] ?? '') !== $list_name) {
          continue;
        }

        $matches[] = [
          'id' => (string) ($list['id'] ?? ''),
          'kind' => (string) ($list['kind'] ?? ''),
        ];
      }

      $total_pages = $this->response_total_pages(
        'list_account_lists',
        $body
      );

      if ($total_pages === null) {
        return null;
      }

      $page++;
    } while ($page <= $total_pages);

    if (count($matches) === 0) {
      $this->fail(
        'resolve_account_list',
        sprintf(
          /* translators: %s: Cloudflare list name. */
          __(
            'Cloudflare IP list "%s" was not found in this account.',
            'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
          ),
          $list_name
        )
      );

      return null;
    }

    if (count($matches) > 1) {
      $this->fail(
        'resolve_account_list',
        sprintf(
          /* translators: %s: Cloudflare list name. */
          __(
            'Cloudflare returned more than one list named "%s".',
            'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
          ),
          $list_name
        )
      );

      return null;
    }

    $list_id = $matches[0]['id'];
    $kind = $matches[0]['kind'];

    if (
      !CloudflareIdentifierValidator::validate_list_id(
        $list_id
      )
    ) {
      $this->fail(
        'resolve_account_list',
        __(
          'Cloudflare returned an invalid internal List ID.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        )
      );

      return null;
    }

    $list_id =
      CloudflareIdentifierValidator::normalize_list_id(
        $list_id
      );

    if ($kind !== 'ip') {
      $this->fail(
        'resolve_account_list',
        sprintf(
          /* translators: %s: Cloudflare list name. */
          __(
            'Cloudflare list "%s" is not an IP list.',
            'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
          ),
          $list_name
        )
      );

      return null;
    }

    $this->accountListIdCache[$cache_key] = $list_id;
    $this->clear_last_error();

    return $list_id;
  }

  /**
   * Determine whether an IP address already exists in an account list.
   */
  public function account_list_contains_ip(
    string $account_id,
    string $list_id,
    string $ip
  ): bool {
    if (
      !CloudflareIdentifierValidator::validate_account_id(
        $account_id
      )
      || !CloudflareIdentifierValidator::validate_list_id(
        $list_id
      )
      || !IpValidator::validate_public_ip($ip)
    ) {
      return false;
    }

    $ip = IpValidator::normalize_public_ip($ip) ?? '';

    $items = $this->get_account_list_item_map(
      $account_id,
      $list_id
    );

    return $items !== null && array_key_exists($ip, $items);
  }

  /**
   * Add one IP address to an account list.
   *
   * Existing entries are treated as successful so synchronization remains
   * idempotent.
   */
  public function add_ip_to_account_list(
    string $account_id,
    string $list_id,
    string $ip,
    string $comment = ''
  ): bool {
    if (
      !CloudflareIdentifierValidator::validate_account_id(
        $account_id
      )
      || !CloudflareIdentifierValidator::validate_list_id(
        $list_id
      )
      || !IpValidator::validate_public_ip($ip)
    ) {
      return false;
    }

    $ip = IpValidator::normalize_public_ip($ip) ?? '';

    $items = $this->get_account_list_item_map(
      $account_id,
      $list_id
    );

    if ($items === null) {
      return false;
    }

    if (array_key_exists($ip, $items)) {
      $this->clear_last_error();

      return true;
    }

    $added = $this->create_account_list_item(
      $account_id,
      $list_id,
      $ip,
      $comment
    );

    if ($added) {
      /*
       * The insertion response is not needed for later operations in this
       * request. An empty ID is sufficient to preserve membership and avoid
       * another full-list lookup.
       */
      $cache_key = $this->account_list_cache_key(
        $account_id,
        $list_id
      );

      $this->accountListItemCache[$cache_key][$ip] = '';
    }

    return $added;
  }

  /**
   * Add a synchronization batch after loading the account list once.
   *
   * @param array<int, array{ip?: string, reason?: string}> $entries
   * @return array<int, string> IP addresses that could not be synchronized.
   */
  public function batch_add_ips_to_account_list(
    string $account_id,
    string $list_id,
    array $entries
  ): array {
    $failed = [];
    $first_error = null;
    $this->retryableFailedIps = [];

    if (!CloudflareIdentifierValidator::validate_account_id(
      $account_id
    ) || !CloudflareIdentifierValidator::validate_list_id(
      $list_id
    )) {
      foreach ($entries as $entry) {
        $failed[] = (string) ($entry['ip'] ?? '');
      }

      return $failed;
    }

    $items = $this->get_account_list_item_map(
      $account_id,
      $list_id
    );

    if ($items === null) {
      foreach ($entries as $entry) {
        $ip = (string) ($entry['ip'] ?? '');
        $failed[] = $ip;
        $this->remember_batch_failure($ip);
      }

      return $failed;
    }

    $cache_key = $this->account_list_cache_key(
      $account_id,
      $list_id
    );

    foreach ($entries as $entry) {
      $ip = (string) ($entry['ip'] ?? '');
      $ip = IpValidator::normalize_public_ip($ip) ?? '';

      if ($ip === '') {
        $failed[] = $ip;
        continue;
      }

      if (array_key_exists($ip, $items)) {
        continue;
      }

      $reason = (string) (
        $entry['reason']
        ?? __('Unknown', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare')
      );

      $added = $this->create_account_list_item(
        $account_id,
        $list_id,
        $ip,
        'Wordfence sync: ' . $reason
      );

      if (!$added) {
        $this->remember_batch_failure($ip);
        $first_error ??= $this->lastError;
        $failed[] = $ip;
        continue;
      }

      $items[$ip] = '';
      $this->accountListItemCache[$cache_key] = $items;
    }

    if ($first_error !== null) { $this->lastError = $first_error; }
    return $failed;
  }

  /**
   * Remove an IP from an account list.
   *
   * An already-absent IP is a successful final state.
   */
  public function remove_ip_from_account_list(
    string $account_id,
    string $list_id,
    string $ip
  ): bool {
    if ($this->pendingListOperation) {
      return $this->fail('remove_account_list_item', 'A previous list operation is still unresolved. Retry synchronization later.');
    }
    if (
      !CloudflareIdentifierValidator::validate_account_id(
        $account_id
      )
      || !CloudflareIdentifierValidator::validate_list_id(
        $list_id
      )
      || !IpValidator::validate_public_ip($ip)
    ) {
      return false;
    }

    $ip = IpValidator::normalize_public_ip($ip) ?? '';

    $items = $this->get_account_list_item_map(
      $account_id,
      $list_id
    );

    if ($items === null) {
      return false;
    }

    if (!array_key_exists($ip, $items)) {
      $this->clear_last_error();

      return true;
    }

    $item_id = $items[$ip];

    /*
     * Cloudflare may not expose a newly added list item immediately.
     * Retry the item lookup before attempting deletion. Do not report
     * success merely because the new item has not appeared yet.
     */
    if ($item_id === '') {
      $item_id = '';

      for ($attempt = 0; $attempt < 5; $attempt++) {
        if ($attempt > 0) {
          usleep(500000);
        }

        $this->clear_account_list_cache($account_id, $list_id);

        $items = $this->get_account_list_item_map(
          $account_id,
          $list_id
        );

        if ($items === null) {
          return false;
        }

        if (
          array_key_exists($ip, $items)
          && $items[$ip] !== ''
        ) {
          $item_id = $items[$ip];
          break;
        }
      }

      if ($item_id === '') {
        return $this->fail(
          'remove_account_list_item',
          __(
            'Cloudflare did not expose the newly added list item in time for deletion. Remove it manually or try again.',
            'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
          )
        );
      }
    }

    $url = $this->apiBase
      . "/accounts/{$account_id}/rules/lists/{$list_id}/items";

    $response = $this->request('REQUEST',
      $url,
      $this->get_request_args(
        true,
        [
          'method' => 'DELETE',
          'body' => wp_json_encode(
            [
              'items' => [
                [
                  'id' => $item_id,
                ],
              ],
            ]
          ),
        ]
      )
    );

    $this->clear_account_list_cache($account_id, $list_id);
    $deleted = $this->complete_list_operation($account_id, $response, 'remove_account_list_item');

    if ($deleted) {
      $cache_key = $this->account_list_cache_key(
        $account_id,
        $list_id
      );

      unset($this->accountListItemCache[$cache_key][$ip]);
    }

    return $deleted;
  }

  /**
   * Return the current IP addresses in an account list.
   */
  public function get_current_account_list_ips(
    string $account_id,
    string $list_id,
    bool $refresh = false
  ): ?array {
    if ($refresh) { $this->clear_account_list_cache($account_id, $list_id); }
    $items = $this->get_account_list_item_map(
      $account_id,
      $list_id
    );

    return $items === null ? null : array_keys($items);
  }

  /**
   * Submit one account-list item without performing another list lookup.
   */
  private function create_account_list_item(
    string $account_id,
    string $list_id,
    string $ip,
    string $comment = ''
  ): bool {
    if ($this->pendingListOperation) {
      return $this->fail('add_account_list_item', 'A previous list operation is still unresolved. Retry synchronization later.');
    }
    $url = $this->apiBase
      . "/accounts/{$account_id}/rules/lists/{$list_id}/items";

    $item = ['ip' => $ip];
    $comment = self::normalize_comment($comment);

    if ($comment !== '') {
      $item['comment'] = $comment;
    }

    $response = $this->request('POST',
      $url,
      $this->get_request_args(
        true,
        [
          'body' => wp_json_encode([$item]),
        ]
      )
    );

    $completed = $this->complete_list_operation($account_id, $response, 'add_account_list_item');
    if (!$completed) { $this->clear_account_list_cache($account_id, $list_id); }
    return $completed;
  }

  /**
   * Load and cache all account-list items.
   *
   * Null indicates that Cloudflare could not provide a complete list.
   *
   * @return array<string, string>|null
   */
  private function get_account_list_item_map(
    string $account_id,
    string $list_id
  ): ?array {
    if (!CloudflareIdentifierValidator::validate_account_id($account_id)
      || !CloudflareIdentifierValidator::validate_list_id($list_id)) {
      return null;
    }
    $key = $this->account_list_cache_key($account_id, $list_id);
    if (array_key_exists($key, $this->accountListItemCache)) {
      if (!$this->coordinate_destination('account_list', $account_id)) { return null; }
      $lease = $this->destinationLeases['account:' . strtolower($account_id)] ?? null;
      if ($lease === null || $lease->pending() === '') {
        return $this->accountListItemCache[$key];
      }
      unset($this->accountListItemCache[$key]);
    }
    $items = [];
    $ids = [];
    $cursor = null;
    $seen_cursors = [];
    $pages = null;
    for ($page = 1; $page <= self::MAX_INVENTORY_PAGES; $page++) {
      $url = $this->apiBase . "/accounts/{$account_id}/rules/lists/{$list_id}/items?per_page=50";
      $url .= $cursor !== null ? '&cursor=' . rawurlencode($cursor) : ($page > 1 ? '&page=' . $page : '');
      $response = $this->request('GET', $url, $this->get_request_args());
      $body = null;
      if (!$this->response_succeeded('read_account_list', $response, [200], $body)) {
        return null;
      }
      $result = $this->response_result_array('read_account_list', $body);
      if ($result === null) { return null; }
      foreach ($result as $item) {
        if (!is_array($item) || !is_string($item['ip'] ?? null)
          || !is_string($item['id'] ?? null) || $item['id'] === ''
          || isset($ids[$item['id']])) {
          $this->fail('read_account_list', 'Cloudflare returned an incomplete or conflicting list item.');
          return null;
        }
        $ids[$item['id']] = true;
        $ip = IpValidator::normalize_public_ip($item['ip']);
        if ($ip === null) {
          // Lists may legitimately contain private addresses and CIDR ranges.
          $parts = explode('/', $item['ip']);
          $packed = @inet_pton($parts[0]);
          if ($packed !== false && (count($parts) === 1 || (count($parts) === 2
            && ctype_digit($parts[1]) && (int) $parts[1] <= strlen($packed) * 8))) {
            continue;
          }
          $this->fail('read_account_list', 'Cloudflare returned an invalid IP list item.');
          return null;
        }
        if (isset($items[$ip])) {
          $this->fail('read_account_list', 'Cloudflare returned conflicting canonical IP entries.');
          return null;
        }
        $items[$ip] = $item['id'];
      }
      $info = $body['result_info'] ?? [];
      if (is_array($info) && array_key_exists('cursors', $info)) {
        if (!is_array($info['cursors'])) {
          $this->fail('read_account_list', 'Cloudflare returned invalid cursor metadata.');
          return null;
        }
        $next = $info['cursors']['after'] ?? null;
        if ($next === null || $next === '') {
          $this->accountListItemCache[$key] = $items;
          return $items;
        }
        if (!is_string($next) || isset($seen_cursors[$next])) {
          $this->fail('read_account_list', 'Cloudflare returned an invalid or repeated cursor.');
          return null;
        }
        $seen_cursors[$next] = true;
        $cursor = $next;
        continue;
      }
      if ($cursor !== null) {
        $this->fail('read_account_list', 'Cloudflare lost cursor pagination metadata.');
        return null;
      }
      $total = $this->inventory_pages('read_account_list', $body, $page, count($result));
      if ($total === null || ($pages !== null && $pages !== $total)) {
        $this->fail('read_account_list', 'Cloudflare inventory pagination is incomplete or changed during reading.');
        return null;
      }
      $pages = $total;
      if ($page >= $pages) {
        $this->accountListItemCache[$key] = $items;
        return $items;
      }
    }
    $this->fail('read_account_list', 'Cloudflare inventory exceeded the safe pagination limit.');
    return null;
  }

  private function account_list_cache_key(
    string $account_id,
    string $list_id
  ): string {
    return $account_id . ':' . $list_id;
  }

  private function clear_account_list_cache(
    string $account_id,
    string $list_id
  ): void {
    unset(
      $this->accountListItemCache[
        $this->account_list_cache_key($account_id, $list_id)
      ]
    );
  }

  public function create_block(
    string $ip,
    string $notes = ''
  ): bool {
    if (!IpValidator::validate_public_ip($ip)) {
      return $this->fail(
        'create_zone_access_rule',
        __('Invalid or non-public IP address.', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare')
      );
    }

    if ($this->zone === '') {
      return $this->fail(
        'create_zone_access_rule',
        __('Cloudflare Zone ID is required.', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare')
      );
    }

    $url = $this->apiBase
      . "/zones/{$this->zone}/firewall/access_rules/rules";

    $notes = self::normalize_comment($notes);

    if ($notes === '') {
      $notes = __(
        'Wordfence Sync Block',
        'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
      );
    }

    $data = [
      'mode' => 'block',
      'configuration' => [
        'target' => 'ip',
        'value' => $ip,
      ],
      'notes' => $notes,
    ];

    $response = $this->request('POST',
      $url,
      $this->get_request_args(
        true,
        [
          'body' => wp_json_encode($data),
        ]
      )
    );

    $body = null;
    if ($this->response_succeeded('create_zone_access_rule', $response, null, $body)) {
      if (!is_array($body['result'] ?? null) || !$this->valid_rule_id($body['result']['id'] ?? null)) {
        return $this->fail('create_zone_access_rule', 'Cloudflare returned an incomplete rule creation result.');
      }
      return $this->confirm_zone_mutation();
    }
    if (!is_wp_error($response) && in_array(wp_remote_retrieve_response_code($response), [400, 409], true)) {
      $original_error = $this->lastError;
      $inventory = $this->get_current_blocked_ips($notes);
      $canonical = IpValidator::normalize_public_ip($ip);
      if ($inventory !== null && in_array($canonical, $inventory, true)) {
        $this->clear_last_error();
        return true;
      }
      if ($inventory !== null) { $this->lastError = $original_error; }
    }
    return false;
  }

  public function delete_block(string $ip): bool {
    $ip = IpValidator::normalize_public_ip($ip) ?? '';
    if ($ip === '' || $this->zone === '') {
      return $this->fail('delete_zone_access_rule', 'A valid public IP and Cloudflare Zone ID are required.');
    }
    $ids = [];
    $pages = null;
    // Read and validate every page before deleting anything.
    for ($page = 1; $page <= self::MAX_INVENTORY_PAGES; $page++) {
      $url = $this->apiBase . "/zones/{$this->zone}/firewall/access_rules/rules?mode=block&configuration.target=ip&configuration.value="
        . rawurlencode($ip) . "&page={$page}&per_page=50";
      $response = $this->request('GET', $url, $this->get_request_args());
      $body = null;
      if (!$this->response_succeeded('find_zone_access_rule', $response, [200], $body)) { return false; }
      $result = $this->response_result_array('find_zone_access_rule', $body);
      if ($result === null) { return false; }
      foreach ($result as $rule) {
        if (!is_array($rule) || !is_array($rule['configuration'] ?? null)
          || !is_string($rule['mode'] ?? null)) {
          return $this->fail('find_zone_access_rule', 'Cloudflare returned an incomplete access rule.');
        }
        $configuration = $rule['configuration'];
        if (($configuration['target'] ?? '') !== 'ip' || $rule['mode'] !== 'block') { continue; }
        if (!is_string($configuration['value'] ?? null)) {
          return $this->fail('find_zone_access_rule', 'Cloudflare returned a rule without its IP address.');
        }
        if (IpValidator::normalize_public_ip($configuration['value']) !== $ip) { continue; }
        $id = $rule['id'] ?? '';
        if (!is_string($id) || !$this->valid_rule_id($id)) {
          return $this->fail('find_zone_access_rule', 'Cloudflare returned a matching rule without a valid ID.');
        }
        if (isset($ids[$id])) {
          return $this->fail('find_zone_access_rule', 'Cloudflare repeated an access-rule ID while reading inventory.');
        }
        $ids[$id] = true;
      }
      $total = $this->inventory_pages('find_zone_access_rule', $body, $page, count($result));
      if ($total === null || ($pages !== null && $total !== $pages)) {
        return $this->fail('find_zone_access_rule', 'Cloudflare rule pagination is incomplete or changed during reading.');
      }
      $pages = $total;
      if ($page >= $pages) { break; }
    }
    foreach (array_keys($ids) as $id) {
      $url = $this->apiBase . "/zones/{$this->zone}/firewall/access_rules/rules/" . rawurlencode($id);
      $response = $this->request('REQUEST', $url, $this->get_request_args(true, ['method' => 'DELETE']));
      $body = null;
      if (!$this->response_succeeded('delete_zone_access_rule', $response, null, $body)) { return false; }
      if (($body['result']['id'] ?? null) !== $id) {
        return $this->fail('delete_zone_access_rule', 'Cloudflare returned an incomplete rule deletion result.');
      }
      if (!$this->confirm_zone_mutation()) { return false; }
    }
    $this->clear_last_error();
    return true;
  }

  /** IPs whose batch failure is transient rather than a permanent rejection. */
  public function get_retryable_failed_ips(): array {
    return array_keys($this->retryableFailedIps);
  }

  private function remember_batch_failure(string $ip): void {
    $code = $this->lastError['http_code'] ?? null;
    if ($code === null || $code === 429 || $code >= 500 || $this->pendingListOperation) {
      $this->retryableFailedIps[$ip] = true;
    }
  }

  public function clear_last_error(): void {
    $this->lastError = null;
  }

  public function get_last_error_message(): string {
    if ($this->lastError === null) {
      return '';
    }

    $operation = str_replace(
      '_',
      ' ',
      $this->lastError['operation']
    );

    $message = sprintf(
      /* translators: 1: Cloudflare operation, 2: error message */
      __('Cloudflare %1$s failed: %2$s', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'),
      $operation,
      $this->lastError['message']
    );

    if ($this->lastError['http_code'] !== null) {
      $message .= sprintf(
        /* translators: %d: HTTP response code */
        __(' (HTTP %d)', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'),
        $this->lastError['http_code']
      );
    }

    return $message;
  }

  /**
   * Store a local validation or Cloudflare error.
   */
  private function fail(
    string $operation,
    string $message,
    ?int $http_code = null
  ): bool {
    $this->lastError = [
      'operation' => $operation,
      'message' => $message,
      'http_code' => $http_code,
    ];

    return false;
  }

  /**
   * Validate a WordPress HTTP API response and retain useful diagnostics.
   *
   * @param mixed $response
   * @param array<int, int>|null $expected_codes
   */
  /**
   * Decode and validate a Cloudflare JSON response body.
   *
   * @param mixed $response WordPress HTTP API response.
   * @return array<string, mixed>|null
   */
  private function decode_json_response(
    string $operation,
    $response
  ): ?array {
    if (is_wp_error($response)) {
      $this->fail(
        $operation,
        $response->get_error_message()
      );

      return null;
    }

    $raw_body = wp_remote_retrieve_body($response);

    if (!is_string($raw_body) || trim($raw_body) === '') {
      $this->fail(
        $operation,
        __(
          'Cloudflare returned an empty response body.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        ),
        self::response_http_code($response)
      );

      return null;
    }

    if (strlen($raw_body) > self::MAX_RESPONSE_BYTES) {
      $this->fail(
        $operation,
        __(
          'Cloudflare returned an unexpectedly large response.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        ),
        self::response_http_code($response)
      );

      return null;
    }

    try {
      $body = json_decode(
        $raw_body,
        true,
        512,
        JSON_THROW_ON_ERROR
      );
    } catch (\JsonException) {
      $this->fail(
        $operation,
        __(
          'Cloudflare returned invalid JSON.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        ),
        self::response_http_code($response)
      );

      return null;
    }

    if (!is_array($body)) {
      $this->fail(
        $operation,
        __(
          'Cloudflare returned a JSON response with an invalid top-level structure.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        ),
        self::response_http_code($response)
      );

      return null;
    }

    // Associative JSON decoding otherwise collapses {} and [] into the same
    // PHP array, allowing a malformed object to masquerade as empty inventory.
    $structure = json_decode($raw_body);
    $body[self::RESULT_LIST_FLAG] = is_object($structure)
      && is_array($structure->result ?? null);

    if (
      !array_key_exists('success', $body)
      || !is_bool($body['success'])
    ) {
      $this->fail(
        $operation,
        __(
          'Cloudflare returned a response without a valid success indicator.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        ),
        self::response_http_code($response)
      );

      return null;
    }

    foreach (['errors', 'messages'] as $field) {
      if (
        array_key_exists($field, $body)
        && !is_array($body[$field])
      ) {
        $this->fail(
          $operation,
          sprintf(
            /* translators: %s: response field name. */
            __(
              'Cloudflare returned an invalid %s field.',
              'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
            ),
            $field
          ),
          self::response_http_code($response)
        );

        return null;
      }
    }

    if (
      array_key_exists('success', $body)
      && $body['success'] === false
    ) {
      $this->fail(
        $operation,
        $this->cloudflare_error_message($body),
        self::response_http_code($response)
      );

      return null;
    }

    return $body;
  }

  /**
   * Return the result array from a validated Cloudflare response.
   *
   * @param array<string, mixed> $body
   * @return array<int|string, mixed>|null
   */
  private function response_result_array(
    string $operation,
    array $body
  ): ?array {
    if (
      !array_key_exists('result', $body)
      || !is_array($body['result'])
      || !array_is_list($body['result'])
      || !($body[self::RESULT_LIST_FLAG] ?? false)
    ) {
      $this->fail(
        $operation,
        __(
          'Cloudflare returned an invalid result structure.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        )
      );

      return null;
    }

    return $body['result'];
  }

  /**
   * Determine the number of pages in a paginated response.
   *
   * @param array<string, mixed> $body
   */
  private function response_total_pages(
    string $operation,
    array $body
  ): ?int {
    if (!array_key_exists('result_info', $body)) {
      return 1;
    }

    if (!is_array($body['result_info'])) {
      $this->fail(
        $operation,
        __(
          'Cloudflare returned invalid pagination information.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        )
      );

      return null;
    }

    $total_pages = $body['result_info']['total_pages'] ?? 1;

    if (
      !is_int($total_pages)
      && !(is_string($total_pages) && ctype_digit($total_pages))
    ) {
      $this->fail(
        $operation,
        __(
          'Cloudflare returned an invalid page count.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        )
      );

      return null;
    }

    $total_pages = (int) $total_pages;

    if ($total_pages < 1 || $total_pages > self::MAX_INVENTORY_PAGES) {
      $this->fail(
        $operation,
        __(
          'Cloudflare returned an invalid page count.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        )
      );

      return null;
    }

    return $total_pages;
  }

  /**
   * @param array<string, mixed> $body
   */
  private function cloudflare_error_message(array $body): string {
    $errors = $body['errors'] ?? [];

    if (
      is_array($errors)
      && isset($errors[0])
      && is_array($errors[0])
    ) {
      $code = isset($errors[0]['code'])
        ? sanitize_text_field((string) $errors[0]['code'])
        : '';

      $message = isset($errors[0]['message'])
        ? sanitize_text_field((string) $errors[0]['message'])
        : '';

      if ($code !== '' && $message !== '') {
        return sprintf(
          'Cloudflare error %s: %s',
          $code,
          $message
        );
      }

      if ($message !== '') {
        return $message;
      }
    }

    if (
      isset($body['message'])
      && is_scalar($body['message'])
    ) {
      $message = sanitize_text_field(
        (string) $body['message']
      );

      if ($message !== '') {
        return $message;
      }
    }

    return __(
      'Cloudflare returned an unsuccessful response.',
      'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
    );
  }

  /**
   * @param mixed $response WordPress HTTP API response.
   */
  private static function response_http_code(
    $response
  ): ?int {
    if (is_wp_error($response)) {
      return null;
    }

    $code = wp_remote_retrieve_response_code($response);

    return $code > 0 ? $code : null;
  }

  private function response_succeeded(
    string $operation,
    $response,
    ?array $expected_codes = null,
    ?array &$decoded_body = null
  ): bool {
    $decoded_body = null;
    if (is_wp_error($response)) {
      return $this->fail(
        $operation,
        $response->get_error_message()
      );
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $successful = $expected_codes !== null
      ? in_array($http_code, $expected_codes, true)
      : ($http_code >= 200 && $http_code < 300);

    if ($successful) {
      $decoded_body = $this->decode_json_response(
        $operation,
        $response
      );

      if ($decoded_body === null) {
        return false;
      }

      $this->clear_last_error();

      return true;
    }

    $body = $this->decode_json_response(
      $operation,
      $response
    );

    $message = $body !== null
      ? $this->cloudflare_error_message($body)
      : $this->get_last_error_message();

    if ($message === '') {
      $response_message = wp_remote_retrieve_response_message(
        $response
      );

      $message = $response_message !== ''
        ? $response_message
        : __(
          'Cloudflare returned an unsuccessful response.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        );
    }

    return $this->fail(
      $operation,
      sanitize_text_field($message),
      $http_code > 0 ? $http_code : null
    );
  }

  private function request(string $method, string $url, array $args) {
    $lease = null;
    $account = '';
    $list_write = false;
    $sync_write = false;
    if (class_exists('WPCF\\FirewallSync\\Services\\DestinationLease')
      && preg_match('~/((?:accounts|zones))/([a-fA-F0-9]{32})/~', $url, $match) === 1) {
      $account = $match[1] === 'accounts' ? $match[2] : '';
      if (!$this->coordinate_destination($account !== '' ? 'account_list' : 'zone_access_rules', $account)) {
        return new \WP_Error('destination_busy', 'Cloudflare destination coordination is unavailable. Retry later.');
      }
      $scope = ($account !== '' ? 'account:' . strtolower($account) : 'zone:' . strtolower($this->zone));
      $lease = $this->destinationLeases[$scope];
      try {
        $pending = $lease->pending();
        if (!$this->resumingListOperation && $pending !== '' && !str_contains($url, '/bulk_operations/')) {
          if ($pending === 'unknown') {
            return new \WP_Error('destination_uncertain', 'An earlier Cloudflare mutation has unknown acceptance. Verify provider completion before repairing destination coordination.');
          }
          $this->resumingListOperation = true;
          try {
            $accepted = ['response' => ['code' => 200], 'body' => wp_json_encode(['success' => true, 'result' => ['operation_id' => $pending]])];
            if (!$this->complete_list_operation($account, $accepted, 'resume_account_list_operation')) {
              return new \WP_Error('destination_pending', 'An earlier Cloudflare list operation is unresolved. Retry later.');
            }
          } finally { $this->resumingListOperation = false; }
          $this->accountListItemCache = [];
        }
        $list_write = $account !== '' && $method !== 'GET' && preg_match('~/rules/lists/[a-fA-F0-9]{32}/items(?:\\?|$)~', $url) === 1;
        $sync_write = $method !== 'GET' && !$list_write;
        $lease->assert_live();
      } catch (\RuntimeException) {
        return new \WP_Error('destination_lost', 'Cloudflare destination lease was lost. Retry later.');
      }
    }
    $response = $this->transport_request($method, $url, $args, ($list_write || $sync_write) ? $lease : null);
    if ($lease !== null) {
      try {
        if (($list_write || $sync_write) && !is_wp_error($response)) {
          $raw_body = wp_remote_retrieve_body($response);
          $body = strlen($raw_body) <= self::MAX_RESPONSE_BYTES ? json_decode($raw_body, true) : null;
          $id = $body['result']['operation_id'] ?? null;
          $valid_envelope = is_array($body)
            && is_bool($body['success'] ?? null)
            && (!array_key_exists('errors', $body) || is_array($body['errors']))
            && (!array_key_exists('messages', $body) || is_array($body['messages']));
          // A synchronous response is confirmed only by its caller after full
          // envelope and operation-specific identity validation.
          if ($list_write && $valid_envelope && $body['success'] === true
            && is_string($id) && preg_match('/\\A[a-zA-Z0-9_-]{1,128}\\z/', $id) === 1) {
            $lease->set_pending($id);
          } elseif ($valid_envelope && $body['success'] === false
            && in_array(wp_remote_retrieve_response_code($response), [400, 401, 403, 404, 409, 429], true)) {
            $lease->set_pending(''); // Explicit rejection, not uncertain acceptance.
          }
        }
        $lease->assert_live();
      } catch (\RuntimeException) {
        return new \WP_Error('destination_lost', 'Cloudflare response could not be recorded under a live destination lease. Retry later.');
      }
    }
    return $response;
  }

  private function transport_request(string $method, string $url, array $args, $pending_lease = null) {
    if (\WPCF\FirewallSync\Services\AtomicFence::active()) {
      throw new \RuntimeException('HTTP cannot run inside a local fencing transaction.');
    }
    if (class_exists('WPCF\\FirewallSync\\Services\\SyncScheduler')) {
      \WPCF\FirewallSync\Services\SyncScheduler::assert_active_lease();
    }
    $key = 'firewall_sync_cf_backoff_' . hash('sha256', $this->token);
    $shared = function_exists('get_transient') ? (int) get_transient($key) : 0;
    $this->retryUntil = max($this->retryUntil, $shared);
    if ($this->retryUntil > time()) {
      return new \WP_Error('cloudflare_backoff', sprintf('Cloudflare retry cooldown active; retry after %d seconds.', $this->retryUntil - time()));
    }
    if ($pending_lease !== null) { $pending_lease->set_pending('unknown'); }
    if (class_exists('WPCF\\FirewallSync\\Services\\DestinationLease')) {
      \WPCF\FirewallSync\Services\DestinationLease::assert_held_leases();
    }
    if (class_exists('WPCF\\FirewallSync\\Services\\SyncScheduler')) {
      \WPCF\FirewallSync\Services\SyncScheduler::assert_active_lease();
    }
    $response = match ($method) {
      'GET' => wp_remote_get($url, $args),
      'POST' => wp_remote_post($url, $args),
      default => wp_remote_request($url, $args),
    };
    if (class_exists('WPCF\\FirewallSync\\Services\\SyncScheduler')) {
      \WPCF\FirewallSync\Services\SyncScheduler::assert_active_lease();
    }
    $http_code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
    $header = !is_wp_error($response) && function_exists('wp_remote_retrieve_header')
      ? wp_remote_retrieve_header($response, 'retry-after') : '';
    if ($http_code === 429 || ($http_code === 503 && is_string($header) && $header !== '')) {
      $delay = 60;
      if (is_string($header) && ctype_digit($header)) {
        $delay = max(1, (int) min((float) $header, 31536000));
      } elseif (is_string($header) && ($date = strtotime($header)) !== false) {
        $delay = max(1, min($date - time(), 31536000));
      }
      $this->retryUntil = time() + $delay;
      if (function_exists('set_transient')) { set_transient($key, $this->retryUntil, $delay); }
    }
    return $response;
  }

  private function complete_list_operation(string $account_id, $response, string $operation): bool {
    $body = null;
    if (!$this->response_succeeded($operation, $response, null, $body)) {
      if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 409) {
        $this->pendingListOperation = true;
      }
      return false;
    }
    $this->pendingListOperation = true;
    $id = $body['result']['operation_id'] ?? null;
    if (!is_string($id) || preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/', $id) !== 1) {
      return $this->fail($operation, 'Cloudflare returned no valid asynchronous operation ID.');
    }
    $this->pendingListOperation = true;
    for ($attempt = 0; $attempt < 3; $attempt++) {
      if ($attempt > 0) { usleep(250000); }
      $url = $this->apiBase . "/accounts/{$account_id}/rules/lists/bulk_operations/" . rawurlencode($id);
      $status_response = $this->request('GET', $url, $this->get_request_args());
      $status_body = null;
      if (!$this->response_succeeded($operation, $status_response, [200], $status_body)) { return false; }
      $result = $status_body['result'] ?? null;
      if (!is_array($result) || ($result['id'] ?? null) !== $id) {
        return $this->fail($operation, 'Cloudflare returned an invalid asynchronous operation status.');
      }
      if (($result['status'] ?? '') === 'completed') {
        $this->clear_durable_pending($account_id);
        $this->pendingListOperation = false;
        return true;
      }
      if (($result['status'] ?? '') === 'failed') {
        $this->clear_durable_pending($account_id);
        $this->pendingListOperation = false;
        return $this->fail($operation, 'Cloudflare asynchronous list operation failed. Retry synchronization later.', 200);
      }
      if (!in_array($result['status'] ?? null, ['pending', 'running'], true)) {
        return $this->fail($operation, 'Cloudflare returned an unknown asynchronous operation status.');
      }
    }
    return $this->fail($operation, 'Cloudflare list operation is still pending. No local success was recorded; retry synchronization later.');
  }

  private function confirm_zone_mutation(): bool {
    $lease = $this->destinationLeases['zone:' . strtolower($this->zone)] ?? null;
    if ($lease === null) { return true; }
    try { $lease->set_pending(''); return true; }
    catch (\RuntimeException) {
      return $this->fail('confirm_zone_mutation', 'Cloudflare completion could not be confirmed under a live destination lease.');
    }
  }

  private function clear_durable_pending(string $account_id): void {
    $lease = $this->destinationLeases['account:' . strtolower($account_id)] ?? null;
    if ($lease !== null) { $lease->set_pending(''); }
  }

  private function valid_rule_id($value): bool {
    return is_string($value) && preg_match('/\A[a-fA-F0-9]{32}\z/', $value) === 1;
  }

  private function inventory_pages(string $operation, array $body, int $page, int $count): ?int {
    $info = $body['result_info'] ?? [];
    if (!is_array($info)) {
      $this->fail($operation, 'Cloudflare returned invalid pagination metadata.');
      return null;
    }
    foreach (['page', 'per_page', 'count', 'total_count', 'total_pages'] as $field) {
      if (!array_key_exists($field, $info)) { continue; }
      $value = $info[$field];
      if ((!is_int($value) && !(is_string($value) && ctype_digit($value) && strlen($value) <= 10))
        || (int) $value < 0) {
        $this->fail($operation, 'Cloudflare returned an invalid pagination count.');
        return null;
      }
    }
    if ((isset($info['page']) && (int) $info['page'] !== $page)
      || (isset($info['count']) && (int) $info['count'] !== $count)
      || (isset($info['per_page']) && ((int) $info['per_page'] < 1 || $count > (int) $info['per_page']))
      || (isset($info['total_count']) && $count > (int) $info['total_count'])
      || (isset($info['total_pages']) && $page > (int) $info['total_pages'])) {
      $this->fail($operation, 'Cloudflare pagination does not match the returned page.');
      return null;
    }
    if (isset($info['total_count'], $info['per_page'], $info['total_pages'])
      && (int) $info['total_pages'] !== max(1, (int) ceil((int) $info['total_count'] / (int) $info['per_page']))) {
      $this->fail($operation, 'Cloudflare pagination totals are inconsistent.');
      return null;
    }
    if (!isset($info['total_pages']) && isset($info['total_count'], $info['per_page'])) {
      $body['result_info']['total_pages'] = max(1, (int) ceil((int) $info['total_count'] / (int) $info['per_page']));
    }
    if (!isset($body['result_info']['total_pages'])
      && ($count >= 50 || (isset($info['total_count']) && (int) $info['total_count'] > $count))) {
      $this->fail($operation, 'Cloudflare inventory lacks proof of complete pagination.');
      return null;
    }
    return $this->response_total_pages($operation, $body);
  }

  private function get_headers(bool $with_content_type = false): array {
    $headers = [
      'Authorization' => 'Bearer ' . $this->token,
    ];

    if ($with_content_type) {
      $headers['Content-Type'] = 'application/json';
    }

    return $headers;
  }

  /**
   * Build the common WordPress HTTP API arguments for Cloudflare.
   *
   * @param array<string, mixed> $overrides
   * @return array<string, mixed>
   */
  private function get_request_args(
    bool $with_content_type = false,
    array $overrides = []
  ): array {
    return array_merge(
      [
        'headers' => $this->get_headers($with_content_type),
        'timeout' => self::HTTP_TIMEOUT_SECONDS,
        'redirection' => self::HTTP_REDIRECTION_LIMIT,
        'reject_unsafe_urls' => true,
        'sslverify' => true,
      ],
      $overrides
    );
  }

  private static function normalize_comment(string $comment): string {
    $comment = preg_replace(
      '/[\x00-\x1F\x7F]+/u',
      ' ',
      $comment
    );

    if (!is_string($comment)) {
      return '';
    }

    $comment = preg_replace('/\s+/u', ' ', $comment);

    if (!is_string($comment)) {
      return '';
    }

    $comment = trim($comment);

    if (function_exists('mb_substr')) {
      return mb_substr(
        $comment,
        0,
        self::MAX_COMMENT_LENGTH,
        'UTF-8'
      );
    }

    return substr($comment, 0, self::MAX_COMMENT_LENGTH);
  }

  /** Optional exact notes restrict only duplicate-write recovery, not normal inventories. */
  public function get_current_blocked_ips(?string $required_notes = null): ?array {
    if (!CloudflareIdentifierValidator::validate_zone_id($this->zone)) {
      $this->fail('read_zone_access_rules', 'Cloudflare Zone ID is invalid.');
      return null;
    }
    $ips = [];
    $seen_ids = [];
    $pages = null;
    for ($page = 1; $page <= self::MAX_INVENTORY_PAGES; $page++) {
      $url = $this->apiBase . "/zones/{$this->zone}/firewall/access_rules/rules?mode=block&page={$page}&per_page=50";
      $response = $this->request('GET', $url, $this->get_request_args());
      $body = null;
      if (!$this->response_succeeded('read_zone_access_rules', $response, [200], $body)) { return null; }
      $result = $this->response_result_array('read_zone_access_rules', $body);
      if ($result === null) { return null; }
      foreach ($result as $rule) {
        if (!is_array($rule) || !is_array($rule['configuration'] ?? null) || !is_string($rule['mode'] ?? null)) {
          $this->fail('read_zone_access_rules', 'Cloudflare returned an incomplete access rule.');
          return null;
        }
        $config = $rule['configuration'];
        if (($config['target'] ?? '') !== 'ip' || $rule['mode'] !== 'block') { continue; }
        if (!is_string($config['value'] ?? null)) {
          $this->fail('read_zone_access_rules', 'Cloudflare returned a rule without its IP address.');
          return null;
        }
        $id = $rule['id'] ?? null;
        if (!$this->valid_rule_id($id) || isset($seen_ids[$id])) {
          $this->fail('read_zone_access_rules', 'Cloudflare returned a missing or repeated access-rule ID.');
          return null;
        }
        $seen_ids[$id] = true;
        if ($required_notes !== null && ($rule['notes'] ?? null) !== $required_notes) { continue; }
        $ip = IpValidator::normalize_public_ip($config['value']);
        if ($ip !== null) { $ips[$ip] = true; }
        elseif (@inet_pton($config['value']) === false) {
          $this->fail('read_zone_access_rules', 'Cloudflare returned an invalid IP rule.');
          return null;
        }
      }
      $total = $this->inventory_pages('read_zone_access_rules', $body, $page, count($result));
      if ($total === null || ($pages !== null && $total !== $pages)) {
        $this->fail('read_zone_access_rules', 'Cloudflare rule pagination is incomplete or changed during reading.');
        return null;
      }
      $pages = $total;
      if ($page >= $pages) {
        $this->clear_last_error();
        return array_keys($ips);
      }
    }
    $this->fail('read_zone_access_rules', 'Cloudflare inventory exceeded the safe pagination limit.');
    return null;
  }

  public function batch_block(array $ips): array {
    $failed = [];
    $first_error = null;
    $this->retryableFailedIps = [];

    foreach ($ips as $entry) {
      $ip = $entry['ip'] ?? '';

      if (!IpValidator::validate_public_ip($ip)) {
        $failed[] = $ip;
        continue;
      }

      $notes = __('Wordfence Sync', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare') . ': ' . ($entry['reason'] ?? __('Unknown', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'));

      if (!$this->create_block($ip, $notes)) {
        $failed[] = $ip;
        $this->remember_batch_failure($ip);
        $first_error ??= $this->lastError;
      }
    }

    if ($first_error !== null) { $this->lastError = $first_error; }
    return $failed;
  }
}
