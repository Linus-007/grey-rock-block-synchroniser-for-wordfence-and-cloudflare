#!/usr/bin/env python3
"""Bind a complete bounded ZIP snapshot to an independently retained receipt digest."""
import argparse
import hashlib
import io
import json
from pathlib import Path, PurePosixPath
import re
import stat
import sys
import zipfile

SLUG = 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
LIMIT = 64 * 1024 * 1024


def digest(data):
    return hashlib.sha256(data).hexdigest()


def snapshot(data, version, slug=SLUG):
    if len(data) > LIMIT:
        raise ValueError('Archive exceeds limit')
    entries = {}
    total = 0
    with zipfile.ZipFile(io.BytesIO(data)) as archive:
        if len(archive.infolist()) > 10000:
            raise ValueError('Archive has too many entries')
        for item in archive.infolist():
            name = item.filename
            parts = PurePosixPath(name).parts
            if (not name or name in entries or '\\' in name or '\x00' in name
                    or name.startswith('/') or any(p in ('.', '..') for p in name.rstrip('/').split('/'))
                    or not parts or parts[0] != slug or '//' in name or item.flag_bits & 1
                    or stat.S_IFMT(item.external_attr >> 16) not in (0, stat.S_IFREG, stat.S_IFDIR)):
                raise ValueError('Unsafe/duplicate archive entry')
            total += item.file_size
            if item.file_size > 32 * 1024 * 1024 or total > 128 * 1024 * 1024:
                raise ValueError('Expanded archive exceeds limit')
            content = archive.read(item)
            entries[name] = {'size': len(content), 'sha256': digest(content), 'directory': item.is_dir()}
        plugin = archive.read(f'{slug}/{SLUG}.php').decode('utf-8')
        readme = archive.read(f'{slug}/readme.txt').decode('utf-8')
        archive.getinfo(f'{slug}/uninstall.php')
        from release_metadata import public_metadata
        public_metadata(plugin, readme, version)
        header = re.search(r'^\s*\* Version:\s*(\S+)', plugin, re.M)
        stable = re.search(r'^Stable tag:\s*(\S+)', readme, re.M)
        if not header or not stable or header[1] != version or stable[1] != version:
            raise ValueError('Archive version mismatch')
    return entries


def read_bounded(path):
    with Path(path).open('rb') as stream:
        data = stream.read(LIMIT + 1)
    if len(data) > LIMIT:
        raise ValueError('Input exceeds limit')
    return data


def create(archive, receipt, commit, version):
    if not re.fullmatch(r'[0-9a-f]{40}', commit) or not re.fullmatch(r'\d+\.\d+\.\d+', version):
        raise ValueError('Invalid candidate identity')
    data = read_bounded(archive)
    record = {'schema': 1, 'commit': commit, 'version': version, 'tag': 'v' + version,
              'archive_sha256': digest(data), 'entries': snapshot(data, version)}
    encoded = (json.dumps(record, sort_keys=True, separators=(',', ':')) + '\n').encode()
    Path(receipt).write_bytes(encoded)
    return digest(encoded)


def verify(archive, receipt, expected, commit, version, equivalent=False):
    raw = read_bounded(receipt)
    if not re.fullmatch(r'[0-9a-f]{64}', expected) or digest(raw) != expected:
        raise ValueError('Receipt differs from independent producer digest')
    record = json.loads(raw)
    if (record.get('schema') != 1 or record.get('commit') != commit
            or record.get('version') != version or record.get('tag') != 'v' + version):
        raise ValueError('Candidate commit/tag/version mismatch')
    data = read_bounded(archive)
    if not equivalent and digest(data) != record.get('archive_sha256'):
        raise ValueError('Archive checksum differs from validated snapshot')
    if snapshot(data, version) != record.get('entries'):
        raise ValueError('Complete ZIP contents differ from validated snapshot')
    return record, data


def extract(archive, receipt, expected, commit, version, target):
    record, data = verify(archive, receipt, expected, commit, version)
    directory = Path(target)
    directory.mkdir(mode=0o700, parents=True, exist_ok=False)
    # Extract the already verified in-memory bytes, never reopen a mutable ZIP.
    with zipfile.ZipFile(io.BytesIO(data)) as z:
        for item in z.infolist():
            destination = directory.joinpath(*PurePosixPath(item.filename).parts)
            if item.is_dir():
                destination.mkdir(parents=True, exist_ok=True)
            else:
                destination.parent.mkdir(parents=True, exist_ok=True)
                destination.write_bytes(z.read(item))
    return directory / SLUG


def verify_tree(directory, receipt, expected, commit, version):
    raw = read_bounded(receipt)
    if digest(raw) != expected:
        raise ValueError('Receipt replaced')
    record = json.loads(raw)
    if record.get('commit') != commit or record.get('version') != version or record.get('tag') != 'v' + version:
        raise ValueError('Tree identity mismatch')
    actual = {}
    directories = set()
    for path in Path(directory).rglob('*'):
        relative = path.relative_to(directory)
        if '.svn' in relative.parts:
            continue
        if path.is_symlink():
            raise ValueError('Staged symlink forbidden')
        if path.is_dir():
            directories.add(relative.as_posix())
        if path.is_file():
            actual[SLUG + '/' + relative.as_posix()] = digest(read_bounded(path))
    wanted = {k: v['sha256'] for k, v in record['entries'].items() if not v['directory']}
    wanted_directories = set()
    for name, entry in record['entries'].items():
        relative = PurePosixPath(name).relative_to(SLUG)
        if entry['directory'] and relative.as_posix() != '.':
            wanted_directories.add(relative.as_posix())
        wanted_directories.update(p.as_posix() for p in relative.parents if p.as_posix() != '.')
    if actual != wanted or directories != wanted_directories:
        raise ValueError('Complete publisher tree differs from validated artifact')


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('operation', choices=['create', 'verify', 'compare', 'extract', 'tree'])
    p.add_argument('--archive'); p.add_argument('--receipt', required=True)
    p.add_argument('--receipt-sha256'); p.add_argument('--commit', required=True); p.add_argument('--version', required=True)
    p.add_argument('--target')
    args = p.parse_args()
    try:
        common = (args.archive, args.receipt, args.receipt_sha256, args.commit, args.version)
        if args.operation == 'create':
            print(create(args.archive, args.receipt, args.commit, args.version))
        elif args.operation == 'tree':
            verify_tree(args.target, args.receipt, args.receipt_sha256, args.commit, args.version)
        elif args.operation == 'extract':
            print(extract(*common, args.target))
        else:
            verify(*common, equivalent=args.operation == 'compare')
        return 0
    except (OSError, ValueError, KeyError, TypeError, zipfile.BadZipFile, RuntimeError):
        print('ARTIFACT_VERIFICATION=FAILED; candidate identity/integrity unavailable', file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())
