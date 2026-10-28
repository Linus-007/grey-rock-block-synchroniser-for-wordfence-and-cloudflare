"""Read GitHub's control-plane results, never a self-asserted status file.

Only the current release run/attempt at the exact clean checkout is accepted.
Trust boundary: repository workflow changes and GitHub runner integrity require
independent review/branch protection; repository administrators remain trusted.
"""
import importlib.util
import json
import os
import re
import subprocess
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


REPOSITORY = 'Linus-007/grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
INTEGRATION_JOBS = {'Mandatory real InnoDB concurrency', 'runtime-policy',
                    'Security runtime (8.2)', 'Security runtime (8.3)',
                    'Security runtime (8.4)', 'Security runtime (8.5)'}
MAIN_WORKFLOWS = {
    'Application Security': ('.github/workflows/security.yml',
        {'WordPress security checks', 'Semgrep CE SAST', 'Gitleaks secret scan'}),
    'Continuous Integration': ('.github/workflows/ci.yml', {'PHP compatibility'}),
    'WordPress Integration': ('.github/workflows/integration.yml', INTEGRATION_JOBS),
}


class GitHub:
    def __init__(self, repository=REPOSITORY):
        if repository != REPOSITORY:
            raise ValueError('Unexpected GitHub repository')
        self.repository = repository
        self.token = os.environ.get('GH_TOKEN') or os.environ.get('GITHUB_TOKEN')
        if not self.token:
            raise ValueError('Authenticated GitHub API verification requires GH_TOKEN')

    def request(self, path, binary=False):
        request = urllib.request.Request('https://api.github.com/repos/' + self.repository + path,
            headers={'Accept': 'application/octet-stream' if binary else 'application/vnd.github+json',
                     'X-GitHub-Api-Version': '2022-11-28'})
        # Artifact downloads redirect to a signed storage URL. Never forward
        # repository credentials to the redirect destination.
        request.add_unredirected_header('Authorization', 'Bearer ' + self.token)
        return request

    def get(self, path):
        with urllib.request.urlopen(self.request(path), timeout=30) as response:
            return json.load(response)

    def pages(self, path, key):
        rows = []
        total = None
        for page in range(1, 1001):
            payload = self.get(path + ('&' if '?' in path else '?') + f'per_page=100&page={page}')
            count = payload.get('total_count')
            batch = payload.get(key)
            if (type(count) is not int or count < 0 or not isinstance(batch, list)
                    or len(batch) > 100 or (total is not None and total != count)):
                raise ValueError('Invalid or changing GitHub pagination')
            total = count
            rows.extend(batch)
            if len(rows) > total or (len(rows) < total and len(batch) != 100):
                raise ValueError('Partial GitHub manifest')
            if len(rows) == total:
                ids = [r.get('id') for r in rows]
                if any(type(i) is not int or i <= 0 for i in ids) or len(set(ids)) != len(ids):
                    raise ValueError('Duplicate/invalid GitHub manifest identities')
                return rows
        raise ValueError('GitHub pagination exceeds bound')


def release_version():
    from wordpress_policy import header
    return header((ROOT / 'readme.txt').read_text(), 'Stable tag')


def verify_main(repository, sha):
    api = GitHub(repository)
    runs = api.pages(f'/actions/runs?head_sha={sha}&event=push&branch=main', 'workflow_runs')
    result = {}
    for name, (path, names) in MAIN_WORKFLOWS.items():
        matching = [r for r in runs if r.get('path') == path and r.get('name') == name]
        if not matching:
            raise ValueError('Missing workflow: ' + name)
        run = max(matching, key=lambda r: (r.get('run_number', 0), r.get('run_attempt', 0)))
        if (run.get('head_sha') != sha or run.get('head_branch') != 'main'
                or run.get('event') != 'push' or run.get('status') != 'completed'
                or run.get('conclusion') != 'success' or type(run.get('run_attempt')) is not int
                or run['run_attempt'] <= 0):
            raise ValueError('Unsuccessful/wrong workflow context: ' + name)
        jobs = api.pages(f"/actions/runs/{run['id']}/attempts/{run['run_attempt']}/jobs", 'jobs')
        if {j.get('name') for j in jobs} != names or len(jobs) != len(names):
            raise ValueError('Obsolete/incomplete mandatory job manifest: ' + name)
        for job in jobs:
            if (job.get('head_sha') != sha or job.get('status') != 'completed'
                    or job.get('conclusion') != 'success' or job.get('run_id') != run['id']):
                raise ValueError('Unsuccessful/wrong mandatory job: ' + str(job.get('name')))
        result[name] = {'id': run['id'], 'attempt': run['run_attempt']}
    return result


