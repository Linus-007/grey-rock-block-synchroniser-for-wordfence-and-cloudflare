"""Offline subprocess/cleanup tests. Docker, sudo and systemctl are substitutes."""
import json
import os
import re
import secrets
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class HarnessTests(unittest.TestCase):
    def run_harness(self, name, scenario):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'scripts').mkdir()
            (root / 'tests/docker').mkdir(parents=True)
            (root / 'tests/docker/compose.yml').touch()
            (root / 'dist').mkdir()
            (root / 'dist/grey-rock-block-synchroniser-for-wordfence-and-cloudflare.zip').touch()
            for script in [name, 'integration-harness.sh', 'stop-test-environment.sh', 'redact-test-output.py']:
                shutil.copy2(ROOT / 'scripts' / script, root / 'scripts' / script)
            resolver = root / 'scripts/resolve-test-session.sh'
            resolver.write_text('''#!/bin/bash
mkdir -p "$GREY_ROCK_TEST_CACHE_DIR"
printf '%s\\n' EXPECTED_PHP_VERSION=1 EXPECTED_WORDPRESS_VERSION=1 EXPECTED_WORDFENCE_VERSION=1 EXPECTED_WPCLI_VERSION=1 > "$GREY_ROCK_TEST_CACHE_DIR/session.env"
''')
            resolver.chmod(0o700)
            (root / 'bin').mkdir()
            wrapper = '''#!/usr/bin/python3
import json, os, pathlib, signal, stat, sys
root = pathlib.Path(os.environ['FIXTURE_ROOT'])
name = pathlib.Path(sys.argv[0]).name
a = sys.argv[1:]
with (root / 'argv.jsonl').open('a') as f: f.write(json.dumps([name, *a]) + '\\n')
if name == 'sudo':
    assert a.pop(0) == '-n', a
    os.environ['MOCK_SUDO'] = '1'
    os.execvp(a[0], a)
if name == 'python3' and a and a[0].endswith('redact-test-output.py'):
    os.execv(sys.executable, [sys.executable, *a])
if name in ('systemctl', 'python3'): sys.exit(0)
if name == 'curl':
    if '--write-out' in a: print('200', end='')
    sys.exit(0)
if name != 'docker': raise SystemExit('Unexpected substitute')
scenario = os.environ['SCENARIO']
if 'info' in a and scenario == 'sudo-success' and not os.environ.get('MOCK_SUDO'): sys.exit(1)
if ('pull' in a and scenario == 'stopped') or ('down' in a and scenario == 'cleanup-stopped'):
    (root / 'stopped.pid').write_text(str(os.getpid()))
    os.kill(os.getpid(), signal.SIGSTOP)
    # Continue after timeout TERM/CONT without accepting a successful test.
    sys.exit(23)
if 'down' in a and scenario == 'cleanup-fail': sys.exit(7)
if 'install' in a and scenario == 'command-fail': sys.exit(9)
if '--env-file' in a:
    assert sys.stdin.read() == '', 'Compose stdin must be detached'
    if 'run' in a or 'exec' in a: assert '--no-TTY' in a
    envfile = pathlib.Path(a[a.index('--env-file') + 1])
    fields = dict(line.split('=', 1) for line in envfile.read_text().splitlines())
    for key in ('TEST_DB_PASSWORD', 'TEST_DB_ROOT_PASSWORD'):
        assert fields[key] not in ' '.join(a)
    secretfile = pathlib.Path(fields['ADMIN_PASSWORD_FILE'])
    if secretfile.exists():
        assert stat.S_IMODE(secretfile.stat().st_mode) == 0o600
        assert stat.S_IMODE(envfile.stat().st_mode) == 0o600
        (root / 'credentials.json').write_text(json.dumps([secretfile.read_text().strip(), fields['TEST_DB_PASSWORD'], fields['TEST_DB_ROOT_PASSWORD']]))
if 'logs' in a or 'ps' in a or 'images' in a: sys.exit(0)
if 'sh' in a: sys.exit(0)
if 'version' in a or '--version' in a or '--field=version' in a or 'php' in a: print('1')
'''
            for name_ in ['sudo', 'docker', 'systemctl', 'curl', 'python3']:
                path = root / 'bin' / name_
                path.write_text(wrapper)
                path.chmod(0o700)
            env = dict(os.environ, PATH=f'{root}/bin:/usr/bin:/bin', FIXTURE_ROOT=str(root),
                       SCENARIO=scenario, GREY_ROCK_TEST_CACHE_DIR=str(root / 'cache'),
                       GREY_ROCK_COMPOSE_TIMEOUT_SECONDS='1')
            result = subprocess.run(['bash', str(root / 'scripts' / name)], env=env,
                                    capture_output=True, text=True, timeout=30)
            report = root / 'reports' / ('integration-ci' if '-ci' in name else 'integration')
            events = (root / 'argv.jsonl').read_text()
            self.assertIn('down', events, result.stderr)
            self.assertFalse(list((root / '.tools').glob('greyrock-*')), 'Temporary credentials leaked')
            self.assertTrue((report / 'cleanup.log').is_file())
            for credential in json.loads((root / 'credentials.json').read_text()):
                self.assertNotIn(credential, events)
                self.assertNotIn(credential, result.stdout + result.stderr)
                for log in report.iterdir(): self.assertNotIn(credential, log.read_text())
            if scenario in ('success', 'sudo-success'):
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertTrue((report / 'summary.txt').exists())
                self.assertIn('install-test-wordpress.php', events)
                self.assertIn('--user', events)
                for event in map(json.loads, events.splitlines()):
                    if 'eval-file' not in event:
                        continue
                    fixture = ROOT / 'tests/integration' / Path(event[-1]).name
                    if 'declare(strict_types=1)' in fixture.read_text():
                        self.assertIn('--use-include', event, fixture.name)
            else:
                self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
                self.assertFalse((report / 'summary.txt').exists())
                self.assertNotIn('RESULT: PASS', result.stdout)
            if scenario in ('stopped', 'cleanup-stopped'):
                pid = int((root / 'stopped.pid').read_text())
                with self.assertRaises(ProcessLookupError): os.kill(pid, 0)
                self.assertIn('limit 1s', (report / 'cleanup.log').read_text() + result.stderr)

    def test_both_entrypoints(self):
        for name in ['integration-test.sh', 'integration-test-ci.sh']:
            for scenario in ['success', 'command-fail', 'cleanup-fail', 'stopped', 'cleanup-stopped']:
                with self.subTest(name=name, scenario=scenario): self.run_harness(name, scenario)

    def test_ci_sudo_fallback(self):
        self.run_harness('integration-test-ci.sh', 'sudo-success')

    def test_stopped_term_ignoring_descendant_is_killed(self):
        with tempfile.TemporaryDirectory() as directory:
            pidfile = Path(directory) / 'pid'
            # TERM-ignoring child forces the kill-after path, including group kill.
            script = Path(directory) / 'child.sh'
            script.write_text(f'#!/bin/bash\ntrap "" TERM\necho $$ > "{pidfile}"\nkill -STOP $$\nwhile :; do sleep 1; done\n')
            script.chmod(0o700)
            driver = f'source "{ROOT}/scripts/integration-harness.sh"; COMPOSE_COMMAND=("{script}"); compose logs'
            result = subprocess.run(['bash', '-c', driver], env=dict(os.environ, GREY_ROCK_COMPOSE_TIMEOUT_SECONDS='1'), capture_output=True, text=True, timeout=12)
            self.assertEqual(result.returncode, 137, result.stderr)
            pid = int(pidfile.read_text())
            status = Path(f'/proc/{pid}/stat')
            self.assertTrue(not status.exists() or status.read_text().split()[2] == 'Z')

    def test_container_side_credential_redirection(self):
        for name in ['integration-test.sh', 'integration-test-ci.sh', 'cloudflare-integration-test.sh']:
            source = (ROOT / 'scripts' / name).read_text()
            self.assertNotIn('--admin_password=', source)
            command = re.search(r"--entrypoint sh cli -c '(.*?)' sh", source, re.S).group(1)
            self.assertIn('eval-file --use-include /artifacts/install-test-wordpress.php', command)
            with tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                password = secrets.token_hex(24)
                credential = root / 'credential'
                credential.write_text(password + '\n')
                credential.chmod(0o600)
                wp = root / 'wp'
                wp.write_text('#!/usr/bin/python3\nimport os, sys\nprint(os.environ["FIXTURE_SECRET"])\nprint(os.environ["FIXTURE_SECRET"], file=sys.stderr)\nsys.exit(int(os.environ.get("FIXTURE_EXIT", "0")))\n')
                wp.chmod(0o700)
                command = command.replace('/run/greyrock-admin-password', str(credential))
                result = subprocess.run(['sh', '-c', command, 'sh', 'http://fixture.invalid'],
                                        env=dict(os.environ, PATH=f'{root}:/usr/bin:/bin', FIXTURE_SECRET=password),
                                        capture_output=True, text=True, timeout=5)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertNotIn(password, result.stdout + result.stderr)
                self.assertIn('Disposable WordPress installed.', result.stdout)

    @unittest.skipUnless(shutil.which('php'), 'PHP CLI is required')
    def test_installer_include_reaches_input_and_bootstrap(self):
        # Match WP-CLI execute_eval's method scope and global positional $args.
        # No database or credential is needed: stop at controlled early stages.
        installer = ROOT / 'tests/integration/install-test-wordpress.php'
        driver = r'''class InstallerRunner {
            public static function run($file, $positional_args) {
                global $args;
                $args = $positional_args;
                include $file;
            }
        }
        InstallerRunner::run($argv[1], array_slice($argv, 2));'''
        with tempfile.TemporaryDirectory() as directory:
            driver_file = Path(directory) / 'runner.php'
            driver_file.write_text('<?php\n' + driver)
            (Path(directory) / 'wp-load.php').write_text(
                '<?php throw new RuntimeException("withheld-bootstrap-detail");')
            for args, stage in [([], 'input'), (['http://fixture.invalid'], 'bootstrap')]:
                with self.subTest(stage=stage):
                    result = subprocess.run(['php', str(driver_file), str(installer), *args],
                                            cwd=directory, capture_output=True, text=True, timeout=5)
                    self.assertEqual(result.returncode, 1)
                    self.assertEqual(result.stdout, '')
                    self.assertIn('GREYROCK_INSTALL_EXCEPTION_STAGE=' + stage, result.stderr)
                    self.assertNotIn('withheld-bootstrap-detail', result.stderr)

    def test_invalid_timeout_is_rejected_without_execution(self):
        driver = f'source "{ROOT}/scripts/integration-harness.sh"; COMPOSE_COMMAND=(false); compose ps'
        result = subprocess.run(['bash', '-c', driver], env=dict(os.environ, GREY_ROCK_COMPOSE_TIMEOUT_SECONDS='0'), capture_output=True, text=True)
        self.assertEqual(result.returncode, 2)


if __name__ == '__main__':
    unittest.main()
