# Grey Rock release stages and recovery

This is the current release procedure. Historical Task 9/15 reports describe
older implementations and are not current execution evidence. No publication
was authorized by the repair task. The commands below that publish require a
separate release authorization after review and successful hosted gates.

## PREPARE

Run `./scripts/release-plugin.sh prepare` and `make metadata-check`. These cheap
checks compare the version header, stable tag, README badge, Requires PHP,
WordPress metadata and public release notes. The builder also verifies public
metadata in the actual ZIP. The complete SVN tree is checked against that ZIP's
receipt. Maintenance headings 1.3.11–1.3.13 were restored from existing Git history;
no published tag was changed. Human review of claims remains necessary.

The CVE approval binds **every byte of every PHP file** in `src`. Changing the
version header changes this identity. Never regenerate an approval fingerprint
as part of a version bump. A changed PHP tree requires an actual new approval.
The current renewal and its original history were preserved by this repair.

## VALIDATE / CI

`make validate` and `make release VERSION=1.3.14` remain the existing local
validation/build interfaces. `./scripts/release-plugin.sh validate` additionally
requires a clean committed main matching fork/main, authenticated main-branch
workflow results, local scanners and the existing disposable WordPress,
Wordfence and real MariaDB harness. It does not call the live Cloudflare helper.
Do not treat local validation as hosted evidence or approval to publish.

The existing main workflows must pass at the exact source SHA and push event.
The integration manifest is exactly six jobs: Mandatory real InnoDB concurrency,
runtime-policy, and Security runtime (8.2), (8.3), (8.4), (8.5). Workflow path,
name, event, SHA, branch, latest attempt, job run identity and conclusions are
verified with authenticated GitHub API pagination. Partial manifests fail closed.

The existing release workflow still calls the existing mandatory security and
integration workflows. A cheap metadata preflight now precedes those calls.
All action SHAs remain pinned. Action deprecation/upgrades require a separate
review; this repair introduces no action-version migration.

## GITHUB RELEASE

Only after separately authorized publication, use the existing command:

```sh
make tag-release VERSION=1.3.14
```

It checks metadata, source cleanliness, destination, local and remote tag
absence, authenticated main checks, approval and the local fencing oracle before
building and creating/pushing an annotated tag. Every prerequisite runs with
fail-fast handling. Existing tags cause a stop, including failed release tags.
Never delete or force-move a published tag to retry a release.

The tag triggers `.github/workflows/release.yml`. Its producer uploads the
validated ZIP/checksum/receipt as the immutable `validated-release` artifact
(retained for 30 days). The GitHub publication job consumes that artifact,
compares exact archive/receipt bytes with producer outputs, and refuses to
modify any existing release. A failed tagged workflow with no release can be
retried on the same immutable tag using the existing workflow's dispatch:

```sh
gh workflow run release.yml --ref v1.3.14 -f version=1.3.14
```

Inspect failed jobs first. A partial existing release or expired artifact is a
manual recovery blocker; the scripts do not silently overwrite assets or
substitute a fresh build. After a completed stage has been recorded locally,
changing its run/artifact/release identity is also a recovery blocker.

## SVN PUBLISH

Use the existing private `~/.config/greyrock-release/svn.env` with
WPORG_SVN_USERNAME, WPORG_SVN_CONFIG_DIR, WPORG_SVN_WORKING_COPY and
WPORG_SVN_URL. The URL must equal:
`https://plugins.svn.wordpress.org/grey-rock-block-synchroniser-for-wordfence-and-cloudflare`.
The working copy must be clean and have that exact origin, trunk/tags/assets.
Cached SVN authentication must support noninteractive access. A working-copy
lock prevents concurrent publishers. Publication needs Bash, Git, Python, SVN
and flock; Docker, sudo, PHP, Composer and Semgrep are not publication tools.

