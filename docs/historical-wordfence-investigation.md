# Historical Wordfence coverage investigation

Working candidate: **1.3.14, unpublished**. Published 1.3.13, its release ZIP,
Git tags and WordPress.org release trees are preserved. No production WordPress
installation or live Cloudflare resource is involved in this investigation.

## Evidence and root causes

Production observations supplied by the operator: WordPress 7.1.3 multisite,
Wordfence 9.0.2, Account IP List mode, 24-hour lookback, threshold one, one-minute
synchronisation, five successful site reports. There were 239
`blocked:wordfence` requests from three IPs and 26 `blocked:waf` requests from
four IPs. The missing addresses were:

| Site | IP | Plugin-block requests |
| --- | --- | ---: |
| salus.zone | 158.101.19.62 | 132 |
| greyscale.zone | 3.107.195.156 | 87 |
| greyscale.zone | 34.95.3.164 | 20 |

None was in `wfBlock::ipBlocks(true)`. These are observations, not proof that
address-wide Cloudflare blocking was authorised. Historical descriptions,
original block types and original expiration times for these three IPs were
not supplied. Their original decision cannot be reconstructed from totals. The four WAF IPs
and raw request metadata were not supplied; the WAF fixture uses synthetic public
addresses while preserving the observed event/IP counts.

1. `HistoricalBlockReader` selected only `blocked:waf`. Plugin-block events were
   invisible to historical evaluation. Active IP blocks could not compensate
   for events whose address was absent from the current block API.
2. `wpcf_sync_blocks` is mutable current state with a unique IP key, not history.
   Updates overwrite previous episodes; skips are absent; removal and expiration
   remove state. An absent row could mean unobserved evidence, a deliberate
   skip, unsuccessful processing or completed cleanup. A successful site run
   only reported the paths that had actually been evaluated.
3. Account-list presence plus previous success suppressed local refresh. New
   evidence could therefore fail to update expiration. Conversely, the historical
   path could restore an absent entry using already-handled WAF evidence, while
   active-block logic applied a temporal suppression boundary. These decisions
   needed consistent membership, reset and recidivism handling.
4. WordPress can return an empty result array after an SQL error. Historical
   reads checked only the PHP result type, which could mistake unavailable
   evidence for an authoritative empty desired set. Wordfence 9.0.2 also wraps
   failed active-block queries in an empty array; the scheduler now checks the
   immediate database error there before any later read can clear it.

5. Real multisite testing exposed an existing lifecycle defect: the plugin
   directory constant contained `includes/../`. WordPress's network plugin
   basename lookup did not collapse that path, so the network-active check
   failed and new-site migrations were not registered correctly. The old test
   returned a constant basename and hid the defect. The constant now uses the
   actual parent directory; a realistic basename regression fails on the old
   implementation and passes on the corrected one.

6. Cold-cache reset testing exposed a pre-existing protected-transaction bug.
   Configuration scope resolution called WordPress's normal option loader while
   the deliberately restricted `AtomicConnection` was installed. The loader
   attempted `suppress_errors()`, which is intentionally absent from that
   connection interface. Configuration getters now use the authoritative fenced
   option reader during protected work, avoiding cache loaders and hooks. Named and fractional-offset site timestamps also use the protected option reader
   inside the fence, avoiding the same cold-cache failure during audit/state writes.
   Normal reads remain unchanged. A narrowly whitelisted, read-only snapshot avoids
   locking shared settings rows and preserves independent-scope progress; mutable
   reset and ownership state retain their existing locked reader. The regression
   rejects mutable keys and scope mismatches at that boundary.

## Wordfence 9.0.2 semantics and qualification

The official 9.0.2 archive was inspected directly:
https://downloads.wordpress.org/plugin/wordfence.9.0.2.zip

Archive SHA-256:
`4124ae2a5d7cff8faadf04c222583fa06f78e8e9c791e50447383e5e12ab5fb6`.

Relevant upstream implementation:

