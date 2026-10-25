"""WordPress policy, release metadata and actual session resolver regressions."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import textwrap
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts'))
import wordpress_policy as policy
builder_spec = importlib.util.spec_from_file_location('build_release', ROOT / 'scripts/build-release.py')
builder = importlib.util.module_from_spec(builder_spec)
builder_spec.loader.exec_module(builder)


class WordPressPolicyTests(unittest.TestCase):
    def test_supported_stable_versions_without_maximum(self):
        for version in ['7.0', '7.0.0', '7.1', '7.99.9', '8.0', '10.0.1']:
            self.assertTrue(policy.is_supported(version), version)

    def test_old_prerelease_invalid_and_malicious_versions_rejected(self):
        for version in ['6.0', '6.9.99', '7.0-alpha1', '7.1-beta2', '8.0-RC1', '9.0-dev', '7.0RC1', '', 'latest', '7', '7.0.0.1', '７.0', '7.0\n', '7.0;echo unsafe', '$(id)', None, [], 7]:
            self.assertFalse(policy.is_supported(version), repr(version))

    def test_latest_stable_numeric_order_and_offer_order(self):
        versions = ['7.9', '8.0-RC2', '7.10', '7.0', '7.10.2', '6.9.99']
        offers = [{'current': v, 'response': 'upgrade'} for v in versions]
        self.assertEqual(policy.latest_stable({'offers': offers}), '7.10.2')
        offers.append({'current': '8.0', 'response': 'latest'})
        offers.append({'current': '10.0', 'response': 'development'})
        self.assertEqual(policy.latest_stable({'offers': list(reversed(offers))}), '8.0')

    def test_no_fallback_to_old_or_invalid_offer(self):
        for payload in [None, {}, {'offers': {}}, {'offers': []}, {'offers': [None]}, {'offers': [{'current': '7.0', 'response': []}]}, {'offers': [{'current': '6.9', 'response': 'latest'}]}, {'offers': [{'current': '7.1-RC1', 'response': 'latest'}]}, {'offers': [{'current': '7.0; echo unsafe', 'response': 'latest'}]}]:
            with self.subTest(payload=payload), self.assertRaises(ValueError):
                policy.latest_stable(payload)

    def test_metadata_enforcement(self):
        plugin = (ROOT / 'src/grey-rock-block-synchroniser-for-wordfence-and-cloudflare.php').read_text()
        readme = (ROOT / 'readme.txt').read_text()
        policy.validate_metadata(plugin, readme)
        self.assertIn('Requires stable WordPress 7.0 or later.', (ROOT / 'README.md').read_text())
        for bad in ['6.0', '6.9', '7.0-RC1', '7.0; echo unsafe']:
            for change_plugin in [True, False]:
                with self.subTest(bad=bad, plugin=change_plugin), self.assertRaises(ValueError):
                    policy.validate_metadata(
                        plugin.replace('Requires at least: 7.0', 'Requires at least: ' + bad) if change_plugin else plugin,
                        readme if change_plugin else readme.replace('Requires at least: 7.0', 'Requires at least: ' + bad),
                    )
        with self.assertRaises(ValueError):
            policy.validate_metadata(plugin, readme + '\nRequires at least: 7.0\n')

    def test_builder_rejects_invalid_source_and_packaged_metadata(self):
        slug = builder.DEFAULT_PLUGIN_SLUG
        with tempfile.TemporaryDirectory() as directory:
            folder = Path(directory)
            output = folder / 'plugin.zip'
            readme = folder / 'readme.txt'
            readme.write_text((ROOT / 'readme.txt').read_text().replace('Requires at least: 7.0', 'Requires at least: 6.0'))
            with self.assertRaises(RuntimeError):
                builder.build_zip(ROOT / 'src', output, slug, readme)
            self.assertFalse(output.exists())
            builder.build_zip(ROOT / 'src', output, slug, ROOT / 'readme.txt')
            builder.verify_zip(output, slug)
            with zipfile.ZipFile(output) as archive:
                entries = {name: archive.read(name) for name in archive.namelist()}
            entries[f'{slug}/readme.txt'] = readme.read_bytes()
            with zipfile.ZipFile(output, 'w') as archive:
                for name, data in entries.items():
                    archive.writestr(name, data)
            with self.assertRaises(RuntimeError):
                builder.verify_zip(output, slug)

    def test_real_session_resolver_selects_latest_and_fails_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            folder = Path(directory)
            (folder / 'sitecustomize.py').write_text(textwrap.dedent('''
                import io, json, os, urllib.request
                def response(request, **kwargs):
                    url = request.full_url
                    if 'php.net' in url:
                        data = {'version': os.environ.get('PHP_TEST_BRANCH', '8.5') + '.99', 'supported_versions': ['8.2', '8.3', '8.4', '8.5']}
                    elif 'version-check' in url:
                        data = {'offers': json.loads(os.environ['WP_FIXTURE_OFFERS'])}
                    elif 'plugins/info' in url:
                        data = {'version': '9.0.0'}
                    else:
                        data = {'tag_name': 'v2.12.0'}
                    return io.BytesIO(json.dumps(data).encode())
                urllib.request.urlopen = response
            '''))
            env = dict(os.environ, PYTHONPATH=directory, GREY_ROCK_TEST_CACHE_DIR=directory, PHP_TEST_BRANCH="8.5")
            env.pop('EXPECTED_PHP_VERSION', None)
            session = folder / 'session.env'
            good = [{'current': v, 'response': 'latest'} for v in ['7.0', '9.0-RC1', '8.0.1']]
            command = ['bash', str(ROOT / 'scripts/resolve-test-session.sh')]
            result = subprocess.run(command, env=dict(env, WP_FIXTURE_OFFERS=json.dumps(good)), capture_output=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            fresh = session.read_text()
            self.assertIn('EXPECTED_WORDPRESS_VERSION=8.0.1\n', fresh)
            for bad in ['6.9', '7.1-RC1', '7.0; echo unsafe', '7.0\nUNEXPECTED=value']:
                offers = [{'current': bad, 'response': 'latest'}]
                result = subprocess.run(command, env=dict(env, WP_FIXTURE_OFFERS=json.dumps(offers)), capture_output=True)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(session.read_text(), fresh)

    def test_integration_downloads_resolved_stable_wordpress(self):
        for name in ['integration-test.sh', 'integration-test-ci.sh']:
            text = (ROOT / 'scripts' / name).read_text()
            self.assertIn('core download --version="$EXPECTED_WORDPRESS_VERSION" --force --skip-content', text)
            self.assertLess(text.index('core download'), text.index('eval-file /artifacts/install-test-wordpress.php'))
            self.assertIn('if [[ "$wordpress_version" != "$EXPECTED_WORDPRESS_VERSION" ]]', text)
            self.assertIn('eval-file /artifacts/wordpress-compatibility-test.php', text)


if __name__ == '__main__':
    unittest.main()
