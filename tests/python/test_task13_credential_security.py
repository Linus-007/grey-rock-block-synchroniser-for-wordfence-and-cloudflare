"""Credential output, error and sudo-boundary regressions; no real services."""
import json
import os
from pathlib import Path
import secrets
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
FILTER = ROOT / 'scripts/redact-test-output.py'


class CredentialSecurityTests(unittest.TestCase):
    def test_command_and_log_redaction(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            values = [secrets.token_hex(24) for _ in range(3)] + ['token-quoted"and/slash']
            admin, env, token = [root / name for name in ('admin', 'env', 'token.json')]
            admin.write_text(values[0] + '\n')
            token.write_text(json.dumps({'token': values[3]}))
            env.write_text(f'ADMIN_PASSWORD_FILE={admin}\nTEST_DB_PASSWORD={values[1]}\nTEST_DB_ROOT_PASSWORD={values[2]}\nCLOUDFLARE_TEST_JSON={token}\n')
            for path in (admin, env, token): path.chmod(0o600)
            payload = ('wp core install --admin_password=' + values[0] + '\n' +
                       '\n'.join(values) + '\n' + json.dumps(values[3]) + '\n').encode()
            for code in (0, 19):
                # Command reads credentials from files; arguments contain no secret.
                child = root / 'child.py'
                child.write_text('import pathlib, sys\nsys.stdout.buffer.write(pathlib.Path(sys.argv[1]).read_bytes())\nsys.stderr.buffer.write(pathlib.Path(sys.argv[1]).read_bytes())\nraise SystemExit(int(sys.argv[2]))\n')
                data = root / 'data'; data.write_bytes(payload); data.chmod(0o600)
                result = subprocess.run(['python3', str(FILTER), '--run', str(env), '--',
                                         'python3', str(child), str(data), str(code)],
                                        capture_output=True, timeout=5)
                self.assertEqual(result.returncode, code, result.stderr)
                for value in values:
                    self.assertNotIn(value.encode(), result.stdout + result.stderr)
                self.assertIn(b'[REDACTED]', result.stdout)
                self.assertNotIn(b'Traceback', result.stderr)
            # Invalid redaction inputs must prevent execution, not merely hide it.
            env.chmod(0o644)
            marker = root / 'ran'
            result = subprocess.run(['python3', str(FILTER), '--run', str(env), '--',
                                     'touch', str(marker)], capture_output=True, timeout=5)
            self.assertNotEqual(result.returncode, 0)
            self.assertFalse(marker.exists())
            self.assertNotIn(b'Traceback', result.stderr)

    def test_install_errors_withhold_all_output(self):
        import re
        for name in ('integration-test.sh', 'integration-test-ci.sh', 'cloudflare-integration-test.sh'):
            source = (ROOT / 'scripts' / name).read_text()
            command = re.search(r"--entrypoint sh cli -c '(.*?)' sh", source, re.S).group(1)
            self.assertNotIn('core install', command)
            self.assertIn('set +x +v', source)
            with tempfile.TemporaryDirectory() as directory:
                secret = secrets.token_hex(24)
                wp = Path(directory) / 'wp'
                wp.write_text('#!/bin/sh\nprintf "%s\\n" "$FIXTURE_SECRET"\nprintf "%s\\n" "$FIXTURE_SECRET" >&2\nexit 9\n')
                wp.chmod(0o700)
                result = subprocess.run(['sh', '-c', command, 'sh', 'http://fixture.invalid'],
                    env=dict(os.environ, PATH=f'{directory}:/usr/bin:/bin', FIXTURE_SECRET=secret),
                    capture_output=True, text=True, timeout=5)
                self.assertEqual(result.returncode, 1)
                self.assertNotIn(secret, result.stdout + result.stderr)
                self.assertIn('details withheld', result.stderr)

    def test_sudo_capture_receives_only_redacted_output(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            secret = secrets.token_hex(24)
            admin = root / 'admin'; admin.write_text(secret + '\n'); admin.chmod(0o600)
            envfile = root / 'env'
            envfile.write_text(f'ADMIN_PASSWORD_FILE={admin}\nTEST_DB_PASSWORD={secret}\nTEST_DB_ROOT_PASSWORD={secret}\n')
            envfile.chmod(0o600)
            sudo = root / 'sudo'
            sudo.write_text('#!/bin/bash\nshift\n"$@" > "$FIXTURE_CAPTURE" 2>&1\nr=$?\ncat "$FIXTURE_CAPTURE"\nexit "$r"\n')
            sudo.chmod(0o700)
            emit = root / 'emit'
            emit.write_text('#!/bin/sh\ncat "$FIXTURE_ADMIN"\ncat "$FIXTURE_ADMIN" >&2\nexit 17\n')
            emit.chmod(0o700)
            capture = root / 'sudo-output'
            driver = f'source "{ROOT}/scripts/integration-harness.sh"; REPO_ROOT="{ROOT}"; RUNTIME_ENV="{envfile}"; COMPOSE_COMMAND=(sudo -n "{emit}"); compose logs'
            result = subprocess.run(['bash', '-c', driver], capture_output=True, text=True,
                env=dict(os.environ, PATH=f'{root}:/usr/bin:/bin', FIXTURE_CAPTURE=str(capture), FIXTURE_ADMIN=str(admin)), timeout=5)
            self.assertEqual(result.returncode, 17)
            self.assertNotIn(secret, result.stdout + result.stderr + capture.read_text())
            self.assertIn('[REDACTED]', capture.read_text())

    def test_redaction_precedes_privilege_output_capture(self):
        source = (ROOT / 'scripts/integration-harness.sh').read_text()
        self.assertIn('command=(python3', source)
        self.assertIn('"${privilege[@]}" timeout', source)
        self.assertNotIn('|', source.split('"${privilege[@]}" timeout', 1)[1].split('\n', 1)[0].replace('||', ''))


if __name__ == '__main__':
    unittest.main()
