"""Fail-closed plugin-scoped CVE applicability verification."""
import json
from pathlib import Path
import re

CVE = "CVE-2026-115560"

REQUIRED_PATTERNS = (
    'session_start',
    'session_id',
    'session\\.serialize_handler',
    'session\\.upload_progress',
    'ini_set',
    'ini_alter',
    'session_module_name',
)


def verify_exception(root, cve):
    root = Path(root)

    if cve != CVE:
        raise ValueError("Unknown CVE exception: " + cve)

    record = json.loads(
        (root / "security/cve-2026-115560-exception.json").read_text()
    )

    if record.get("cve") != CVE:
        raise ValueError("CVE exception identifier mismatch")

    if record.get("classification") != (
        "not_exploitable_through_reviewed_plugin_source"
    ):
        raise ValueError("CVE exception classification invalid")

    if record.get("scope") != "src/**/*.php in this Grey Rock plugin only":
        raise ValueError("CVE exception scope mismatch")

    if record.get("runtime_mitigation") != (
        "session.upload_progress.enabled=0"
    ):
        raise ValueError("Required runtime mitigation not documented")

    if tuple(record.get("prohibited_source_patterns", [])) != REQUIRED_PATTERNS:
        raise ValueError("CVE exception source policy differs from reviewed rules")

    sources = sorted((root / "src").rglob("*.php"))
    if not sources:
        raise ValueError("No plugin PHP sources available for assessment")

    for path in sources:
        contents = path.read_text()
        for pattern in REQUIRED_PATTERNS:
            if re.search(pattern, contents, re.IGNORECASE):
                raise ValueError(
                    "CVE exception requires renewed review: "
                    + str(path.relative_to(root))
                )

    override = root / "tests/docker/php-security.ini"
    settings = [
        line.strip()
        for line in override.read_text().splitlines()
        if line.strip() and not line.lstrip().startswith(";")
    ]
    if settings != ["session.upload_progress.enabled=0"]:
        raise ValueError("PHP upload-progress mitigation invalid")

    compose = (root / "tests/docker/compose.yml").read_text()
    target = (
        "target: /usr/local/etc/php/conf.d/"
        "zz-greyrock-security.ini"
    )
    if compose.count(target) != 2:
        raise ValueError("PHP mitigation must cover both test services")

    for runner in ("integration-test.sh", "integration-test-ci.sh"):
        text = (root / "scripts" / runner).read_text()
        marker = "===== VERIFY PHP UPLOAD-PROGRESS MITIGATION ====="
        if text.count(marker) != 1:
            raise ValueError("Missing integration mitigation gate: " + runner)
        section = text.split(marker, 1)[1].split(
            "===== WAIT FOR WORDPRESS", 1
        )[0]
        if section.count('ini_get("session.upload_progress.enabled")') != 2:
            raise ValueError("Missing PHP runtime assertions: " + runner)

    return {
        "cve": CVE,
        "classification": record["classification"],
        "plugin_php_files_reviewed": len(sources),
        "runtime_mitigation": "verified in test configuration",
    }
