#!/usr/bin/env bash
# PostToolUse (Edit|Write): format an edited PHP file with Pint.
set -euo pipefail

file_path=$(jq -r '.tool_input.file_path // empty')

if [[ -z "$file_path" || "$file_path" != *.php || ! -f "$file_path" ]]; then
    exit 0
fi

cd "$CLAUDE_PROJECT_DIR"
vendor/bin/pint --format agent "$file_path" >&2 || true
