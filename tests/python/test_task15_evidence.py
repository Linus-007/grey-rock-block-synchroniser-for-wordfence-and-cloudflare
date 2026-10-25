"""Offline evidence rejection tests; never invoke a publication entry point."""
import importlib.util
from pathlib import Path
import sys
import unittest
from unittest.mock import patch
import datetime
import re
import io
import json

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts'))
import release_evidence as evidence


def module(name, path):
    spec = importlib.util.spec_from_file_location(name, ROOT / path)
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


class EvidenceTests(unittest.TestCase):
    def test_actual_jobs_required(self):
        controls = evidence.required_controls(['8.2', '8.5'])
        sha = 'a' * 40
        jobs = [dict(
            name=('integration-gates' if name == 'Mandatory real InnoDB concurrency'
                  or name.startswith('Security runtime (') else 'security-gates') + ' / ' + name,
            head_sha=sha, status='completed', conclusion='success',
                     steps=[dict(name=step, conclusion='success')]) for name, step in controls.items()]
        evidence.verify_jobs(jobs, controls, sha)
        for field, value in [('head_sha', 'b' * 40), ('status', 'in_progress'), ('conclusion', 'skipped')]:
            with self.subTest(field=field):
                changed = [dict(j) for j in jobs]
                changed[0][field] = value
                with self.assertRaises(ValueError): evidence.verify_jobs(changed, controls, sha)
        for bad in [jobs[:-1], jobs + [jobs[0]], []]:
            with self.assertRaises(ValueError): evidence.verify_jobs(bad, controls, sha)
        jobs[0]['steps'][0]['conclusion'] = 'skipped'
        with self.assertRaises(ValueError): evidence.verify_jobs(jobs, controls, sha)

    def test_unexpected_job_origin_rejected(self):
        controls = evidence.required_controls(['8.2', '8.5'])
        sha = 'a' * 40
        jobs = [
            {
                'name': 'unexpected-workflow / ' + name,
                'head_sha': sha,
                'status': 'completed',
                'conclusion': 'success',
                'steps': [{'name': step, 'conclusion': 'success'}],
            }
            for name, step in controls.items()
        ]

        with self.assertRaisesRegex(ValueError, 'workflow|job|origin'):
            evidence.verify_jobs(jobs, controls, sha)

    def test_local_claims_never_authorize(self):
        with patch.dict('os.environ', {}, clear=True), self.assertRaises(ValueError): evidence.verify()

    def test_unknown_advisory_blocks_even_with_github_context(self):
        from unittest.mock import patch
        import tempfile

        github_context = {
            'GITHUB_ACTIONS': 'true',
            'GITHUB_REPOSITORY': 'example/grey-rock',
            'GITHUB_RUN_ID': '12345',
            'GITHUB_RUN_ATTEMPT': '1',
            'GITHUB_SHA': 'a' * 40,
            'GH_TOKEN': 'offline-test-placeholder',
        }

        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'security').mkdir()
            (root / 'security/php-support.json').write_text(
                json.dumps({
                    'unresolved_advisories': [
                        'CVE-2099-999999: unassessed advisory'
                    ]
                })
            )
            with patch.object(evidence, 'ROOT', root):
                with patch.dict('os.environ', github_context, clear=True):
                    with patch.object(
                        evidence.subprocess, 'check_output'
                    ) as git_check:
                        with patch.object(
                            evidence.urllib.request, 'urlopen'
                        ) as network:
                            with self.assertRaisesRegex(
                                ValueError, 'Unassessed upstream PHP advisory'
                            ):
                                evidence.verify()

            git_check.assert_not_called()
            network.assert_not_called()

    def test_lifecycle_expiry_and_eol(self):
        runtime = module('task15_runtime', 'scripts/php-runtime.py')
        self.assertNotIn('8.1', runtime.supported_branches(datetime.date(2026, 10, 24)))
        with self.assertRaises(ValueError): runtime.supported_branches(datetime.date(2030, 1, 1))
        with self.assertRaises(ValueError): runtime.resolve('8.1')
        for metadata in [None, '8.5', [], ['8.1']]:
            with patch.object(runtime.urllib.request, 'urlopen', return_value=io.StringIO(json.dumps({'version': '8.5.99', 'supported_versions': metadata}))):
                with self.assertRaises(ValueError): runtime.resolve('8.5')
        for version in ['8.2.35', '8.3.36', '8.4.27', '8.5.12']:
            with self.subTest(patched_version=version):
                runtime.security_eligible(version)
        for version in ['8.2.34', '8.3.35', '8.4.26', '8.5.11', '8.5.4']:
            with self.subTest(unpatched_version=version):
                with self.assertRaises(ValueError):
                    runtime.security_eligible(version)

    def test_concurrency_partial_and_duplicate_rejected(self):
        suite = module('task15_counts', 'scripts/task15-concurrency-result.py')
        with self.assertRaises(ValueError): suite.validate('PASS stale/test; exits=[0,0]\n' * 180)
        with self.assertRaises(ValueError): suite.validate('')
        source = (ROOT / 'tests/integration/task14-mariadb-suite.php').read_text()
        cases = re.findall(r"'([a-z-]+)'", source.split('$cases = [', 1)[1].split('];', 1)[0])
        modes = ['stale', 'locked', 'rollback', 'expiry', 'independent', 'unknown',
                 'unknown-rollback', 'unknown-expiry', 'connection-loss', 'unknown-connection-loss']
        complete = ''.join(f'PASS {mode}/{case}; exits=[0,0]; expiry=forced\n' for mode in modes for case in cases)
        self.assertEqual(suite.validate(complete), {'completed': 180, 'passed': 180, 'failed': 0})
        for bad in [complete.replace('PASS', 'FAIL', 1), complete + complete.splitlines()[0] + '\n', '\n'.join(complete.splitlines()[:-1])]:
            with self.assertRaises(ValueError): suite.validate(bad)

    def test_release_dependencies(self):
        text = (ROOT / '.github/workflows/release.yml').read_text()
        self.assertIn('needs: [security-gates, integration-gates]', text)
        self.assertIn('needs: [security-gates, integration-gates, release]', text)
        integration = (ROOT / '.github/workflows/integration.yml').read_text()
        self.assertIn("GREY_ROCK_TASK14_FENCING: '1'", integration)
        self.assertIn('matrix: ${{ fromJSON', integration)
        self.assertNotIn('continue-on-error', integration)