- `lib/wfLog.php`: `firewallBadIPs`, `takeBlockingAction` and `do503` use
  `blocked:wordfence` for country policies, request patterns and IP-block/rate
  responses. This action does not establish permanent IP-block authority.
- `lib/wordfenceClass.php`: banned-URL and blank-UA/referrer POST handling create
  temporary IP blocks. WAF log ingestion also emits `blocked:wordfence` for
  plugin decisions without failed WAF rules, with separate actions for certain
  lockouts and WFSN responses.
- `models/block/wfBlock.php`: `ipBlocks(true)` includes manual, automatic,
  WFSN and rate-block records **and rate throttles**. Its inclusion of throttles
  does not turn throttling into a full edge IP-block policy. `DURATION_FOREVER`
  is explicitly zero.
- `waf/wfWAFBlockConstants.php` and `wfWAFBlockI18n.php`: descriptions are stable
  identifiers, including parameterised messages; translated UI strings must not
  be guessed as security policy.
- `lib/wfSchema.php`: `wfHits` preserves action, description, URL, time and IP,
  but not the authoritative IP-block type and expiration at that historical hit.

| Evidence | Forwarding decision | Recorded expiration |
| --- | --- | --- |
| Current permanent IP block | Eligible, subject to public IP, trust, attribution, reset and retry checks | Wordfence's explicit permanent state |
| Current finite IP block | Eligible under the same checks | Actual Wordfence expiration |
| Current throttle | Skip; a throttle is not an IP block | No new edge block |
| Historical `blocked:waf` | Eligible at the configured threshold after attribution/reset/trust checks | Latest qualifying WAF event plus configured lookback |
| Historical `blocked:wordfence` with current qualifying IP authority | Covered by the current IP-block path; audit the hit | Preserve current IP-block expiration; do not create another historical lease |
| Historical `blocked:wordfence` without current IP authority | Audit skip; counts/descriptions cannot recover type and expiry | No inferred block |
| Country or request-pattern hit | Explain scope in the skip reason | No inferred address-wide block |
| Logged/learned WAF traffic, unrelated hosts | Outside the selected block evidence | No block or cross-site audit attribution |

Removal from Cloudflare follows the existing cleanup/reconciliation schedules
and requires confirmed completion; storing an expiration is not a provider TTL.

The conservative plugin-hit policy intentionally does not revive expired or
removed IP blocks, even when a message says “Accessed a banned URL” or “Manual
block by administrator”. A current block is the authority for those paths.
A different policy that creates a new historical plugin-hit lifetime would be
a product/security policy expansion, not a reconstruction of Wordfence expiry.

## Implementation

Historical reads select both block actions, retain the Wordfence hit ID and
stable description for classification, match exact current home/site hosts,
normalise public addresses and honour reset watermarks. SQL failures stop the
evaluation before ownership reconciliation. WAF threshold aggregation remains
separate from plugin-hit authority; plugin requests do not inflate WAF counts.

Complete Cloudflare inventory is the membership authority. Present entries
pass through the existing Client's idempotent membership path, refreshing local
attribution and authoritative expiry without POSTing an existing IP. Old active
evidence remains suppressed by explicit resets, even when another owner keeps
the address in Cloudflare. Confirmed membership can recover retry-limited local
state without another mutation; absent membership still respects the failure
limit. Missing entries require evidence newer than previous
successful handling. WAF evidence remains in the desired ownership set even
when forwarding is suppressed. New attributable WAF evidence can establish local
provenance when an active network block had no attributable plugin hit locally.

`AuditLog` owns a new per-site InnoDB table, `wpcf_sync_audit`. The separate
**Historical Audit** menu is available in Site Admin and Network Admin. Existing
Synchronisation Log views and their stored state remain available. The audit
uses existing escaped rendering, filters and sorting, and current-network site
scope. The network view retains the existing limit of 500 recent records per
site, rather than presenting itself as an unlimited export.

