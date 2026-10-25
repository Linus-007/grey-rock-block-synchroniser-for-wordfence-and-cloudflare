# Atomic local fencing: blocked implementation and verification plan

Status: **NOT IMPLEMENTED; NOT VERIFIED ON INNODB; RELEASE BLOCKED.**

Task 8 changes no affected production behavior. Its new test reproduces 18
unfenced local mutation paths using actual PHP classes, deterministic clock and
UUID values, and interleavings inside storage shims. The default test exits zero
when it successfully characterizes the vulnerability. `--require-fenced` exits
one because the safety property is not satisfied. Neither mode is InnoDB proof.

## Mutation scope inventory

S means a scheduler's site lease; D means the effective installation-scoped
account/zone lease. An optional S is required if that scheduler invocation owns
one. Manual/network actions do not acquire a scheduler S merely to write a site
record. The future authorization set must be explicit per operation; an empty
set must not accidentally authorize a protected write.

| Actual path | Local mutation | Required context | Current protection / gap |
| --- | --- | --- | --- |
| `BlockOwnership::add/remove` | Insert/no-op or delete independent owner | D; S for scheduled sync | Separate guards then SQL |
| `BlockLogger::log` | Permanent/expiring success and failure reset | D; S for scheduled sync | Separate guards then INSERT/upsert |
| `BlockLogger::mark_failed` | Failure record, bounded increment or transient nonincrement | D; S when scheduled | Same gap; rejection must not become successful logging |
| `BlockLogger::remove` | Local row deletion | D; S when scheduled | Separate guards then delete |
| `ResetWatermarkStore::set/clear` | Site option add/update or network option update, normalization/pruning | D; S when present | Repeated guards still separate from API read/write and cache changes |
| `NetworkManualOwnershipStore::add/remove` | Network associative ownership option read/modify/write | D; normally no S | Whole-array update can overwrite newer state after expiry |
| `SyncScheduler::run_now` last attempt | Site attempt timestamp | S; D not yet acquired | Guard then update_option |
| `persist_last_result`, last-run timestamp | Site result/error/run bookkeeping | S; D while operation still owns it | Guard then one/two option writes; a partial result/error pair is possible |
| `record_batch_results` | Log/mark_failed plus watermark clear | D + S | Delegates to the unfenced leaf writes; later guard can stop after an earlier stale write |
| Wordfence ownership reconciliation | Owner removal/restoration, reset and log deletion | D + S | Leaf gap; remote confirmation does not make local commits atomic |
| Trusted-address cleanup | Per-inheriting-site log removal | D + S | Destination pinned, scoped enumeration; leaf SQL still unfenced |
| Expiry cleanup/finalization | Watermark then row-id delete | D + S | Last guard before delete can be followed by replacement; can report complete after stale delete |
| `Reconciler::purge_missing_local_records` | Reset followed by log removal | D; S if invoked by scheduler | Separate state changes; first can persist before a later guard fails |
| `NetworkSynchronizer::reconcile_network` | Network reset, per-site log removal, optional purge | D; no synthetic S for every site | Combined ownership read and leaf mutation gaps remain |
| Manual/test block handlers | Local owner/manual-network claim and optional log after HTTP | D, ordinary capability/nonce checks | No new S required; endpoint authorization is distinct from lease fencing |
| Manual list removal reset | Reset/log/owner changes for correct scope | D, appropriate site/network capability/nonce | Leaf gaps; preserve manual isolation and reset ordering |
| `DestinationLease::acquire/replace/release` | Active owner, renewal, pending ID/unknown, conditional release | Exact D owner/value and deadline where required | CAS predicates are in the same SQL statement; modeled replacement rejection passes; real engine semantics unverified |
| Site lease acquire/renew/release | Scheduler token/heartbeat/conditional release | Exact S owner/value and deadline | Same distinction: lease-row CAS, not atomic data fencing |
| Client pending/unknown persistence | Pre-dispatch marker, receipt ID, terminal clear | D and exact old value; scheduler guard when present | Preserve durability outside any later local data transaction |
| Client cooldown transient | Credential-fingerprint backoff | No ownership authorization; conservative advisory state | Not a distributed lock; expiry cannot authorize uncertain remote work |
| Admin feedback/reconciliation transients | Notices/results | Authorized scope, not a destination ownership claim | Do not use as authority; lease-aware core result stores still need S |
| Configuration, DNS cache, cron/lifecycle version writes | Admin configuration/scheduling/cache/version | Existing capabilities or lifecycle; quiesce writers for destination changes | Not silently converted to protected synchronization writes |
| Migration/create tables/uninstall | DDL, migration INSERT, global option/table removal | Authorized offline lifecycle with drained writers | DDL may implicitly commit; never wrap normal lifecycle DDL in a synchronization transaction |

