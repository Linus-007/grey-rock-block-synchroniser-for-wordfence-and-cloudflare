#!/usr/bin/env python3
"""Fail closed on mutable/unreviewed Actions uses references; no network calls."""
import json
from pathlib import Path
import re
import sys

ROOT = Path(__file__).resolve().parents[1]
KEY = re.compile(r'(?:^|[\s,{])[\'"]?uses[\'"]?\s*:\s*')
VALUE = re.compile(r'''(?:"([^"\n]+)"|'([^'\n]+)'|([^\s,}\]]+))''')
SHA = re.compile(r'[0-9a-f]{40}\Z')


def validate(root):
    records = json.loads((root / 'security/action-pins.json').read_text())
    approved = set()
    for record in records:
        repo, commit = record['repository'], record['commit']
        if (not re.fullmatch(r'[\w.-]+/[\w.-]+', repo) or not SHA.fullmatch(commit)
                or record['commit_url'] != f'https://github.com/{repo}/commit/{commit}'
                or not record['release_url'].startswith(f'https://github.com/{repo}/releases/tag/')
                or not re.fullmatch(r'\d{4}-\d{2}-\d{2}', record['verified_on'])
                or not record.get('evidence') or not record.get('version')):
            raise ValueError('Invalid action verification inventory')
        approved.add(repo + '@' + commit)
    errors, count = [], 0
    for directory in ['.github/workflows', '.github/actions']:
        for path in sorted((root / directory).rglob('*')):
            if path.suffix not in ('.yml', '.yaml'):
                continue
            for number, line in enumerate(path.read_text().splitlines(), 1):
                if line.lstrip().startswith('#'):
                    continue
                for key in KEY.finditer(line):
                    match = VALUE.match(line, key.end())
                    value = next((g for g in match.groups() if g is not None), '') if match else ''
                    if value.startswith('./'):
                        if '..' in Path(value).parts or '${{' in value or not (root / value).exists():
                            errors.append(f'{path.relative_to(root)}:{number}: invalid local uses')
                        continue
                    count += 1
                    if value not in approved:
                        errors.append(f'{path.relative_to(root)}:{number}: mutable or unreviewed remote uses')
    if count == 0:
        errors.append('No remote Actions references found; coverage is incomplete')
    return errors, count


def main():
    try:
        errors, count = validate(ROOT)
    except (OSError, ValueError, KeyError, TypeError):
        print('ACTION_PINS=ERROR; verification inventory unavailable/invalid')
        return 1
    for error in errors:
        print(error)
    print(f'ACTION_PINS={"FAIL" if errors else "PASS"}; remote_references={count}')
    return int(bool(errors))


if __name__ == '__main__':
    sys.exit(main())
