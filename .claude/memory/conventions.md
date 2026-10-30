# Repo Conventions (from the Laravel Vue starter kit)

## Backend

- Laravel 13, PHP `^8.3` (composer.json). Fortify only provides login, logout, throttling and password confirmation, all backed by MySQL (`AuthenticateMySQLUser`). Every other Fortify feature is off.
- Controllers live in `app/Http/Controllers` with grouped subfolders (`Settings/`). Form Requests live in `app/Http/Requests/<Area>/`.
- Shared validation rules are traits in `app/Concerns/`.
- Routes are split into files (`routes/web.php`, `routes/settings.php`) and use `Route::inertia()` for static pages.
- PHPStan (Larastan) runs at level 7 over `app/`, `config/`, `database/`, `routes/`. Pint is the formatter.
- `composer ci:check` = `npm run check` + `npm run types:check` + `composer test` (config:clear, pint --test, phpstan, artisan test).

## Frontend

- Pages: `resources/js/pages/`, with **lowercase folders** (`auth/`, `settings/`) and **PascalCase files** (`Profile.vue`).
- Components: `resources/js/components/` are flat PascalCase. shadcn-vue (new-york, neutral, reka-ui) lives in `components/ui/<name>/`.
- Layouts: `layouts/AppLayout.vue` → `layouts/app/AppSidebarLayout.vue`. Settings use `layouts/settings/Layout.vue`.
- Composables: `composables/useXxx.ts`. Types: `types/*.ts`. Utils: `lib/utils.ts` (`cn`).
- Icons: `@lucide/vue`. Toasts: `vue-sonner` via `lib/flashToast.ts`.
- Routes are imported with Wayfinder: `@/routes` (named) and `@/actions` (controllers). These are generated and gitignored.
- Build: Vite+ (`vp`). Lint/format: `npm run check`. Types: `vue-tsc`.

## Tests

- Pest 4. `tests/Feature/{Auth,Settings}`, `tests/Unit`. Create tests with `php artisan make:test --pest Name`.
- The Boost `testing-best-practices` skill is in `.cursor/skills/`.