The inventory distinguishes inspected call paths from individually executed leaf
reproductions. The new 18 cases cover owner add/remove, both success-log branches,
permanent/transient failure branches, log removal, new/existing/site/network reset
updates and clears, network manual add/remove, status pair, final cleanup delete
and reconciliation reset. Attempt/last-run bookkeeping and full controller flows
are source-mapped, not independently exercised in this new shim matrix. Existing
scheduler, permission, scope and recovery suites still run.

## API/schema constraints reviewed

- `wpdb::query()` operates on its current connection and may reconnect/replay
  after server-gone-away. A connection-ID check after an unfenced replay is too
  late. `suppress_errors()` is not a transaction/reconnect guarantee.
- Option APIs perform reads, hooks and object-cache mutations outside a supplied
  transaction boundary. A rollback does not roll back cache or arbitrary hooks.
  Starting a transaction around `update_option()` is therefore insufficient.
- Standard options have UNIQUE option_name; ownership has PRIMARY KEY(ip, owner),
  and logs have UNIQUE ip plus a row-id primary key. Plugin create-table SQL does
  not explicitly select InnoDB. Existing installs' engines must be inspected.
- Network sitemeta has no corresponding unique (network, option) ownership key.
  Missing network option creation and duplicate legacy rows need a proven
  serialization/initialization protocol; locking only an existing meta row does
  not settle the absent-row case, especially across isolation levels.
- A lease-only `EXISTS` in INSERT/UPDATE is not assumed to lock its lease source
  or serialize takeover. A zero affected-row result from a gated statement must
  not pass existing `!== false` success checks when authorization was rejected.

## Candidate transaction model (proposal only)

The proposed safety invariant is: **a local mutation cannot commit after B has
successfully replaced the relevant authoritative lease(s)**. This is a
serialization property, not a guarantee of commit before a wall-clock deadline.
Arbitrary suspension after the last time check can pass a deadline while holding
row locks. A strict real-time expiry guarantee remains a separate requirement;
do not advertise it as achieved by this proposal.

1. Run HTTP with no transaction open. Keep the already committed unknown/receipt
   marker intact. A subsequent local rollback must not erase dispatch evidence.
2. Begin a short local-only transaction through a proven connection-bound
   primitive that cannot reconnect and replay an unfenced statement. Reject an
   existing outer transaction, unsupported engine/routing, or unverified session.
   Do not blindly START TRANSACTION over somebody else's transaction: it can
   commit prior work. A tested connection-generation/session policy is mandatory.
3. Lock the required existing S rows in deterministic table/key order, then D
   rows in deterministic table/key order, then affected entity rows. No path may
   acquire S after D. D-only manual/network operations stay D-only; they do not
   acquire another worker's S. All paths must agree on this order.
4. Use locking current reads (not an earlier MVCC/cached snapshot). Verify exact
   token, scope, target blog/network and server deadline after row acquisition.
   A missing, replaced, malformed, expired or unreadable lease aborts. A
   conditional renewal must remain owner/deadline constrained, and a statement
   returning past its prior deadline must not authorize further work.
5. Read/compute the actual entity update under those locks. Do not reuse an option
   array calculated outside the transaction. Serialize network whole-array state
   and solve absent/duplicate sitemeta rows explicitly. Any initializer or
   entity sentinel must be scoped to that state, not a global destination lock;
   it requires its own schema/compatibility review before implementation.
