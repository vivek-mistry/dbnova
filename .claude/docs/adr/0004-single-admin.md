# ADR 0004 — Single admin, registration disabled

**Status:** Superseded by [ADR 0008](0008-mysql-account-login.md) (2026-10-30)

## Context

The starter kit allows public registration. DBNova grants full control of a MySQL server to whoever logs in.

## Decision

Turn off `Features::registration()` in `config/fortify.php`. `php artisan dbnova:install` creates the one admin user. Keep 2FA and passkeys available and encourage them during install.

## Alternatives

- **Multi-user without roles.** More accounts with the same power means a larger attack surface and no clear benefit for V1.
- **Multi-user with roles (admin/read-only).** Valuable, but adds policies throughout the app. Deferred until after V1. The read-only _mode_ (ADR-independent config) covers part of that need.

## Consequences

- The registration tests and page are removed or adjusted in Phase 1.
- Future roles can be added with policies without changing this decision's data model (the `users` table stays).
