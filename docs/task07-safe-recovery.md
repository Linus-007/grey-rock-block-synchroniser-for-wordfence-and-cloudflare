# Pending-operation recovery and destination coordination

This is an operator runbook, not a reset tool. Task 7 adds no recovery endpoint,
reset button, automatic clearing timer, or destructive CLI command. Do not run
publication, mutation, or database repair commands merely to diagnose a lock.
The procedure was exercised with mocked provider responses; a real
WordPress/database/provider recovery has not been certified.

## Establish the incident boundary

1. Obtain incident authority from the installation operator and every affected
   site/network owner. A site administrator must not reset installation-wide
   coordination that can protect other sites or networks. Ordinary site actions
   require `manage_options`; network actions require `manage_network_options`
   and their existing nonces. Those checks do not by themselves authorize a
   direct database repair or work in another installation.
2. Inventory all writers: WP-Cron, external cron/CLI, manual administration,
   cleanup, reconciliation, other plugins/scripts, and independent WordPress
   installations using the account or zone. Disable their synchronization
   triggers using approved controls and drain or terminate existing requests
   and workers. Stopping a scheduler or deactivating the plugin does not prove
   an existing process stopped. Waiting 900 seconds is not proof either.
3. For account lists, quiesce writers across the **entire account**, not just one
   list. Cloudflare permits one pending bulk operation per account. If another
   installation or external writer cannot be identified and quiesced, stop.
4. Take protected backups of coordination and local ownership/log/watermark
   state. Do not uninstall, reinstall, delete transients/options, or flush caches
   as a recovery shortcut. Uninstall removes ownership evidence while unresolved
   destination coordination may survive; reinstall does not resolve it.

## Verify identity and obtain a read-only snapshot

5. In the correctly authorized site/network context, identify the effective mode,
   normalized account or zone ID, and resolved list ID/name/kind. Verify the
   destination with authorized read-only provider access. Resolve names within
   the intended account and validate the returned list's identity and IP kind;
   do not substitute a same-named list in another account. Do not disclose tokens
   in URLs, shell arguments, tickets, screenshots, lock names, or reports.
6. On the authoritative primary database, verify the installation's actual table
   prefix and primary options table. The key is
   `firewall_sync_destination_` plus SHA-256 of `account:` + lowercase account ID,
   or `zone:` + lowercase zone ID. Account keys deliberately contain no list ID
   or credential. Never select a row merely by prefix and clear every match.
7. Read the exact stored row without the options object cache. Capture its
   serialized-value digest, owner state, database time, lease timestamp, pending
   category, and protected operation ID if present. A changed row or active
   worker means stop and establish quiescence again. Corrupt/missing fields are
   not evidence that clearing is safe.
8. Open an audit record containing the incident reference, authorized operator
   role, UTC times, installation/site/network scope, destination fingerprint,
   before-state digest, classification, evidence references and reviewers.
   Preserve full inventory and sensitive identifiers only in approved restricted
   incident storage. No credentials or private block inventories belong in a
   public validation report.

## Classify the remote operation

| Observed state | Required evidence | Allowed next step |
| --- | --- | --- |
| Known ID, `completed` | Successful status GET in the verified account; returned ID matches; terminal status | Read complete current inventory; review local desired state before resuming writers |
| Known ID, `failed` | Same identity checks and explicit terminal failure | Read complete inventory; do not assume atomic rollback or no partial application |
| Known ID, `pending`/`running` | Matching successful status response | Retain the barrier; bounded read-only polling and escalation only |
| Known ID unavailable, 404, unauthorized, malformed, mismatched ID/status | No trustworthy terminal evidence | Retain the barrier; repair read access or seek provider evidence |
| `unknown`, including a timeout/lost response | Acceptance and operation identity are not established | Retain the barrier; investigate without submitting a test mutation |
| Proven failure before dispatch | Trusted evidence tied to this exact attempt that HTTP was never dispatched, with all workers stopped | Review identity and complete remote state; only a separately authorized, validated repair may proceed |
| Response lost after dispatch | HTTP dispatch is established but acceptance/completion is not | Treat as unknown unless a trustworthy matching operation receipt/status is recovered |

The reviewed provider contract offers status by operation ID. The durable row
contains no request journal, target IP, list ID, verb or payload digest. It cannot
by itself distinguish a death after writing the marker but before dispatch from
a lost response after dispatch. A generic `WP_Error`, timeout, missing operation
ID, expired lease, elapsed time, or repeated stable inventory is not dispatch or
completion evidence. A currently present/absent address does not prove a queued
operation will not change it later.

