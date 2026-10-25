# PHP compatibility and security validation — Task 1

Task 15 supersedes the historical runtime and gate status below. See
[the current support and evidence policy](task15-security-support.md).

Baseline: v1.3.10, commit `4fef7ac0a935c6a0fbdac489d48ce3d12cb32d5d`.

## Confirmed findings and corrections

- CI used an 8.1–8.4 matrix, integration/security used 8.3, and release used 8.1. All four now invoke one composite action which resolves the newest upstream stable patch, installs it, verifies the actual runtime exactly, and runs repository validation. No maximum PHP branch is encoded.
- The existing resolver already rejected ordinary prerelease suffixes. Its selection logic is now a separately testable Python module with strict ASCII full-string version parsing, numeric ordering, malformed-response rejection and explicit regression coverage for development, alpha, beta and RC releases, including future branches.
- Local session resolution previously returned a cached file indefinitely. Every resolution now fetches current versions, fails on remote errors, and atomically replaces the session only after successful resolution. CI fails if PHP changes between host resolution and integration resolution, rather than silently testing different releases.
- Local integration previously skipped pulls based on a persistent image marker. It now always pulls current images. CI already pulled images and continues to do so.
- Both integration scripts retain the actual WordPress-container PHP probe and exact upstream patch comparison. Both now also probe and verify the WP-CLI image before plugin execution. Missing or lagging future container images fail validation; there is no fallback to an older PHP branch.
- Weekly CI and integration runs join the existing scheduled security run to detect new stable releases without requiring a source change.
- A resolved release is a compatibility candidate, not a support declaration. Host syntax/static checks, security checks and disposable integration must succeed before the release workflow can publish. Metadata is unchanged. Release validation, metadata consistency, ZIP construction, checksums and existing publication behavior remain, with security and integration gates added before publication. Manual release-version input is passed through an environment variable before validation to avoid shell interpolation.
- The translation target also resolves its WP-CLI PHP image dynamically. Test-session storage defaults to repository-local `.tools/test-session`, with a configurable directory, rather than requiring writes outside the workspace. The live Cloudflare test script only receives this matching cache-path adjustment; it was not executed.

## October 2026 security assessment

PHP's October 21 advisory identifies CVE-2026-109618 (out-of-memory corruption) and CVE-2026-109619 (stale request state across worker reuse) as critical remote-code-execution flaws. Upstream fixes are 8.5.12, 8.4.27, 8.3.36 and 8.2.35. These are interpreter vulnerabilities, not plugin dependency vulnerabilities; Composer audit cannot certify the interpreter.

Sources: https://www.php.net/archive/2026.php#2026-10-21-5 and https://ubuntu.com/security/notices/USN-8948-1 .

Ubuntu backports must be assessed by distribution/package revision, not solely `PHP_VERSION`. USN-8948-1 lists Ubuntu 26.04 PHP `8.5.4-0ubuntu1.5` as fixed for both CVEs. APP's installed CLI/common and other installed PHP packages report that revision via `dpkg-query`. PHP CLI reports 8.5.4, as expected. No host update is needed for those two CVEs on the evidence inspected. This does not certify an uninspected web SAPI, running worker process or other machine. The CI action installs an upstream version and therefore verifies that exact upstream version; local static/release validation does not incorrectly reject Ubuntu's patched 8.5.4.

PHP's October 23 advisory additionally describes CVE-2026-115560, an unusual session serialization-handler transition combined with enabled session upload progress. It recommends `session.upload_progress.enabled=0` pending a regular bugfix release. The two October 21 fixes do not establish protection against that separate issue. No host configuration was changed. Source: https://www.php.net/archive/2026.php#2026-10-23-1 .

## Executed checks and limits

- `make release VERSION=1.3.10`: PASS; includes PHP source lint, all 15 existing PHP static regression scripts, seven new Python regression tests, metadata/version/whitespace checks and release ZIP construction. Repeated after final implementation changes with exit 0.
- `python3 -m unittest discover -s tests/python -v`: PASS, seven tests, including an end-to-end shell resolver test with stubbed remote responses. It proves a cached old session is replaced, a future stable release is selected over an RC, file permissions remain 0600, and network failure/version drift return failure without replacing the last session.
- PHP lint on all `src` and `tests` PHP files: PASS, 42 files, PHP CLI 8.5.4 with Ubuntu fixed package revision.
- `bash -n` for every `scripts/*.sh`: PASS.
- PyYAML parse/structure checks: PASS for four workflows, the composite action and disposable Compose configuration. This is not a GitHub Actions execution or actionlint result; actionlint is unavailable.
- `git diff --check`: PASS.
- Original three privacy diffs compared to a pre-edit snapshot: identical byte-for-byte.
- Live PHP resolution: BLOCKED by shell DNS restrictions. Stubbed resolver tests passed; no live resolution success is claimed.
- `./scripts/integration-test-ci.sh`: attempted; failed during dependency resolution due to DNS restrictions, before container startup. Separately, `docker info` reports daemon socket permission denied. No disposable integration pass is claimed and no sudo service operations were attempted.
- `RUN_SEMGREP=0 ./scripts/security-check.sh`: FAIL overall. PHP source lint and release build succeeded; Composer audit could not reach Packagist and PHP_CodeSniffer could not run because local SimpleXML/xmlwriter extensions are absent. CI explicitly requests its required XML/mbstring extensions. Semgrep was intentionally disabled for this PHP-focused check, not reported as passing.

Future PHP compatibility remains conditional on successful hosted checks and availability of matching WordPress/WP-CLI images and tooling. No compatibility metadata or runtime plugin behavior was changed. No production, Cloudflare, commit, push, tag, publish, deployment or history-rewrite action was performed.

## Files

Task changes: `.github/actions/php-compatibility/action.yml`; `.github/workflows/ci.yml`; `.github/workflows/integration.yml`; `.github/workflows/security.yml`; `.github/workflows/release.yml`; `Makefile`; `scripts/php-runtime.py`; `scripts/resolve-test-session.sh`; `scripts/integration-test.sh`; `scripts/integration-test-ci.sh`; `scripts/cloudflare-integration-test.sh`; `tests/python/test_php_runtime.py`; this report.

Pre-existing privacy edits preserved: `tests/static/cleanup-reset-regression.php`, `tests/static/historical-block-evidence-regression.php`, `tests/static/reset-watermark-capacity-regression.php`.
