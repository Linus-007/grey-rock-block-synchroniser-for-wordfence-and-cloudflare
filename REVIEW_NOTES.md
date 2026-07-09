# wordfence-cloudflare-firewall-sync Review Notes

## Reviewed repository

- Upstream: https://github.com/notmike101/wordfence-cloudflare-firewall-sync
- Branch reviewed: main
- Commit reviewed: e3d2e56
- Review status: Fork candidate, not install-ready.

## Summary

This repository provides a useful WordPress plugin shell for syncing Wordfence IP blocks to Cloudflare, but it is not ready for production use as-is.

The current implementation uses Cloudflare zone-level Access Rules, not Cloudflare account-level custom IP lists. The admin UI is per-site, not network-admin. Several PHP bugs were found in the Cloudflare client, scheduler, logger and admin action code.

## Desired direction

Support two Cloudflare enforcement modes:

1. Account-level Cloudflare custom IP list.
2. Legacy per-zone Cloudflare Access Rules.

For the multisite use case, account-level list mode should be the preferred mode.

## Confirmed gaps

- Cloudflare account-level list API is not implemented.
- Network-admin settings are not implemented.
- Existing settings use get_option/update_option instead of get_site_option/update_site_option.
- Existing permissions use manage_options instead of manage_network_options.
- API token is rendered as a plain text field.
- Current plugin syncs Wordfence's current block list, not a two-events-within-24-hours policy.
- Expiration logic exists conceptually, but implementation needs repair.

## Confirmed code issues

- Cloudflare client delete URL appears to be missing a slash after /zones.
- Cloudflare client get_current_blocked_ips() loops over undefined $rules.
- BlockLogger::TABLE is private but referenced outside the class.
- BlockLogger::log() uses $wpdb-prefix instead of $wpdb->prefix.
- BlockLogger::has_synced() declares global $wbdb instead of global $wpdb.
- SyncScheduler::register() uses $options before defining it.
- SyncScheduler success/failure logging appears reversed.
- Plugin.php and Fields.php call SyncScheduler::cleanup_expired(), but SyncScheduler exposes run_cleanup().
- Fields.php references $sanitized without defining it.
- Fields.php calls create_test_block(), but the Cloudflare client exposes create_block().

## Initial recommendation

Fork this repository only as a refactor base. Do not install as-is on production.
