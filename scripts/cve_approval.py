"""Release-only approval and expiry validation for a scoped CVE exception.

This module must not be imported by WordPress plugin runtime code.
"""
from datetime import datetime, timedelta, timezone
import hashlib
import json
from pathlib import Path
import re

CVE = "CVE-2026-115560"
RECORD = "security/cve-2026-115560-exception.json"


def source_fingerprint(root):
    """Stable SHA-256 identity of the complete reviewed plugin PHP tree."""
    root = Path(root)
    source_root = root / "src"
    paths = sorted(source_root.rglob("*.php"))

    if not paths:
        raise ValueError("No plugin PHP files available for approval binding")

    digest = hashlib.sha256()

    for path in paths:
        if path.is_symlink():
            raise ValueError("Symlinked PHP source requires manual review")
        relative = path.relative_to(source_root).as_posix().encode("utf-8")
        content = path.read_bytes()
        digest.update(len(relative).to_bytes(8, "big"))
        digest.update(relative)
        digest.update(len(content).to_bytes(8, "big"))
        digest.update(content)

    return digest.hexdigest()


def parse_utc(value, field):
    if not isinstance(value, str) or not value:
        raise ValueError("Missing UTC approval timestamp: " + field)

    try:
        result = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError as error:
        raise ValueError("Invalid UTC approval timestamp: " + field) from error

    if result.tzinfo is None or result.utcoffset() != timedelta(0):
        raise ValueError("Approval timestamp must be UTC: " + field)

    return result.astimezone(timezone.utc)


def verify_approval(root, cve, now=None):
    """Return approval evidence or reject any missing/invalid approval."""
    if cve != CVE:
        raise ValueError("No approval policy for CVE: " + cve)

    root = Path(root)
    record = json.loads((root / RECORD).read_text())

    if record.get("cve") != CVE:
        raise ValueError("Exception approval CVE mismatch")

    approval = record.get("approval")
    if not isinstance(approval, dict):
        raise ValueError("CVE exception approval is missing")

    if approval.get("status") != "approved":
        raise ValueError("CVE exception is not approved")

    days = approval.get("duration_days")
    if type(days) is not int or not 1 <= days <= 365:
        raise ValueError("Invalid approval duration")

    approver = approval.get("approved_by")
    if not isinstance(approver, str) or not approver.strip():
        raise ValueError("CVE exception approver is missing")

    decision = approval.get("decision_record")
    if not isinstance(decision, str) or not decision.strip():
        raise ValueError("CVE exception approval decision record is missing")

    approved = parse_utc(approval.get("approved_at_utc"), "approved_at_utc")
    expires = parse_utc(approval.get("expires_at_utc"), "expires_at_utc")

    if expires != approved + timedelta(days=days):
        raise ValueError("CVE exception expiration does not match duration")

    if now is None:
        now = datetime.now(timezone.utc)
    if now.tzinfo is None:
        raise ValueError("Approval verification requires timezone-aware UTC")
    now = now.astimezone(timezone.utc)

    if approved > now:
        raise ValueError("CVE exception approval is dated in the future")

    if now >= expires:
        raise ValueError("CVE exception approval has expired")

    source_hash = approval.get("approved_source_sha256")
    if not isinstance(source_hash, str) or not re.fullmatch(
        r"[0-9a-f]{64}", source_hash
    ):
        raise ValueError("CVE exception approved source identity is invalid")

    # The original approved fingerprint remains immutable historical evidence.
    # Current-source CVE applicability is independently verified by
    # cve_applicability.verify_exception in the mandatory release gate.
    current_source_hash = source_fingerprint(root)

    return {
        "cve": CVE,
        "approved_by": approver,
        "approved_at_utc": approved.isoformat(),
        "expires_at_utc": expires.isoformat(),
        "approved_source_sha256": source_hash,
        "current_source_sha256": current_source_hash,
        "decision_record": decision,
    }
