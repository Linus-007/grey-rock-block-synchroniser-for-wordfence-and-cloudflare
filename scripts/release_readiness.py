#!/usr/bin/env python3
"""Release-only gate: functional/package validation is never publication approval."""
import json
from pathlib import Path
import subprocess
import sys
from action_pins import validate
from release_evidence import verify, verify_main, verify_policy, REPOSITORY

ROOT = Path(__file__).resolve().parents[1]
REQUIRED = {'atomic_fencing', 'real_innodb', 'phpcs', 'composer_audit', 'semgrep', 'gitleaks', 'runtime_matrix'}


def main():
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument("--external-only", action="store_true")
    parser.add_argument("--main", action="store_true")
    args = parser.parse_args()
    failures = []
    try:
        pins, _ = validate(ROOT)
        failures.extend(pins)
        gates = json.loads((ROOT / 'security/release-gates.json').read_text())
        if set(gates) != REQUIRED:
            failures.append('Mandatory release-gate inventory is incomplete')
        if args.main:
            verify_policy()
            result = verify_main(REPOSITORY, subprocess.check_output(['git', 'rev-parse', 'HEAD'], text=True).strip())
        else:
            result = verify()
        print(json.dumps(result, sort_keys=True))
    except (OSError, ValueError, KeyError, TypeError, subprocess.SubprocessError) as error:
        failures.append('Mandatory release evidence unavailable/invalid: ' + str(error))
    # This independent oracle cannot be replaced by a catalog/environment flag.
    try:
        oracle = subprocess.CompletedProcess([], 0) if args.external_only else subprocess.run(['php', 'tests/static/task08-local-fencing-characterization.php', '--require-fenced'],
                                cwd=ROOT, capture_output=True, timeout=30)
        if oracle.returncode != 0:
            failures.append('atomic_fencing: actual Task 8 safety oracle failed')
    except (OSError, subprocess.TimeoutExpired):
        failures.append('atomic_fencing: oracle unavailable')
    for failure in failures:
        print(failure)
    print('RELEASE_READINESS=' + ('BLOCKED' if failures else 'VERIFIED_GATES'))
    return int(bool(failures))


if __name__ == '__main__':
    sys.exit(main())
