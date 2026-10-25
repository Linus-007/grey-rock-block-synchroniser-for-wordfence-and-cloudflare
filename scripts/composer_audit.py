#!/usr/bin/env python3
"""Distinguish audited findings from unavailable/incomplete advisory service results."""
import json
import subprocess


def classify(code, output):
    try:
        data = json.loads(output)
    except (ValueError, TypeError):
        return 'ERROR'
    if not isinstance(data, dict) or not isinstance(data.get('advisories'), (dict, list)):
        return 'ERROR'
    abandoned = data.get('abandoned', {})
    if not isinstance(abandoned, (dict, list)):
        return 'ERROR'
    if data['advisories'] or abandoned:
        return 'FINDINGS'
    return 'CLEAN' if code == 0 else 'ERROR'


def main():
    result = subprocess.run(['composer', 'audit', '--locked', '--format=json', '--no-interaction', '--no-ansi'], capture_output=True, text=True)
    status = classify(result.returncode, result.stdout)
    print(f'COMPOSER_AUDIT={status}; composer_exit={result.returncode}')
    if status == 'ERROR':
        print('Advisory service/command failed or returned an incomplete result; no clean audit is claimed.')
    elif status == 'FINDINGS':
        print('Dependency advisories or abandoned packages require review; raw package diagnostics omitted.')
    return 0 if status == 'CLEAN' else 1


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except OSError:
        raise SystemExit('COMPOSER_AUDIT=ERROR; Composer could not be executed.')
