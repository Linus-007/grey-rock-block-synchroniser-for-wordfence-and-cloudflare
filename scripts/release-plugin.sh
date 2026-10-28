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
  ./scripts/release-plugin.sh prepare
  ./scripts/release-plugin.sh validate
  ./scripts/release-plugin.sh publish X.Y.Z
  ./scripts/release-plugin.sh remote-verify X.Y.Z

Modes:
  self-test  Run nonpublishing validation; requires reviewed source changes.
  validate   Run every nonpublishing release gate from a clean committed repository.
  publish    Verify and consume the existing immutable GitHub artifact; publish SVN only.
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


verify_repository() {
	cd "$REPO_ROOT"

	[[ -d .git ]] || fail "Git repository is unavailable."
	[[ -f "$PLUGIN_ENTRY" ]] || fail "Plugin entry file is unavailable."
	[[ -f readme.txt ]] || fail "WordPress readme is unavailable."
	[[ -f Makefile ]] || fail "Makefile is unavailable."


	[[ "$(git branch --show-current)" == "main" ]] ||
		fail "The current Git branch is not main."

	[[ "$(git remote get-url fork)" == "$EXPECTED_FORK" ]] ||
		fail "The fork remote is not the Linus-007 repository."

	if [[ "$MODE" == "self-test" ]]; then
		git diff --check
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
	[[ -f "$RELEASE_CONFIG" ]] || fail "SVN release configuration is unavailable."
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
    require_command flock
    exec 9>"$WPORG_SVN_WORKING_COPY/.svn/greyrock-release.lock"
    flock --nonblock 9 || fail "Another release process owns the SVN working copy."

    # Read-only remote recovery must remain available after an ambiguous
    # commit that left local bookkeeping dirty. Publishing still requires clean.
    if [[ "$MODE" != "remote-verify" ]]; then
        [[ -z "$(svn status "$WPORG_SVN_WORKING_COPY")" ]] ||
            fail "The WordPress.org SVN working copy is not clean."
    fi
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

	if [[ "$MODE" == "publish" || "$MODE" == "remote-verify" ]]; then
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
	(cd "$REPO_ROOT" && python3 scripts/release_readiness.py --external-only) || fail "Mandatory release requirements remain unresolved."
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
	[[ "$MODE" == "publish" || "$MODE" == "remote-verify" ]] && return 0
	python3 scripts/release_artifact.py verify --archive "$RELEASE_ZIP" \
		--receipt "$CANDIDATE_RECEIPT" --receipt-sha256 "$CANDIDATE_RECEIPT_SHA" \
		--commit "$CANDIDATE_COMMIT" --version "$VERSION"
}

verify_github_main_checks() {
    python3 scripts/release_evidence.py
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
    echo "===== DISPOSABLE WORDPRESS, WORDFENCE AND MARIADB INTEGRATION ====="
    GREY_ROCK_TASK14_FENCING=1 GREY_ROCK_VALIDATION_ZIP="$CANDIDATE_ARCHIVE" ./scripts/integration-test-ci.sh

	echo "===== VERIFY THE SAME TESTED RELEASE ZIP ====="
	verify_release_candidate

	sha256sum "$RELEASE_ZIP" |
		tee "${RELEASE_ZIP}.sha256"

	if [[ "$MODE" == "self-test" ]]; then
		git diff --check
	else
		[[ -z "$(git status --porcelain=v1)" ]] ||
			fail "Tests changed the Git working tree."
	fi

	echo "PASS: All local release gates completed."
}

wait_for_github_release() {
    umask 077
    TEMP_DIR="$(mktemp -d)"
    CANDIDATE_ARCHIVE="$TEMP_DIR/candidate.zip"
    CANDIDATE_RECEIPT="$TEMP_DIR/receipt.json"
    CANDIDATE_COMMIT="$(git rev-parse HEAD)"
    CANDIDATE_RECEIPT_SHA="$(python3 scripts/release_evidence.py "$TEMP_DIR" "$VERSION" "$CANDIDATE_COMMIT")"
    STATE_DIR="$REPO_ROOT/reports/releases/$VERSION"
    mkdir -p "$STATE_DIR"
    if [[ -f "$STATE_DIR/github-evidence.json" ]]; then
        cmp "$TEMP_DIR/github-evidence.json" "$STATE_DIR/github-evidence.json" || fail "Immutable release identity changed; manual recovery required."
    else
        cp "$TEMP_DIR/github-evidence.json" "$STATE_DIR/github-evidence.json"
    fi
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
# Existing SVN translation/externals properties could change the committed
# bytes even when the working files match the receipt.
for target in [working_copy / 'trunk', working_copy / 'tags' / version]:
    properties = subprocess.run(['svn', 'proplist', '--xml', '--verbose', '--recursive', str(target)],
                                check=True, capture_output=True, text=True)
    for prop in ET.fromstring(properties.stdout).findall('.//property'):
        if prop.get('name') in {'svn:eol-style', 'svn:keywords', 'svn:special', 'svn:externals'}:
            raise SystemExit('SVN translation/externals property requires manual review: ' + prop.get('name'))
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

    if LC_ALL=C svn commit \
        --non-interactive \
        --config-dir "$WPORG_SVN_CONFIG_DIR" \
        --username "$WPORG_SVN_USERNAME" \
        --message "Release $VERSION" \
        "$svn_trunk" "$svn_tag" >"$STATE_DIR/svn-transaction.log" 2>&1; then
        SVN_COMMITTED=1
        python3 - "$STATE_DIR" <<'PYTHON'
import json, pathlib, re, sys
state = pathlib.Path(sys.argv[1])
record = json.loads((state / 'github-evidence.json').read_text())
matches = re.findall(r'^Committed revision ([0-9]+)\.$', (state / 'svn-transaction.log').read_text(), re.M)
record.update(stage='SVN PUBLISH; verification pending', svn_revision=int(matches[0]) if len(matches) == 1 else None)
(state / 'svn-committed.json').write_text(json.dumps(record, sort_keys=True) + '\n')
PYTHON
    else
        # A network error may occur after the server committed. Never rollback
        # or blindly retry a transaction whose outcome has not been resolved.
        SVN_COMMITTED=1
        echo "SVN transaction returned failure; outcome uncertain. Run remote-verify before retry." >&2
        return 1
    fi
    echo "SVN transaction succeeded; verifying authoritative revision."
    verify_remote_svn || { echo "POST_COMMIT_VERIFICATION_FAILED; do not recommit. Run remote-verify." >&2; return 1; }

	echo "PASS: WordPress.org SVN release $VERSION was published."
}

