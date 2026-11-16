# Security policy

DBNova is effectively a superuser console for a MySQL server, so security reports are taken seriously.

## Supported versions

| Version | Supported |
| ------- | --------- |
| 1.0.x   | Yes       |
| < 1.0   | No        |

## Reporting a vulnerability

Please **do not open a public issue** for a security problem.

Report it privately through GitHub: **Security → Report a vulnerability** on this repository (private vulnerability reporting). Include:

- what an attacker can do, and what they need first (a session, a MySQL account, network access);
- the steps or a request that reproduces it;
- the DBNova version or commit, PHP version and MySQL version.

You'll get an acknowledgement within a few days. Fixes are released as a patch version, and the advisory is published once a fixed version is available.

## Scope

In scope: DBNova's own code. Examples:

- authentication and sessions;
- SQL or command injection;
- credential exposure;
- bypassing read-only mode or confirmations;
- one DBNova user reaching another's data.

Out of scope:

- what a MySQL account can already do with its grants (DBNova deliberately lets users run any SQL their account allows);
- vulnerabilities in MySQL itself.

How DBNova is protected, and what it assumes, is described in [`.claude/docs/SECURITY.md`](.claude/docs/SECURITY.md).
