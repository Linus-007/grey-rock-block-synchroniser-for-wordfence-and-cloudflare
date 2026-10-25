"""Offline regression coverage for PHP selection and workflow enforcement."""
import importlib.util
from pathlib import Path
import unittest
import os
import subprocess
import tempfile
import textwrap

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('php_runtime', ROOT / 'scripts/php-runtime.py')
runtime = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runtime)


class RuntimeTests(unittest.TestCase):
    def test_numeric_order_and_future_stable(self):
        self.assertEqual(runtime.latest_stable({'version': '8.2.35', 'supported_versions': ['8.2']}), '8.2.35')
        versions = ['8.5.9', '8.5.12', '8.6.0RC3', '8.6.0', '9.0.0beta1']
        self.assertEqual(runtime.latest_stable({v: {'version': v} for v in versions}), '8.6.0')
        self.assertEqual(runtime.latest_stable({'a': {'version': '8.99.99'}, 'b': {'version': '9.0.0'}}), '9.0.0')

    def test_all_prerelease_forms_excluded(self):
        for suffix in ['dev', '-dev', 'alpha1', 'beta2', 'RC1', 'rc3', '-RC1', '+build', '\n']:
            with self.subTest(suffix=suffix), self.assertRaises(ValueError):
                runtime.latest_stable({'test': {'version': '9.0.0' + suffix}})

    def test_invalid_payload_fails_closed(self):
        for payload in [[], {}, {'x': None}, {'x': {'version': 'latest'}}, {'x': {'version': 80512}}]:
            with self.subTest(payload=payload), self.assertRaises(ValueError):
                runtime.latest_stable(payload)

    def test_runtime_enforcement(self):
        runtime.verify_runtime('8.5.4', '8.5.12')  # Branch compatibility is not a security verdict.
        runtime.verify_runtime('8.6.0', '8.6.0', exact=True)
        for actual, expected in [('8.4.27', '8.5.12'), ('8.6.0RC1', '8.6.0'), ('8.5.11', '8.5.12')]:
            with self.subTest(actual=actual), self.assertRaises(ValueError):
                runtime.verify_runtime(actual, expected, exact=True)

    def test_session_refresh_and_network_failure(self):
        with tempfile.TemporaryDirectory() as directory:
            folder = Path(directory)
            session = folder / 'session.env'
            session.write_text('EXPECTED_PHP_VERSION=8.3.1\n')
            sentinel = folder / 'sentinel'
            sentinel.write_text('unchanged')
            (folder / 'session.tmp').symlink_to(sentinel)
            # Stub only remote responses; execute the real shell/Python resolver.
            (folder / 'sitecustomize.py').write_text(textwrap.dedent("""
                import io, json, os, urllib.request
                def response(request, **kwargs):
                    if os.environ.get('FAIL_RESOLUTION'):
                        raise OSError('offline fixture')
                    url = request.full_url
                    if 'php.net' in url:
                        branch = os.environ.get('PHP_TEST_BRANCH', '8.5')
                        data = {'version': branch + '.99', 'supported_versions': ['8.2', '8.3', '8.4', '8.5']}
                    elif 'version-check' in url:
                        data = {'offers': [{'current': '7.0', 'response': 'latest'}]}
                    elif 'plugins/info' in url:
                        data = {'version': os.environ.get('FIXTURE_WORDFENCE_VERSION', '9.0.0')}
                    else:
                        data = {'tag_name': 'v2.12.0'}
                    return io.BytesIO(json.dumps(data).encode())
                urllib.request.urlopen = response
            """))
            env = dict(os.environ, PYTHONPATH=directory, GREY_ROCK_TEST_CACHE_DIR=directory, PHP_TEST_BRANCH="8.5")
            env.pop('EXPECTED_PHP_VERSION', None)
            command = ['bash', str(ROOT / 'scripts/resolve-test-session.sh')]
            result = subprocess.run(command, env=env, capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            fresh = session.read_text()
            self.assertEqual(sentinel.read_text(), 'unchanged')
            self.assertIn('EXPECTED_PHP_VERSION=8.5.99', fresh)
            self.assertIn('WORDPRESS_IMAGE=wordpress:php8.5-apache', fresh)
            self.assertIn('WPCLI_IMAGE=wordpress:cli-php8.5', fresh)
            self.assertEqual(session.stat().st_mode & 0o777, 0o600)
            for extra in [
                {'FAIL_RESOLUTION': '1'},
                {'EXPECTED_PHP_VERSION': '8.5.11'},
                {'FIXTURE_WORDFENCE_VERSION': '9.0.0RC1'},
                {'FIXTURE_WORDFENCE_VERSION': '9.0.0; printf unsafe'},
                {'FIXTURE_WORDFENCE_VERSION': '9.0.0\nUNEXPECTED=value'},
            ]:
                result = subprocess.run(command, env=dict(env, **extra), capture_output=True, text=True)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(session.read_text(), fresh)
                self.assertEqual(list(folder.glob('session-*.tmp')), [])

    def test_action_stops_on_runtime_or_validation_failure(self):
        action = (ROOT / '.github/actions/php-compatibility/action.yml').read_text()
        script = textwrap.dedent(action.split('      run: |\n', 1)[1])
        actual = subprocess.check_output(['php', '-r', 'echo PHP_VERSION;'], text=True)
        with tempfile.TemporaryDirectory() as directory:
            folder = Path(directory)
            marker = folder / 'make-ran'
            make = folder / 'make'
            make.write_text('#!/bin/sh\nprintf called > "$GATE_MARKER"\nexit 29\n')
            make.chmod(0o700)
            env = dict(os.environ, PATH=directory + os.pathsep + os.environ['PATH'], GATE_MARKER=str(marker))
            for expected, code, ran in [('999.0.0', 1, False), (actual, 29, True)]:
                result = subprocess.run(
                    ['bash', '--noprofile', '--norc', '-eo', 'pipefail', '-c', script],
                    cwd=ROOT, env=dict(env, EXPECTED_PHP_VERSION=expected),
                    capture_output=True, text=True,
                )
                self.assertEqual(result.returncode, code, result.stderr)
                self.assertEqual(marker.exists(), ran)

    def test_workflows_share_gate(self):
        for name in ['ci', 'integration', 'security', 'release']:
            text = (ROOT / f'.github/workflows/{name}.yml').read_text()
            self.assertEqual(text.count('uses: ./.github/actions/php-compatibility'), 2 if name == 'integration' else 1)
            self.assertNotIn('continue-on-error:', text)
        action = (ROOT / '.github/actions/php-compatibility/action.yml').read_text()
        self.assertIn('make validate', action)
        self.assertIn('--exact', action)
        release = (ROOT / '.github/workflows/release.yml').read_text()
        publish = release.index('uses: softprops/action-gh-release')
        self.assertNotIn('continue-on-error:', release)
        self.assertNotIn('continue-on-error:', action)
        self.assertNotIn('if:', action)
        for gate in ['make release', './scripts/security-check.sh', './scripts/integration-test-ci.sh']:
            self.assertLess(release.index(gate), publish)

    def test_integration_runtime_and_refresh_preserved(self):
        for name in ['integration-test.sh', 'integration-test-ci.sh']:
            text = (ROOT / 'scripts' / name).read_text()
            self.assertIn('"$SESSION_RESOLVER" --refresh', text)
            self.assertIn('pull db wordpress cli', text)
            self.assertIn('exec --no-TTY wordpress', text)
            self.assertIn('if [[ "$php_version" != "$EXPECTED_PHP_VERSION" ]]', text)
            self.assertNotIn('images-ready.', text)
            self.assertIn('cli-php-version.txt', text)
            early_check = text.index('"$REPORT_DIR/php-version.txt"')
            self.assertLess(early_check, text.index('eval-file --use-include /artifacts/install-test-wordpress.php'))
            self.assertGreaterEqual(text.count('exec --no-TTY wordpress'), 3)


if __name__ == '__main__':
    unittest.main()