verify_remote_svn() {
    local revision
    revision="$(svn info --non-interactive --config-dir "$WPORG_SVN_CONFIG_DIR" \
        --username "$WPORG_SVN_USERNAME" --show-item last-changed-revision "$WPORG_SVN_URL/tags/$VERSION")" || return 1
    [[ "$revision" =~ ^[0-9]+$ ]] || fail "Remote SVN revision is invalid."
    python3 - "$STATE_DIR" "$revision" <<'PYTHON' || return 1
import json, pathlib, sys
state = pathlib.Path(sys.argv[1])
record = json.loads((state / 'github-evidence.json').read_text())
record.update(svn_revision=int(sys.argv[2]), stage='SVN PUBLISH; tree verification pending')
(state / 'svn-revision.json').write_text(json.dumps(record, sort_keys=True) + '\n')
PYTHON
    local exported="$TEMP_DIR/remote-$revision"
    svn export --non-interactive --config-dir "$WPORG_SVN_CONFIG_DIR" \
        --username "$WPORG_SVN_USERNAME" -r "$revision" \
        "$WPORG_SVN_URL/tags/$VERSION@$revision" "$exported" >/dev/null || return 1
    python3 scripts/release_artifact.py tree --target "$exported" --receipt "$CANDIDATE_RECEIPT" \
        --receipt-sha256 "$CANDIDATE_RECEIPT_SHA" --commit "$CANDIDATE_COMMIT" --version "$VERSION" || return 1
    python3 - "$STATE_DIR" "$revision" <<'PYTHON' || return 1
import json, pathlib, sys
state = pathlib.Path(sys.argv[1])
record = json.loads((state / 'github-evidence.json').read_text())
record.update(svn_revision=int(sys.argv[2]), destination='https://plugins.svn.wordpress.org/grey-rock-block-synchroniser-for-wordfence-and-cloudflare', stage='REMOTE VERIFY')
path = state / 'svn-verified.json'
if path.exists() and json.loads(path.read_text()) != record:
    raise SystemExit('Remote release receipt changed; manual recovery required')
temporary = state / 'svn-verified.pending'
temporary.write_text(json.dumps(record, sort_keys=True) + '\n')
temporary.replace(path)
PYTHON
    echo "AUTHORITATIVE_SVN_PUBLISHED version=$VERSION revision=$revision"
    echo "WordPress.org public-directory indexing may lag; it is not an SVN transaction failure."
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
    if [[ "${GREY_ROCK_PUBLISH_AUTHORIZATION:-}" == "PUBLISH $VERSION TO $EXPECTED_SVN_URL" ]]; then
        return 0
    fi
    [[ -t 0 ]] || fail "Noninteractive publication requires exact GREY_ROCK_PUBLISH_AUTHORIZATION."
	printf "Type PUBLISH %s to continue: " "$VERSION"
	local confirmation
	read -r confirmation < /dev/tty
	[[ "$confirmation" == "PUBLISH $VERSION" ]] || fail "Publication confirmation did not match."
}

publish_release() {
	wait_for_github_release
	verify_release_candidate
    if svn info --non-interactive "$WPORG_SVN_URL/tags/$VERSION" >"$TEMP_DIR/existing-tag.txt" 2>"$TEMP_DIR/existing-tag-error.txt"; then
        verify_remote_svn
        return 0
    fi
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
cd "$REPO_ROOT"

case "$MODE" in
	self-test|validate|prepare)
		if [[ -n "$REQUESTED_VERSION" ]]; then
			usage
			exit 2
		fi
		;;
	publish|remote-verify)
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
	git \
	python3
do
	require_command "$command_name"
done

cd "$REPO_ROOT"

resolve_version
python3 scripts/release_metadata.py --version "$VERSION"
if [[ "$MODE" == "prepare" ]]; then
    exit 0
fi
verify_repository

echo
echo "===== RELEASE IDENTITY ====="
printf "Mode:             %s\n" "$MODE"
printf "Version:          %s\n" "$VERSION"
printf "Git commit:       %s\n" "$(git rev-parse HEAD)"
printf "Git destination:  %s\n" "$(git remote get-url fork)"
printf "SVN destination:  %s\n" "$EXPECTED_SVN_URL"

echo
echo "===== GITHUB MAIN CHECKS ====="
verify_github_main_checks

if [[ "$MODE" == "publish" || "$MODE" == "remote-verify" ]]; then
    require_command svn
    load_and_verify_svn_configuration
    if [[ "$MODE" == "remote-verify" ]]; then
        wait_for_github_release
        verify_remote_svn
    else
        publish_release
    fi
else
    require_command make
    require_command php
    require_command sha256sum
    run_release_gates
    echo "RELEASE VALIDATION RESULT: PASS (no publication)"
fi

}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
	release_main "$@"
fi
