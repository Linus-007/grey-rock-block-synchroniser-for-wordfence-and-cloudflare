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
sys.path.insert(0, str(ROOT / 'scripts'))
COMMIT = 'a' * 40


def execute_gate(payload, jobs=None):
    sys.path.insert(0, str(ROOT / 'scripts'))
    import release_evidence as evidence
    def get(api, path):
        if '/jobs' in path:
            import re
            run = int(re.search(r'/runs/(\d+)', path)[1])
            wanted = list(evidence.MAIN_WORKFLOWS.values())[run - 1][1]
            selected = [dict(j, run_id=run) for j in jobs['jobs'] if j['name'] in wanted]
            return {'total_count': len(selected) + (jobs['total_count'] - len(jobs['jobs'])), 'jobs': selected}
        return payload
    with patch.dict('os.environ', {'GH_TOKEN': 'offline'}), patch.object(evidence.GitHub, 'get', get):
        try:
            evidence.verify_main(evidence.REPOSITORY, COMMIT)
        except (ValueError, SystemExit):
            return True
    return False


def workflows():
    return {'total_count': 3, 'workflow_runs': [
        {'id': i, 'name': name, 'path': '.github/workflows/' + path, 'head_sha': COMMIT,
         'head_branch': 'main', 'event': 'push', 'run_number': 20, 'run_attempt': 1,
         'status': 'completed', 'conclusion': 'success'}
        for i, (name, path) in enumerate([
            ('Application Security', 'security.yml'), ('Continuous Integration', 'ci.yml'),
            ('WordPress Integration', 'integration.yml')], start=1)]}


def successful_jobs():
    names = ['WordPress security checks', 'Semgrep CE SAST', 'Gitleaks secret scan', 'PHP compatibility', 'Mandatory real InnoDB concurrency', 'runtime-policy', *[f'Security runtime ({v})' for v in ['8.2','8.3','8.4','8.5']]]
    return {'total_count': len(names), 'jobs': [{'id': i + 10, 'name': name, 'status': 'completed', 'conclusion': 'success', 'head_sha': COMMIT} for i, name in enumerate(names)]}


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
        later['id'] = 99; data['workflow_runs'].append(later); data['total_count'] = 4
        self.assertTrue(execute_gate(data, successful_jobs()))


class AuthenticatedManifestRegression(unittest.TestCase):
    def test_each_of_six_integration_jobs_is_mandatory(self):
        sys.path.insert(0, str(ROOT / 'scripts'))
        import release_evidence as evidence
        self.assertEqual(len(evidence.INTEGRATION_JOBS), 6)
        for name in evidence.INTEGRATION_JOBS:
            jobs = successful_jobs()
            jobs['jobs'] = [j for j in jobs['jobs'] if j['name'] != name]
            jobs['total_count'] = len(jobs['jobs'])
            with self.subTest(missing=name): self.assertTrue(execute_gate(workflows(), jobs))
        for field, value in [('head_sha', 'b'*40), ('conclusion', 'skipped')]:
            jobs = successful_jobs(); jobs['jobs'][-1][field] = value
            self.assertTrue(execute_gate(workflows(), jobs))

    def test_authentication_and_pagination(self):
        import release_evidence as evidence
        with patch.dict('os.environ', {}, clear=True), self.assertRaises(ValueError): evidence.GitHub()
        with patch.dict('os.environ', {'GH_TOKEN': 'fixture'}, clear=True): api = evidence.GitHub()
        rows = [{'id': i} for i in range(1, 102)]
        responses = [{'total_count': 101, 'jobs': rows[:100]}, {'total_count': 101, 'jobs': rows[100:]}]
        with patch.object(api, 'get', side_effect=responses) as get:
            self.assertEqual(api.pages('/fixture', 'jobs'), rows)
            self.assertIn('page=2', get.call_args.args[0])
        for bad in [
            [{'total_count': 101, 'jobs': rows[:99]}],
            [responses[0], {'total_count': 102, 'jobs': rows[100:]}],
            [responses[0], {'total_count': 101, 'jobs': [rows[0]]}],
            [{'total_count': 0, 'jobs': rows[:1]}],
        ]:
            with patch.object(api, 'get', side_effect=bad), self.assertRaises(ValueError): api.pages('/fixture', 'jobs')


class TagFailureRegression(unittest.TestCase):
    def test_prerequisite_failures_cannot_create_or_push_tags(self):
        import os
        import shutil
        target = (ROOT / 'Makefile').read_text().split('tag-release: version-check', 1)[1].split('\nclean:', 1)[0]
        with tempfile.TemporaryDirectory(prefix='greyrock-tag-denial-') as directory:
            root = Path(directory); (root / 'bin').mkdir()
            (root / 'Makefile').write_text('tag-release: version-check' + target + '\nversion-check:\n\t@true\n')
            (root / 'bin/git').write_text('''#!/bin/bash
set -eu
printf '%s\\n' "$*" >> "$FIXTURE_LOG"
case "$1" in
 status) exit 0;;
 branch) echo main;;
 remote) echo git@github.com:Linus-007/grey-rock-block-synchroniser-for-wordfence-and-cloudflare.git;;
 rev-parse) [[ "$FIXTURE_CASE" == local-tag ]] && exit 0; exit 1;;
 ls-remote) [[ "$FIXTURE_CASE" == remote-error ]] && exit 42; [[ "$FIXTURE_CASE" == remote-tag ]] && echo existing; exit 0;;
 *) echo FORBIDDEN >> "$FIXTURE_LOG"; exit 97;;
esac
''')
            (root / 'bin/python3').write_text('#!/bin/bash\nprintf "evidence-failed\\n" >> "$FIXTURE_LOG"\nexit 41\n')
            for path in (root / 'bin').iterdir(): path.chmod(0o700)
            for scenario in ['local-tag', 'remote-tag', 'remote-error', 'missing-evidence']:
                log = root / (scenario + '.log')
                result = subprocess.run([shutil.which('make'), 'tag-release', 'VERSION=1.3.14'], cwd=root,
                    env=dict(os.environ, PATH=str(root / 'bin') + ':/usr/bin:/bin', FIXTURE_CASE=scenario, FIXTURE_LOG=str(log)), capture_output=True, text=True)
                with self.subTest(scenario=scenario):
                    self.assertNotEqual(result.returncode, 0)
                    self.assertNotIn('FORBIDDEN', log.read_text())
                    self.assertNotIn('tag -a', log.read_text())
                    self.assertNotIn('push ', log.read_text())


class NoninteractiveAuthorization(unittest.TestCase):
    def test_exact_version_and_destination_required(self):
        import os
        url = 'https://plugins.svn.wordpress.org/grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        for authorization, expected in [('', 1), ('PUBLISH 1.3.13 TO ' + url, 1), ('PUBLISH 1.3.14 TO https://example.invalid', 1), ('PUBLISH 1.3.14 TO ' + url, 0)]:
            result = subprocess.run(['bash', '-c', f'source "{ROOT}/scripts/release-plugin.sh"\nVERSION=1.3.14\nconfirm_publication'],
                env=dict(os.environ, GREY_ROCK_PUBLISH_AUTHORIZATION=authorization), stdin=subprocess.DEVNULL, capture_output=True)
            self.assertEqual(result.returncode, expected)
