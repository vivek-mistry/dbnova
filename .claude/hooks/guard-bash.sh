#!/usr/bin/env bash
# PreToolUse (Bash): block commands that expose MySQL credentials or build shell strings.
set -euo pipefail

command=$(jq -r '.tool_input.command // empty')

if grep -Eq 'mysql(dump|admin)?([[:space:]]|$)' <<<"$command"; then
    if grep -Eq '(^|[[:space:]])(-p[^[:space:]]+|--password=)|MYSQL_PWD=' <<<"$command"; then
        echo "Blocked by DBNova rules: never pass MySQL passwords on the command line. Use a --defaults-extra-file (mode 0600) instead." >&2
        exit 2
    fi
fi

if grep -q 'fromShellCommandline' <<<"$command"; then
    echo "Blocked by DBNova rules: Process::fromShellCommandline is forbidden. Use array arguments." >&2
    exit 2
fi

exit 0
