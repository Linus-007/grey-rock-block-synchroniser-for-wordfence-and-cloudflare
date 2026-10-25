#!/usr/bin/env python3
"""Fail-closed output filter. Arguments are file paths, never secret values."""
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys


def private_read(path):
    info = path.stat()
    if not stat.S_ISREG(info.st_mode) or stat.S_IMODE(info.st_mode) & 0o077:
        raise ValueError('Unsafe credential file')
    return path.read_bytes()


def main():
    process = None
    try:
        secrets = []
        run = sys.argv[1:2] == ['--run']
        env = Path(sys.argv[2] if run else sys.argv[1])
        fields = dict(line.split(b'=', 1) for line in private_read(env).splitlines()
                      if b'=' in line and not line.startswith(b'#'))
        for key, value in fields.items():
            if re.search(rb'(PASSWORD|TOKEN|SECRET|CREDENTIAL)', key) and not key.endswith(b'_FILE'):
                secrets.append(value)
        if not all(fields.get(key) for key in (b'ADMIN_PASSWORD_FILE', b'TEST_DB_PASSWORD', b'TEST_DB_ROOT_PASSWORD')):
            raise ValueError('Incomplete redaction inputs')
        admin = fields.get(b'ADMIN_PASSWORD_FILE')
        if admin:
            secrets.append(private_read(Path(os.fsdecode(admin))).strip())
        token_file = fields.get(b'CLOUDFLARE_TEST_JSON')
        if token_file:
            payload = json.loads(private_read(Path(os.fsdecode(token_file))))
            secrets.append(payload['token'].encode())
        if any(not value or b'\n' in value or b'\r' in value for value in secrets):
            raise ValueError('Invalid credential')
        secrets = sorted(set(secrets), key=len, reverse=True)
        # Line buffering handles arbitrary pipe chunk boundaries. Hex-generated
        # passwords need no shell/JSON decoding; also redact encoded API tokens.
        variants = set(secrets)
        for value in secrets:
            variants.add(json.dumps(value.decode())[1:-1].encode())
            variants.add(value.replace(b"'", b"'\\''"))
        pattern = re.compile(b'|'.join(re.escape(v) for v in sorted(variants, key=len, reverse=True))) if variants else None
        process = None
        stream = sys.stdin.buffer
        if run:
            if sys.argv[3:4] != ['--'] or not sys.argv[4:]:
                raise ValueError('Missing command')
            process = subprocess.Popen(sys.argv[4:], stdin=subprocess.DEVNULL,
                                       stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
            stream = process.stdout
        for line in stream:
            sys.stdout.buffer.write(pattern.sub(b'[REDACTED]', line) if pattern else line)
            sys.stdout.buffer.flush()
        return process.wait() if process else 0
    except BaseException:
        if process is not None and process.poll() is None:
            try:
                process.terminate()
                process.wait(timeout=2)
            except BaseException:
                process.kill()
        # Never include exception text, paths, input, configuration or traceback.
        sys.stderr.write('ERROR: Test output withheld: credential redaction failed.\n')
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