def required_controls(branches):
    controls = {
        'WordPress security checks': 'Run WordPress security checks',
        'Semgrep CE SAST': 'Resolve and run latest stable Semgrep CE',
        'Gitleaks secret scan': 'Scan full Git history and current working tree with free Gitleaks CLI',
        'runtime-policy': 'Resolve supported runtime matrix',
        'Mandatory real InnoDB concurrency': 'Run all 180 disposable InnoDB scenarios',
    }
    controls.update({f'Security runtime ({branch})': 'Run disposable WordPress integration test' for branch in branches})
    return controls


def verify_jobs(jobs, controls, sha):
    for name, step in controls.items():
        origin = 'integration-gates' if (
            name in {'Mandatory real InnoDB concurrency', 'runtime-policy'}
            or name.startswith('Security runtime (')
        ) else 'security-gates'
        expected_name = origin + ' / ' + name
        matches = [job for job in jobs if job.get('name') == expected_name]
        if len(matches) != 1:
            raise ValueError('Missing/ambiguous mandatory job: ' + name)
        job = matches[0]
        if job.get('head_sha') != sha or job.get('status') != 'completed' or job.get('conclusion') != 'success':
            raise ValueError('Unsuccessful or mismatched job: ' + name)
        steps = job.get('steps', [])
        selected = [s for s in steps if s.get('name') == step]
        if len(selected) != 1 or selected[0].get('conclusion') != 'success':
            raise ValueError('Missing/unsuccessful actual control step: ' + step)
        # No skipped installation, runtime verification, assertions or cleanup.
        if any(s.get('conclusion') != 'success' for s in steps):
            raise ValueError('Incomplete mandatory job: ' + name)


def verify_policy():
    policy = json.loads((ROOT / 'security/php-support.json').read_text())
    # An unresolved upstream advisory may be excepted only when this exact
    # plugin's documented non-applicability and runtime mitigation are verified.
    advisories = policy['unresolved_advisories']
    if advisories:
        from cve_applicability import verify_exception
        for advisory in advisories:
            cve = advisory.split(':', 1)[0].strip()
            if cve != 'CVE-2026-115560':
                raise ValueError('Unassessed upstream PHP advisory: ' + cve)
            verify_exception(ROOT, cve)
            from cve_approval import verify_approval
            verify_approval(ROOT, cve)
def verify():
    verify_policy()
    env = os.environ
    repository = env.get('GITHUB_REPOSITORY', 'Linus-007/grey-rock-block-synchroniser-for-wordfence-and-cloudflare')
    run = env.get('GREY_ROCK_RELEASE_RUN_ID') or env.get('GITHUB_RUN_ID', '')
    attempt = env.get('GREY_ROCK_RELEASE_RUN_ATTEMPT') or env.get('GITHUB_RUN_ATTEMPT', '')
    sha = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip()
    if not run.isdigit() or not attempt.isdigit() or int(run) <= 0 or int(attempt) <= 0:
        raise ValueError('Explicit release run ID and attempt required for external verification')
    api = GitHub(repository)
    if subprocess.check_output(['git', 'status', '--porcelain', '--untracked-files=normal'], cwd=ROOT, text=True).strip():
        raise ValueError('Evidence requires a clean source checkout')
    record = api.get('/actions/runs/' + run)
    if (record.get('head_sha') != sha or record.get('run_attempt') != int(attempt)
            or record.get('path') != '.github/workflows/release.yml'
            or record.get('event') not in {'push', 'workflow_dispatch'}
            or record.get('head_branch') != 'v' + release_version()
            or record.get('status') not in {'in_progress', 'completed'}
            or (record.get('status') == 'completed' and record.get('conclusion') != 'success')):
        raise ValueError('Stale or wrong workflow run evidence')
    jobs = api.pages(f'/actions/runs/{run}/attempts/{attempt}/jobs', 'jobs')
    spec = importlib.util.spec_from_file_location('runtime', ROOT / 'scripts/php-runtime.py')
    runtime = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(runtime)
    if any(j.get('run_id') != int(run) for j in jobs):
        raise ValueError('Release job belongs to wrong workflow run')
    verify_jobs(jobs, required_controls(runtime.supported_branches()), sha)
    return {'revision': sha, 'run_id': run, 'attempt': attempt, 'controls': 'verified by GitHub API'}


