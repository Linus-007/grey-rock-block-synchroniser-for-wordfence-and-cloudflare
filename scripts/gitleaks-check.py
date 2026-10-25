#!/usr/bin/env python3
"""Fail closed on history/worktree exposure; do not print credential diagnostics."""
from pathlib import Path
import shutil
import secrets
import string
import subprocess
import tempfile
import security_tools


def scan(binary, mode, target):
    command = [str(binary), mode, '--redact=100', '--exit-code=1', '--no-banner', '--ignore-gitleaks-allow']
    if mode == 'git':
        command += ['--log-opts=--all --full-history']
    result = subprocess.run(command + [str(target)], capture_output=True)
    # Even tool errors remain mandatory failures. Raw findings never enter reports.
    print(f'GITLEAKS_{mode.upper()}_EXIT={result.returncode}')
    return result.returncode


def main():
    root = security_tools.ROOT
    shallow = subprocess.check_output(['git', '-C', str(root), 'rev-parse', '--is-shallow-repository'], text=True).strip()
    if shallow != 'false':
        raise ValueError('Full Git history is required')
    binary = security_tools.install_gitleaks()
    # Nonfunctional random positive control stays in memory and is never logged.
    fixture = ('gh' + 'p_' + ''.join(secrets.choice(string.ascii_letters + string.digits) for _ in range(36))).encode()
    control = subprocess.run([str(binary), 'stdin', '--redact=100', '--exit-code=1', '--no-banner'], input=fixture, capture_output=True)
    if control.returncode != 1:
        raise ValueError('Gitleaks positive control did not detect the synthetic pattern')
    print('GITLEAKS_POSITIVE_CONTROL=PASS')
    manifest = binary.parent / 'manifest.json'
    provenance = root / 'reports/gitleaks'
    provenance.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(manifest, provenance / 'manifest.json')
    failed = scan(binary, 'git', root) != 0
    # Scan current tracked + non-ignored untracked content, excluding private caches.
    with tempfile.TemporaryDirectory(prefix='gitleaks-', dir=root / '.tools/security-tools') as directory:
        target = Path(directory)
        names = subprocess.check_output(['git', '-C', str(root), 'ls-files', '--cached', '--others', '--exclude-standard', '-z']).decode().split('\0')
        for name in names:
            source = root / name
            if not name or source.is_symlink() or not source.is_file():
                continue
            destination = target / name
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(source, destination)
        failed = scan(binary, 'dir', target) != 0 or failed
    return 1 if failed else 0


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except (OSError, ValueError, subprocess.SubprocessError):
        raise SystemExit('Gitleaks unavailable/incomplete; no clean scan is claimed.')
