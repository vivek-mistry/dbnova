---
name: dbnova-security-review
description: Security checklist for DBNova changes. Run before closing any phase, and whenever code touches SQL building, Process execution, uploads, credentials, auth or destructive actions.
---

# DBNova Security Review

Review the diff for the phase (`git diff main...` or the uncommitted changes) against `.claude/docs/SECURITY.md`. For a deeper independent pass, delegate to the `dbnova-security-reviewer` agent with the list of changed files.

## Checklist
### SQL
- [ ] Every value is a bound parameter, with no interpolated values
- [ ] Every identifier goes through `IdentifierQuoter`, and catalog validation is used where practical
- [ ] No `DB::`, Eloquent or Query Builder against the managed server (the arch test passes)
- [ ] Read-only mode is enforced in the adapter for every new mutating method

### Processes
- [ ] Array arguments only, with no `fromShellCommandline` / `exec` / `shell_exec` / `system` / `passthru` / backticks
- [ ] Credentials only through `DefaultsFile` (0600, deleted in `finally`)
- [ ] Binary paths come from config

### Files & uploads
- [ ] UUID paths, and the original filename is never used as a path
- [ ] Zip entry validation, a zip-bomb guard, and a disk-space check
- [ ] Temp files are cleaned up on every path (success, failure, cancel)

### Web
- [ ] New routes are behind `auth` + `verified`, and destructive ones also behind `password.confirm`
- [ ] Rate limits on upload, query and login endpoints
- [ ] No secrets in Inertia props, logs, exceptions or job payloads
- [ ] Destructive actions: typed confirmation + an audit log entry
- [ ] No `v-html` with database-sourced content

## Output
Report the findings with their severity (high/medium/low), file and line, and the fix. A phase can't be closed while high-severity findings are open.
