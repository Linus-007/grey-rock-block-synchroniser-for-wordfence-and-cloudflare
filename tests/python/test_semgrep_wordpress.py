"""The adapter must preserve upstream rules and reject unreviewed snapshots."""
import hashlib
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'scripts'))
import semgrep_wordpress as adapter


class WordPressAdapterTests(unittest.TestCase):
    def test_only_reviewed_rule_changes_and_original_is_immutable(self):
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory)
            snapshot = base / 'upstream'
            target = snapshot / adapter.TARGET
            target.parent.mkdir(parents=True)
            content = b'rules:\n- id: echoed-request\n  # Wordpress Escapes\n  - pattern: esc_html(...)\n  - pattern: esc_attr(...)\n'
            target.write_bytes(content)
            (snapshot / 'other.yaml').write_bytes(b'rules: []\n')
            hashes = {p.relative_to(snapshot).as_posix(): hashlib.sha256(p.read_bytes()).hexdigest()
                      for p in snapshot.rglob('*') if p.is_file()}
            manifest = {'community_php_rules': {'commit': 'a' * 40, 'files': hashes}}
            with patch.object(adapter, 'UPSTREAM_SHA256', hashes[adapter.TARGET]):
                provenance = adapter.prepare(snapshot, base / 'adapted', manifest)
                changed = (base / 'adapted' / adapter.TARGET).read_bytes()
                self.assertEqual(changed.replace(adapter.SANITIZER.encode(), b''), content)
                self.assertEqual(target.read_bytes(), content)
                self.assertEqual((base / 'adapted/other.yaml').read_bytes(), b'rules: []\n')
                self.assertEqual(provenance['upstream_sha256'], hashes[adapter.TARGET])
                target.write_bytes(content + b'# tampered\n')
                with self.assertRaises(ValueError):
                    adapter.prepare(snapshot, base / 'rejected', manifest)
            target.write_bytes(content)
            with self.assertRaises(ValueError):
                adapter.prepare(snapshot, base / 'unreviewed', manifest)


if __name__ == '__main__':
    unittest.main()
