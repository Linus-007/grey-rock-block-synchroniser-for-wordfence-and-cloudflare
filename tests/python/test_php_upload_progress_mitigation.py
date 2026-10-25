"""Regression checks for the disposable PHP upload-progress mitigation."""
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]


class PHPUploadProgressMitigationTests(unittest.TestCase):
    def test_override_disables_upload_progress(self):
        path = ROOT / "tests/docker/php-security.ini"
        self.assertTrue(path.is_file())
        settings = [
            line.strip()
            for line in path.read_text().splitlines()
            if line.strip() and not line.lstrip().startswith(";")
        ]
        self.assertEqual(settings, ["session.upload_progress.enabled=0"])

    def test_both_php_services_receive_read_only_override(self):
        compose = (ROOT / "tests/docker/compose.yml").read_text()
        services = compose.split("\nservices:\n", 1)[1]

        for service, following in (("wordpress", "cli"), ("cli", None)):
            section = services.split("\n  " + service + ":\n", 1)[1]
            if following is not None:
                section = section.split("\n  " + following + ":\n", 1)[0]
            else:
                section = section.split("\nvolumes:\n", 1)[0]

            with self.subTest(service=service):
                self.assertIn("source: ./php-security.ini", section)
                self.assertIn(
                    "target: /usr/local/etc/php/conf.d/zz-greyrock-security.ini",
                    section,
                )
                self.assertIn("read_only: true", section)

    def test_both_integration_runners_enforce_runtime_mitigation(self):
        for filename in ("integration-test.sh", "integration-test-ci.sh"):
            source = (ROOT / "scripts" / filename).read_text()

            with self.subTest(runner=filename):
                self.assertEqual(
                    source.count("===== VERIFY PHP UPLOAD-PROGRESS MITIGATION ====="),
                    1,
                )

                section = source.split(
                    "===== VERIFY PHP UPLOAD-PROGRESS MITIGATION =====", 1
                )[1].split("===== WAIT FOR WORDPRESS", 1)[0]

                self.assertEqual(
                    section.count('ini_get("session.upload_progress.enabled")'),
                    2,
                )
                self.assertIn(
                    "compose run --rm --no-TTY --no-deps --entrypoint php cli",
                    section,
                )
                self.assertIn("compose exec --no-TTY wordpress", section)
                self.assertIn("exit(1)", section)

    def test_override_is_not_a_release_advisory_clearance(self):
        import json

        policy = json.loads((ROOT / "security/php-support.json").read_text())
        self.assertTrue(
            any("CVE-2026-115560" in advisory
                for advisory in policy["unresolved_advisories"])
        )


if __name__ == "__main__":
    unittest.main()
