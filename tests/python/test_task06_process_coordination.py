"""Independent PHP workers; file-locked SQL emulator, NOT MySQL/WordPress proof."""
from pathlib import Path
import select
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


@unittest.skipUnless(shutil.which('php'), 'PHP CLI unavailable')
class ProcessCoordination(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix='grey-rock-task06-')
        self.addCleanup(self.directory.cleanup)
        self.state = Path(self.directory.name) / 'state'
        self.clock = Path(self.directory.name) / 'clock'
        self.now = 2000000000
        self.clock.write_text(str(self.now))
        self.workers = []
        self.addCleanup(self.stop_workers)

    def stop_workers(self):
        for worker in self.workers:
            if worker.poll() is None:
                worker.kill()
            worker.wait(timeout=5)
            worker.stdin.close()
            worker.stdout.close()
            worker.stderr.close()

    def line(self, worker):
        self.assertTrue(select.select([worker.stdout], [], [], 5)[0], 'Worker barrier timed out')
        return worker.stdout.readline().strip()

    def worker(self):
        worker = subprocess.Popen(
            ['php', str(ROOT / 'tests/fixtures/task06-process-worker.php'), str(self.state), str(self.clock)],
            stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
        )
        self.workers.append(worker)
        return worker

    def command(self, worker, command):
        worker.stdin.write(command + '\n')
        worker.stdin.flush()
        return self.line(worker)

    def advance(self):
        self.now += 900
        self.clock.write_text(str(self.now))

    def test_replacement_fences_old_process_and_preserves_new_owner(self):
        first = self.worker()
        self.assertEqual(self.line(first), 'ACQUIRED')
        contender = self.worker()
        self.assertEqual(self.line(contender), 'BUSY')
        self.advance()
        replacement = self.worker()
        self.assertEqual(self.line(replacement), 'ACQUIRED')
        self.assertEqual(self.command(first, 'guard'), 'LOST')
        self.assertEqual(self.command(first, 'release'), 'RELEASED')
        third = self.worker()
        self.assertEqual(self.line(third), 'BUSY')
        self.assertEqual(self.command(replacement, 'guard'), 'LIVE')
        self.assertEqual(self.command(replacement, 'release'), 'RELEASED')

    def test_killed_worker_pending_identity_survives_takeover(self):
        first = self.worker()
        self.assertEqual(self.line(first), 'ACQUIRED')
        self.assertEqual(self.command(first, 'pending'), 'STORED')
        first.kill()
        first.wait(timeout=5)
        blocked = self.worker()
        self.assertEqual(self.line(blocked), 'BUSY')
        self.advance()
        replacement = self.worker()
        self.assertEqual(self.line(replacement), 'ACQUIRED')
        self.assertEqual(self.command(replacement, 'read'), 'process-operation')
        self.assertEqual(self.command(replacement, 'release'), 'RELEASED')
