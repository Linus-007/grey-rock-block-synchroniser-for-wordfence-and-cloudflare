# Runtime support and release evidence

PHP 8.1 remains the technical syntax/runtime minimum declared in plugin metadata.
This task does not raise it: existing code uses PHP 8.1 features, and older syntax
compatibility has not been reassessed. Technical installation eligibility does
not confer security support. PHP 8.1 is EOL and excluded from security support.

The plugin security policy accepts only the latest stable upstream patch of a
reviewed, security-maintained PHP branch, with no known unresolved applicable
vulnerability. On 2026-10-24, PHP's authoritative lifecycle table lists 8.2, 8.3,
8.4 and 8.5; their security deadlines are respectively 2026-12-31, 2027-12-31,
2028-12-31 and 2029-12-31. The reviewed snapshot in security/php-support.json
expires on 2026-12-31. Refresh it from upstream before expiry; do not infer future
branches or support from version ordering. Missing/expired policy or failed
release lookup blocks validation. CI resolves each branch's latest stable patch;
prereleases and patches superseded by upstream fixes are ineligible. A new
advisory affecting even the latest patch remains a release blocker requiring
human advisory review; runtime matching and passing tests are not vulnerability
certification. No paid vulnerability service is required.

The reusable integration workflow schedules all reviewed maintained branches,
including the minimum and current branches. Each runs static validation and a
disposable WordPress/Wordfence integration, checking the exact PHP patch in both
WordPress and WP-CLI. Image publication lag fails closed. These are configured
checks, not a claim that GitHub-hosted runs have completed. Actual Task 15 local
coverage is recorded in reports/task-15.zip. Earlier APP results (WordPress
7.1.3/PHP 8.5.12/Wordfence 9.0.2/MariaDB 13.0.2 and 180 scenarios) are historical
user-supplied observations and do not validate this changed revision.

WordPress 7.0 remains the technical minimum. Official security support targets
the latest stable WordPress with current security fixes, resolved at test time;
older branches and future stable releases are not automatically certified by
a numeric minimum comparison. Review WordPress advisories before release. The
current stable Wordfence from WordPress.org is the compatibility target; validate
its block API with the harness rather than preserving a forever-current version
claim. Plugin, WordPress, PHP and Wordfence minimums must all be met. Composer
requirements here are development tooling, not mandatory production dependencies.
WordPress hosting recommendations currently favor PHP 8.3+ and MariaDB 10.11+;
they are distinct from this plugin's technical minimum.

Atomic local fencing requires transactional InnoDB for all protected state and
lease tables, on the same supported database/connection. Unsupported engines or
unverifiable transaction state fail closed. Protected transactions serialize
against lease replacement using InnoDB row locks. Strict commit before lease
expiration is **not guaranteed**: a transaction admitted under the lease can
retain its locks beyond the deadline. External Cloudflare HTTP operations are
not atomically rolled back with local database transactions. Unknown remote
outcomes retain pending receipts and require reconciliation.

Cloudflare compatibility assumes the documented v4 API, endpoint schemas,
permissions, pagination and asynchronous operation semantics used by Client.php.
Account plans, rate limits and API changes can affect availability. Mock and
local tests do not verify live account permissions or remote behavior. No live
Cloudflare operation is part of this task.

## Evidence and trust boundary

security/release-gates.json is an inventory, never execution proof. The checker
ignores claimed verified status. It requires GitHub's authenticated API to report
successful mandatory jobs and actual control steps from the same release run,
attempt and exact clean source revision. It rejects missing, duplicate, skipped,
failed, partial or mismatched jobs. The local independent atomic oracle also
runs; it cannot replace the dedicated real InnoDB control. That job requires
180 unique scenarios across 18 paths, zero failures, and successful Compose
cleanup. Gitleaks must run its full-history and current-tree command; a positive
control alone is insufficient. Every reviewed PHP branch must complete.

Release build and publication depend on both reusable integration and security
workflows. The existing producer digest/receipt checks bind the validated ZIP to
that revision. No manually supplied evidence file can unlock local publication;
without independent current-run evidence it remains blocked. Repository admins,
GitHub runners, and reviewed workflow code are trusted. Protect workflow/script
changes and require review; this mechanism cannot defend against an administrator
rewriting or disabling the controls. No GitHub-hosted execution was performed
in Task 15. All seven gates remain blocked for this uncommitted revision.

## Optional defense in depth

These are not mandatory plugin dependencies. Prefer free tiers where suitable;
confirm current plan limits before adoption. Protect administrative access with
Cloudflare Zero Trust Access and MFA, carefully testing admin AJAX, REST API,
WP-CLI, login callbacks and authentication flows before enforcement. Avoid broad
bypasses that expose administration. Cloudflare Tunnel can reduce public origin
exposure; Cloudflare One/WARP may suit authorized administrator access. Cloudflare
WAF, secure TLS with origin certificate verification, and Wordfence provide
complementary layers. None supplies database transaction atomicity.

Sources reviewed 2026-10-24:
- https://www.php.net/supported-versions.php
- https://www.php.net/releases/
- https://wordpress.org/about/requirements/
- https://wordpress.org/about/security/
- https://wordpress.org/plugins/wordfence/
- https://developers.cloudflare.com/fundamentals/api/

As of the 2026-10-24 advisory review, no resolved PHP patch is certified for
security support here. The October 21 fixes address CVE-2026-109618 and
CVE-2026-109619, but the October 23 notice describes CVE-2026-115560 pending a
regular release. Patches through 8.2.35, 8.3.36, 8.4.27 and 8.5.12 are excluded
by this policy; future versions still require advisory clearance before release.
The matrix covers maintained candidate branches; current known-affected
candidates fail closed. No configuration mitigation is treated as a fixed
version. See https://www.php.net/archive/2026.php for the upstream notices.
