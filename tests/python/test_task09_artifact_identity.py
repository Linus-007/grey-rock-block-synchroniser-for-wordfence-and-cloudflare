"""Offline adversarial tests of actual ZIP and workflow publication decisions."""
import hashlib
import importlib.util
import io
import json
import os
import re
from pathlib import Path
import stat
import subprocess
import sys
import tempfile
import textwrap
import unittest
import warnings
import zipfile

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts'))
import release_artifact as artifact

VERSION = re.search(r'^Stable tag: ([0-9]+\.[0-9]+\.[0-9]+)$',
                    (ROOT / 'readme.txt').read_text(), re.MULTILINE).group(1)


def workflow_inline(marker, terminator):
    source = (ROOT / '.github/workflows/release.yml').read_text()
    return textwrap.dedent(source.split(marker, 1)[1].split(terminator, 1)[0])


class ArtifactIdentity(unittest.TestCase):
    def archive(self, extra=None):
        buffer = io.BytesIO()
        with zipfile.ZipFile(buffer, 'w') as z:
            z.writestr(artifact.SLUG + '/', '')
            for path in sorted((ROOT / 'src').rglob('*')):
                if path.is_file(): z.writestr(artifact.SLUG + '/' + path.relative_to(ROOT / 'src').as_posix(), path.read_bytes())
            z.writestr(artifact.SLUG + '/readme.txt', (ROOT / 'readme.txt').read_bytes())
            if extra: z.writestr(*extra)
        return buffer.getvalue()

    def test_rejects_unsafe_complete_archives(self):
        symlink = zipfile.ZipInfo(artifact.SLUG + '/link'); symlink.create_system = 3
        symlink.external_attr = (stat.S_IFLNK | 0o777) << 16
        for entry in [artifact.SLUG+'/../escape', artifact.SLUG+'/./file', '/absolute', artifact.SLUG+'//file', artifact.SLUG+'/uninstall.php', symlink]:
            with self.subTest(entry=str(entry)), warnings.catch_warnings():
                warnings.simplefilter('ignore', UserWarning)
                with self.assertRaises((ValueError, KeyError)): artifact.snapshot(self.archive((entry, b'changed')), VERSION)

    def test_actual_builder_rejects_traversal_and_preserves_custom_slug(self):
        spec=importlib.util.spec_from_file_location('task09_builder',ROOT/'scripts/build-release.py')
        builder=importlib.util.module_from_spec(spec); spec.loader.exec_module(builder)
        with tempfile.TemporaryDirectory() as directory:
            archive=Path(directory)/'candidate.zip'
            archive.write_bytes(self.archive((artifact.SLUG+'/../escape',b'changed')))
            with self.assertRaises(RuntimeError): builder.verify_zip(archive,artifact.SLUG)
            builder.build_zip(ROOT/'src',archive,'custom-plugin-directory',ROOT/'readme.txt')
            builder.verify_zip(archive,'custom-plugin-directory')

    def test_replacement_receipt_is_not_independent_evidence(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory); archive = root/'candidate.zip'; receipt = root/'receipt.json'
            archive.write_bytes(self.archive()); original = artifact.create(archive, receipt, 'a'*40, VERSION)
            archive.write_bytes(self.archive((artifact.SLUG+'/replacement.txt', b'replacement')))
            artifact.create(archive, receipt, 'a'*40, VERSION)
            with self.assertRaises(ValueError): artifact.verify(archive, receipt, original, 'a'*40, VERSION)

    def test_extracted_publisher_tree_must_match_every_file(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory); archive=root/'candidate.zip'; receipt=root/'receipt.json'
            archive.write_bytes(self.archive()); expected=artifact.create(archive, receipt, 'a'*40, VERSION)
            target=artifact.extract(archive,receipt,expected,'a'*40,VERSION,root/'extract')
            artifact.verify_tree(target,receipt,expected,'a'*40,VERSION)
            (target/'unexpected.txt').write_text('extra')
            with self.assertRaises(ValueError): artifact.verify_tree(target,receipt,expected,'a'*40,VERSION)
            (target/'unexpected.txt').unlink(); (target/'uninstall.php').unlink()
            with self.assertRaises(ValueError): artifact.verify_tree(target,receipt,expected,'a'*40,VERSION)

    def test_actual_github_publish_inline_rejects_paired_replacement(self):
        code=textwrap.dedent((ROOT/'.github/workflows/release.yml').read_text().split("      - name: Verify independent producer identity before publication",1)[1].split("          python3 - <<'PYTHON'\n",1)[1].split("\n          PYTHON",1)[0])
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory); (root/'dist').mkdir(); archive=root/'dist'/f'{artifact.SLUG}.zip'; receipt=root/'dist/release-receipt.json'
            archive.write_bytes(self.archive()); receipt_sha=artifact.create(archive,receipt,'a'*40,VERSION)
            env=dict(os.environ,EXPECTED_ARCHIVE_SHA=artifact.digest(archive.read_bytes()),EXPECTED_RECEIPT_SHA=receipt_sha,EXPECTED_VERSION=VERSION,EXPECTED_COMMIT='a'*40)
            good=subprocess.run([sys.executable,'-c',code],cwd=root,env=env,capture_output=True)
            self.assertEqual(good.returncode,0,good.stderr)
            archive.write_bytes(self.archive((artifact.SLUG+'/replacement.txt',b'changed')))
            artifact.create(archive,receipt,'a'*40,VERSION)
            (root/'dist'/f'{artifact.SLUG}.zip.sha256').write_text(artifact.digest(archive.read_bytes())+'  '+f'dist/{artifact.SLUG}.zip\n')
            bad=subprocess.run([sys.executable,'-c',code],cwd=root,env=env,capture_output=True)
            self.assertNotEqual(bad.returncode,0)

    def test_actual_github_version_decision_requires_matching_tag(self):
        source=(ROOT/'.github/workflows/release.yml').read_text()
        code=textwrap.dedent(source.split('        run: |\n',1)[1].split('\n      - name:',1)[0])
        # A shell function replaces only the read-only git identity boundary.
        code='git() { printf "%s\\n" "$TEST_COMMIT"; }\n'+code
        with tempfile.TemporaryDirectory() as directory:
            env=dict(os.environ,GITHUB_EVENT_NAME='workflow_dispatch',REQUESTED_VERSION='1.3.11',GITHUB_REF_NAME='main',GITHUB_REF='refs/heads/main',GITHUB_SHA='a'*40,TEST_COMMIT='a'*40,GITHUB_OUTPUT=str(Path(directory)/'output'))
            for ref,commit,expected in [('refs/heads/main','a'*40,1),('refs/tags/v1.3.9','a'*40,1),('refs/tags/v1.3.11','b'*40,1),('refs/tags/v1.3.11','a'*40,0)]:
                env.update(GITHUB_REF=ref,TEST_COMMIT=commit)
                result=subprocess.run(['bash','-eu','-c',code],env=env,capture_output=True)
                self.assertEqual(result.returncode,expected,result.stderr)


