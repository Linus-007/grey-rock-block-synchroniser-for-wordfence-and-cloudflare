<?php

namespace WPCF\FirewallSync\Services;

use WPCF\FirewallSync\Cloudflare\Client;
use WPCF\FirewallSync\Services\BlockLogger;

final class Reconciler {
  public static function run(Client $client): array {
    $cf_ips = $client->get_current_blocked_ips();
    $log_ips = BlockLogger::get_all_ips();
    $cf_set = array_flip($cf_ips);
    $log_set = array_flip($log_ips);
    $missing_in_cf = array_diff_key($log_set, $cf_set);
    $orphaned_in_cf = array_diff_key($cf_set, $log_set);

    return [
      'missing_in_cf' => array_keys($missing_in_cf),
      'orphaned_in_cf' => array_keys($orphaned_in_cf),
    ];
  }
}