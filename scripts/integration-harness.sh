#!/usr/bin/env bash
# Shared by the disposable harnesses. No command arguments are printed: they may
# contain sensitive values in future callers. Keep sudo policy and its PTY intact.
compose() {
    local seconds="${GREY_ROCK_COMPOSE_TIMEOUT_SECONDS:-300}" result=0
    local -a command=("${COMPOSE_COMMAND[@]}")
    if [[ ! "$seconds" =~ ^[1-9][0-9]*$ || ${#seconds} -gt 5 ]]; then
        echo "ERROR: GREY_ROCK_COMPOSE_TIMEOUT_SECONDS must be 1..99999." >&2
        return 2
    fi
    # Run redaction INSIDE sudo, before sudo output/journal capture. Secrets
    # are read from restricted files; command arguments contain only paths.
    local -a privilege=()
    if [[ "${command[0]:-}" == sudo ]]; then
        privilege=(sudo -n)
        command=("${command[@]:2}")
    fi
    if [[ -n "${RUNTIME_ENV:-}" ]]; then
        if [[ ! -f "$RUNTIME_ENV" ]]; then
            echo 'ERROR: Credential redaction input is unavailable.' >&2
            return 1
        fi
        command=(python3 "${REPO_ROOT}/scripts/redact-test-output.py" --run "$RUNTIME_ENV" -- "${command[@]}")
    fi
    # timeout must share the command privilege and manage the entire group.
    "${privilege[@]}" timeout --signal=TERM --kill-after=5s "${seconds}s" "${command[@]}" "$@" < /dev/null || result=$?
    if [[ "$result" -ne 0 ]]; then
        printf 'ERROR: Compose failed (exit %s; limit %ss + 5s kill grace).\n' "$result" "$seconds" >&2
        echo '124/137 may indicate timeout. Check sudo authorization (sudo -v), Docker health, and cleanup.log; verify project containers/volumes were removed before rerunning.' >&2
        echo 'For recurring stops, capture PID/PPID/PGID/SID/TPGID/state/wchan and signal provenance; avoid ps command arguments and Compose config output containing secrets.' >&2
    fi
    return "$result"
}
