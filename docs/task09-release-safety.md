# Release policy and offline verification

Task 15 supersedes the historical runtime and gate status below. See
[the current support and evidence policy](task15-security-support.md).

Version remains 1.3.10. A buildable ZIP and passing local tests do not authorize
publication. The Task 8 atomic-fencing oracle still fails. Release is blocked.

`make validate` checks reviewed full-SHA action references and functional policy.
`make release VERSION=1.3.10` produces an unpublished package. The separate
`make release-readiness` requires every reviewed evidence category and executes
the actual Task 8 safety oracle with `--require-fenced`. `make tag-release`, the
GitHub publication workflow, and the WordPress publisher require that gate.
There is no command-line/environment bypass. The evidence register is reviewed
source, not a signed attestation or proof that a scanner ran. Future changes must
link genuine evidence; merely editing statuses does not resolve the oracle.

`security/action-pins.json` records official upstream release and commit pages,
versions, dates and verification limitations. `scripts/action_pins.py` accepts
only recorded repository/commit pairs, including composite action dependencies.
Updating dependencies requires reviewing upstream identity/release notes and
updating that inventory. This is identity verification, not a full upstream audit.

The GitHub release requires the matching existing tag event/context and checked
out commit. Its build job retains archive and receipt digests as job outputs.
The publication job compares downloaded files with these independent outputs
before invoking the pinned release action. Downloading a ZIP and its own checksum
alone is not authenticity verification. The workflow control plane and reviewed
producer code remain trusted; repository compromise is outside this guarantee.

The WordPress publisher consumes an already validated existing GitHub release.
It no longer creates/pushes tags. It freezes a private local candidate before
integration and retains its receipt digest. Both integrations consume that ZIP;
subsequent local changes fail verification. It checks successful required checks,
release workflow identity, tag commit, stable version, complete asset coverage,
checksum, and all ZIP entries against the independent local receipt. Remote ZIP
container timestamps may differ, but every entry's name/content/type must match.
The frozen local candidate supplies SVN content. Extraction uses validated bytes;
trunk/tag file trees are checked again immediately before the SVN commit. The
SVN origin and successful, valid tag inventory are required. The interactive
confirmation remains separate from validation. No publication is authorized here.

Offline tests execute the actual shell main and inline workflow code. Only
external integrations/scanners/Git/SVN/HTTP boundaries and the final SVN adapter
are simulated. Socket creation and mutation commands are denied; the successful
simulation compares every submitted file with the captured producer ZIP. Passing
simulations prove decision behavior for injected cases, not live GitHub/SVN,
scanner, WordPress or InnoDB correctness. The fixture's synthetic evidence and
PHP oracle success exist only in temporary copies. The real source remains
blocked. No provider request occurs in these tests.

Run locally without publication:

```sh
python3 scripts/action_pins.py
python3 -m unittest discover -s tests/python -p 'test_task09*.py' -v
make validate
make metadata-check
python3 scripts/build-release.py
python3 scripts/release_readiness.py # expected exit 1 until blockers are closed
```

The legacy integration helpers still require separately reviewed runtime and
provider configuration; they are not a permitted atomicity harness. Do not run
those helpers here. Follow `docs/task08-atomic-fencing-plan.md` to provision an
already authorized unprivileged disposable runner, install fail-closed Cloudflare
HTTP interception and egress denial, and reproduce the real race first. Preserve
`docs/task07-safe-recovery.md` and unknown-operation stop conditions. Never clear
pending markers by elapsed time. Independent installations need a designated
writer per Cloudflare account/zone; separate databases do not coordinate them.