class PublicMetadataRegression(unittest.TestCase):
    def test_flat_download_checksum_checks_actual_bytes(self):
        text = (ROOT / '.github/workflows/release.yml').read_text().split('      - name: Verify downloaded checksum', 1)[1]
        code = textwrap.dedent(text.split("          python3 - <<'PYTHON'\n", 1)[1].split('\n          PYTHON', 1)[0])
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory) / 'dist'; base.mkdir()
            name = artifact.SLUG + '.zip'; (base / name).write_bytes(b'exact downloaded bytes')
            for path in [name, 'dist/' + name]:
                (base / (name + '.sha256')).write_text(artifact.digest((base / name).read_bytes()) + '  ' + path + '\n')
                result = subprocess.run([sys.executable, '-c', code], cwd=directory, capture_output=True)
                self.assertEqual(result.returncode, 0, result.stderr)
            (base / name).write_bytes(b'replaced')
            self.assertNotEqual(subprocess.run([sys.executable, '-c', code], cwd=directory, capture_output=True).returncode, 0)

    def test_metadata_history_and_source_bound_approval(self):
        import release_metadata
        from cve_approval import parse_utc, verify_approval
        import shutil
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            shutil.copytree(ROOT / 'src', root / 'src'); shutil.copytree(ROOT / 'security', root / 'security')
            for name in ['readme.txt', 'README.md']: shutil.copyfile(ROOT / name, root / name)
            subprocess.run(['git', 'init', '-q', str(root)], check=True, capture_output=True)
            subprocess.run(['git', '-C', str(root), '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '--allow-empty', '-qm', 'Local release fixture'], check=True)
            for version in ['1.3.11','1.3.12','1.3.13']:
                subprocess.run(['git','-C',str(root),'tag','v'+version],check=True)
            self.assertEqual(release_metadata.validate(root, VERSION), VERSION)
            approved = verify_approval(root, 'CVE-2026-115560')
            self.assertEqual(approved['approved_source_sha256'],
                             '8cf764c61a9dda76f747f5f7720e981bd81ee34e4ea2ec94b860aacb18a7bfae')
            for filename, old, new in [
                ('README.md', 'badge/version-' + VERSION, 'badge/version-0.0.0'),
                ('README.md', '### 1.3.12', '### missing'),
                ('readme.txt', '= ' + VERSION + ' =', '= Unreleased ='),
                ('readme.txt', 'Requires PHP: 8.2', 'Requires PHP: 8.1'),
                ('readme.txt', 'Stable tag: ' + VERSION, 'Stable tag: 0.0.0'),
            ]:
                path = root / filename; before = path.read_text(); path.write_text(before.replace(old, new))
                with self.subTest(filename=filename, field=old), self.assertRaises(ValueError): release_metadata.validate(root, VERSION)
                path.write_text(before)
            path = root / 'src' / (artifact.SLUG + '.php')
            before = path.read_text()
            # Always change the fixture, including when VERSION is already 1.3.15.
            path.write_text(before + '\n// Unapproved source fixture.\n')
            changed = verify_approval(root, 'CVE-2026-115560')
            self.assertEqual(changed['approved_source_sha256'],
                             approved['approved_source_sha256'])
            self.assertNotEqual(changed['current_source_sha256'],
                                approved['approved_source_sha256'])
            path.write_text(before)
            with self.assertRaisesRegex(ValueError, 'expired'):
                verify_approval(root, 'CVE-2026-115560',
                                now=parse_utc(approved['expires_at_utc'], 'expires_at_utc'))
            record_path = root / 'security/cve-2026-115560-exception.json'
            record = json.loads(record_path.read_text())
            for approval, reason in [
                (None, 'approval is missing'),
                (dict(record['approval'], decision_record=None), 'decision record is missing'),
            ]:
                with self.subTest(reason=reason):
                    record_path.write_text(json.dumps(dict(record, approval=approval)))
                    with self.assertRaisesRegex(ValueError, reason):
                        verify_approval(root, 'CVE-2026-115560')
