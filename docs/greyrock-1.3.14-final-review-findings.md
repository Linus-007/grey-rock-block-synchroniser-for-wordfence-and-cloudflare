# Grey Rock 1.3.14 final implementation review

Unpublished candidate. No production change, live Cloudflare call, deployment, publication, push or tag. Original 1.3.13 release archive and original source-bound CVE approval are preserved.

## Corrected during final review

1. **Historical memory growth:** the earlier candidate materialised the entire matching wfHits result, all per-IP WAF event timestamps and all historical audit callbacks. A busy 24-hour network could exhaust PHP memory. Queries now use descending primary-key pages of 500 without OFFSET, WAF aggregation keeps only count/latest per distinct IP, and the scheduler drains audit callbacks in batches of 50. Later-page SQL failure invalidates the full desired set; it never returns a partial set for ownership reconciliation. Concurrent newer IDs are handled on the next scan. A non-advancing cursor fails closed.
2. **Same-host subdirectory attribution:** matching hostname alone cannot distinguish native multisite paths. Historical candidates and active-block attribution now also consult WordPress's `get_site_by_path` core router and compare its blog ID to the current site. SQL errors during routing invalidate evaluation. The exact-host gate still runs first. Configured aliases absent from the core domain catalog preserve the existing exact-host fallback; the implementation does not invent mapping policy for third-party aliases.

New mandatory regressions cover 1,201 events across three pages, count/latest aggregation despite ID/time order differences, concurrent insertion visibility on the next run, later-page empty-array SQL errors, active attribution beyond page one, cursor failure, parent/child exclusion, child/parent inclusion, prefix collisions, aliases, unrelated hosts and routing SQL errors. The route regression models the documented core API; real-WordPress certification remains blocked by environment availability.

WordPress routing contract inspected: https://developer.wordpress.org/reference/functions/get_site_by_path/ (2026-10-27). It returns the closest domain/path site, uses the full path by default and returns false when no site is found.

## Areas re-reviewed

- **Qualification:** blocked:wordfence counts/descriptions do not confer permanent or address-wide authority. Current IP authority is required; throttles are excluded. Historical WAF counts remain a separate threshold policy. Current finite expiration and explicit permanent state retain their meaning.
- **Expired/temporary blocks:** active expiration equal to now is rejected, expired/removed plugin decisions are not revived by history, and historical WAF leases remain bounded by latest evidence plus lookback. No new expiry policy was inferred from messages.
- **Account-list reconciliation:** complete inventory supplies membership. Present membership refreshes local state without duplicate POST; resets remain authoritative, absent membership needs new evidence, and only proven present membership bypasses the mutation retry limit. Shared ownership/manual ownership and unknown receipts retain their barriers.
- **Stale membership:** only proven Grey Rock-managed unowned entries are deletion candidates. Network deletion waits for all inheriting sites to succeed. Unknown/unmanaged remote entries are intentionally retained. This is ownership safety, not a promise that every remote entry will disappear.
- **Audit:** detection/skip identity remains deterministic, success/failure attempts distinct, rows site-scoped and independent of mutable IP state. Audit failure prevents successful reporting. Partial completed scan audit entries may survive a later-page failure: they describe observed evidence, not a completed synchronization. No ownership or forwarding is derived from those rows.
- **SQL:** both history page queries and routing reads inspect immediate last_error; Wordfence active loading also checks the immediate database error. Empty arrays on failure remain unknown, not authoritative absence. Existing fenced mutable reads remain locked and ambiguous values fail closed.
- **Concurrency:** no changes to destination/site lease scope, mutable locks, unresolved receipts or cleanup authority. Bounded audit flushes continue under the existing fence. Strict commit-before-deadline and atomic HTTP-plus-DB behavior remain explicitly unguaranteed.
- **Multisite/security:** current-network administrative scope, capabilities, escaping, public-IP/trusted-address exclusions, per-site audit schema, migration and uninstall behavior remain. The original plugin directory correction and read-only configuration snapshot fixes are retained. All 13 changed/new runtime files have individual security findings and hashes in the pending review record.

## Remaining defects and operational risks affecting other installations

- **Uncatalogued shared aliases:** when several subsites use the same configured alias not represented in WordPress's site-domain routing catalog, the preserved exact-host fallback cannot separate them by path. Native catalogued domains are corrected. Such third-party mapping configurations need an explicit mapping contract and additional integration coverage before claiming correct attribution. This is a known attribution limitation, not a security certification.
- **Total workload remains unbounded:** per-result/buffer memory is bounded, but distinct-IP aggregation, site-routing calls, total scan time and total audit write volume still scale with traffic. Router calls may add database work. A workload can exhaust the lease or execution budget; guarded mutations then fail closed. No hard throughput guarantee or production sizing benchmark was established.
- **Audit growth/retention:** confirmed present membership can generate distinct success attempts every interval. Thirty-day pruning is write-driven and may be expensive for a large backlog; inactive sites can retain older rows. The network UI shows at most 500 recent records per site. Audit is not tamper-evident against a database administrator and is not a complete manual/cleanup ledger.
- **Time-of-check/provider delays:** a finite block valid when collected can approach or cross its deadline while later work or asynchronous provider completion runs. The provider list has no native per-entry TTL; removal depends on a successful subsequent cleanup/reconciliation. No hard no-overstay guarantee is made. This retained cross-system timing limitation needs separate design if strict wall-clock enforcement is required.
- **Evidence mutation:** primary-key pagination avoids new-insert OFFSET drift; it is not a database-wide snapshot of concurrent Wordfence deletions/edits. Existing Wordfence history retention and usable request URLs remain prerequisites.
- **Historical recovery:** no evidence can reconstruct deleted state or original block type/expiry from the supplied production request totals. The original three IPs may correctly remain skipped without current authority.
- **Verification blockers:** the original Docker projects were removed by prior successful teardown. No existing installation can be reused. User constraints prohibit a replacement/duplicate installation, so current-source Docker, upgrade, real expiry and real InnoDB concurrency runs are unavailable. Earlier passing logs apply to fingerprint f625e12d7bf2e69f05cdabd934a3ba78cce20af90e756e143d6c4997ec4652c5, not the final source. Live production Cloudflare permissions, rules, asynchronous results and expiry remain unverified.
- **Governance blockers:** two checks enforce the same unchanged prior source-bound CVE exception. A separate explicit human decision is needed for renewal. Release workflow evidence, all required branch integrations and separately authorised publication remain outside this local review. The pending record cannot satisfy those gates.

## Readiness

Not release-ready. Local functional/security check results are recorded separately from pending approval and unavailable integration checks in the handoff report. A new approval alone does not resolve the integration and live-provider limitations. No security control or approval test has been weakened.

## Incidental harness timing correction

The initial combined run also reproduced an existing expected-success mock command
being killed by a one-second Compose timeout (exit 124). Normal substitute
commands now receive five seconds of startup headroom. Intentional stopped and
cleanup-stopped controls retain one second, including their exact timeout
assertions; the TERM-ignoring descendant control also remains one second.
Production watchdog settings, credential assertions and cleanup assertions
were not changed. The original failure transcript and subsequent complete gate
result are retained separately in the final handoff.
