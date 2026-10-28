# 1.3.14 source-bound security exception review and approval instructions

Status: **PENDING HUMAN APPROVAL**. This document is a source review, not an approval, security certification, publication or deployment authorisation.

## Scope and conclusion

The original `security/cve-2026-115560-exception.json` remains byte-for-byte unchanged, including its fingerprint, approval identity, dates and decision history. The release verifier still consumes that canonical file; no pending review can satisfy its approval checks.

The original rationale concerns only this plugin: it does not start PHP sessions, switch PHP session serialization handlers, or manipulate session upload progress. It does not certify the host runtime or other WordPress plugins. Review of all 13 changed/new runtime PHP files and the prohibited-pattern check across all 27 runtime PHP files supports that same narrow rationale. Database `SET SESSION` statements configure MariaDB transactions, not PHP sessions. No runtime dependency or PHP session API has been introduced.

On 2026-10-27 the [PHP advisory archive](https://www.php.net/archive/2026.php) still describes the October 23 issue as requiring a particular configured session handler, a runtime switch to another handler and enabled upload progress. It continues to recommend disabling upload progress pending a regular fix. That supports the plugin-scoped review; it does not make an affected runtime safe. The October 26 Windows Brotli notice is separate and does not resolve this CVE. Existing runtime mitigation and unresolved-advisory policy are unchanged.

The pending JSON lists the exact candidate fingerprint, every changed runtime PHP file with its SHA-256 and individual finding, the original record hash and the policy-only verification result. It deliberately has no approver, decision timestamp, expiration timestamp or approval decision.

## Exact review record

`security/reviews/greyrock-1.3.14-cve-2026-115560.pending.json`

Candidate runtime PHP fingerprint:

`4d83d73bd1e4f4f07c976450550bca8b141aa4882db9af566b46b6efb594b1e1`

Original canonical record SHA-256:

`5e7d9e0e84ae2be2124ef8954b6d1e1735a027246faaf902a47502eed61bac3d`

These hashes are different kinds of identity: the candidate fingerprint frames sorted PHP paths relative to `src/` and their contents; the original record hash is ordinary SHA-256 of the JSON bytes.

## Required distinct human decision

The current instruction to review and prepare an exception does not approve a newly reviewed fingerprint. An authorised project owner must review the pending JSON, source patch, findings and limitations, then explicitly make the source-specific decision. Suggested exact statement:

> I approve the plugin-scoped CVE-2026-115560 non-applicability exception for Grey Rock 1.3.14 runtime source fingerprint 4d83d73bd1e4f4f07c976450550bca8b141aa4882db9af566b46b6efb594b1e1 for 30 days from my actual recorded approval time. This applies only to src/**/*.php in this plugin, preserves the documented mitigation and all other release gates, and does not certify the hosting runtime or other WordPress components. Publication and deployment were explicitly excluded and remain excluded from this approval.

No approval date or identity should be filled in before this decision. The human can reject or request further review instead.

## Applying a real approval through the existing workflow

Only after explicit human approval:

1. Recompute the candidate fingerprint with `scripts/cve_approval.py::source_fingerprint`. If it differs, stop and renew the source review; do not approve a different tree by copying this hash.
2. Preserve the exact original canonical JSON in an append-only history path such as `security/approval-history/cve-2026-115560-5e7d9e0e84ae2be2124ef8954b6d1e1735a027246faaf902a47502eed61bac3d.json`. Verify its ordinary SHA-256 against the value above. Never edit the historical copy.
3. Prepare a NEW canonical record from the reviewed pending JSON. Set `approval.status` to `approved`, record the actual authorised human identity and UTC decision time, a 30-day duration, expiration exactly 30 days after that time, the reviewed source fingerprint and the actual explicit decision text. Reference the preserved historical record and its hash. Do not reuse or amend the previous approval identity/date/fingerprint as if it covered functional changes.
4. Leave all original rationale, scope, mitigation and release restrictions intact. Retain the pending review as review evidence rather than converting its original review into an asserted human decision.
5. Use the existing `verify_approval` validation and run both named source-bound security checks, then the normal relevant validation gates. No test exclusions, alternate approval resolver, environment override or verifier change is required or authorised by this document.

The existing decision-record test also requires the literal history-preservation statement “Publication and deployment were explicitly excluded”; retain that truthful exclusion in the actual recorded decision.

The present task performs none of steps 2–5: they describe a future authorised recording of an actual human decision. The two current approval checks intentionally remain blocked. Even a valid new exception does not unlock the other release gates or provide Docker/live Cloudflare verification.
