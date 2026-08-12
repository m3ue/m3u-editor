# AGENTS.md — m3u-editor

Compact guidance for AI agents working in this Laravel 13 / Filament v5 / Livewire 4 / Pest 4 IPTV editor.

## Foundation Context

This application's Laravel ecosystem packages and versions:

| Package | Ver | Role |
|---|---|---|
| `laravel/framework` | 13 | Core |
| `filament/filament` | 5 | Admin + guest panels |
| `livewire/livewire` | 4 | Reactive components |
| `laravel/horizon` | 5 | Queue supervisor (dvr-queue) |
| `pestphp/pest` | 4 | Testing |
| `laravel/sanctum` | 4 | API auth |
| `laravel/socialite` | 5 | OAuth |
| `laravel/reverb` | 1 | WebSockets |
| `laravel/ai` | 0.4 | AI SDK (agents, chatbots, embeddings) |
| `laravel/mcp` | 0.7 | MCP server runtime |
| `laravel/pail` | 1 | Log tailing |
| `laravel/pint` | 1 | Code formatter |
| `tailwindcss` | 4 | CSS |

## Skills (load on trigger)

| Skill | Trigger |
|---|---|
| `laravel-best-practices` | Writing/reviewing Laravel code — controllers, models, migrations, jobs, Eloquent |
| `pest-testing` | Writing/fixing tests, test files, assertions, datasets, browser tests |
| `configuring-horizon` | Horizon by name — supervisors, queues, dashboard, balancing, notifications |
| `tailwindcss-development` | Tailwind, CSS, styling, layout, responsive, dark mode |
| `socialite-development` | OAuth, Socialite, social login providers |
| `ai-sdk-development` | `Laravel\Ai\` namespace, AI agents, chatbots, TTS/STT, embeddings, RAG |
| `php-best-practices` | Code review, type safety, PSR standards, SOLID |

Skills live in `.agents/skills/` (mirrors in `.claude/`, `.github/`, `.cursor/`, `.ai/`).

## Conventions

- Use PHP 8 constructor property promotion — no empty zero-param constructors unless private.
- Explicit return type declarations + type hints on all method parameters.
- Enum keys in TitleCase: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments; only comment exceptionally complex logic.
- Follow existing conventions — check sibling files for structure, approach, and naming.
- **Laravel-style:** `php artisan make:` for new files (models, controllers, migrations, Resources). Use `config()` not `env()` outside config files. Eager-load relationships to avoid N+1. Form Request classes for validation, not inline. `ShouldQueue` for time-consuming jobs. Factories in tests, not manual model creation.
- **Testing:** Every change must be tested. Run `php artisan test --compact` (filtered). Do NOT delete tests without approval.

## Framework Version Quirks

**Laravel 13 structure:**
- No `app/Http/Kernel.php` — middleware in `bootstrap/app.php` via `withMiddleware()`.
- No `app/Console/Kernel.php` — commands auto-discovered; routes in `routes/console.php`.
- Model casts in `casts()` method, not `$casts` property.
- Column modifications in migrations must repeat ALL previously defined attributes.

**Filament v5 namespaces (differ from v3/v4):**
| Component | Namespace |
|---|---|
| Form fields | `Filament\Forms\Components\` |
| Infolists | `Filament\Infolists\Components\` |
| Layout (Grid, Section, Tabs) | `Filament\Schemas\Components\` |
| Schema utilities (Get, Set) | `Filament\Schemas\Components\Utilities\` |
| **Actions** | `Filament\Actions\` (NO sub-namespaces) |
| Icons | `Filament\Support\Icons\Heroicon` |

Filament v5 breakage: file visibility defaults to `private`; `Grid`/`Section`/`Fieldset` no longer auto-span columns.

## Repo Architecture

- **Filament admin + guest panel.** Guest: `app/Filament/GuestPanel/` with own Resources/Pages/Widgets. Admin scoping doesn't auto-protect guest routes. ~32 Resource files across both.
- **Horizon supervisor `dvr-queue`** owns `dvr`, `dvr-post`, `dvr-meta`. Jobs on these queues must stay on these queues.
- **`app/Api/`** is the Xtream API surface, not a Laravel package.
- **PRs target `experimental`** (not `main`). Upstream: `m3ue/m3u-editor`. Origin is fork.

## Workflow

```
# Run dev environment (all 4 services concurrently)
composer run dev