For a known ID, the existing normal Client path can resume bounded status GETs
before inventory and clears its coordination identity only on a matching terminal
status. This is existing worker recovery, not a newly privileged diagnostic tool.
It is **not** permission to run a normal synchronization while the incident is
being inspected: synchronization can subsequently submit new mutations. An
unknown barrier intentionally blocks even the Client's ordinary inventory HTTP;
use separately authorized read-only provider tooling for investigation instead
of disabling the guard.

## Verify complete state and preserve independent ownership

9. Once a matching terminal outcome or sufficiently specific provider receipt is
   established, obtain complete fresh provider inventory with all cursors/pages
   and validated metadata. Read every relevant list if the affected list cannot
   be established, and the complete applicable zone rules for zone incidents.
   Truncated inventory, changing cursors/totals, malformed items or unavailable
   permissions means stop. An inventory snapshot alone cannot resolve unknown
   acceptance or establish that an operation has finished.
10. Review the intended action against preserved local records from all affected
    sites and networks, including manual ownership and reset watermarks. A
    provider comment, IP membership, or absence of one site's owner is not proof
    that an entry is exclusively managed by that site. Do not delete foreign,
    manual, orphaned or independently managed blocks as part of lock recovery.
    Keep cleanup, purge and reconciliation disabled during this review.
11. If the original intent/operation cannot be established, or any independent
    writer/provider activity is unresolved, retain the row and keep writers
    stopped. Escalate to the destination owner/provider for authoritative
    receipt and completion evidence. This can intentionally remain blocked
    indefinitely. No safe automatic unknown-state clearing is established here.

## Conditional repair and controlled return to service

12. No database clearing action is supplied by this task. A break-glass repair is
    conditional on the preceding evidence, quiescence, explicit change approval,
    and a separately reviewed procedure validated on an actual supported
    WordPress/database environment. Do not promote the mocked tests to that
    validation. If those prerequisites are unavailable, stop here without
    modifying the row.
13. A future approved repair must compare the **entire exact original serialized
    value** on the verified primary table, recheck the destination and operation
    identity, affect exactly one row, and abort on any mismatch or storage error.
    Never use unconditional `update_option`, prefix deletion, timestamp-only
    matching, or an expired lease as authorization. Re-read authoritative state
    afterwards and append the before/after digests, evidence, approver and result
    to the audit record. No provider mutation is necessary to clear a verified
    local barrier, and no provider deletion belongs in that operation.
14. Resume one designated installation's controlled writer only after review of
    the resulting inventory and ownership. Begin with read-only verification,
    then a reviewed intended synchronization with cleanup/purge still disabled.
    Watch for pending/unknown recurrence or identity drift; stop on ambiguity.
    Restore scheduling and any cleanup policy separately after successful
    verification. Never enable multiple independent writers on the assumption
    that this installation's lease protects them.

## Configuration requirements and limits

Use one authoritative database with the standard unique options key and normal
WordPress autocommit behavior. Validate custom database routing, sharding,
replicas, persistent caches and connection replacement before relying on this
protocol. Database-time checks are conservative guards, not a transaction
covering an ownership/log write and Cloudflare.

Assign one designated writer installation per account-list account or zone.
Use separate accounts for concurrently independent list writers; separate lists
in the same account still share the provider's bulk-operation constraint. Use
separate zones or a single designated owner for zone mutations. Independent
installations may coordinate through an explicit operational maintenance process,
but this plugin adds no shared external coordination service. Differing tokens
are not coordination or ownership isolation. Contradictory policies can remain
unsafe even when a single installation serializes their HTTP.

Quiesce writers before uninstall or reinstall. Do not sweep unresolved rows by
age. Monitor retained rows by destination fingerprint and incident ownership;
ordinary successful release removes empty rows, but unresolved state or crashes
can retain historical rows. Any garbage collection requires the same evidence
and scope controls, not a global timer.

## Contracts reviewed

- [Cloudflare bulk operation status](https://developers.cloudflare.com/api/resources/rules/subresources/lists/subresources/bulk_operations/methods/get/)
- [Cloudflare list item creation and account pending limit](https://developers.cloudflare.com/api/resources/rules/subresources/lists/subresources/items/methods/create/)
- [Cloudflare Lists API conflict/rate-limit behavior](https://developers.cloudflare.com/waf/tools/lists/lists-api/)
- [WordPress database schema](https://developer.wordpress.org/reference/functions/wp_get_db_schema/)
- [WordPress prepared SQL](https://developer.wordpress.org/reference/classes/wpdb/prepare/)
