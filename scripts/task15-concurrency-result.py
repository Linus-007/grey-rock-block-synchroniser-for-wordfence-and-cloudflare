#!/usr/bin/env python3
"""Reject missing, duplicate, partial or failed real concurrency scenarios."""
import json
from pathlib import Path
import re
import sys


def validate(text):
    records = re.findall(r'^(PASS|FAIL) ([a-z-]+)/([a-z-]+);.*$', text, re.M)
    modes = {'stale', 'locked', 'rollback', 'expiry', 'independent', 'unknown',
             'unknown-rollback', 'unknown-expiry', 'connection-loss', 'unknown-connection-loss'}
    source = (Path(__file__).resolve().parents[1] / 'tests/integration/task14-mariadb-suite.php').read_text()
    cases = set(re.findall(r"'([a-z-]+)'", source.split('$cases = [', 1)[1].split('];', 1)[0]))
    expected = {(mode, case) for mode in modes for case in cases}
    actual = [(mode, case) for _, mode, case in records]
    passed = sum(status == 'PASS' for status, _, _ in records)
    result = {'completed': len(records), 'passed': passed, 'failed': len(records) - passed}
    if len(expected) != 180 or len(actual) != 180 or set(actual) != expected or passed != 180:
        raise ValueError('Incomplete/failed real InnoDB suite: ' + json.dumps(result))
    return result


if __name__ == '__main__':
    try:
        print(json.dumps(validate(Path(sys.argv[1]).read_text()), sort_keys=True))
    except (OSError, ValueError, IndexError) as error:
        raise SystemExit(str(error)) from error
