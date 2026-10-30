"""Regression tests for release-only CVE exception approval."""
from datetime import datetime, timezone
import json
from pathlib import Path
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts"))

from cve_approval import source_fingerprint, verify_approval

CVE = "CVE-2026-115560"
NOW = datetime(2026, 10, 25, 15, 0, tzinfo=timezone.utc)


class CVEApprovalTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / "src").mkdir()
        (self.root / "security").mkdir()
        (self.root / "src/plugin.php").write_text("<?php echo 'test';\n")

        self.record = {
            "cve": CVE,
            "approval": {
                "status": "approved",
                "duration_days": 30,
                "approved_by": "explicit-test-approver",
                "approved_at_utc": "2026-10-25T15:00:00+00:00",
                "expires_at_utc": "2026-11-24T15:00:00+00:00",
                "approved_source_sha256": source_fingerprint(self.root),
                "decision_record": "offline-test-decision",
            },
        }
        self.save()

    def save(self):
        (self.root / "security/cve-2026-115560-exception.json").write_text(
            json.dumps(self.record)
        )

    def verify(self, now=NOW):
        return verify_approval(self.root, CVE, now=now)

    def test_valid_approved_exception(self):
        result = self.verify()
        self.assertEqual(result["approved_by"], "explicit-test-approver")

    def test_pending_or_revoked_exception_rejected(self):
        for status in ("pending", "rejected", "revoked"):
            with self.subTest(status=status):
                self.record["approval"]["status"] = status
                self.save()
                with self.assertRaises(ValueError):
                    self.verify()

    def test_expiration_at_30_days(self):
        from datetime import timedelta
        self.verify(NOW + timedelta(days=30, seconds=-1))
        with self.assertRaisesRegex(ValueError, "expired"):
            self.verify(NOW + timedelta(days=30))

    def test_changed_plugin_source_preserves_approval(self):
        original = self.record["approval"]["approved_source_sha256"]
        (self.root / "src/plugin.php").write_text("<?php echo 'changed';\n")
        result = self.verify()
        self.assertEqual(result["approved_source_sha256"], original)
        self.assertEqual(result["current_source_sha256"], source_fingerprint(self.root))
        self.assertNotEqual(result["current_source_sha256"], original)

    def test_changed_expiry_without_duration_rejected(self):
        self.record["approval"]["expires_at_utc"] = (
            "2026-12-24T15:00:00+00:00"
        )
        self.save()
        with self.assertRaisesRegex(ValueError, "expiration"):
            self.verify()

    def test_duration_is_configurable_but_consistent(self):
        self.record["approval"]["duration_days"] = 14
        self.record["approval"]["expires_at_utc"] = (
            "2026-11-08T15:00:00+00:00"
        )
        self.save()
        self.verify()

        self.record["approval"]["duration_days"] = 0
        self.save()
        with self.assertRaisesRegex(ValueError, "duration"):
            self.verify()

    def test_missing_decision_or_approver_rejected(self):
        for field in ("approved_by", "decision_record"):
            with self.subTest(field=field):
                original = self.record["approval"][field]
                self.record["approval"][field] = None
                self.save()
                with self.assertRaises(ValueError):
                    self.verify()
                self.record["approval"][field] = original

    def test_actual_exception_has_valid_approval(self):
        record = json.loads(
            (ROOT / "security/cve-2026-115560-exception.json").read_text()
        )
        self.assertEqual(record["approval"]["status"], "approved")
        self.assertEqual(record["approval"]["duration_days"], 30)
        result = verify_approval(ROOT, CVE)
        self.assertEqual(
            result["approved_source_sha256"],
            record["approval"]["approved_source_sha256"],
        )


if __name__ == "__main__":
    unittest.main()
