# Phase 10 — Release & self-hosted installation

**Status:** not started
**Goal:** Someone else can install, run, upgrade and back up DBNova from the docs alone.

## Tasks
- [ ] Finalize `docs/INSTALL.md` (bare metal: Ubuntu + nginx + PHP-FPM + supervisor/systemd + cron)
- [ ] Docker Compose (app, worker, scheduler) with MySQL client binaries in the image, plus an example for reaching host MySQL
- [ ] A public `README.md` at the repo root (features, screenshots, requirements, quick start). Ask before creating it
- [ ] `CHANGELOG.md` + the `v1.0.0` semver tag (with the user's approval)
- [ ] Upgrade guide + backup and restore of `database/database.sqlite`
- [ ] Final `dbnova:install` UX pass (non-interactive flags for automation)
- [ ] Fresh-machine install test following only the docs

## Acceptance criteria
- A clean VM install following INSTALL.md works end to end (login, browse, import, export).
- The upgrade path is tested from a previous build.

## Completion notes
_(filled by /phase-done)_