# Run tests (NEVER via Docker — 5+ min vs sub-second)
vendor/bin/pest                             # all
vendor/bin/pest --filter=TestName           # one test

# Format before finalizing
vendor/bin/pint --dirty --format agent

# Create files via artisan (never hand-write)
php artisan make:model Name --no-interaction
php artisan make:filament-resource Name --no-interaction

# Rebuild frontend if Vite manifest errors
npm run build   # or: composer run dev
```

## Laravel Boost MCP Tools

Boost MCP is wired via `opencode.json` → `php artisan boost:mcp`. Always prefer these over shell/file reads:

| Tool | Use for |
|---|---|
| `search-docs` | Version-scoped docs — call BEFORE writing framework code |
| `database-schema` | DB structure before migrations (summary:true first) |
| `database-query` | Read-only SQL instead of tinker |
| `get-absolute-url` | Every URL shared with user |
| `browser-logs` / `last-error` / `read-log-entries` | Debug capture |
| `application-info` | Package versions, PHP/Laravel versions |
| `list-artisan-commands` | Discover available artisan commands |

**`search-docs` usage:** pass multiple broad topic queries (e.g. `["resource table test", "create action"]`). Do NOT add package names to query strings. Pass `packages` array to scope.

## High-Cost Gotchas

1. **Bootstrap cache poisons artisan.** `bootstrap/cache/services.php` + `config.php` reference missing `App\Providers\QueueMonitorServiceProvider` (broken upstream). **Symptom:** every `php artisan *` crashes with "Class not found". **Fix:** `rm bootstrap/cache/services.php bootstrap/cache/config.php`. Re-occurs; delete before any artisan call when in doubt.
2. **`Queue::fake()` does NOT intercept `dispatch()` from model listeners.** Use `Bus::fake()` + `Http::preventStrayRequests()` in tests touching `Playlist` models. Canonical: `tests/Feature/DvrRecordingDownloadTest.php`.
3. **Filament downloads — never `Action::streamDownload()` for files >1MB** (per PR #1406). Buffers + base64s through Livewire. Use `Action::make('x')->url(route('x.download', $record))->openUrlInNewTab()` + `StreamedResponse` controller. Canonical: `app/Http/Controllers/DvrRecordingDownloadController.php`.
4. **Resource ownership — scope BOTH `getEloquentQuery` AND `getGlobalSearchEloquentQuery`.** Separate code paths; one without the other leaks records in search. Canonical: `app/Filament/Resources/DvrRecordings/DvrRecordingResource.php`.
5. **Controllers with model binding → `abort_unless($rec->user_id === $request->user()->id, 403)`.** Filament scoping doesn't extend to plain controllers.
6. **Storage ops on model, not controller.** `downloadResponse(): StreamedResponse` / `resolveStorageDisk(): string` / `resolveMimeType(): string` belong on the model. Test pattern: `Storage::fake('dvr')` + put + assert.
7. **WIP commit early.** Untracked files have zero protection (lost work to `git clean -fd`).

## Known Stale Signals

- **`.cursor/rules/laravel-boost.mdc`** (Mar 2026) declares Laravel v12 / Filament v4 / Livewire v3 — project is on v13 / v5 / v4. Don't trust it.
- **`boost.json` `packages`** truncated to `["filament/filament"]` — `search-docs` returns thin results for Horizon/Livewire/Pest/etc. Widening it improves output.
- **`bootstrap/cache/services.php`** permanently broken (upstream source issue) — not a local mistake to fix.

## Guardrails

**Always:**
- `search-docs` before writing framework code
- `php artisan make:` with `--no-interaction` (never hand-write migrations/models/Resources)
- `vendor/bin/pint --dirty --format agent` before finalizing PHP changes
- `php artisan test --compact` for any Model/Job/Resource/Controller change
- Named routes + `route()` over URL strings

**Never (without approval):**
- Edit a Job's `tries`/`backoff` without checking Horizon supervisor config
- Delete or rename tests
- Modify `composer.json`
- Create new top-level directories outside `app/`, `database/`, `resources/`, `routes/`, `tests/`
- Create documentation files