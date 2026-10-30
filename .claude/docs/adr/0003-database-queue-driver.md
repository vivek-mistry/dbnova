# ADR 0003 — Database (SQLite) queue driver by default

**Status:** Accepted (2026-10-30)

## Context
Imports and exports run for minutes to hours in a background worker. The spec suggested Redis.

## Decision
Keep `QUEUE_CONNECTION=database` (already configured). Use named queues `imports`, `exports`, `default`. Set `retry_after` above the maximum job runtime, `tries=1`, and run the worker with `--timeout=0`. Redis remains a documented optional `.env` switch.

## Alternatives
- **Redis (required).** Adds a service plus the phpredis extension or the predis package (a new dependency). Its throughput benefits don't matter for a single operator. Rejected as a requirement, kept as an option.
- **sync.** That would block HTTP requests. Rejected.

## Consequences
- No extra install steps.
- Recovery depends on the job heartbeat + `dbnova:recover-jobs`, not on queue-level retries (which we don't want for non-idempotent imports).
