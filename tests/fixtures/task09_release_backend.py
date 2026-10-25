"""Offline external-effect adapter. Never invokes git, svn, networking or services."""
import hashlib
import io
import json
import os
from pathlib import Path
import shutil
import sys
import zipfile

SLUG = 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
VERSION = os.environ['TASK09_VERSION']
COMMIT = 'a' * 40
REPOSITORY = 'Linus-007/grey-rock-block-synchroniser-for-wordfence-and-cloudflare'


def root():
    return Path(os.environ['TASK09_FIXTURE_ROOT'])


def scenario():
    return os.environ.get('TASK09_SCENARIO', 'valid')


def log(event):
    with (root() / 'events.jsonl').open('a') as stream:
        stream.write(json.dumps(event) + '\n')


def archive_bytes():
    data = (root() / 'producer.zip').read_bytes()
    if scenario() not in {'zip_mismatch', 'paired_replacement', 'stale_artifact'}:
        return data
    output = io.BytesIO()
    with zipfile.ZipFile(io.BytesIO(data)) as old, zipfile.ZipFile(output, 'w') as new:
        for item in old.infolist():
            content = old.read(item)
            if item.filename == SLUG + '/assets/admin.js':
                content += b'\n/* offline replacement */\n'
            new.writestr(item, content)
    return output.getvalue()


def urlopen(request, **kwargs):
    url = request.full_url if hasattr(request, 'full_url') else request
    log({'effect': 'mock-http', 'path': url.split('github.com/', 1)[-1].split('?', 1)[0]})
    if url.startswith('https://api.github.com/repos/' + REPOSITORY + '/actions/runs'):
        if '/jobs' in url:
            names = ['WordPress security checks', 'Semgrep CE SAST', 'Gitleaks secret scan', 'PHP compatibility', 'WordPress integration test']
            jobs = [{'name': n, 'status': 'completed', 'conclusion': 'failure' if scenario() == 'failed_check' and n == 'Gitleaks secret scan' else 'success', 'head_sha': COMMIT} for n in names]
            value = {'total_count': len(jobs), 'jobs': jobs}
        else:
            rows = [{'id': i, 'name': n, 'path': '.github/workflows/' + p, 'head_sha': COMMIT, 'event': 'push', 'head_branch': 'main', 'run_number': 10, 'status': 'completed', 'conclusion': 'success'} for i, (n, p) in enumerate([('Application Security', 'security.yml'), ('Continuous Integration', 'ci.yml'), ('WordPress Integration', 'integration.yml')], 1)]
            if scenario() == 'missing_security':
                rows = rows[1:]
            rows.append({'id': 4, 'name': 'Build and Release Plugin', 'path': '.github/workflows/' + ('other.yml' if scenario() == 'workflow_identity' else 'release.yml'), 'head_sha': COMMIT, 'event': 'push', 'head_branch': 'v' + VERSION, 'run_number': 10, 'status': 'completed', 'conclusion': 'success'})
            value = {'workflow_runs': rows}
        return io.BytesIO(json.dumps(value).encode())
    if url.startswith('https://api.github.com/repos/' + REPOSITORY + '/git/ref/tags/'):
        return io.BytesIO(json.dumps({'object': {'type': 'commit', 'sha': 'b' * 40 if scenario() == 'wrong_tag' else COMMIT}}).encode())
    if '/releases/tags/' in url and url.startswith('https://api.github.com/repos/' + REPOSITORY + '/'):
        names = [SLUG + '.zip', SLUG + '.zip.sha256', 'release-receipt.json']
        if scenario() == 'missing_artifact':
            names = names[1:]
        return io.BytesIO(json.dumps({'tag_name': 'v1.3.9' if scenario() == 'release_version' else 'v' + VERSION, 'draft': False, 'prerelease': False, 'assets': [{'name': n, 'browser_download_url': f'https://github.com/{REPOSITORY}/releases/download/v{VERSION}/{n}'} for n in names]}).encode())
    prefix = f'https://github.com/{REPOSITORY}/releases/download/v{VERSION}/'
    if url.startswith(prefix):
        name = url[len(prefix):]
        data = archive_bytes()
        if name == SLUG + '.zip':
            return io.BytesIO(data)
        if name == SLUG + '.zip.sha256':
            checksum = '0' * 64 if scenario() == 'checksum_mismatch' else hashlib.sha256(data).hexdigest()
            return io.BytesIO((checksum + '  dist/' + SLUG + '.zip\n').encode())
        if name == 'release-receipt.json':
            return io.BytesIO(json.dumps({'commit': COMMIT, 'version': VERSION, 'tag': 'v' + VERSION, 'archive_sha256': hashlib.sha256(data).hexdigest()}).encode())
    raise RuntimeError('OFFLINE DENIAL: unexpected network request')


