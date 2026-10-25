#!/usr/bin/env bash

set -Eeuo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RELEASE_CONFIG="${HOME}/.config/greyrock-release/svn.env"

EXPECTED_FORK="git@github.com:Linus-007/grey-rock-block-synchroniser-for-wordfence-and-cloudflare.git"
EXPECTED_SVN_URL="https://plugins.svn.wordpress.org/grey-rock-block-synchroniser-for-wordfence-and-cloudflare"
GITHUB_REPOSITORY="Linus-007/grey-rock-block-synchroniser-for-wordfence-and-cloudflare"

PLUGIN_SLUG="grey-rock-block-synchroniser-for-wordfence-and-cloudflare"
PLUGIN_ENTRY="src/${PLUGIN_SLUG}.php"
RELEASE_ZIP="dist/${PLUGIN_SLUG}.zip"

MODE="${1:-}"
REQUESTED_VERSION="${2:-}"

TEMP_DIR=""
SVN_PREPARED=0
SVN_COMMITTED=0
VERSION=""

usage() {
	cat <<'EOF'
Usage:
  ./scripts/release-plugin.sh self-test
  ./scripts/release-plugin.sh validate
  ./scripts/release-plugin.sh publish X.Y.Z

Modes:
  self-test  Run every nonpublishing release gate while this script is uncommitted.
  validate   Run every nonpublishing release gate from a clean committed repository.
  publish    Verify an existing validated GitHub release, then request SVN publication approval.
EOF
}

remove_unversioned_svn_files() {
	python3 - "$WPORG_SVN_WORKING_COPY" "$VERSION" <<'PYTHON'
from pathlib import Path
import shutil
import subprocess
import sys
import xml.etree.ElementTree as ET

working_copy = Path(sys.argv[1]).resolve()
version = sys.argv[2]

result = subprocess.run(
    ["svn", "status", "--xml", str(working_copy)],
    check=True,
    capture_output=True,
    text=True,
)

root = ET.fromstring(result.stdout)

for entry in root.findall(".//entry"):
    status = entry.find("wc-status")

    if status is None or status.get("item") != "unversioned":
        continue

    path = Path(entry.get("path", ""))

    if not path.is_absolute():
        path = (Path.cwd() / path).resolve()
    else:
        path = path.resolve()

    try:
        relative = path.relative_to(working_copy)
    except ValueError:
        continue

    allowed = (
        relative.parts[:1] == ("trunk",)
        or relative.parts[:2] == ("tags", version)
    )

    if not allowed:
        continue

    if path.is_dir():
        shutil.rmtree(path)
    elif path.exists() or path.is_symlink():
        path.unlink()
PYTHON
}

restore_svn_working_copy() {
	if [[ "$SVN_PREPARED" -ne 1 || "$SVN_COMMITTED" -eq 1 ]]; then
		return
	fi

	echo "Restoring the WordPress.org SVN working copy after failure." >&2

	svn revert --recursive \
		"$WPORG_SVN_WORKING_COPY/trunk" \
		"$WPORG_SVN_WORKING_COPY/tags" >/dev/null 2>&1 || true

	remove_unversioned_svn_files || true
}

cleanup() {
	result="$?"

	trap - EXIT INT TERM
	set +e

	if [[ "$result" -ne 0 ]]; then
		restore_svn_working_copy
	fi

	if [[ -n "$TEMP_DIR" && -d "$TEMP_DIR" ]]; then
		rm -rf "$TEMP_DIR"
	fi

	exit "$result"
}


fail() {
	echo "ERROR: $*" >&2
	exit 1
}

require_command() {
	command -v "$1" >/dev/null 2>&1 ||
		fail "Required command is unavailable: $1"
}

plugin_version() {
	sed -nE \
		's/^[[:space:]]*\*[[:space:]]Version:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+).*$/\1/p' \
		"$PLUGIN_ENTRY" |
	head -n 1
}

readme_stable_tag() {
	sed -nE \
		's/^[[:space:]]*Stable tag:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+).*$/\1/p' \
		readme.txt |
	head -n 1
}

validate_changed_files_for_self_test() {
	local unexpected=0
	local status_line
	local path

	while IFS= read -r status_line; do
		[[ -z "$status_line" ]] && continue

		path="${status_line:3}"

		if [[ "$path" != "scripts/release-plugin.sh" ]]; then
			echo "Unexpected repository change: $status_line" >&2
			unexpected=1
		fi
	done < <(git status --porcelain=v1 --untracked-files=all)

	[[ "$unexpected" -eq 0 ]] ||
		fail "Self-test permits only scripts/release-plugin.sh to be uncommitted."
}

