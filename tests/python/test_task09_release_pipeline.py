"""Actual shell entry/decision flow; deny all external writes and socket access."""
import importlib.util
import json
import os
import re
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
FIXTURES = ROOT / 'tests/fixtures'
VERSION = re.search(r'^Stable tag: ([0-9]+\.[0-9]+\.[0-9]+)$',
                    (ROOT / 'readme.txt').read_text(), re.MULTILINE).group(1)
sys.path.insert(0, str(ROOT / 'scripts'))
import action_pins
import release_artifact as artifact


class FullOfflinePublisher(unittest.TestCase):
    def run_case(self, scenario):
        with tempfile.TemporaryDirectory(prefix='grey-rock-release-offline-') as directory:
            root = Path(directory)
            for name in ['scripts', 'security', 'src', '.github', 'tests/docker']:
                shutil.copytree(ROOT / name, root / name)
            shutil.copyfile(ROOT / 'readme.txt', root / 'readme.txt')
            shutil.copyfile(ROOT / 'README.md', root / 'README.md')
            shutil.copyfile(ROOT / 'Makefile', root / 'Makefile')
            (root / '.git').mkdir(); (root / 'dist').mkdir(); (root / 'site').mkdir()
            (root / 'vendor/bin').mkdir(parents=True)
            (root / 'working/.svn').mkdir(parents=True)
            for name in ['trunk', 'tags', 'assets']: (root / 'working' / name).mkdir()
            (root / 'svn.env').write_text(f'WPORG_SVN_USERNAME=fixture\nWPORG_SVN_CONFIG_DIR={root}/config\nWPORG_SVN_WORKING_COPY={root}/working\nWPORG_SVN_URL=https://plugins.svn.wordpress.org/{artifact.SLUG}\n')
            (root / 'svn.env').chmod(0o600)
            # Synthetic passing evidence exists only inside this throwaway mock.
            catalog = json.loads((root / 'security/release-gates.json').read_text())
            for record in catalog.values(): record.update(status='verified', evidence='OFFLINE SIMULATION ONLY')
            (root / 'security/release-gates.json').write_text(json.dumps(catalog))
            # Publication-flow unit tests mock the external evidence boundary.
            # Real verifier rejection/acceptance is covered by Task 15 tests.
            # Keep the actual authenticated API, manifest and artifact verifier.
            with (root / 'scripts/release_evidence.py').open('a') as stream:
                stream.write("\n_original_verify = verify\ndef verify():\n    if (ROOT / 'late-evidence-revoked').exists(): raise ValueError('Evidence revoked')\n    return _original_verify()\n")
            import zipfile, importlib.util
            spec = importlib.util.spec_from_file_location('fixture_build', ROOT / 'scripts/build-release.py')
            builder = importlib.util.module_from_spec(spec); spec.loader.exec_module(builder)
            producer = root / (artifact.SLUG + '.zip')
            builder.build_zip(root / 'src', producer, artifact.SLUG, root / 'readme.txt')
            (root / 'producer.zip').write_bytes(producer.read_bytes())
            artifact.create(producer, root / 'release-receipt.json', 'a' * 40, VERSION)
            (root / (artifact.SLUG + '.zip.sha256')).write_text(artifact.digest(producer.read_bytes()) + '  dist/' + artifact.SLUG + '.zip\n')
            # Mutate both producer and public copies so byte equality alone
            # cannot satisfy the checksum or receipt validation gates.
            if scenario == 'producer_bad_checksum':
                (root / (artifact.SLUG + '.zip.sha256')).write_text('0' * 64 + '  dist/' + artifact.SLUG + '.zip\n')
            if scenario in {'receipt_wrong_commit', 'receipt_wrong_version', 'receipt_wrong_entries'}:
                receipt_path = root / 'release-receipt.json'
                receipt = json.loads(receipt_path.read_text())
                if scenario == 'receipt_wrong_commit': receipt['commit'] = 'b' * 40
                elif scenario == 'receipt_wrong_version': receipt['version'] = '0.0.0'
                else: receipt['entries'] = []
                receipt_path.write_text(json.dumps(receipt))
            with zipfile.ZipFile(root / 'bundle.zip', 'w') as bundle:
                for name in [artifact.SLUG + '.zip', artifact.SLUG + '.zip.sha256', 'release-receipt.json']:
                    bundle.write(root / name, name)
            (root / 'site/sitecustomize.py').write_text('from task09_release_backend import install\ninstall()\n')
            wrapper = f'''#!{sys.executable}
import sys, runpy
from pathlib import Path
from task09_release_backend import command
name = Path(sys.argv[0]).name
if name == 'python3':
    args = sys.argv[1:]
    if args[0] == '-':
        code = sys.stdin.read(); sys.argv = ['-', *args[1:]]; exec(compile(code, 'actual-publication-inline', 'exec'), {{'__name__': '__main__'}})
    elif args[0] == '-c':
        code = args[1]; sys.argv = ['-c', *args[2:]]; exec(code, {{'__name__': '__main__'}})
    else:
        sys.path.insert(0, str(Path(args[0]).resolve().parent)); sys.argv = args; runpy.run_path(args[0], run_name='__main__')
else: sys.exit(command(name, sys.argv[1:]))
'''
            (root / 'bin').mkdir()
            for name in ['python3', 'git', 'svn', 'make', 'php', 'composer', 'sudo', 'systemctl', 'curl', 'docker']:
                path = root / 'bin' / name; path.write_text(wrapper); path.chmod(0o700)
            def executable(path, command):
                path.write_text(f'#!/bin/bash\nexec "{root}/bin/python3" "{root}/dispatch.py" {command}\n'); path.chmod(0o700)
            (root / 'dispatch.py').write_text('import sys\nfrom task09_release_backend import command\nraise SystemExit(command(sys.argv[1], sys.argv[2:]))\n')
            for path, command in [('scripts/semgrep-check.sh','semgrep')]: executable(root / path, command)
            (root / 'scripts/gitleaks-check.py').write_text('from task09_release_backend import command\nraise SystemExit(command("gitleaks", []))\n')
            (root / 'vendor/bin/phpcs').write_text('#!/bin/bash\nexit 0\n'); (root / 'vendor/bin/phpcs').chmod(0o700)
            requested = '1.3.9' if scenario == 'requested_version' else VERSION
            # Only the external SVN mutation adapter and interactive consent are
            # replaced. Actual main, gates, remote identity, freeze/ZIP/extract
            # decisions execute from the unmodified copied production script.
            driver = f'''set -Eeuo pipefail
source "{root}/scripts/release-plugin.sh"
REPO_ROOT="{root}"
RELEASE_CONFIG="{root}/svn.env"
confirm_publication() {{ python3 "{root}/dispatch.py" confirm; }}
commit_wordpress_release() {{ python3 "{root}/dispatch.py" simulated-publisher "$PUBLICATION_SOURCE" "$CANDIDATE_RECEIPT" "$CANDIDATE_RECEIPT_SHA" "$CANDIDATE_COMMIT" "$VERSION"; }}
release_main publish {requested}
'''
            env = dict(os.environ, PATH=str(root / 'bin') + ':/usr/bin:/bin',
                       PYTHONPATH=str(root / 'site') + os.pathsep + str(FIXTURES), TASK09_FIXTURE_ROOT=str(root), TASK09_SCENARIO=scenario, TASK09_VERSION=VERSION, GH_TOKEN='offline-placeholder', GREY_ROCK_RELEASE_RUN_ID='4', GREY_ROCK_RELEASE_RUN_ATTEMPT='1')
            if scenario == 'missing_auth':
                env.pop('GH_TOKEN', None); env.pop('GITHUB_TOKEN', None)
            result = subprocess.run(['bash', '-c', driver], cwd=root, env=env, capture_output=True, text=True, timeout=25)
            events = [json.loads(line) for line in (root / 'events.jsonl').read_text().splitlines()] if (root / 'events.jsonl').exists() else []
            self.assertFalse([e for e in events if e.get('name') in {'make','semgrep','gitleaks','integration','provider','docker','sudo'}], 'SVN-only path reran validation')
            self.assertFalse([e for e in events if e['effect'] == 'FORBIDDEN'], 'A forbidden real-write/service primitive was requested')
            submitted = [e for e in events if e['effect'] == 'SIMULATED_SUBMISSION']
            if scenario == 'valid':
                self.assertEqual(result.returncode, 0, result.stdout[-1800:] + result.stderr[-1800:])
                self.assertEqual(len(submitted), 1)
                with __import__('zipfile').ZipFile(root / 'producer.zip') as z:
                    expected = {n[len(artifact.SLUG)+1:]: z.read(n) for n in z.namelist() if not n.endswith('/')}
                actual = {p.relative_to(root / 'simulated-submission').as_posix(): p.read_bytes() for p in (root / 'simulated-submission').rglob('*') if p.is_file()}
                self.assertEqual(actual, expected, 'Complete simulated publisher payload differs from independently captured producer')
            else:
                self.assertNotEqual(result.returncode, 0, 'A failed mandatory gate was accepted')
                self.assertFalse(submitted, 'Publication attempted after a mandatory failure')
                errors = {
                    'artifact_http_415': 'HTTP Error 415',
                    'artifact_http_404': 'HTTP Error 404',
                    'asset_http_415': 'HTTP Error 415',
                    'artifact_json_error': 'Immutable producer artifact digest mismatch',
                    'artifact_wrong_run': 'Artifact belongs to another source or run',
                    'artifact_wrong_source': 'Artifact belongs to another source or run',
                    'artifact_missing_digest': 'Independent GitHub artifact digest unavailable',
                    'producer_bad_checksum': 'Downloaded checksum mismatch',
                    'receipt_wrong_commit': 'Candidate commit/tag/version mismatch',
                    'receipt_wrong_version': 'Candidate commit/tag/version mismatch',
                    'receipt_wrong_entries': 'Complete ZIP contents differ from validated snapshot',
                }
                if scenario in errors:
                    self.assertIn(errors[scenario], result.stderr)
            return result.returncode

    def test_complete_offline_decision_and_payload(self):
        self.run_case('valid')

    def test_transport_and_identity_failures_prohibit_publication(self):
        for scenario in ['artifact_http_415', 'artifact_http_404', 'artifact_json_error',
                         'asset_http_415', 'artifact_wrong_run', 'artifact_wrong_source',
                         'artifact_missing_digest', 'producer_bad_checksum',
                         'receipt_wrong_commit', 'receipt_wrong_version', 'receipt_wrong_entries']:
            with self.subTest(scenario=scenario): self.run_case(scenario)

    def test_all_failure_boundaries_prohibit_publication(self):
        for scenario in ['missing_auth','missing_check','missing_security','failed_check','commit_mismatch','wrong_tag','workflow_identity','requested_version','release_version','missing_artifact','checksum_mismatch','zip_mismatch','paired_replacement','stale_artifact','late_gate_failure','svn_origin','svn_unavailable','bundle_mismatch','expired_artifact']:
            with self.subTest(scenario=scenario): self.run_case(scenario)


