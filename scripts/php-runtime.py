#!/usr/bin/env python3
"""Resolve upstream stable PHP candidates; resolution does not certify compatibility."""
import argparse
import datetime
from pathlib import Path
import os
import json
import re
import urllib.request
import urllib.error


def stable_version(value):
    if not isinstance(value, str) or not re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+", value, flags=re.ASCII):
        raise ValueError(f"Not a stable PHP release: {value!r}")
    return tuple(map(int, value.split('.')))


def latest_stable(payload):
    candidates = []
    if not isinstance(payload, dict):
        raise ValueError('PHP release response must be an object')
    if 'version' in payload:
        return '.'.join(map(str, stable_version(payload['version'])))
    for release_key, release in payload.items():
        if not isinstance(release, dict):
            continue
        version = release.get('version', release_key)
        try:
            key = stable_version(version)
        except ValueError:
            continue
        candidates.append((key, version))
    if not candidates:
        raise ValueError('Could not resolve current stable PHP release')
    return max(candidates)[1]


def verify_runtime(actual, expected, exact=False):
    """Check runtime compatibility only; patch provenance requires a vendor advisory."""
    actual_parts = stable_version(actual)
    expected_parts = stable_version(expected)
    if actual_parts[:2] != expected_parts[:2] or (exact and actual_parts != expected_parts):
        raise ValueError(f'PHP runtime mismatch: expected {expected}, got {actual}')


def supported_branches(today=None):
    today = today or datetime.date.today()
    policy = json.loads((Path(__file__).resolve().parents[1] / 'security/php-support.json').read_text())
    if today > datetime.date.fromisoformat(policy['review_by']):
        raise ValueError('PHP lifecycle policy requires upstream review')
    branches = [branch for branch, end in policy['branches'].items()
                if today <= datetime.date.fromisoformat(end)]
    if not branches:
        raise ValueError('No security-maintained PHP branches')
    return sorted(branches, key=lambda value: stable_version(value + '.0'))


def security_eligible(version):
    policy = json.loads((Path(__file__).resolve().parents[1] / 'security/php-support.json').read_text())
    parts = stable_version(version)
    branch = '.'.join(version.split('.')[:2])
    if branch not in supported_branches():
        raise ValueError('PHP release outside security-maintained branches')
    minimum = policy['minimum_patched'].get(branch)
    if not minimum:
        raise ValueError('Missing reviewed PHP security patch floor: ' + branch)
    if parts < stable_version(minimum):
        raise ValueError('PHP release predates required security patches: ' + version)


def resolve(branch=None):
    branches = supported_branches()
    branch = branch or os.environ.get('PHP_TEST_BRANCH') or branches[-1]
    if branch not in branches:
        raise ValueError('PHP branch outside reviewed security support policy')
    request = urllib.request.Request(
        'https://www.php.net/releases/?json&version=' + branch,
        headers={'Accept': 'application/json', 'User-Agent': 'Grey-Rock-PHP-Resolver'},
    )
    with urllib.request.urlopen(request, timeout=30) as response:
        payload = json.load(response)
    if (not isinstance(payload, dict) or not isinstance(payload.get('supported_versions'), list)
            or branch not in payload['supported_versions']):
        raise ValueError('Authoritative PHP branch support data unavailable/mismatched')
    version = latest_stable(payload)
    if '.'.join(version.split('.')[:2]) != branch:
        raise ValueError('PHP release data does not match maintained branch')
    security_eligible(version)
    return version


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--verify', nargs=2, metavar=('ACTUAL', 'EXPECTED'))
    parser.add_argument('--exact', action='store_true')
    parser.add_argument('--matrix', action='store_true')
    args = parser.parse_args()
    if args.matrix:
        print(json.dumps({'branch': supported_branches()}))
    elif args.verify:
        verify_runtime(*args.verify, exact=args.exact)
    else:
        version = resolve()
        print(f'EXPECTED_PHP_VERSION={version}')
        print(f'EXPECTED_PHP_BRANCH={".".join(version.split(".")[:2])}')


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, KeyError, urllib.error.URLError) as error:
        raise SystemExit(f"PHP compatibility check failed: {error}") from error