The audit covers automatic Wordfence evaluation and forwarding decisions. Manual
controls and cleanup retain their existing state handling. Audit entries contain
IP, internal outcome/reason, recorded time, evidence time,
and the original hit ID where applicable. Raw request URLs, usernames, bodies,
Cloudflare responses and credentials are not copied. Repeated detections/skips
for identical evidence are idempotent; success/failure attempts remain distinct.
Historical decisions are written in batches of at most 50 under the existing
atomic lease fence. State removal, reset, expiration and reconciliation do not
delete audit rows. A per-site prune on the next audit write removes rows older
than 30 days. Uninstall removes the dedicated audit table, preserving the
existing handling of unresolved destination receipts.

Audit persistence failure prevents successful synchronisation reporting. A
confirmed remote success followed by failed local state persistence records a
separate audit failure when that audit backend is available, and remains an
incomplete synchronisation. An unavailable audit backend cannot log its own
failure; the site's persisted synchronisation status remains the fallback.
No request-local audit table or mutable IP state is used as ownership authority.

## Verification

See the final verification results appended below. The reproducible disposable
entry point is:

```sh
tests/docker/run-historical-regression.sh
tests/docker/run-historical-upgrade.sh
tests/docker/run-historical-real-expiry.sh # one actual 900-second lease expiry
```

It uses a unique Docker Compose project, fresh volumes, no published ports and
an internal-only database/CLI network. A WordPress HTTP denial filter is a
second egress barrier; only explicit in-process Cloudflare fixture routes
return simulated results. PHP's existing upload-progress mitigation is retained.
Cleanup removes only the unique project's containers, network and volumes.
The runner never invokes production/provider scripts, host service helpers,
release publication or the published ZIP builder.

The separate upgrade fixture recreates the unchanged 1.3.13 state schema and
stored-version boundary across five sites. It compares authoritative database
snapshots before/after upgrade, including permanent and failed state, owners,
configuration, reset watermarks and an unresolved receipt. It verifies new empty
InnoDB audit tables rather than inventing historical audit rows. It does not
install or modify the published ZIP.

The real-expiry fixture selects one existing unknown-receipt case and waits for
the actual 900-second lease duration; it is reported separately from the standard
180-case forced-expiry matrix.

The new isolated regression is mandatory in `make validate`:

```sh
php tests/static/historical-wordfence-coverage-regression.php
```

The existing shared-IP regression now exercises idempotent Client membership
plus actual local refresh rather than a removed scheduler suppression helper.
Static UI expectations include audit-aware reset routes and the trusted-address
audit path without removing either original allow-list exclusion.

## Remaining limits

- This fixes future observation and auditing. Deleted/expired historical Grey
  Rock records cannot be reconstructed. Wordfence history must still exist
  within the configured window and have a usable attributable hostname.
- Cloudflare transport and asynchronous results are simulated, not certified
  against a live account. External HTTP and local database transactions cannot
  form one atomic transaction. Existing unknown-operation barriers remain.
- Final source review replaces unbounded result materialisation with descending
  primary-key pages of 500, WAF count/latest aggregation per distinct IP, and
  audit buffering of at most 50 entries. Total scan time and distinct-IP memory
  remain proportional to traffic; this is not a hard workload ceiling.
- Thirty-day audit retention is enforced on subsequent writes, not by a new
  independent scheduler. An inactive site can retain older audit rows until its
  next write or uninstall. Operators remain responsible for personal-data policy.
- The existing CVE-2026-115560 exception approval is bound to the previously
  reviewed source hash. This functional source change invalidates it. Approval
  metadata and security gates are deliberately unchanged; no renewed approval,
  security certification, publication or deployment is asserted.

## Final source review correction

The final review also fixes same-host subdirectory attribution using WordPress
core get_site_by_path routing for catalogued domains, for both historical and
active-block evidence. Routing SQL failures invalidate evaluation. Configured
aliases absent from that catalog retain the prior exact-host fallback and need
separate mapping-policy review if multiple subsites share an alias. See
`greyrock-1.3.14-security-renewal.md` for the pending source-bound review.
The original Docker projects were removed after their completed runs; those
logs validate the earlier fingerprint, not the later pagination/routing edits.
No replacement WordPress installation was created during final security review.