6. Execute only the necessary prepared local writes. Treat authorization failure,
   database error, deadlock, timeout and lost connection as failed work. Do not
   retry HTTP. A verified idempotent no-op is distinct from rejected authorization;
   return successful synchronization only for a verified committed result.
7. Confirm the same session, recheck deadline policy, then COMMIT. A lost COMMIT
   response is uncertain local completion, not confirmed success. Fail closed and
   re-read later under a fresh lease; no blind replay of non-idempotent increments.
8. Invalidate the correct blog/network individual and aggregate option caches only
   for committed writes. Plan for termination between commit and invalidation:
   security reads must remain authoritative, and recovery/versioned invalidation
   may be needed for persistent caches. No precommit cache publication or
   post-rollback stale cache is acceptable. Preserve supported hooks without
   permitting HTTP/arbitrary callbacks inside the transaction.
9. On failure, rollback on the same live session, stop protected mutations and
   preserve preexisting pending markers. On process death, engine rollback must
   release row locks, but earlier committed remote-dispatch state remains.
   Failed rollback/release cannot confer ownership. Release logical leases with
   exact owner/value CAS only after the local transaction is settled.

Conditional correctness argument: if A locks and verifies current lease rows
first, B's replacement cannot complete until A's local transaction is committed
or rolled back. If B replaces first, A's locking current read rejects its old
identity. This argument depends on actual engine locks, one pinned connection,
all relevant writes participating, correct absent-row/entity handling and no
reconnect/autocommit escape. None of those are proven by the emulated tests.

Single-statement alternatives may be smaller for direct ownership/log SQL, but
INSERT/upsert, DELETE, zero-row no-ops, source-row locks, isolation behavior and
option-array creation still require real server proof. A partial correction must
not be described as fencing all local state. No alternative was inserted into
production in Task 8.

## Actual integration verification required

Every case must use actual WordPress APIs/classes and separate PHP processes on
actual InnoDB. Use deterministic file/socket barriers with bounded timeouts, not
probabilistic sleeps. Publish sanitized event order and database commit outcomes.

- A checks, pauses, expires; B acquires; A resumes: no old data commit and no
  success return for every inventory row above. Baseline must fail first.
- A obtains locking reads first; B attempts replacement: prove B cannot complete
  first. Include A crossing expiry while blocked/paused; state the precise time
  policy rather than claiming strict deadline completion.
- Query-start versus actual lock-wait time, SQL returning after expiry, row locks
  on both options tables and local entity tables, and supported isolation levels.
- Deadlock victims, lock wait timeout, write failure after the first local change,
  rollback, idempotent no-op, bounded failure increments, rejected zero-row DML.
- KILL the test worker's own session from a second disposable connection at
  BEGIN/read/data-write/COMMIT boundaries; observe core reconnect/replay. The test
  DB user needs authority over only its disposable connections/schema, not
  production/global administrative privileges.
- Kill PHP before/after COMMIT and before cache invalidation; persistent object
  cache must not resurrect rolled-back or replaced ownership. Inspect independent
  readers after restart.
- Site option creation, missing and duplicate network meta rows, concurrent
  distinct option keys, serialized arrays, non-InnoDB rejection and outer-TX
  rejection. Never auto-convert a user's engine while running synchronization.
- Multiple sites, two networks, inherited/override configurations, correct table
  prefixes, manual/cron/CLI overlap, network reconciliation and owner isolation.
- Unknown/known pending markers survive each local rollback, error and death;
  intercepted HTTP sees no duplicate writes and no request during a transaction.
- Uninstall/reinstall only with drained workers; no clearing unknown state by age.
  Re-run the unchanged Task 7 recovery matrix and stop conditions.
- Independent installations on distinct databases both acquiring a scope remain
  an expected limitation. Enforce a designated writer per account or zone.

No transaction implementation or real InnoDB atomicity harness is supplied or
claimed validated by Task 8. Building and validating that candidate/harness on the
permitted environment is a release prerequisite, not a completed test gate.

## Minimum separately authorized environment

