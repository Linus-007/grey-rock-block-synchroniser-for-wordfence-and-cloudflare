#!/usr/bin/env python3
"""Run CE and report only counts: source fragments may contain credentials."""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
from semgrep_wordpress import prepare


def summarize(code, data):
    if not isinstance(data, dict):
        raise ValueError('Malformed Semgrep output')
    findings = data.get('results')
    errors = data.get('errors')
    paths = data.get('paths')
    if not isinstance(findings, list) or not isinstance(errors, list) or not isinstance(paths, dict):
        raise ValueError('Malformed Semgrep output')
    scanned = paths.get('scanned')
    if not isinstance(scanned, list):
        raise ValueError('Missing Semgrep scan coverage')
    summary = {'tool_exit': code, 'findings': len(findings), 'errors': len(errors), 'scanned_files': len(scanned)}
    return summary, code == 0 and not findings and not errors and bool(scanned)


def main():
    binary = Path(sys.argv[1])
    root = Path(__file__).resolve().parents[1]
    env = dict(os.environ, SEMGREP_ENABLE_VERSION_CHECK='0')
    env.pop('SEMGREP_APP_TOKEN', None)
    rule_test = subprocess.run([str(binary), 'scan', '--test', '--oss-only', '--metrics=off', 'security'], cwd=root, env=env, capture_output=True)
    print(f'SEMGREP_RULE_TEST_EXIT={rule_test.returncode}')
    if rule_test.returncode != 0:
        return 1
    command = [str(binary), 'scan', '--oss-only', '--metrics=off', '--strict', '--error', '--no-git-ignore', '--config', 'security/semgrep.yml', '--json', 'src']
    community = binary.parent.parent.parent / 'community-php'
    if not community.is_dir():
        raise ValueError('Missing reproducible community PHP rules')
    manifest = json.loads((community.parent / 'manifest.json').read_text())
    with tempfile.TemporaryDirectory(prefix='grey-rock-semgrep-') as directory:
        adapted = Path(directory) / 'community-php'
        provenance = prepare(community, adapted, manifest)
        tests = subprocess.run([str(binary), 'scan', '--test', '--oss-only', '--metrics=off',
                                '--config', str(adapted / 'lang/security/injection/echoed-request.yaml'),
                                'tests/fixtures/semgrep/echoed-request.php'],
                               cwd=root, env=env, capture_output=True)
        print(f'SEMGREP_WORDPRESS_TEST_EXIT={tests.returncode}')
        if tests.returncode != 0:
            print(tests.stdout.decode(errors='replace'))
            print(tests.stderr.decode(errors='replace'))
            return 1
        command += ['--config', str(adapted)]
        result = subprocess.run(command, cwd=root, env=env, capture_output=True, text=True)
    destination = root / 'reports/semgrep'
    destination.mkdir(parents=True, exist_ok=True)
    (destination / 'wordpress-adaptation.json').write_text(json.dumps(provenance, indent=2))
    data = json.loads(result.stdout)
    summary, passed = summarize(result.returncode, data)
    destination = root / 'reports/semgrep'
    destination.mkdir(parents=True, exist_ok=True)
    (destination / 'summary.json').write_text(json.dumps(summary, indent=2))
    print('SEMGREP_CE ' + json.dumps(summary))
    return 0 if passed else 1


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except (OSError, ValueError, KeyError, TypeError):
        raise SystemExit('Semgrep unavailable/incomplete; no clean scan is claimed.')
