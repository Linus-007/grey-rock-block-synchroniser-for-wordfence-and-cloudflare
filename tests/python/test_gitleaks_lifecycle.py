"""Verify the scanner receives a live snapshot, independently of downloads."""
import importlib.util
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'scripts'))
spec = importlib.util.spec_from_file_location('gitleaks_lifecycle_target', Path(__file__).resolve().parents[2] / 'scripts/gitleaks-check.py')
scanner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(scanner)


class SnapshotLifetimeTests(unittest.TestCase):
    def test_worktree_scan_occurs_before_snapshot_removal(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            binary = root / 'tool/gitleaks'
            binary.parent.mkdir()
            (binary.parent / 'manifest.json').write_text('{}')
            (root / '.tools/security-tools').mkdir(parents=True)
            (root / 'fixture.txt').write_text('offline control')
            seen = []

            def scan(binary, mode, target):
                seen.append(mode)
                if mode == 'dir':
                    self.assertTrue(target.is_dir(), 'Snapshot deleted before worktree scan')
                    self.assertEqual((target / 'fixture.txt').read_text(), 'offline control')
                return 0

            def output(command, **kwargs):
                return 'false\n' if '--is-shallow-repository' in command else b'fixture.txt\0'

            with patch.object(scanner.security_tools, 'ROOT', root), patch.object(scanner.security_tools, 'install_gitleaks', return_value=binary), patch.object(scanner.subprocess, 'check_output', side_effect=output), patch.object(scanner.subprocess, 'run') as run, patch.object(scanner, 'scan', side_effect=scan):
                run.return_value.returncode = 1
                self.assertEqual(scanner.main(), 0)
                self.assertEqual(seen, ['git', 'dir'])
                self.assertEqual(run.call_count, 1, 'Positive control ran more than once')


if __name__ == '__main__':
    unittest.main()
