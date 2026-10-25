"""One reviewed, fail-closed community rule adaptation; originals stay immutable."""
import hashlib
from pathlib import Path

TARGET = 'lang/security/injection/echoed-request.yaml'
UPSTREAM_SHA256 = '50179ab219afbc106ca8bdd095d74ddd7bb7f69827c3df90fefc1f5a1e041456'
# Intentionally limited to a complete double-quoted href template. Intersecting
# the PHP call pattern prevents regex-only matches from sanitizing other nodes.
SANITIZER = r'''  - patterns:
    - pattern: esc_url($URL)
    - pattern-regex: '(?<=<a href="<\?php echo )esc_url\([^;\r\n]*\)(?=; \?>")'
'''


def prepare(snapshot, destination, manifest):
    expected = manifest['community_php_rules']['files']
    actual = {p.relative_to(snapshot).as_posix(): hashlib.sha256(p.read_bytes()).hexdigest()
              for p in snapshot.rglob('*') if p.is_file()}
    if actual != expected or actual.get(TARGET) != UPSTREAM_SHA256:
        raise ValueError('Community snapshot changed; review WordPress adaptation')
    adapted = {}
    for name in sorted(actual):
        content = (snapshot / name).read_bytes()
        if name == TARGET:
            marker = b'  # Wordpress Escapes\n'
            if content.count(marker) != 1:
                raise ValueError('Unexpected echoed-request structure')
            content = content.replace(marker, marker + SANITIZER.encode(), 1)
        target = destination / name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(content)
        adapted[name] = hashlib.sha256(content).hexdigest()
    return {'upstream_commit': manifest['community_php_rules']['commit'],
            'target': TARGET, 'upstream_sha256': UPSTREAM_SHA256,
            'adapted_sha256': adapted[TARGET],
            'adapter_sha256': hashlib.sha256(Path(__file__).read_bytes()).hexdigest()}
