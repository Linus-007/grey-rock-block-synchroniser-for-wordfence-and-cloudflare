"""Fail-closed applicability assessment for CVE-2026-115560."""
import json
from pathlib import Path
import re
import sys
import unittest

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts"))
CVE = "CVE-2026-115560"


class CVE2026115560ApplicabilityTests(unittest.TestCase):
    def test_exception_is_documented_and_scoped(self):
        record = json.loads(
            (ROOT / "security/cve-2026-115560-exception.json").read_text()
        )
        self.assertEqual(record["cve"], CVE)
        self.assertEqual(
            record["classification"],
            "not_exploitable_through_reviewed_plugin_source",
        )
        self.assertEqual(
            record["runtime_mitigation"],
            "session.upload_progress.enabled=0",
        )
        self.assertIn("other WordPress components", record["rationale"])

    def test_plugin_does_not_use_relevant_session_apis(self):
        record = json.loads(
            (ROOT / "security/cve-2026-115560-exception.json").read_text()
        )
        files = sorted((ROOT / "src").rglob("*.php"))
        self.assertGreater(len(files), 0)

        for path in files:
            source = path.read_text()
            for pattern in record["prohibited_source_patterns"]:
                with self.subTest(file=str(path.relative_to(ROOT)),
                                  pattern=pattern):
                    self.assertIsNone(
                        re.search(pattern, source, re.IGNORECASE),
                        "Session-sensitive behavior requires renewed CVE review",
                    )

    def test_mitigation_remains_present_in_both_containers(self):
        override = (
            ROOT / "tests/docker/php-security.ini"
        ).read_text()
        self.assertIn("session.upload_progress.enabled=0", override)

        compose = (ROOT / "tests/docker/compose.yml").read_text()
        self.assertEqual(
            compose.count(
                "target: /usr/local/etc/php/conf.d/"
                "zz-greyrock-security.ini"
            ),
            2,
        )

    def test_exception_approval_is_valid_and_time_bound(self):
        from datetime import datetime, timedelta
        from cve_approval import source_fingerprint, verify_approval

        record = json.loads(
            (ROOT / "security/cve-2026-115560-exception.json").read_text()
        )
        approval = record["approval"]

        self.assertEqual(approval["status"], "approved")
        self.assertEqual(approval["duration_days"], 30)

        approved = datetime.fromisoformat(approval["approved_at_utc"])
        expires = datetime.fromisoformat(approval["expires_at_utc"])

        self.assertEqual(expires - approved, timedelta(days=30))
        self.assertEqual(
            approval["approved_source_sha256"],
            verify_approval(ROOT, CVE)["approved_source_sha256"],
        )
        self.assertTrue(approval["decision_record"])
        verify_approval(ROOT, CVE)

    def test_exception_expiry_never_controls_plugin_runtime(self):
        """Release governance must not control installed plugin operation."""
        forbidden = (
            "CVE-2026-115560",
            "cve_applicability",
            "cve-2026-115560-exception",
            "approved_at_utc",
            "expires_at_utc",
            "duration_days",
            "RELEASE_READINESS",
        )

        sources = sorted((ROOT / "src").rglob("*.php"))
        self.assertGreater(len(sources), 0)

        for path in sources:
            content = path.read_text()
            for marker in forbidden:
                with self.subTest(
                    file=str(path.relative_to(ROOT)),
                    marker=marker,
                ):
                    self.assertNotIn(
                        marker,
                        content,
                        "Security exception governance must not affect "
                        "the installed plugin",
                    )

    def test_exception_requires_explicit_recorded_decision(self):
        record = json.loads(
            (ROOT / "security/cve-2026-115560-exception.json").read_text()
        )
        approval = record["approval"]

        self.assertEqual(approval["status"], "approved")
        self.assertTrue(approval["approved_by"])
        self.assertIn(
            "Publication and deployment were explicitly excluded",
            approval["decision_record"],
        )

    def test_advisory_remains_in_policy(self):
        policy = json.loads(
            (ROOT / "security/php-support.json").read_text()
        )
        self.assertTrue(
            any(CVE in item
                for item in policy["unresolved_advisories"])
        )


if __name__ == "__main__":
    unittest.main()