class ArtifactAndPinPolicy(unittest.TestCase):
    def test_all_remote_references_have_reviewed_pins(self):
        errors, count = action_pins.validate(ROOT)
        self.assertEqual(errors, []); self.assertGreaterEqual(count, 13)

    def test_mutable_or_unknown_sha_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory); (root / '.github/workflows').mkdir(parents=True); (root / 'security').mkdir()
            shutil.copyfile(ROOT / 'security/action-pins.json', root / 'security/action-pins.json')
            for reference in ['actions/checkout@v6', 'actions/checkout@'+'0'*40, 'docker://image:latest']:
                (root / '.github/workflows/test.yaml').write_text('steps: [{ uses: "'+reference+'" }]\n')
                self.assertTrue(action_pins.validate(root)[0])


class LocalSvnTransactions(unittest.TestCase):
    """Exercise the production SVN adapter against file:// repositories only."""
    def setUp(self):
        if not shutil.which('svnadmin'):
            self.skipTest('Local SVN fixture requires svnadmin')
        self.temporary = tempfile.TemporaryDirectory(prefix='greyrock-local-svn-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.repo = self.root / 'repository'
        self.wc = self.root / 'working'
        self.url = self.repo.as_uri()
        self.run_command(['svnadmin', 'create', str(self.repo)])
        self.run_command(['svn', 'mkdir', self.url + '/trunk', self.url + '/tags', self.url + '/assets', '-m', 'Fixture only'])
        self.run_command(['svn', 'checkout', self.url, str(self.wc)])
        # Track an obsolete file to prove scheduled deletions and additions.
        (self.wc / 'trunk/obsolete.txt').write_text('old')
        self.run_command(['svn', 'add', str(self.wc / 'trunk/obsolete.txt')])
        self.run_command(['svn', 'commit', str(self.wc), '-m', 'Fixture old file'])
        self.archive = self.root / 'candidate.zip'
        self.receipt = self.root / 'receipt.json'
        self.archive.write_bytes(__import__('test_task09_artifact_identity').ArtifactIdentity().archive())
        self.digest = artifact.create(self.archive, self.receipt, 'a' * 40, VERSION)
        self.source = artifact.extract(self.archive, self.receipt, self.digest, 'a' * 40, VERSION, self.root / 'extracted')
        self.state = self.root / 'state'; self.state.mkdir()
        (self.state / 'github-evidence.json').write_text(json.dumps({'commit': 'a' * 40, 'version': VERSION}))
        self.bin = self.root / 'bin'; self.bin.mkdir()
        # Only the local repository URL and local paths can reach real svn.
        (self.bin / 'svn').write_text('''#!/usr/bin/env python3
import os, subprocess, sys
from pathlib import Path
args = sys.argv[1:]
root = os.environ['LOCAL_SVN_ROOT']
if any('://' in a and not a.startswith('file://' + root + '/') for a in args):
    raise SystemExit('Fixture denies external SVN destinations')
with open(root + '/commands.log', 'a') as log: log.write(' '.join(args) + '\\n')
mode = os.environ.get('LOCAL_SVN_FAILURE')
if mode == 'commit' and args[0] == 'commit': raise SystemExit(42)
if mode == 'export' and args[0] == 'export': raise SystemExit(43)
raise SystemExit(subprocess.call(['/usr/bin/svn', *args]))
''')
        (self.bin / 'svn').chmod(0o700)

    def run_command(self, args):
        return subprocess.run(args, check=True, capture_output=True, text=True).stdout

    def shell(self, operation, failure=''):
        script = f'''set -Eeuo pipefail
source "{ROOT}/scripts/release-plugin.sh"
cd "{ROOT}"
VERSION={VERSION}
WPORG_SVN_WORKING_COPY="{self.wc}"
WPORG_SVN_URL="{self.url}"
EXPECTED_SVN_URL="{self.url}"
WPORG_SVN_CONFIG_DIR="{self.root}/config"
WPORG_SVN_USERNAME=fixture
PUBLICATION_SOURCE="{self.source}"
CANDIDATE_ARCHIVE="{self.archive}"
CANDIDATE_RECEIPT="{self.receipt}"
CANDIDATE_RECEIPT_SHA={self.digest}
CANDIDATE_COMMIT={'a' * 40}
STATE_DIR="{self.state}"
TEMP_DIR="{self.root}/remote"
mkdir -p "$TEMP_DIR"
verify_release_readiness() {{ :; }}
verify_release_candidate() {{ :; }}
{operation}
'''
        return subprocess.run(['bash', '-c', script], capture_output=True, text=True, timeout=20,
            env=dict(os.environ, PATH=str(self.bin) + ':/usr/bin:/bin', LOCAL_SVN_ROOT=str(self.root), LOCAL_SVN_FAILURE=failure))

    def test_commit_exact_tree_and_idempotent_remote_verification(self):
        result = self.shell('commit_wordpress_release')
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertFalse((self.wc / 'trunk/obsolete.txt').exists())
        receipt = json.loads((self.state / 'svn-verified.json').read_text())
        self.assertEqual(receipt['svn_revision'], 3)
        # Existing tags prohibit a new commit; verification alone can resume.
        denied = self.shell('commit_wordpress_release')
        self.assertNotEqual(denied.returncode, 0)
        shutil.rmtree(self.root / 'remote')
        verified = self.shell('verify_remote_svn')
        self.assertEqual(verified.returncode, 0, verified.stderr)
        self.assertEqual(self.run_command(['svnlook', 'youngest', str(self.repo)]).strip(), '3')
        # An ambiguous commit may leave a dirty WC. Read-only remote recovery
        # must still work, while publishing must continue to reject dirt.
        (self.wc / 'assets/operator-note.txt').write_text('preserve')
        (self.root / 'svn.env').write_text('# Fixture variables already assigned\n')
        shutil.rmtree(self.root / 'remote')
        recovered_dirty = self.shell(f'MODE=remote-verify\nRELEASE_CONFIG="{self.root}/svn.env"\nload_and_verify_svn_configuration\nverify_remote_svn')
        self.assertEqual(recovered_dirty.returncode, 0, recovered_dirty.stderr)
        self.assertEqual((self.wc / 'assets/operator-note.txt').read_text(), 'preserve')

    def test_transaction_failure_and_post_commit_recovery(self):
        failed = self.shell('commit_wordpress_release', 'commit')
        self.assertNotEqual(failed.returncode, 0)
        self.assertFalse((self.state / 'svn-committed.json').exists())
        self.assertEqual(self.run_command(['svnlook', 'youngest', str(self.repo)]).strip(), '2')
        self.run_command(['svn', 'revert', '-R', str(self.wc)])
        for path in [self.wc / 'tags' / VERSION]:
            if path.exists(): shutil.rmtree(path)
        # Clean unversioned additions left by uncertain transaction deliberately.
        for path in (self.wc / 'trunk').iterdir():
            if path.name != 'obsolete.txt':
                shutil.rmtree(path) if path.is_dir() else path.unlink()
        result = self.shell('commit_wordpress_release', 'export')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('POST_COMMIT_VERIFICATION_FAILED', result.stderr)
        self.assertTrue((self.state / 'svn-committed.json').exists())
        self.assertFalse((self.state / 'svn-verified.json').exists())
        recovered = self.shell('verify_remote_svn')
        self.assertEqual(recovered.returncode, 0, recovered.stderr)
        self.assertEqual(self.run_command(['svnlook', 'youngest', str(self.repo)]).strip(), '3')

    def test_dirty_tree_and_wrong_destination_block_before_commit(self):
        (self.wc / 'assets/user.txt').write_text('preserve')
        result = self.shell('commit_wordpress_release')
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual((self.wc / 'assets/user.txt').read_text(), 'preserve')
        self.assertEqual(self.run_command(['svnlook', 'youngest', str(self.repo)]).strip(), '2')

    def test_translation_properties_fail_before_release_commit(self):
        self.run_command(['svn', 'update', str(self.wc)])
        self.run_command(['svn', 'propset', 'svn:keywords', 'Id', str(self.wc / 'trunk/obsolete.txt')])
        self.run_command(['svn', 'commit', str(self.wc), '-m', 'Fixture property'])
        self.run_command(['svn', 'update', str(self.wc)])
        # A tree property survives directory synchronisation; externals could
        # otherwise change what the resulting remote release means.
        self.run_command(['svn', 'propset', 'svn:externals', '^/assets external-assets', str(self.wc / 'trunk')])
        self.run_command(['svn', 'commit', str(self.wc), '-m', 'Fixture externals'])
        before = self.run_command(['svnlook', 'youngest', str(self.repo)]).strip()
        result = self.shell('commit_wordpress_release')
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(self.run_command(['svnlook', 'youngest', str(self.repo)]).strip(), before)
