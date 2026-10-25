#!/usr/bin/env python3
"""Stable WordPress compatibility and release metadata policy (no upper bound)."""
import argparse
import re
from pathlib import Path

MINIMUM = '7.0'


def version_tuple(value):
    if not isinstance(value, str) or not re.fullmatch(r'[0-9]+\.[0-9]+(?:\.[0-9]+)?', value, flags=re.ASCII):
        raise ValueError('Invalid stable WordPress version')
    parts = tuple(map(int, value.split('.')))
    return parts + (0,) * (3 - len(parts))


def is_supported(value):
    try:
        return version_tuple(value) >= version_tuple(MINIMUM)
    except ValueError:
        return False


def latest_stable(payload):
    if not isinstance(payload, dict) or not isinstance(payload.get('offers'), list):
        raise ValueError('Invalid WordPress release response')
    candidates = []
    for offer in payload['offers']:
        if not isinstance(offer, dict) or offer.get('response') not in ('upgrade', 'latest'):
            continue
        value = offer.get('current')
        try:
            parts = version_tuple(value)
        except ValueError:
            continue
        candidates.append((parts, value))
    if not candidates:
        raise ValueError('Could not resolve a stable WordPress release')
    version = max(candidates)[1]
    if not is_supported(version):
        raise ValueError('Latest stable WordPress is below the supported minimum')
    return version


def header(text, label):
    matches = re.findall(r'^\s*(?:\*\s*)?' + re.escape(label) + r':[ \t]*([^\r\n]+)', text, re.MULTILINE)
    if len(matches) != 1:
        raise ValueError(f'Missing or duplicate {label} metadata')
    return matches[0].strip()


def validate_metadata(plugin, readme):
    for text in [plugin, readme]:
        if header(text, 'Requires at least') != MINIMUM:
            raise ValueError('Requires at least must be 7.0 in plugin and readme')
        if not is_supported(header(text, 'Tested up to')):
            raise ValueError('Tested up to must be a stable supported WordPress version')
    if header(plugin, 'Tested up to') != header(readme, 'Tested up to'):
        raise ValueError('Plugin and readme Tested up to metadata disagree')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check-metadata', action='store_true')
    parser.add_argument('--source', type=Path, default=Path('src'))
    parser.add_argument('--readme', type=Path, default=Path('readme.txt'))
    args = parser.parse_args()
    if not args.check_metadata:
        parser.error('--check-metadata is required')
    plugin = args.source / 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare.php'
    validate_metadata(plugin.read_text(encoding='utf-8'), args.readme.read_text(encoding='utf-8'))
    print('WordPress compatibility metadata: PASS (stable 7.0+, no maximum)')


if __name__ == '__main__':
    try:
        main()
    except (ValueError, OSError) as error:
        raise SystemExit(f'WordPress policy validation failed: {error}') from error
