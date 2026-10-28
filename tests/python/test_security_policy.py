"""Focused failure handling and release security barrier regressions."""
import contextlib
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import tarfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts'))
import security_tools
import composer_audit
import semgrep_scan

spec = importlib.util.spec_from_file_location('gitleaks_check', ROOT / 'scripts/gitleaks-check.py')
gitleaks = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gitleaks)


class SecurityPolicyTests(unittest.TestCase):
    def test_latest_semgrep_excludes_yanked_and_prereleases(self):
        payload = {'releases': {v: [{'yanked': y}] for v, y in [('1.9.9', False), ('1.10.2', False), ('1.11.0rc1', False), ('1.12.0', True), ('2.0.0.dev1', False)]}}
        self.assertEqual(security_tools.latest_semgrep(payload), '1.10.2')
        payload['releases']['2.0.0'] = [{'yanked': False}]
        self.assertEqual(security_tools.latest_semgrep(payload), '2.0.0')
        for payload in [None, {}, {'releases': []}, {'releases': {'1.0.0rc1': [{}]}}, {'releases': {'1.0.0': []}}]:
            with self.subTest(payload=payload), self.assertRaises(ValueError):
                security_tools.latest_semgrep(payload)

    def test_scanner_resolution_failure_never_uses_old_binary(self):
        with patch.object(security_tools, 'fetch', side_effect=OSError('offline')), patch.object(security_tools.subprocess, 'run') as command:
            with self.assertRaises(OSError):
                security_tools.install_semgrep()
            command.assert_not_called()

    def test_checksum_is_required_before_extraction(self):
        content = b'non-executable regression fixture'
        valid = hashlib.sha256(content).hexdigest() + '  scanner.tar.gz'
        security_tools.verify_checksum(content, valid, 'scanner.tar.gz')
        for checksum in ['', valid + '\n' + valid, '0' * 64 + '  scanner.tar.gz', valid.replace('scanner.tar.gz', 'other.tar.gz')]:
            with self.subTest(checksum=checksum), self.assertRaises(ValueError):
                security_tools.verify_checksum(content, checksum, 'scanner.tar.gz')

    def test_rule_snapshot_does_not_extract_links_or_traverse_paths(self):
        commit = 'a' * 40
        prefix = f'semgrep-rules-{commit}/php/'
        buffer = io.BytesIO()
        with tarfile.open(fileobj=buffer, mode='w:gz') as archive:
            for name in ['rules/safe.yml', '../../escape.yml']:
                data = b'rules: []\n'
                info = tarfile.TarInfo(prefix + name)
                info.size = len(data)
                archive.addfile(info, io.BytesIO(data))
            link = tarfile.TarInfo(prefix + 'link.yml')
            link.type = tarfile.SYMTYPE
            link.linkname = '../../escape.yml'
            archive.addfile(link)
        with tempfile.TemporaryDirectory() as directory, patch.object(security_tools, 'fetch', side_effect=[json.dumps([{'sha': commit}]).encode(), buffer.getvalue()]):
            destination = Path(directory) / 'snapshot'
            result = security_tools.snapshot_php_rules(destination)
            self.assertEqual(result['commit'], commit)
            self.assertEqual(set(result['files']), {'rules/safe.yml'})
            self.assertFalse((Path(directory) / 'escape.yml').exists())

    def test_composer_findings_and_connectivity_errors_are_distinct(self):
        clean = json.dumps({'advisories': [], 'abandoned': []})
        self.assertEqual(composer_audit.classify(0, clean), 'CLEAN')
        for code in [1, 2, 100]:
            self.assertEqual(composer_audit.classify(code, clean), 'ERROR')
        for output in ['', 'offline', '{}', '[]', '{"advisories": null}']:
            self.assertEqual(composer_audit.classify(0, output), 'ERROR')
        for code in [0, 1, 3]:
            self.assertEqual(composer_audit.classify(code, json.dumps({'advisories': {'fixture/package': [{}]}})), 'FINDINGS')
        self.assertEqual(composer_audit.classify(2, json.dumps({'advisories': [], 'abandoned': {'fixture/package': None}})), 'FINDINGS')

    def test_gitleaks_redaction_full_history_and_nonzero_propagation(self):
        for code in [0, 1, 2]:
            with patch.object(gitleaks.subprocess, 'run', return_value=subprocess.CompletedProcess([], code, b'private fixture text', b'private fixture text')) as command, contextlib.redirect_stdout(io.StringIO()) as output:
                self.assertEqual(gitleaks.scan('scanner', 'git', ROOT), code)
                args = command.call_args.args[0]
                self.assertIn('--redact=100', args)
                self.assertIn('--log-opts=--all --full-history', args)
                self.assertIn('--ignore-gitleaks-allow', args)
                self.assertNotIn('private fixture text', output.getvalue())

    def test_semgrep_findings_errors_and_empty_coverage_fail_closed(self):
        clean = {'results': [], 'errors': [], 'paths': {'scanned': ['fixture.php']}}
        self.assertTrue(semgrep_scan.summarize(0, clean)[1])
        self.assertFalse(semgrep_scan.summarize(2, clean)[1])
        for data in [dict(clean, results=[{}]), dict(clean, errors=[{}]), dict(clean, paths={'scanned': []})]:
            self.assertFalse(semgrep_scan.summarize(0, data)[1])
        for data in [None, {}, dict(clean, paths={}), dict(clean, results='invalid')]:
            with self.assertRaises(ValueError):
                semgrep_scan.summarize(0, data)

    def test_release_requires_all_security_jobs_and_limits_write_token(self):
        workflow = (ROOT / '.github/workflows/release.yml').read_text()
        self.assertIn('uses: ./.github/workflows/security.yml', workflow)
        self.assertIn('needs: [security-gates, integration-gates, release]', workflow)
        self.assertEqual(workflow.count('contents: write'), 1)
        publish = workflow.split('  publish:', 1)[1]
        self.assertNotIn('actions/checkout', publish)
        self.assertNotIn('continue-on-error:', workflow)
        self.assertNotIn('if:', workflow)
        self.assertIn("hashlib.sha256((base / name).read_bytes()).hexdigest()", publish)
        security = (ROOT / '.github/workflows/security.yml').read_text()
        self.assertIn('workflow_call:', security)
        self.assertIn('fetch-depth: 0', security)
        self.assertIn('python3 scripts/gitleaks-check.py', security)
        self.assertIn('./scripts/semgrep-check.sh', security)
        self.assertNotIn('gitleaks/gitleaks-action', security)
        self.assertNotIn('semgrep==', security)
        self.assertNotIn('secrets.GITHUB_TOKEN', security)
        self.assertNotIn('pull_request_target:', security)
        self.assertNotIn('continue-on-error:', security)

    def test_reproducible_ce_install_and_no_registry_rules(self):
        install = (ROOT / 'scripts/security_tools.py').read_text()
        self.assertIn('--only-binary=:all:', install)
        self.assertIn('--no-index', install)
        self.assertIn('manifest.json', install)
        self.assertIn('snapshot_php_rules(destination)', install)
        self.assertIn('community_php_rules', install)
        scan = (ROOT / 'scripts/semgrep_scan.py').read_text()
        for flag in ['--oss-only', '--strict', '--metrics=off', '--error']:
            self.assertIn(flag, scan)
        self.assertNotIn('--config auto', scan)
        self.assertIn('security/semgrep.yml', scan)
        self.assertIn('capture_output=True', scan)


if __name__ == '__main__':
    unittest.main()
