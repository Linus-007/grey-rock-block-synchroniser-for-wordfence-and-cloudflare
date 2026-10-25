#!/usr/bin/env python3
"""Install freshly resolved stable free scanners in the repository; never fall back."""
import argparse
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import platform
import re
import subprocess
import sys
import tarfile
import tempfile
import urllib.request

ROOT = Path(__file__).resolve().parents[1]


def stable(value):
    if not isinstance(value, str) or not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+', value, flags=re.ASCII):
        raise ValueError('Invalid stable scanner version')
    return tuple(map(int, value.split('.')))


def latest_semgrep(payload):
    if not isinstance(payload, dict) or not isinstance(payload.get('releases'), dict):
        raise ValueError('Invalid Semgrep release response')
    candidates = []
    for version, files in payload.get('releases', {}).items():
        try:
            parts = stable(version)
        except ValueError:
            continue
        if isinstance(files, list) and any(isinstance(f, dict) and not f.get('yanked', False) for f in files):
            candidates.append((parts, version))
    if not candidates:
        raise ValueError('No non-yanked stable Semgrep release')
    return max(candidates)[1]


def fetch(url):
    request = urllib.request.Request(url, headers={'User-Agent': 'Grey-Rock-Security-Tools'})
    with urllib.request.urlopen(request, timeout=30) as response:
        content = response.read(100 * 1024 * 1024 + 1)
    if len(content) > 100 * 1024 * 1024:
        raise ValueError('Scanner download exceeds limit')
    return content


def verify_checksum(content, checksums, filename):
    expected = []
    for line in checksums.splitlines():
        fields = line.split()
        if len(fields) == 2 and fields[1].lstrip('*') == filename:
            expected.append(fields[0])
    if len(expected) != 1 or not re.fullmatch(r'[a-fA-F0-9]{64}', expected[0]):
        raise ValueError('Missing or ambiguous scanner checksum')
    if hashlib.sha256(content).hexdigest() != expected[0].lower():
        raise ValueError('Scanner checksum mismatch')


def snapshot_php_rules(destination):
    payload = json.loads(fetch('https://api.github.com/repos/semgrep/semgrep-rules/commits?path=php&per_page=1'))
    commit = payload[0].get('sha') if isinstance(payload, list) and payload and isinstance(payload[0], dict) else None
    if not isinstance(commit, str) or not re.fullmatch(r'[a-f0-9]{40}', commit):
        raise ValueError('Invalid community rule commit')
    content = fetch(f'https://github.com/semgrep/semgrep-rules/archive/{commit}.tar.gz')
    directory = destination / 'community-php'
    hashes = {}
    total = 0
    with tarfile.open(fileobj=io.BytesIO(content), mode='r:gz') as archive:
        for member in archive:
            total += member.size
            if total > 100 * 1024 * 1024:
                raise ValueError('Community archive exceeds expanded size limit')
            parts = PurePosixPath(member.name).parts
            if len(parts) < 3 or parts[:2] != (f'semgrep-rules-{commit}', 'php'):
                continue
            if '..' in parts or not member.isfile() or not member.name.endswith(('.yml', '.yaml')):
                continue
            if member.size > 5 * 1024 * 1024:
                raise ValueError('Community rules exceed limits')
            target = directory.joinpath(*parts[2:])
            target.parent.mkdir(parents=True, exist_ok=True)
            data = archive.extractfile(member).read()
            target.write_bytes(data)
            hashes['/'.join(parts[2:])] = hashlib.sha256(data).hexdigest()
    if not hashes:
        raise ValueError('Community PHP rule snapshot is empty')
    return {'commit': commit, 'files': hashes}


