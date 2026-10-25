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


def required_controls(branches):
    controls = {
        'WordPress security checks': 'Run WordPress security checks',
        'Semgrep CE SAST': 'Resolve and run latest stable Semgrep CE',
        'Gitleaks secret scan': 'Scan full Git history and current working tree with free Gitleaks CLI',
        'Mandatory real InnoDB concurrency': 'Run all 180 disposable InnoDB scenarios',
    }
    controls.update({f'Security runtime ({branch})': 'Run disposable WordPress integration test' for branch in branches})
    return controls


def verify_jobs(jobs, controls, sha):
    for name, step in controls.items():
        origin = 'integration-gates' if (
            name == 'Mandatory real InnoDB concurrency'
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


def verify():
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
    env = os.environ
    repository = env.get('GITHUB_REPOSITORY', '')
    run = env.get('GITHUB_RUN_ID', '')
    attempt = env.get('GITHUB_RUN_ATTEMPT', '')
    sha = env.get('GITHUB_SHA', '')
    if (env.get('GITHUB_ACTIONS') != 'true' or not re.fullmatch(r'[\w.-]+/[\w.-]+', repository)
            or not run.isdigit() or not attempt.isdigit() or not re.fullmatch(r'[0-9a-f]{40}', sha)
            or not env.get('GH_TOKEN')):
        raise ValueError('Independent GitHub run evidence unavailable; local status values cannot authorize publication')
    if subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip() != sha:
        raise ValueError('Evidence source revision mismatch')
    if subprocess.check_output(['git', 'status', '--porcelain', '--untracked-files=normal'], cwd=ROOT, text=True).strip():
        raise ValueError('Evidence requires a clean source checkout')

    def get(path):
        request = urllib.request.Request('https://api.github.com/repos/' + repository + path,
            headers={'Authorization': 'Bearer ' + env['GH_TOKEN'], 'Accept': 'application/vnd.github+json',
                     'X-GitHub-Api-Version': '2022-11-28'})
        with urllib.request.urlopen(request, timeout=30) as response:
            return json.load(response)

    record = get('/actions/runs/' + run)
    if (record.get('head_sha') != sha or record.get('run_attempt') != int(attempt)
            or record.get('path') != '.github/workflows/release.yml'
            or record.get('event') not in {'push', 'workflow_dispatch'}):
        raise ValueError('Stale or wrong workflow run evidence')
    jobs = []
    for page in range(1, 21):
        payload = get(f'/actions/runs/{run}/attempts/{attempt}/jobs?per_page=100&page={page}')
        jobs.extend(payload['jobs'])
        if len(jobs) >= payload['total_count']:
            break
    else:
        raise ValueError('Incomplete GitHub job listing')
    spec = importlib.util.spec_from_file_location('runtime', ROOT / 'scripts/php-runtime.py')
    runtime = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(runtime)
    verify_jobs(jobs, required_controls(runtime.supported_branches()), sha)
    return {'revision': sha, 'run_id': run, 'attempt': attempt, 'controls': 'verified by GitHub API'}
