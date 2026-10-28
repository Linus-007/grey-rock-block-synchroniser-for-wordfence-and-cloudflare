# Grey Rock 1.3.14 current-source integration results

This note supersedes the earlier report's integration-unavailable status after
the user clarified authorisation to run the existing disposable infrastructure.
Existing scripts and Compose configuration were used unchanged. No replacement
framework, production access, deployment, publication, push or tag was involved.

Runtime fingerprint: `4d83d73bd1e4f4f07c976450550bca8b141aa4882db9af566b46b6efb594b1e1`.
All three existing runners exited zero; the full historical runner includes
180 unique MariaDB concurrency cases. The existing real-expiry runner also
passed its actual 900-second unknown/ownership-add-manual case.
Installed plugin PHP files independently matched the same fingerprint.

- `tests/docker/run-historical-upgrade.sh`: exit 0, 331.921 seconds; log `/tmp/greyrock-current-integration-20261028T004055Z/multisite-upgrade.log`.
- `tests/docker/run-historical-real-expiry.sh`: exit 0, 1182.515 seconds; log `/tmp/greyrock-current-integration-20261028T004055Z/real-lease-expiration.log`.
- `tests/docker/run-historical-regression.sh`: exit 0, 2614.934 seconds; log `/tmp/greyrock-current-integration-20261028T004055Z/historical-and-concurrency.log`.

All normal project containers, networks and volumes were removed. Original CVE
approval JSON, approval history, prepared applicability review, source changes,
published 1.3.13 archive and earlier evidence were preserved. No source defect
was found and no runtime/test-source correction was needed. The two source-bound
approval checks remain pending an authorised human decision. Local integration
results do not replace independent release-workflow/runtime-matrix gates.
Production Cloudflare behavior remains unverified; provider responses were
simulated and the existing network/HTTP barriers remained in force.

The complete updated report, raw logs, execution/source receipts, case inventory
and source patches are in `/home/lpsweers/greyrock-1.3.14-integration-handoff.zip`.
