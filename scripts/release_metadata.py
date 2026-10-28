#!/usr/bin/env python3
"""Cheap release preflight, also applied to the packaged public metadata."""
import argparse
from pathlib import Path
import re
import subprocess
from wordpress_policy import header, validate_metadata

SLUG = 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'


def public_metadata(plugin, readme, version):
    validate_metadata(plugin, readme)
    if header(plugin, 'Version') != version or header(readme, 'Stable tag') != version:
        raise ValueError('Plugin version / stable tag / requested version disagree')
    if header(plugin, 'Requires PHP') != header(readme, 'Requires PHP'):
        raise ValueError('Requires PHP metadata disagree')
    if not re.fullmatch(r'8\.[2-9]', header(plugin, 'Requires PHP')):
        raise ValueError('Requires PHP must name a reviewed supported minimum')
    sections = readme.split('== Changelog ==', 1)
    if len(sections) != 2 or not re.search(r'^= ' + re.escape(version) + r' =\s*\n\S', sections[1], re.M):
        raise ValueError('Missing public changelog heading/notes for ' + version)
    if '== Upgrade Notice ==' not in readme:
        raise ValueError('Missing public upgrade notice')


def validate(root, version=None, history=True):
    root = Path(root)
    plugin = (root / 'src' / (SLUG + '.php')).read_text()
    readme = (root / 'readme.txt').read_text()
    version = version or header(plugin, 'Version')
    if not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+', version):
        raise ValueError('Invalid release version')
    public_metadata(plugin, readme, version)
    docs = (root / 'README.md').read_text()
    if 'badge/version-' + version + '-blue' not in docs:
        raise ValueError('README version badge is stale')
    if not re.search(r'^### ' + re.escape(version) + r'\s*\n- \S', docs, re.M):
        raise ValueError('README release notes are missing or still marked unreleased')
    if history:
        tags = subprocess.check_output(['git', 'tag', '--list', 'v*'], cwd=root, text=True).splitlines()
        # Published maintenance versions must retain public headings. Claims
        # still require human review against the immutable source diffs.
        for tag in tags:
            if re.fullmatch(r'v1\.3\.(?:1[0-3])', tag):
                old = tag[1:]
                if f'### {old}\n' not in docs or f'= {old} =' not in readme:
                    raise ValueError('Published version missing from changelog: ' + old)
    return version


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--version')
    args = parser.parse_args()
    print('PREPARE=' + validate(Path(__file__).resolve().parents[1], args.version))


if __name__ == '__main__':
    try:
        main()
    except (ValueError, OSError, subprocess.SubprocessError) as error:
        raise SystemExit('Release metadata blocked: ' + str(error))