def install_semgrep():
    version = latest_semgrep(json.loads(fetch('https://pypi.org/pypi/semgrep/json')))
    base = ROOT / '.tools/security-tools'
    base.mkdir(parents=True, exist_ok=True)
    destination = Path(tempfile.mkdtemp(prefix=f'semgrep-{version}-', dir=base))
    subprocess.run([sys.executable, '-m', 'venv', str(destination / 'venv')], check=True, stdout=sys.stderr)
    python = destination / 'venv/bin/python'
    wheels = destination / 'wheels'
    wheels.mkdir()
    # Resolve once, keep exact wheels and all hashes, then install offline.
    subprocess.run([str(python), '-m', 'pip', '--isolated', 'download', '--index-url', 'https://pypi.org/simple', '--only-binary=:all:', '--dest', str(wheels), f'semgrep=={version}'], check=True, stdout=sys.stderr)
    subprocess.run([str(python), '-m', 'pip', '--isolated', 'install', '--no-index', '--find-links', str(wheels), '--only-binary=:all:', f'semgrep=={version}'], check=True, stdout=sys.stderr)
    community = snapshot_php_rules(destination)
    hashes = {p.name: hashlib.sha256(p.read_bytes()).hexdigest() for p in wheels.iterdir()}
    manifest = {'version': version, 'wheels': hashes, 'community_php_rules': community, 'rules_sha256': hashlib.sha256((ROOT / 'security/semgrep.yml').read_bytes()).hexdigest(), 'rule_tests_sha256': hashlib.sha256((ROOT / 'security/semgrep.php').read_bytes()).hexdigest()}
    (destination / 'manifest.json').write_text(json.dumps(manifest, indent=2), encoding='utf-8')
    binary = destination / 'venv/bin/semgrep'
    env = dict(os.environ, SEMGREP_ENABLE_VERSION_CHECK='0')
    actual = subprocess.check_output([str(binary), '--version'], env=env, text=True).strip()
    if actual != version:
        raise ValueError('Installed Semgrep does not match resolved version')
    return binary


def install_gitleaks():
    payload = json.loads(fetch('https://api.github.com/repos/gitleaks/gitleaks/releases/latest'))
    tag = payload.get('tag_name', '')
    if not isinstance(tag, str) or not tag.startswith('v') or payload.get('draft') or payload.get('prerelease'):
        raise ValueError('Invalid stable Gitleaks release')
    version = tag[1:]
    stable(version)
    machine = {'x86_64': 'x64', 'aarch64': 'arm64'}.get(platform.machine())
    if platform.system() != 'Linux' or machine is None:
        raise ValueError('Gitleaks installer requires supported Linux architecture')
    filename = f'gitleaks_{version}_linux_{machine}.tar.gz'
    checksums_name = f'gitleaks_{version}_checksums.txt'
    base_url = f'https://github.com/gitleaks/gitleaks/releases/download/{tag}/'
    content = fetch(base_url + filename)
    verify_checksum(content, fetch(base_url + checksums_name).decode('utf-8'), filename)
    base = ROOT / '.tools/security-tools'
    base.mkdir(parents=True, exist_ok=True)
    destination = Path(tempfile.mkdtemp(prefix=f'gitleaks-{version}-', dir=base))
    with tarfile.open(fileobj=io.BytesIO(content), mode='r:gz') as archive:
        members = [m for m in archive.getmembers() if m.name == 'gitleaks' and m.isfile()]
        if len(members) != 1 or members[0].size > 100 * 1024 * 1024:
            raise ValueError('Unexpected scanner archive')
        binary = destination / 'gitleaks'
        binary.write_bytes(archive.extractfile(members[0]).read())
    binary.chmod(0o700)
    # Goreleaser binaries may report "version" without a leading v.
    actual = subprocess.check_output([str(binary), 'version'], text=True).strip().removeprefix('v')
    if actual != version:
        raise ValueError('Installed Gitleaks does not match resolved version')
    (destination / 'manifest.json').write_text(json.dumps({'version': version, 'archive_sha256': hashlib.sha256(content).hexdigest()}, indent=2))
    return binary


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('tool', choices=['semgrep', 'gitleaks'])
    args = parser.parse_args()
    print(install_semgrep() if args.tool == 'semgrep' else install_gitleaks())


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, subprocess.SubprocessError, tarfile.TarError):
        # No raw download/authentication diagnostics in CI summaries.
        raise SystemExit('Scanner installation failed; no scan or fallback was accepted.')
