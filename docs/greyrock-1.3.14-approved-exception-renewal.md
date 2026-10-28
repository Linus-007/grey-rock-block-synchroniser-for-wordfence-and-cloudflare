# Authorized CVE-2026-115560 exception renewal — Grey Rock 1.3.14

The project owner explicitly authorized a new 30-day plugin-scoped residual-risk
acceptance for runtime source fingerprint `4d83d73bd1e4f4f07c976450550bca8b141aa4882db9af566b46b6efb594b1e1`.
The source fingerprint was recalculated and matched before approval recording.

- Authorized identity: Linus-007 <colour@grayscale.zone>.
- Identity basis: repository-local Git user.name/user.email, corroborated by the Linus-007 fork remote and plugin repository URI.
- Approved UTC: 2026-10-28T01:33:03+00:00.
- Expires UTC: 2026-11-27T01:33:03+00:00 (exactly 30 days).
- Canonical record: `security/cve-2026-115560-exception.json`.
- Preserved original: `security/approval-history/cve-2026-115560-5e7d9e0e84ae2be2124ef8954b6d1e1735a027246faaf902a47502eed61bac3d.json`.
- Original JSON SHA-256: `5e7d9e0e84ae2be2124ef8954b6d1e1735a027246faaf902a47502eed61bac3d`; byte-identical to the pre-renewal record and HEAD.

The vulnerability remains unpatched. Its documented exploitation conditions do
not apply to the reviewed Grey Rock plugin code. The owner accepts the documented
residual plugin-scoped risk for the identified source and period. This does not
extend to WordPress, PHP hosting/runtime, Wordfence, other plugins or unrelated
vulnerabilities, and does not assert vendor remediation. Existing mitigation and
all unrelated security/release requirements remain mandatory.

Publication and deployment were explicitly excluded.

The prepared technical review and original approval history were preserved
without alteration. The new canonical record supersedes only the prior approval
for the current source; it does not rewrite the historical identity/date/hash.
The verifier, security tests, runtime mitigation and release-gate inventory were
not modified. Runtime PHP and test implementations were unchanged in this task.

Both previously blocked CVE approval tests passed. The complete `make validate`
gate subsequently passed: all 84 Python tests, configured PHP regressions,
source lint, model oracle, metadata/pin/inline-script and whitespace checks.
Actual logs, exit statuses and durations are retained in
`/tmp/greyrock-approved-renewal-20261028T013303Z` and the updated evidence ZIP.

Earlier current-source integrations remain applicable: historical Wordfence,
five-site upgrade, all 180 MariaDB concurrency cases and one actual 900-second
lease-expiry case passed, with project cleanup verified. They were not restarted
for this governance-only change. Independent release-workflow/runtime-matrix
requirements still apply, and production Cloudflare behavior remains unverified.
No production modification, deployment, publication, push or release tag occurred.
