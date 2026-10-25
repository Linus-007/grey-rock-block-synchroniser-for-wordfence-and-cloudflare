"""Independent processes with file-backed SQL emulation, never MariaDB proof."""
from pathlib import Path
import select
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


@unittest.skipUnless(shutil.which('php'), 'PHP CLI missing')
class IndependentAudit(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='grey-rock-audit-')
        self.addCleanup(self.temp.cleanup)
        self.directory = Path(self.temp.name)
        self.clock = self.directory / 'clock'
        self.clock.write_text('2100000000')
        self.processes = []
        self.addCleanup(self.cleanup_processes)

    def cleanup_processes(self):
        for process in self.processes:
            if process.poll() is None:
                process.kill()
            process.wait(timeout=5)
            for stream in [process.stdin, process.stdout, process.stderr]:
                stream.close()

    def start(self, database='first'):
        process = subprocess.Popen(['php', str(ROOT / 'tests/fixtures/task07-process-worker.php'),
                                    str(self.directory / database), str(self.clock)],
                                   stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        self.processes.append(process)
        return process

    def read(self, process):
        self.assertTrue(select.select([process.stdout], [], [], 5)[0], 'Process barrier timed out')
        return process.stdout.readline().strip()

    def send(self, process, command):
        process.stdin.write(command + '\n'); process.stdin.flush()
        return self.read(process)

    def test_kill_preserves_unknown_and_replacement(self):
        first = self.start(); self.assertEqual(self.read(first), 'ACQUIRED')
        self.assertEqual(self.send(first, 'unknown'), 'STORED')
        first.kill(); first.wait(timeout=5)
        denied = self.start(); self.assertEqual(self.read(denied), 'BUSY')
        self.clock.write_text('2100000900')
        replacement = self.start(); self.assertEqual(self.read(replacement), 'ACQUIRED')
        self.assertEqual(self.send(replacement, 'state'), 'unknown')
        self.assertEqual(self.send(replacement, 'release'), 'RELEASED')

    def test_independent_databases_do_not_coordinate(self):
        first = self.start('installation-one'); second = self.start('installation-two')
        self.assertEqual(self.read(first), 'ACQUIRED')
        self.assertEqual(self.read(second), 'ACQUIRED')
        self.assertEqual(self.send(first, 'guard'), 'LIVE')
        self.assertEqual(self.send(second, 'guard'), 'LIVE')
        self.assertEqual(self.send(first, 'release'), 'RELEASED')
        self.assertEqual(self.send(second, 'release'), 'RELEASED')
