# ADR 0005 — Polling for progress updates

**Status:** Accepted (2026-10-30)

## Context
Import/export progress, the process list and dashboard stats need periodic refresh.

## Decision
Use Inertia v3 polling (`usePoll`) or `useHttp` on an interval (2 s while active, stopped when the job is terminal). The worker writes progress at most every ~8 MB or 2 s.

## Alternatives
- **Laravel Reverb (websockets).** True push, but another long-running daemon, more ports and proxy config. Deferred until there's a clear need.
- **Server-Sent Events.** Simpler than websockets but holds PHP-FPM workers open. Rejected.

## Consequences
- Up to ~2 s of latency on progress, which is acceptable.
- Easy to swap for Reverb later, because the job row stays the source of truth.