def fetch_release(directory, version, sha):
    """Authenticate the producer artifact, then compare every public asset byte."""
    import hashlib
    import io
    import zipfile
    from release_artifact import LIMIT, SLUG, verify as verify_artifact
    verify()
    api = GitHub()
    run_id = os.environ.get('GREY_ROCK_RELEASE_RUN_ID') or os.environ.get('GITHUB_RUN_ID')
    run = api.get('/actions/runs/' + run_id)
    if run.get('status') != 'completed' or run.get('conclusion') != 'success' or run.get('head_sha') != sha:
        raise ValueError('Release producer has not completed successfully')
    ref = api.get('/git/ref/tags/v' + version)['object']
    for _ in range(6):
        if ref.get('type') == 'commit':
            break
        if ref.get('type') != 'tag' or not re.fullmatch('[0-9a-f]{40}', ref.get('sha', '')):
            raise ValueError('Invalid release tag')
        ref = api.get('/git/tags/' + ref['sha'])['object']
    if ref.get('type') != 'commit' or ref.get('sha') != sha:
        raise ValueError('Stale release tag: never move or reuse it')
    artifacts = api.pages('/actions/runs/' + run_id + '/artifacts', 'artifacts')
    matches = [a for a in artifacts if a.get('name') == 'validated-release']
    if len(matches) != 1 or matches[0].get('expired') is not False:
        raise ValueError('Missing/expired/ambiguous immutable producer artifact')
    artifact = matches[0]
    producer = artifact.get('workflow_run', {})
    if producer.get('id') != int(run_id) or producer.get('head_sha') != sha:
        raise ValueError('Artifact belongs to another source or run')
    expected = artifact.get('digest', '')
    if not re.fullmatch(r'sha256:[0-9a-f]{64}', expected):
        raise ValueError('Independent GitHub artifact digest unavailable')

    def download(path):
        with urllib.request.urlopen(api.request(path, binary=True), timeout=60) as response:
            data = response.read(LIMIT + 1)
        if len(data) > LIMIT:
            raise ValueError('Downloaded artifact exceeds limit')
        return data

    bundle = download('/actions/artifacts/' + str(artifact['id']) + '/zip')
    if 'sha256:' + hashlib.sha256(bundle).hexdigest() != expected:
        raise ValueError('Immutable producer artifact digest mismatch')
    names = [SLUG + '.zip', SLUG + '.zip.sha256', 'release-receipt.json']
    with zipfile.ZipFile(io.BytesIO(bundle)) as archive:
        if sorted(archive.namelist()) != sorted(names):
            raise ValueError('Unexpected producer artifact manifest')
        if sum(i.file_size for i in archive.infolist()) > LIMIT:
            raise ValueError('Expanded producer artifact exceeds limit')
        data = {name: archive.read(name) for name in names}
    release = api.get('/releases/tags/v' + version)
    if release.get('tag_name') != 'v' + version or release.get('draft') is not False or release.get('prerelease') is not False:
        raise ValueError('Incomplete/nonstable GitHub release')
    # GitHub release responses can truncate assets; use the dedicated paginated
    # list endpoint (it returns an array, unlike Actions manifests).
    assets = []
    for page in range(1, 1001):
        batch = api.get(f"/releases/{release['id']}/assets?per_page=100&page={page}")
        if not isinstance(batch, list):
            raise ValueError('Invalid release assets')
        assets.extend(batch)
        if len(batch) < 100:
            break
    else:
        raise ValueError('Incomplete release assets')
    for name in names:
        selected = [a for a in assets if a.get('name') == name and a.get('state') == 'uploaded']
        if len(selected) != 1 or download('/releases/assets/' + str(selected[0]['id'])) != data[name]:
            raise ValueError('Public release asset differs from immutable producer: ' + name)
    checksum = data[names[1]].decode('ascii').split()
    if (len(checksum) != 2 or checksum[1].lstrip('*') not in {names[0], 'dist/' + names[0]}
            or checksum[0] != hashlib.sha256(data[names[0]]).hexdigest()):
        raise ValueError('Downloaded checksum mismatch')
    directory = Path(directory)
    archive_path = directory / 'candidate.zip'
    receipt_path = directory / 'receipt.json'
    archive_path.write_bytes(data[names[0]])
    receipt_path.write_bytes(data[names[2]])
    receipt_sha = hashlib.sha256(data[names[2]]).hexdigest()
    verify_artifact(archive_path, receipt_path, receipt_sha, sha, version)
    record = {'commit': sha, 'version': version, 'run_id': run['id'], 'attempt': run['run_attempt'],
              'artifact_id': artifact['id'], 'artifact_digest': expected, 'release_id': release['id'],
              'receipt_sha256': receipt_sha, 'archive_sha256': checksum[0]}
    (directory / 'github-evidence.json').write_text(json.dumps(record, sort_keys=True) + '\n')
    return receipt_sha


if __name__ == '__main__':
    import sys
    try:
        if len(sys.argv) == 4:
            print(fetch_release(*sys.argv[1:]))
        else:
            print(json.dumps(verify_main(REPOSITORY, subprocess.check_output(
                ['git', 'rev-parse', 'HEAD'], text=True).strip()), sort_keys=True))
    except (ValueError, OSError, KeyError, TypeError, subprocess.SubprocessError, __import__('zipfile').BadZipFile) as error:
        raise SystemExit('GitHub evidence blocked: ' + str(error))