Use an unprivileged disposable runner with a working already-authorized Docker
endpoint or preinstalled user-space database/WordPress facilities. It must allow
local network/Unix-socket/process IPC, separate PHP processes, isolated database
volumes and access only to its test services. Do not add this user to Docker groups
or start host services as part of validation. This APP currently has Compose and
WP-CLI but denied Docker and TCP socket/bind probing, no database server/client or rootless
runtime, and no PHP MySQL driver: it cannot run the required stack.

Preprovision approved images, database tools, PHP mysqli/mysqlnd, WordPress 7.0+
verified stable release(s), current Wordfence, and a persistent cache backend with
its WordPress drop-in. All participating tables must be verified InnoDB. Test the
minimum PHP 8.1 and resolver-verified current stable PHP where supported; retain
exact runtime, image digest, WordPress/Wordfence versions and isolation/session
settings. The current local PHP 8.5.4 is not certified current or security-patched
merely because it executes tests. Provide SimpleXML, XMLReader/XMLWriter, DOM and
other required analysis extensions in the prebuilt runner, not by modifying this
host.

Required permissions: CRUD/DDL for only the disposable schema, session transaction
settings, observation/termination of that test user's own connections, ownership
of the disposable WordPress files/volumes, WP-CLI test administration, and local
process/cache IPC. No production credentials, access or global database privileges
are required. Network-dependent security/resolution work needs approved outbound
access or independently verified current artifacts/provenance on a separate runner.

Install a fail-closed `pre_http_request` Cloudflare mock in the test WordPress MU
plugin bootstrap *before* Grey Rock/Wordfence execution. It must reject every
unexpected request to Cloudflare, including GET; queued expected requests return
synthetic replies. Require outbound provider egress denial as a second barrier.
The normal application must not receive live Cloudflare credentials. Existing
integration scripts do not provide a full atomicity harness or this complete
network/cache matrix merely by returning zero.

### Exact commands for the existing base tests (future authorized runner only)

These commands are not executed by Task 8. `TASK8_ENV` must already be an
operator-prepared mode-0600 file with disposable DB passwords, approved image
references, resolved version values, and all compose artifact bindings. It is
not an existing private configuration file to discover or print. The plugin ZIP
binding must refer to the separately built current candidate. Verify the fixed
container names and port in compose.yml are unused by other work first.

```bash
set -Eeuo pipefail
: "${TASK8_ENV:?Supply a separately prepared disposable environment file}"
test -r "$TASK8_ENV"
docker info >/dev/null 2>&1 || exit 1
TASK8_COMPOSE=(docker compose --env-file "$TASK8_ENV" --project-name greyrock-task08 --file tests/docker/compose.yml)
"${TASK8_COMPOSE[@]}" config --quiet
"${TASK8_COMPOSE[@]}" up --detach db wordpress
"${TASK8_COMPOSE[@]}" run --rm cli core version
"${TASK8_COMPOSE[@]}" run --rm cli eval 'echo PHP_VERSION, PHP_EOL;'
"${TASK8_COMPOSE[@]}" run --rm cli db query 'SELECT VERSION(), CONNECTION_ID(), @@autocommit;'
"${TASK8_COMPOSE[@]}" run --rm cli db query 'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE();'
"${TASK8_COMPOSE[@]}" run --rm cli eval-file /artifacts/wordpress-compatibility-test.php
"${TASK8_COMPOSE[@]}" run --rm cli eval-file /artifacts/admin-action-button-test.php
"${TASK8_COMPOSE[@]}" run --rm cli eval-file /artifacts/ownership-migration-test.php
"${TASK8_COMPOSE[@]}" run --rm cli eval-file /artifacts/manual-removal-ownership-test.php
"${TASK8_COMPOSE[@]}" run --rm cli eval-file /artifacts/wordfence-block-api-probe.php
```

The above assumes disposable WordPress and plugins have been installed, correctly
versioned and the mandatory Cloudflare mock is active. Those setup steps, cache
profile and second network must be separately provisioned/reviewed. They are not
silently supplied by compose startup. `wp db query` requires the client tool in
the prebuilt CLI runner. Do not print SHOW CREATE TABLE for configuration rows or
select stored credentials; engine/schema metadata is sufficient.