verify_repository() {
	cd "$REPO_ROOT"

	[[ -d .git ]] || fail "Git repository is unavailable."
	[[ -f "$PLUGIN_ENTRY" ]] || fail "Plugin entry file is unavailable."
	[[ -f readme.txt ]] || fail "WordPress readme is unavailable."
	[[ -f Makefile ]] || fail "Makefile is unavailable."
	[[ -f "$RELEASE_CONFIG" ]] || fail "SVN release configuration is unavailable."

	[[ "$(git branch --show-current)" == "main" ]] ||
		fail "The current Git branch is not main."

	[[ "$(git remote get-url fork)" == "$EXPECTED_FORK" ]] ||
		fail "The fork remote is not the Linus-007 repository."

	if [[ "$MODE" == "self-test" ]]; then
		validate_changed_files_for_self_test
	else
		[[ -z "$(git status --porcelain=v1)" ]] ||
			fail "The Git working tree is not clean."
	fi

	git fetch --no-tags fork \
		'+refs/heads/main:refs/remotes/fork/main'

	[[ "$(git rev-parse HEAD)" == "$(git rev-parse refs/remotes/fork/main)" ]] ||
		fail "Local main does not match Linus-007 fork/main."
}

load_and_verify_svn_configuration() {
	# shellcheck disable=SC1090
	source "$RELEASE_CONFIG"

	for variable_name in \
		WPORG_SVN_USERNAME \
		WPORG_SVN_CONFIG_DIR \
		WPORG_SVN_WORKING_COPY \
		WPORG_SVN_URL
	do
		[[ -n "${!variable_name:-}" ]] ||
			fail "Missing SVN configuration: $variable_name"
	done

	[[ "$WPORG_SVN_URL" == "$EXPECTED_SVN_URL" ]] ||
		fail "SVN configuration targets an unexpected repository."

	[[ -d "$WPORG_SVN_WORKING_COPY/.svn" ]] ||
		fail "The WordPress.org SVN working copy is unavailable."

	[[ -z "$(svn status "$WPORG_SVN_WORKING_COPY")" ]] ||
		fail "The WordPress.org SVN working copy is not clean."
	[[ "$(svn info --show-item url "$WPORG_SVN_WORKING_COPY")" == "$EXPECTED_SVN_URL" ]] ||
		fail "The SVN working copy has an unexpected origin."

	svn info \
		--non-interactive \
		--config-dir "$WPORG_SVN_CONFIG_DIR" \
		--username "$WPORG_SVN_USERNAME" \
		"$WPORG_SVN_URL" >/dev/null

	for directory in trunk tags assets; do
		[[ -d "$WPORG_SVN_WORKING_COPY/$directory" ]] ||
			fail "SVN directory is missing: $directory"
	done
}