Supply an authenticated GH_TOKEN with repository contents and Actions read
access and explicitly select a completed release run and attempt. These values
select evidence; the API must independently verify them. GITHUB_ACTIONS is never
set or required. For example, after reviewing the chosen run:

```sh
export GITHUB_REPOSITORY=Linus-007/grey-rock-block-synchroniser-for-wordfence-and-cloudflare
export GREY_ROCK_RELEASE_RUN_ID="$(gh api "repos/$GITHUB_REPOSITORY/actions/runs?head_sha=$(git rev-parse HEAD)&per_page=100" --jq '.workflow_runs | map(select(.path == ".github/workflows/release.yml" and .head_branch == "v1.3.14")) | max_by(.run_number) | .id')"
export GREY_ROCK_RELEASE_RUN_ATTEMPT="$(gh api "repos/$GITHUB_REPOSITORY/actions/runs/$GREY_ROCK_RELEASE_RUN_ID" --jq '.run_attempt')"
./scripts/release-plugin.sh publish 1.3.14
```

The interactive confirmation is `PUBLISH 1.3.14`. Explicitly authorized
noninteractive publication uses the exact version and complete destination:

```sh
GREY_ROCK_PUBLISH_AUTHORIZATION='PUBLISH 1.3.14 TO https://plugins.svn.wordpress.org/grey-rock-block-synchroniser-for-wordfence-and-cloudflare' ./scripts/release-plugin.sh publish 1.3.14
```

The publisher verifies the completed release run/attempt, mandatory control
steps, tag SHA and GitHub artifact ID/digest/source. It downloads that immutable
artifact and compares all public GitHub asset bytes to it. It verifies the ZIP,
checksum and complete receipt, extracts validated in-memory bytes, and compares
both staged SVN trees immediately before committing. It never rebuilds or
repeats Docker/PHP/security pipelines. CVE approval, pin policy, authenticated
mandatory evidence and exact artifact identity are checked again before commit.

## REMOTE VERIFY and recovery

Run this after a successful or ambiguous SVN transaction or a post-commit failure:

```sh
./scripts/release-plugin.sh remote-verify 1.3.14
```

Keep the authenticated run/attempt environment above. Read-only remote verification permits a dirty working copy after an ambiguous commit and preserves it; publishing still requires a clean working copy. The script reauthenticates
the immutable artifact and compares the entire versioned remote SVN tag at its
recorded revision. Matching existing publication is reusable without another
commit. A nonmatching tag is never overwritten. Remote receipts live under
`reports/releases/1.3.14/`: github-evidence.json, svn-transaction.log,
svn-committed.json, svn-revision.json and svn-verified.json. Preserve this directory
outside the checkout before cleaning generated reports. Copies alone never
satisfy current API/artifact/tree verification.

Commit success is recorded before post-commit checks. A failed commit command
can have an uncertain remote outcome; the working copy is deliberately retained
for investigation and remote verification rather than blindly rolled back or
recommitted. On pre-commit errors, only the publisher's allowed release paths
are reverted. Do not remove user changes or infer absence from a network error.
No post-commit pipeline uses `grep -q`; producers are consumed completely.

Authoritative SVN success and delayed WordPress.org directory indexing are
separate outcomes. Indexing lag cannot justify recommitting the same tag.

## Safe local regression commands

```sh
python3 -m unittest discover -s tests/python -p 'test_task07_publication*.py' -v
python3 -m unittest discover -s tests/python -p 'test_task09*.py' -v
python3 -m unittest discover -s tests/python -p 'test_task15_evidence.py' -v
make validate
./scripts/release-plugin.sh prepare
```

The existing offline adapter denies sockets and external mutation commands. It
now exercises the real authenticated evidence and immutable artifact verifier.
Additional tests execute the actual SVN adapter against isolated file://
repositories, with commit/export fault injection. These tests certify local
control flow, never live GitHub execution, scanners or WordPress.org service
behavior. No test may use production SVN, production WordPress or live rules.
