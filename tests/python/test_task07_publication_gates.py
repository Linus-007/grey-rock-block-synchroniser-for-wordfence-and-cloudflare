"""Exercise the actual publication-script gate with mocked GitHub HTTP only."""
from pathlib import Path
import contextlib
import io
import json
import sys
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
COMMIT = 'a' * 40


def execute_gate(payload, jobs=None):
    script = (ROOT / 'scripts/release-plugin.sh').read_text()
    code = script.split('verify_github_main_checks() {', 1)[1].split("<<'PYTHON'\n", 1)[1].split('\nPYTHON', 1)[0]
    def response(request, **kwargs):
        value = jobs if '/jobs' in request.full_url else payload
        return io.BytesIO(json.dumps(value).encode())
    with patch.object(sys, 'argv', ['gate', 'fixture/project', COMMIT]), patch('urllib.request.urlopen', side_effect=response), contextlib.redirect_stdout(io.StringIO()):
        try:
            exec(compile(code, 'publication-gate', 'exec'), {'__name__': '__main__'})
        except SystemExit as error:
            return error.code not in (None, 0)
    return False


def workflows():
    return {'workflow_runs': [
        {'id': i, 'name': name, 'path': '.github/workflows/' + path, 'head_sha': COMMIT,
         'head_branch': 'main', 'event': 'push', 'run_number': 20, 'run_attempt': 1,
         'status': 'completed', 'conclusion': 'success'}
        for i, (name, path) in enumerate([
            ('Application Security', 'security.yml'), ('Continuous Integration', 'ci.yml'),
            ('WordPress Integration', 'integration.yml')], start=1)]}


def successful_jobs():
    names = ['WordPress security checks', 'Semgrep CE SAST', 'Gitleaks secret scan', 'PHP compatibility', 'WordPress integration test']
    return {'total_count': len(names), 'jobs': [{'name': name, 'status': 'completed', 'conclusion': 'success', 'head_sha': COMMIT} for name in names]}


class PublicationGates(unittest.TestCase):
    def test_duplicate_display_name_cannot_substitute_workflow(self):
        data = workflows()
        data['workflow_runs'][0]['path'] = '.github/workflows/unrelated.yml'
        self.assertTrue(execute_gate(data, successful_jobs()), 'Publication accepted a workflow display-name substitution')

    def test_skipped_security_job_is_not_a_pass(self):
        jobs = successful_jobs()
        jobs['jobs'][2]['conclusion'] = 'skipped'
        self.assertTrue(execute_gate(workflows(), jobs), 'Publication accepted skipped Gitleaks despite successful aggregate run')

    def test_wrong_context_is_rejected(self):
        for field, value in [('head_sha', 'b' * 40), ('head_branch', 'other'), ('event', 'pull_request')]:
            data = workflows(); data['workflow_runs'][0][field] = value
            self.assertTrue(execute_gate(data, successful_jobs()), f'Publication accepted wrong {field}')

    def test_complete_expected_gate_succeeds(self):
        self.assertFalse(execute_gate(workflows(), successful_jobs()))

    def test_local_scanners_cannot_be_skipped_by_environment(self):
        script = (ROOT / 'scripts/release-plugin.sh').read_text().split('run_release_gates() {', 1)[1].split('\nwait_for_github_release()', 1)[0]
        self.assertIn('RUN_SEMGREP=1 ./scripts/security-check.sh', script)
        self.assertIn('python3 scripts/gitleaks-check.py', script)

    def test_local_security_failures_stop_before_build(self):
        source = (ROOT / 'scripts/release-plugin.sh').read_text()
        function = 'run_release_gates() {' + source.split('run_release_gates() {', 1)[1].split('wait_for_github_release() {', 1)[0]
        with tempfile.TemporaryDirectory(prefix='grey-rock-gate-audit-') as directory:
            fixture = Path(directory); (fixture / 'scripts').mkdir(); (fixture / 'bin').mkdir()
            scripts = {
                'scripts/security-check.sh': '#!/bin/bash\necho SECURITY_SEMGREP=$RUN_SEMGREP\nexit "$SECURITY_EXIT"\n',
                'bin/python3': '#!/bin/bash\necho GITLEAKS_GATE\nexit "$GITLEAKS_EXIT"\n',
                'bin/make': '#!/bin/bash\necho UNEXPECTED_BUILD\nexit 99\n',
            }
            for name, contents in scripts.items():
                path = fixture / name; path.write_text(contents); path.chmod(0o700)
            for security, gitleaks in [('1', '0'), ('0', '1')]:
                with self.subTest(security=security, gitleaks=gitleaks):
                    result = subprocess.run(['bash', '-c', 'set -Eeuo pipefail\n' + function + '\nrun_release_gates'],
                                            cwd=fixture, capture_output=True, text=True,
                                            env={'PATH': str(fixture / 'bin') + ':/usr/bin:/bin', 'RUN_SEMGREP': '0',
                                                 'SECURITY_EXIT': security, 'GITLEAKS_EXIT': gitleaks}, timeout=5)
                    self.assertNotEqual(result.returncode, 0)
                    self.assertIn('SECURITY_SEMGREP=1', result.stdout)
                    self.assertNotIn('UNEXPECTED_BUILD', result.stdout)
                    self.assertEqual('GITLEAKS_GATE' in result.stdout, security == '0')

    def test_incomplete_jobs_and_latest_failed_run_block_publication(self):
        jobs = successful_jobs(); jobs['total_count'] += 1
        self.assertTrue(execute_gate(workflows(), jobs))
        data = workflows(); later = dict(data['workflow_runs'][0], run_number=21, conclusion='failure')
        data['workflow_runs'].append(later)
        self.assertTrue(execute_gate(data, successful_jobs()))
