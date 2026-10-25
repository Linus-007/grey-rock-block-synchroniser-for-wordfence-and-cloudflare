#!/usr/bin/env python3
"""Run actual WordPress classes in separate processes; no Docker/service changes.

Requires an already prepared, disposable local WordPress MULTISITE installation
whose database name begins greyrock_task13_, all relevant tables InnoDB, current
candidate plugin active, and environment GREY_ROCK_TASK13_DISPOSABLE=YES.
No credentials are accepted on command lines. WordPress loads its own wp-config.
Use provider egress denial as a second barrier; worker bootstrap denies WP HTTP.
Forced-expiry mode is a fixture. --expiry real waits the actual 900-second TTL.
This is a stale-write oracle, NOT a complete transaction/release certification.
"""
import argparse
import os
from pathlib import Path
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]
CASES = (
    'ownership-add-manual', 'ownership-add-scheduled', 'ownership-remove',
    'log-permanent-success', 'log-expiring-success', 'log-permanent-failure',
    'log-expiring-transient', 'log-remove', 'site-reset-set-existing',
    'site-reset-create', 'site-reset-clear', 'network-reset-set',
    'network-reset-clear', 'network-manual-add', 'network-manual-remove',
    'scheduler-status', 'cleanup-final-row-delete', 'reconcile-reset',
)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--wordpress-root', type=Path, required=True)
    parser.add_argument('--case', choices=CASES, action='append')
    parser.add_argument('--scenario', choices=('stale', 'locked', 'rollback', 'expiry', 'independent', 'unknown', 'unknown-rollback', 'unknown-expiry', 'connection-loss', 'unknown-connection-loss'), default='stale')
    parser.add_argument('--expiry', choices=('forced', 'real'), default='forced')
    args = parser.parse_args()
    worker = ROOT / 'tests/integration/task13-mariadb-worker.php'
    probe = subprocess.run(['php', str(worker), '--probe'], capture_output=True)
    if probe.returncode:
        print('UNVERIFIED: mysqli runtime prerequisite is unavailable.')
        return 77
    if os.environ.get('GREY_ROCK_TASK13_DISPOSABLE') != 'YES':
        print('Refusing test: GREY_ROCK_TASK13_DISPOSABLE=YES is required.')
        return 2
    failed = False
    for case in args.case or CASES:
        with tempfile.TemporaryDirectory(prefix='greyrock-task13-') as directory:
            command = ['php', str(worker), str(args.wordpress_root.resolve()), directory, case]
            workers = []
            try:
                for role in ('A', 'B'):
                    workers.append(subprocess.Popen([*command, role, args.expiry, args.scenario],
                        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL))
                deadline = time.monotonic() + (970 if args.expiry == 'real' else 65)
                while any(p.poll() is None for p in workers):
                    if time.monotonic() >= deadline:
                        raise TimeoutError
                    time.sleep(0.1)
                codes = [p.returncode for p in workers]
                safe = codes == [0, 0]
                print(f'{case}: {"SAFE" if safe else "FAILED"}; scenario={args.scenario}; expiry={args.expiry}; exits={codes}', flush=True)
                failed |= not safe
            except BaseException:
                print(f'{case}: FAILED (bounded process/barrier error)', flush=True)
                failed = True
            finally:
                for process in workers:
                    if process.poll() is None:
                        process.kill()
                    process.wait(timeout=5)
    return 1 if failed else 0


if __name__ == '__main__':
    raise SystemExit(main())