resolve_version() {
	local metadata_version
	local stable_tag

	metadata_version="$(plugin_version)"
	stable_tag="$(readme_stable_tag)"

	[[ "$metadata_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] ||
		fail "Plugin metadata contains an invalid version."

	[[ "$stable_tag" == "$metadata_version" ]] ||
		fail "Plugin version and readme Stable tag do not match."

	if [[ "$MODE" == "publish" ]]; then
		[[ "$REQUESTED_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] ||
			fail "Publish requires a semantic version in X.Y.Z format."

		[[ "$REQUESTED_VERSION" == "$metadata_version" ]] ||
			fail "Requested version does not match plugin metadata."
	fi

	VERSION="$metadata_version"

	printf "Resolved release version: %s\n" "$VERSION"
}

verify_release_does_not_exist() {
	# A GitHub release must already exist and be independently verified. Only
	# WordPress.org nonexistence is checked here; errors are never absence.
	local listing
	listing="$(svn ls --xml --non-interactive --config-dir "$WPORG_SVN_CONFIG_DIR" \
		--username "$WPORG_SVN_USERNAME" "$WPORG_SVN_URL/tags")" || fail "SVN tag inventory unavailable."
	printf '%s' "$listing" | python3 -c 'import sys, xml.etree.ElementTree as E; root=E.fromstring(sys.stdin.read()); assert root.tag == "lists" and root.find("list") is not None; sys.exit(1 if any(e.text == sys.argv[1] for e in root.findall(".//entry/name")) else 0)' "$VERSION" \
		|| fail "SVN version exists or its inventory is invalid."
}

verify_release_readiness() {
	(cd "$REPO_ROOT" && python3 scripts/release_readiness.py) || fail "Mandatory release requirements remain unresolved."
}

capture_release_candidate() {
	umask 077
	TEMP_DIR="$(mktemp -d)"
	CANDIDATE_ARCHIVE="$TEMP_DIR/candidate.zip"
	CANDIDATE_RECEIPT="$TEMP_DIR/receipt.json"
	cp "$RELEASE_ZIP" "$CANDIDATE_ARCHIVE"
	CANDIDATE_COMMIT="$(git rev-parse HEAD)"
	CANDIDATE_RECEIPT_SHA="$(python3 scripts/release_artifact.py create --archive "$CANDIDATE_ARCHIVE" \
		--receipt "$CANDIDATE_RECEIPT" --commit "$CANDIDATE_COMMIT" --version "$VERSION")"
	readonly CANDIDATE_ARCHIVE CANDIDATE_RECEIPT CANDIDATE_COMMIT CANDIDATE_RECEIPT_SHA
}

verify_release_candidate() {
	python3 scripts/release_artifact.py verify --archive "$CANDIDATE_ARCHIVE" \
		--receipt "$CANDIDATE_RECEIPT" --receipt-sha256 "$CANDIDATE_RECEIPT_SHA" \
		--commit "$(git rev-parse HEAD)" --version "$VERSION"
	python3 scripts/release_artifact.py verify --archive "$RELEASE_ZIP" \
		--receipt "$CANDIDATE_RECEIPT" --receipt-sha256 "$CANDIDATE_RECEIPT_SHA" \
		--commit "$CANDIDATE_COMMIT" --version "$VERSION"
}

verify_github_main_checks() {
	python3 - "$GITHUB_REPOSITORY" "$(git rev-parse HEAD)" <<'PYTHON'
import json
import sys
import urllib.error
import urllib.request

repository = sys.argv[1]
commit = sys.argv[2]

required = {
    "Application Security": (".github/workflows/security.yml", {
        "WordPress security checks", "Semgrep CE SAST", "Gitleaks secret scan",
    }),
    "Continuous Integration": (".github/workflows/ci.yml", {"PHP compatibility"}),
    "WordPress Integration": (".github/workflows/integration.yml", {"WordPress integration test"}),
}

url = (
    f"https://api.github.com/repos/{repository}/actions/runs"
    f"?head_sha={commit}&event=push&branch=main&per_page=100"
)

request = urllib.request.Request(
    url,
    headers={
        "Accept": "application/vnd.github+json",
        "User-Agent": "grey-rock-release-validation",
        "X-GitHub-Api-Version": "2022-11-28",
    },
)

try:
    with urllib.request.urlopen(request, timeout=30) as response:
        payload = json.load(response)
except (urllib.error.HTTPError, urllib.error.URLError) as error:
    raise SystemExit(f"GitHub workflow lookup failed: {error}")

latest = {}

for run in payload.get("workflow_runs", []):
    name = run.get("name")

    if name not in required or run.get("path", "").split("@", 1)[0] != required[name][0]:
        continue

    existing = latest.get(name)

    if (
        existing is None
        or run.get("run_number", 0) > existing.get("run_number", 0)
    ):
        latest[name] = run

failed = False

for name in sorted(required):
    run = latest.get(name)

    if run is None:
        print(f"{name}: NOT FOUND")
        failed = True
        continue

    status = run.get("status", "unknown")
    conclusion = run.get("conclusion") or "pending"

    print(f"{name}: {status} / {conclusion}")

    if (status != "completed" or conclusion != "success"
            or run.get("head_sha") != commit or run.get("head_branch") != "main"
            or run.get("event") != "push" or type(run.get("id")) is not int
            or run["id"] <= 0):
        failed = True
        continue

    jobs_url = f"https://api.github.com/repos/{repository}/actions/runs/{run['id']}/jobs?filter=latest&per_page=100"
    try:
        with urllib.request.urlopen(urllib.request.Request(jobs_url, headers=request.headers), timeout=30) as response:
            jobs_payload = json.load(response)
    except (urllib.error.HTTPError, urllib.error.URLError, ValueError):
        raise SystemExit("GitHub job verification unavailable; publication blocked.")
    jobs = jobs_payload.get("jobs")
    if (not isinstance(jobs, list) or jobs_payload.get("total_count") != len(jobs)
            or not jobs or len(jobs) > 100):
        raise SystemExit("GitHub job coverage is incomplete; publication blocked.")
    for expected in required[name][1]:
        matches = [job for job in jobs if job.get("name") == expected]
        if (len(matches) != 1 or matches[0].get("status") != "completed"
                or matches[0].get("conclusion") != "success"
                or matches[0].get("head_sha") != commit):
            failed = True
    if any(job.get("status") != "completed" or job.get("conclusion") != "success" for job in jobs):
        failed = True

if failed:
    raise SystemExit("Required GitHub checks have not all passed.")

print("PASS: Required GitHub checks passed.")
PYTHON
}

run_release_gates() {
	echo
	echo "===== LOCAL APPLICATION SECURITY ====="
	RUN_SEMGREP=1 ./scripts/security-check.sh

	echo "===== LOCAL FULL-HISTORY AND WORKTREE SECRET SCAN ====="
	python3 scripts/gitleaks-check.py

	echo
	echo "===== BUILD RELEASE ZIP ====="
	make release VERSION="$VERSION"

	[[ -f "$RELEASE_ZIP" ]] ||
		fail "Release ZIP was not created."

	echo
	capture_release_candidate
	echo "===== DISPOSABLE WORDPRESS INTEGRATION ====="
	GREY_ROCK_VALIDATION_ZIP="$CANDIDATE_ARCHIVE" ./scripts/integration-test.sh

	echo
	echo "===== LIVE CLOUDFLARE INTEGRATION ====="
	GREY_ROCK_VALIDATION_ZIP="$CANDIDATE_ARCHIVE" ./scripts/cloudflare-integration-test.sh

	echo
	echo "===== VERIFY THE SAME TESTED RELEASE ZIP ====="
	verify_release_candidate

	sha256sum "$RELEASE_ZIP" |
		tee "${RELEASE_ZIP}.sha256"

	if [[ "$MODE" == "self-test" ]]; then
		validate_changed_files_for_self_test
	else
		[[ -z "$(git status --porcelain=v1)" ]] ||
			fail "Tests changed the Git working tree."
	fi

	echo "PASS: All local release gates completed."
}

wait_for_github_release() {
	python3 - \
		"$GITHUB_REPOSITORY" \
		"$(git rev-parse HEAD)" \
		"$VERSION" \
		"$PLUGIN_SLUG" "$CANDIDATE_ARCHIVE" "$CANDIDATE_RECEIPT" "$CANDIDATE_RECEIPT_SHA" "$TEMP_DIR" <<'PYTHON'
import json
import sys
import time
import urllib.error
import urllib.request

from pathlib import Path
import hashlib
sys.path.insert(0, str(Path.cwd() / "scripts"))
from release_artifact import verify, LIMIT
repository, commit, version, plugin_slug, candidate, receipt, receipt_sha, temporary = sys.argv[1:]

headers = {
    "Accept": "application/vnd.github+json",
    "User-Agent": "grey-rock-release-verification",
    "X-GitHub-Api-Version": "2022-11-28",
}

runs_url = (
    f"https://api.github.com/repos/{repository}/actions/runs"
    f"?head_sha={commit}&per_page=50"
)

release_url = (
    f"https://api.github.com/repos/{repository}/releases/tags/v{version}"
)

workflow = None

for attempt in range(1, 41):
    request = urllib.request.Request(runs_url, headers=headers)

    with urllib.request.urlopen(request, timeout=30) as response:
        payload = json.load(response)

    candidates = [
        run
        for run in payload.get("workflow_runs", [])
        if run.get("name") == "Build and Release Plugin"
        and run.get("path", "").split("@", 1)[0] == ".github/workflows/release.yml"
        and run.get("head_sha") == commit
        and run.get("event") in {"push", "workflow_dispatch"}
        and run.get("head_branch") in {"main", "v" + version}
    ]

    if candidates:
        workflow = max(
            candidates,
            key=lambda run: run.get("run_number", 0),
        )

        status = workflow.get("status")
        conclusion = workflow.get("conclusion")

        print(
            f"GitHub release workflow: "
            f"{status} / {conclusion or 'pending'}"
        )

        if status == "completed":
            if conclusion != "success":
                raise SystemExit(
                    "GitHub release workflow did not succeed."
                )
            break

    if attempt == 40:
        raise SystemExit("GitHub release workflow timed out.")

    time.sleep(15)

release = None

for attempt in range(1, 21):
    request = urllib.request.Request(release_url, headers=headers)

    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            release = json.load(response)
    except urllib.error.HTTPError as error:
        if error.code != 404:
            raise
    else:
        break

    if attempt == 20:
        raise SystemExit("GitHub release was not created in time.")

    time.sleep(10)

if (release.get("tag_name") != "v" + version or release.get("draft") is not False
        or release.get("prerelease") is not False):
    raise SystemExit("GitHub release identity/version is not an approved stable release.")

def get_json(url):
    with urllib.request.urlopen(urllib.request.Request(url, headers=headers), timeout=30) as response:
        return json.load(response)

reference = get_json(f"https://api.github.com/repos/{repository}/git/ref/tags/v{version}").get("object", {})
for depth in range(5):
    if reference.get("type") == "commit":
        break
    if reference.get("type") != "tag" or not isinstance(reference.get("sha"), str):
        raise SystemExit("GitHub tag identity unavailable.")
    reference = get_json(f"https://api.github.com/repos/{repository}/git/tags/{reference['sha']}").get("object", {})
if reference.get("type") != "commit" or reference.get("sha") != commit:
    raise SystemExit("GitHub tag does not identify the validated commit.")

assets = release.get("assets", [])
for name in [f"{plugin_slug}.zip", f"{plugin_slug}.zip.sha256", "release-receipt.json"]:
    if len([a for a in assets if a.get("name") == name]) != 1:
        raise SystemExit("GitHub release artifact coverage is incomplete/duplicated.")

def download(name):
    url = f"https://github.com/{repository}/releases/download/v{version}/{name}"
    asset = next(a for a in assets if a.get("name") == name)
    if asset.get("browser_download_url") != url:
        raise SystemExit("Unexpected release asset origin.")
    with urllib.request.urlopen(urllib.request.Request(url, headers=headers), timeout=30) as response:
        data = response.read(LIMIT + 1)
    if len(data) > LIMIT:
        raise SystemExit("Release asset too large.")
    return data

archive = download(f"{plugin_slug}.zip")
checksum = download(f"{plugin_slug}.zip.sha256").decode("ascii").split()
if (len(checksum) != 2 or checksum[0] != hashlib.sha256(archive).hexdigest()
        or checksum[1].lstrip("*") not in {f"{plugin_slug}.zip", f"dist/{plugin_slug}.zip"}):
    raise SystemExit("GitHub artifact checksum mismatch.")
# The downloaded checksum/receipt are not authenticity anchors. The retained
# local producer receipt digest is independent of downloaded replacement files.
remote = Path(temporary) / "github.zip"
remote.write_bytes(archive)
verify(remote, receipt, receipt_sha, commit, version, equivalent=True)
remote_receipt = json.loads(download("release-receipt.json"))
if (remote_receipt.get("commit") != commit or remote_receipt.get("version") != version
        or remote_receipt.get("tag") != "v" + version
        or remote_receipt.get("archive_sha256") != hashlib.sha256(archive).hexdigest()):
    raise SystemExit("GitHub producer receipt is stale or has wrong identity.")
print("PASS: Existing GitHub release matches the independently retained complete candidate.")

PYTHON
}

schedule_svn_changes() {
	python3 - \
		"$WPORG_SVN_WORKING_COPY/trunk" \
		"$WPORG_SVN_WORKING_COPY/tags/$VERSION" <<'PYTHON'
from pathlib import Path
import subprocess
import sys
import xml.etree.ElementTree as ET

targets = [Path(value).resolve() for value in sys.argv[1:]]

for target in targets:
    result = subprocess.run(
        ["svn", "status", "--xml", str(target)],
        check=True,
        capture_output=True,
        text=True,
    )

    root = ET.fromstring(result.stdout)

    missing = []
    unversioned = []
    invalid = []

    for entry in root.findall(".//entry"):
        status = entry.find("wc-status")

        if status is None:
            continue

        item = status.get("item")
        path = entry.get("path", "")

        if item == "missing":
            missing.append(path)
        elif item == "unversioned":
            unversioned.append(path)
        elif item in {"conflicted", "obstructed", "incomplete"}:
            invalid.append((path, item))

    if invalid:
        details = ", ".join(
            f"{path} ({item})"
            for path, item in invalid
        )
        raise SystemExit(f"Invalid SVN state: {details}")

    for path in missing:
        subprocess.run(
            ["svn", "rm", "--force", path],
            check=True,
        )

    for path in unversioned:
        subprocess.run(
            ["svn", "add", "--parents", "--force", path],
            check=True,
        )
PYTHON
}

validate_svn_change_scope() {
	python3 - "$WPORG_SVN_WORKING_COPY" "$VERSION" <<'PYTHON'
from pathlib import Path
import subprocess
import sys
import xml.etree.ElementTree as ET

working_copy = Path(sys.argv[1]).resolve()
version = sys.argv[2]

result = subprocess.run(
    ["svn", "status", "--xml", str(working_copy)],
    check=True,
    capture_output=True,
    text=True,
)

root = ET.fromstring(result.stdout)
seen = False

for entry in root.findall(".//entry"):
    status = entry.find("wc-status")

    if status is None or status.get("item") == "normal":
        continue

    seen = True
    path = Path(entry.get("path", ""))

    if not path.is_absolute():
        path = (Path.cwd() / path).resolve()
    else:
        path = path.resolve()

    try:
        relative = path.relative_to(working_copy)
    except ValueError:
        raise SystemExit(f"SVN path is outside the working copy: {path}")

    allowed = (
        relative.parts[:1] == ("trunk",)
        or relative.parts[:2] == ("tags", version)
    )

    if not allowed:
        raise SystemExit(
            f"Unexpected SVN change outside release scope: {relative}"
        )

if not seen:
    raise SystemExit("No SVN release changes were detected.")

print("PASS: SVN changes are limited to trunk and the new version tag.")
PYTHON
}

sync_release_directory() {
	local source_directory="$1"
	local destination_directory="$2"

	python3 - "$source_directory" "$destination_directory" <<'PYTHON'
from pathlib import Path
import shutil
import sys

source = Path(sys.argv[1]).resolve()
destination = Path(sys.argv[2]).resolve()

if not source.is_dir():
    raise SystemExit(f"Release source is unavailable: {source}")

destination.mkdir(parents=True, exist_ok=True)

for child in destination.iterdir():
    if child.name == ".svn":
        continue

    if child.is_dir() and not child.is_symlink():
        shutil.rmtree(child)
    else:
        child.unlink()

for child in source.iterdir():
    target = destination / child.name

    if child.is_dir() and not child.is_symlink():
        shutil.copytree(child, target)
    else:
        shutil.copy2(child, target)
PYTHON
}

commit_wordpress_release() {
	echo
	echo "===== UPDATE WORDPRESS.ORG SVN ====="

	svn update \
		--non-interactive \
		--config-dir "$WPORG_SVN_CONFIG_DIR" \
		--username "$WPORG_SVN_USERNAME" \
		"$WPORG_SVN_WORKING_COPY"

	[[ -z "$(svn status "$WPORG_SVN_WORKING_COPY")" ]] ||
		fail "SVN working copy became dirty before release preparation."

	verify_release_does_not_exist

	local release_source="$PUBLICATION_SOURCE"
	local svn_trunk="$WPORG_SVN_WORKING_COPY/trunk"
	local svn_tag="$WPORG_SVN_WORKING_COPY/tags/$VERSION"

	[[ ! -e "$svn_tag" ]] ||
		fail "Local SVN tag directory already exists: $svn_tag"

	svn copy "$svn_trunk" "$svn_tag"
	SVN_PREPARED=1

	sync_release_directory "$release_source" "$svn_trunk"
	sync_release_directory "$release_source" "$svn_tag"

	schedule_svn_changes
	validate_svn_change_scope

	for metadata_file in \
		"$svn_trunk/$PLUGIN_SLUG.php" \
		"$svn_tag/$PLUGIN_SLUG.php"
	do
		local detected_version

		detected_version="$(
			sed -nE \
				's/^[[:space:]]*\*[[:space:]]Version:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+).*$/\1/p' \
				"$metadata_file" |
			head -n 1
		)"

		[[ "$detected_version" == "$VERSION" ]] ||
			fail "SVN plugin metadata does not match $VERSION."
	done

	for readme_file in \
		"$svn_trunk/readme.txt" \
		"$svn_tag/readme.txt"
	do
		local detected_stable_tag

		detected_stable_tag="$(
			sed -nE \
				's/^[[:space:]]*Stable tag:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+).*$/\1/p' \
				"$readme_file" |
			head -n 1
		)"

		[[ "$detected_stable_tag" == "$VERSION" ]] ||
			fail "SVN readme Stable tag does not match $VERSION."
	done

	echo
	echo "===== WORDPRESS.ORG SVN CHANGE SET ====="
	svn status "$WPORG_SVN_WORKING_COPY"

	echo
	verify_release_readiness
	verify_release_candidate
	for destination in "$svn_trunk" "$svn_tag"; do
		python3 scripts/release_artifact.py tree --target "$destination" --receipt "$CANDIDATE_RECEIPT" \
			--receipt-sha256 "$CANDIDATE_RECEIPT_SHA" --commit "$CANDIDATE_COMMIT" --version "$VERSION"
	done
	echo "===== COMMIT WORDPRESS.ORG RELEASE ====="

	svn commit \
		--non-interactive \
		--config-dir "$WPORG_SVN_CONFIG_DIR" \
		--username "$WPORG_SVN_USERNAME" \
		--message "Release $VERSION" \
		"$svn_trunk" \
		"$svn_tag"

	SVN_COMMITTED=1

	svn update \
		--non-interactive \
		--config-dir "$WPORG_SVN_CONFIG_DIR" \
		--username "$WPORG_SVN_USERNAME" \
		"$WPORG_SVN_WORKING_COPY"

	[[ -z "$(svn status "$WPORG_SVN_WORKING_COPY")" ]] ||
		fail "SVN working copy is not clean after publication."

	svn info \
		--non-interactive \
		--config-dir "$WPORG_SVN_CONFIG_DIR" \
		--username "$WPORG_SVN_USERNAME" \
		"$WPORG_SVN_URL/tags/$VERSION" >/dev/null

	echo "PASS: WordPress.org SVN release $VERSION was published."
}

publish_wordpress_svn() {
	verify_release_readiness
	verify_release_candidate
	PUBLICATION_SOURCE="$(python3 scripts/release_artifact.py extract --archive "$CANDIDATE_ARCHIVE" \
		--receipt "$CANDIDATE_RECEIPT" --receipt-sha256 "$CANDIDATE_RECEIPT_SHA" \
		--commit "$CANDIDATE_COMMIT" --version "$VERSION" --target "$TEMP_DIR/submission")"
	commit_wordpress_release
}

confirm_publication() {
	printf "Type PUBLISH %s to continue: " "$VERSION"
	local confirmation
	read -r confirmation < /dev/tty
	[[ "$confirmation" == "PUBLISH $VERSION" ]] || fail "Publication confirmation did not match."
}

publish_release() {
	verify_release_readiness
	verify_release_candidate
	wait_for_github_release
	verify_release_does_not_exist
	confirm_publication
	# This publisher consumes an existing validated release. It never creates
	# or pushes a tag, and never rebuilds the archive after the gates.
	publish_wordpress_svn
}

release_main() {
MODE="${1:-}"
REQUESTED_VERSION="${2:-}"
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
verify_release_readiness

case "$MODE" in
	self-test|validate)
		if [[ -n "$REQUESTED_VERSION" ]]; then
			usage
			exit 2
		fi
		;;
	publish)
		if [[ -z "$REQUESTED_VERSION" ]]; then
			usage
			exit 2
		fi
		;;
	*)
		usage
		exit 2
		;;
esac

for command_name in \
	bash \
	curl \
	git \
	make \
	php \
	python3 \
	sha256sum \
	svn
do
	require_command "$command_name"
done

cd "$REPO_ROOT"

verify_repository
load_and_verify_svn_configuration
resolve_version

echo
echo "===== RELEASE IDENTITY ====="
printf "Mode:             %s\n" "$MODE"
printf "Version:          %s\n" "$VERSION"
printf "Git commit:       %s\n" "$(git rev-parse HEAD)"
printf "Git destination:  %s\n" "$(git remote get-url fork)"
printf "SVN destination:  %s\n" "$WPORG_SVN_URL"

echo
echo "===== GITHUB MAIN CHECKS ====="
verify_github_main_checks

if [[ "$MODE" == "publish" ]]; then
	verify_release_does_not_exist
fi

run_release_gates

if [[ "$MODE" == "publish" ]]; then
	publish_release
else
	echo
	echo "RELEASE VALIDATION RESULT: PASS"
	echo "No Git tag, GitHub release or WordPress.org SVN change was made."
fi

}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
	release_main "$@"
fi