def install():
    import socket
    import time
    import urllib.request
    def denied(*args, **kwargs):
        raise RuntimeError('OFFLINE DENIAL: socket access prohibited')
    socket.socket = denied
    urllib.request.urlopen = urlopen
    time.sleep = lambda seconds: None


def command(name, args):
    log({'effect': 'command', 'name': name, 'verb': args[0] if args else ''})
    if name in {'sudo', 'systemctl', 'curl', 'docker'}:
        log({'effect': 'FORBIDDEN', 'name': name}); return 97
    if name == 'git':
        if args[:2] == ['branch', '--show-current']: print('main')
        elif args[:3] == ['remote', 'get-url', 'fork']: print('git@github.com:' + REPOSITORY + '.git')
        elif args and args[0] == 'status': pass
        elif args and args[0] == 'fetch': pass  # simulated read, no real ref update
        elif args and args[0] == 'rev-parse': print('b' * 40 if scenario() == 'commit_mismatch' and 'refs/remotes/fork/main' in args else COMMIT)
        else: log({'effect': 'FORBIDDEN', 'name': name}); return 97
        return 0
    if name == 'svn':
        if args and args[0] == 'status': return 0
        if args[:3] == ['info', '--show-item', 'url']:
            print('https://plugins.svn.wordpress.org/' + ('unexpected' if scenario() == 'svn_origin' else SLUG)); return 0
        if args and args[0] == 'info': return 0
        if args and args[0] == 'ls':
            if scenario() == 'svn_unavailable': return 1
            print('<lists><list/></lists>'); return 0
        log({'effect': 'FORBIDDEN', 'name': name}); return 97
    if name == 'php':
        if '--require-fenced' in args: return 1 if scenario() == 'atomic_unresolved' else 0
        if '-r' in args: print('8.5.4')
        return 0
    if name == 'composer':
        if args and args[0] == 'audit':
            print('{}' if scenario() == 'incomplete_audit' else '{"advisories":[],"abandoned":[]}')
        return 0
    if name == 'make':
        if not args or args[0] != 'release': log({'effect': 'FORBIDDEN', 'name': name}); return 97
        if scenario() == 'missing_local_artifact': return 0
        sys.path.insert(0, str(root() / 'scripts'))
        import importlib.util
        spec = importlib.util.spec_from_file_location('candidate_builder', root() / 'scripts/build-release.py')
        builder = importlib.util.module_from_spec(spec); spec.loader.exec_module(builder)
        destination = root() / 'dist' / (SLUG + '.zip'); destination.parent.mkdir(exist_ok=True)
        if not (root() / 'producer.zip').exists():
            builder.build_zip(root() / 'src', destination, SLUG, root() / 'readme.txt'); builder.verify_zip(destination, SLUG)
            (root() / 'producer.zip').write_bytes(destination.read_bytes())
        else: destination.write_bytes((root() / 'producer.zip').read_bytes())
        return 0
    if name == 'integration':
        if scenario() == 'integration_failure': return 1
        if scenario() == 'replaced_local':
            target = root() / 'dist' / (SLUG + '.zip'); target.write_bytes(archive_bytes() + b'untrusted replacement')
            target.with_suffix('.zip.sha256').write_text(hashlib.sha256(target.read_bytes()).hexdigest())
        if scenario() == 'replaced_receipt':
            target = Path(os.environ['GREY_ROCK_VALIDATION_ZIP']).parent / 'receipt.json'
            target.write_text('{}')
        return 0
    if name == 'semgrep': return int(scenario() == 'semgrep_failure')
    if name == 'gitleaks': return int(scenario() == 'gitleaks_failure')
    if name == 'provider': return int(scenario() == 'provider_failure')
    if name == 'confirm':
        if scenario() == 'late_gate_failure':
            (root() / 'late-evidence-revoked').write_text('Independent release evidence revoked after confirmation\n')
        return 0
    if name == 'simulated-publisher':
        sys.path.insert(0, str(root() / 'scripts'))
        from release_artifact import verify_tree
        source, receipt, expected, commit, version = args
        verify_tree(source, receipt, expected, commit, version)
        output = root() / 'simulated-submission'
        shutil.copytree(source, output)
        log({'effect': 'SIMULATED_SUBMISSION', 'files': len([p for p in output.rglob('*') if p.is_file()])})
        return 0
    raise RuntimeError('Unknown offline command')