The existing `scripts/integration-test.sh` and `integration-test-ci.sh` contain
sudo/service-start fallbacks; `stop-test-environment.sh` stops host services.
Do not use those wrappers in this restricted APP run. The direct commands above
have no escalation fallback. Stop on a denied daemon, missing tool or denied IPC.
Cleanup in a separately approved disposable run may use the same compose array's
`down --volumes --remove-orphans` only after verifying project ownership and
saving sanitized evidence; never invoke the host-service stop helper.

A candidate atomicity harness does not yet exist. Its proposed, explicitly future
interface is `wp eval-file /artifacts/task08-atomic-fencing-test.php <scenario>`
with scenario names `stale-before-write`, `locked-before-takeover`, `delayed-sql`,
`deadlock`, `rollback`, `connection-loss`, `commit-uncertain`, `cache-kill`,
`network-create`, `pending-survival` and `outer-transaction`. The future harness
must launch two actual workers and implement the acceptance criteria above.
These are planned commands, not runnable existing tests or evidence of success.

### Exact security/compatibility commands to close the other gates

On the separately authorized prebuilt runner, preserve preceding reports before
any helper that clears report directories. Do not call publish/tag/release or
service-start helpers for these checks.

```bash
composer validate --strict --no-interaction --no-check-publish
composer check-platform-reqs --no-interaction
python3 scripts/composer_audit.py
vendor/bin/phpcs --standard=phpcs.xml.dist src
./scripts/semgrep-check.sh
python3 scripts/gitleaks-check.py
python3 scripts/php-runtime.py
python3 scripts/wordpress_policy.py --check-metadata
python3 -m unittest discover -s tests/python -p 'test_*.py'
make validate
make metadata-check
python3 scripts/build-release.py --source src --readme readme.txt --plugin-slug grey-rock-block-synchroniser-for-wordfence-and-cloudflare --output reports/task-08/authorized-candidate.zip
python3 -m zipfile --test reports/task-08/authorized-candidate.zip
python3 -m unittest discover -s tests/python -p test_task07_publication_gates.py -v
```

A new safe atomic implementation must also make the local `--require-fenced`
probe pass after its fixture is adapted to the real atomic interface. Its
current expected exit one must not be suppressed to close the release gate.
The existing shim cannot validate a future transaction, so real tests remain
mandatory even if this oracle eventually exits zero.

Action pinning requires reviewed upstream full commit SHA identities, then
updating every remote `uses:` in workflows and the local composite action.
`rg -n 'uses:' .github/workflows .github/actions` inventories references; the
current version tags are not immutable pins. Do not invent SHA values or edit
GitHub repository settings. Publisher verification still needs a no-side-effect
whole-script harness and validated GitHub/SVN artifact-content binding; the
existing extracted-function tests do not close that prerequisite. Do not run
`release-plugin.sh validate` here: it reads private release configuration and
contains live-provider/escalation paths.

## Contracts reviewed

- [WordPress query/reconnect behavior](https://developer.wordpress.org/reference/classes/wpdb/query/)
- [WordPress site option updates/cache/hooks](https://developer.wordpress.org/reference/functions/update_option/)
- [WordPress network option updates](https://developer.wordpress.org/reference/functions/update_network_option/)
- [WordPress schema](https://developer.wordpress.org/reference/functions/wp_get_db_schema/)
- [MySQL locking reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html)
- [MariaDB FOR UPDATE](https://mariadb.com/docs/server/reference/sql-statements/data-manipulation/selecting-data/for-update)
- [MariaDB transaction boundaries](https://mariadb.com/docs/server/reference/sql-statements/transactions/start-transaction)
- [GitHub action supply-chain guidance](https://docs.github.com/en/actions/reference/security/secure-use)

The Task 7 recovery runbook remains authoritative for unknown provider acceptance.
Do not clear markers by age, remove independently managed blocks as a diagnostic,
or enable independent installations as concurrent writers. No external service,
new paid dependency or production transaction was introduced.
