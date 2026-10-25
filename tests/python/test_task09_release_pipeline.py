"""Actual shell entry/decision flow; deny all external writes and socket access."""
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
FIXTURES = ROOT / 'tests/fixtures'
sys.path.insert(0, str(ROOT / 'scripts'))
import action_pins
import release_artifact as artifact


class FullOfflinePublisher(unittest.TestCase):
    def run_case(self, scenario):
        with tempfile.TemporaryDirectory(prefix='grey-rock-release-offline-') as directory:
            root = Path(directory)
            for name in ['scripts', 'security', 'src', '.github']:
                shutil.copytree(ROOT / name, root / name)
            shutil.copyfile(ROOT / 'readme.txt', root / 'readme.txt')
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
            (root / 'scripts/release_evidence.py').write_text(
                'import os\n'
                'from pathlib import Path\n'
                'def verify():\n'
                '    root = Path(os.environ["TASK09_FIXTURE_ROOT"])\n'
                '    if (root / "late-evidence-revoked").exists():\n'
                '        raise ValueError("Independent release evidence revoked after confirmation")\n'
                '    return {"fixture": "OFFLINE SIMULATION ONLY"}\n')
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
            for path, command in [('scripts/semgrep-check.sh','semgrep'), ('scripts/integration-test.sh','integration'), ('scripts/cloudflare-integration-test.sh','provider')]: executable(root / path, command)
            (root / 'scripts/gitleaks-check.py').write_text('from task09_release_backend import command\nraise SystemExit(command("gitleaks", []))\n')
            (root / 'vendor/bin/phpcs').write_text('#!/bin/bash\nexit 0\n'); (root / 'vendor/bin/phpcs').chmod(0o700)
            requested = '1.3.9' if scenario == 'requested_version' else '1.3.10'
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
                       PYTHONPATH=str(root / 'site') + os.pathsep + str(FIXTURES), TASK09_FIXTURE_ROOT=str(root), TASK09_SCENARIO=scenario)
            result = subprocess.run(['bash', '-c', driver], cwd=root, env=env, capture_output=True, text=True, timeout=25)
            events = [json.loads(line) for line in (root / 'events.jsonl').read_text().splitlines()] if (root / 'events.jsonl').exists() else []
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
            return result.returncode

    def test_complete_offline_decision_and_payload(self):
        self.run_case('valid')

    def test_all_failure_boundaries_prohibit_publication(self):
        for scenario in ['atomic_unresolved','missing_security','failed_check','semgrep_failure','gitleaks_failure','incomplete_audit','integration_failure','provider_failure','commit_mismatch','wrong_tag','workflow_identity','requested_version','release_version','missing_local_artifact','missing_artifact','checksum_mismatch','zip_mismatch','paired_replacement','stale_artifact','replaced_local','replaced_receipt','late_gate_failure','svn_origin','svn_unavailable']:
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
