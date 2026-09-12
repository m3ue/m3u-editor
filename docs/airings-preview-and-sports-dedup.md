# Airings Preview & Sports Dedup — Key Takeaways

Session notes for PR #1490 (matched airings preview) and PR #1503 (DVR capacity).

## Airings preview (PR #1490)

- **Review blocker fixed**: `getEpisodeRecordingStatus()` must include `Purged` in
  the duplicate-check statuses. Retention deletes the recording FILE but keeps
  the DB row as a permanent "already recorded" sentinel — dropping `Purged`
  re-scheduled the same scripted episode after cleanup.
- **Edit preview fixed**: the preview previously returned early for existing
  rules and rendered the SAVED values. It now builds a temp rule from the
  form's CURRENT state (`series_title`/`channel_id`/`series_mode` via `onBlur`),
  spreading the record's attributes as the base for fields outside the form.
  Works for new and edited rules; nothing is persisted.
- **Preview mirrors the scheduler**: the dry run (`matchSeriesRuleDryRun`) runs
  the exact scheduling loop without creating rows; skip reasons are
  `already_scheduled` (in-window duplicate or pending recording) vs
  `already_recorded` (completed/purged episode).
- **Channel selector**: deduplicates by `title` (IPTV variants) and falls back
  to `name` — a null `title` previously crashed Filament's `Select` render
  (`isOptionDisabled` got a null label).

## Sports dedup window (no season/episode data)

The title-only dedup was a false dichotomy:

- Date-keyed identity → next-day replays double-recorded.
- Purge-forget → re-matches later in the season got missed.
- **Solution: title + dedup window.** A same-title airing within
  `sports_dedup_days` of an existing recording (any status incl. Purged) is a
  replay → skipped. Beyond the window → new event → recorded.
- Default window: **2 days**; per-rule override field
  (`sports_dedup_days` on the rule) wins over the per-DVR-setting default;
  **0 = record every same-title airing** (for providers using generic titles).
- **Playoff games need no special handling**: dedup keys on the PROGRAMME's
  title, and distinct titles ("Bruins at Kings - Game 2") never collide —
  every distinct game records; only identical titles within the window dedup.
- Scripted episodes (S/E present) keep exact identity + Purged blocking.

## CI checks (lint / tests / docker build)

- **Pint**: the repo's `pint.json` enables `Pint/laravel_blade`, which shells
  out to Node — run `npm ci` before `vendor/bin/pint --test` (as CI does), or
  Pint fails with "requires Node.js".
- **Pest on Postgres**: replicate CI with
  `DB_CONNECTION=pg_test` + `TEST_DB_*` env, `REDIS_PASSWORD` (Redis auth is
  required here), `REVERB_APP_SECRET` in `.env`, and `touch database/jobs.sqlite`
  (the jobs connection is SQLite).
- **Test flake lesson**: `Channel::factory()` randomizes `enabled` — a preview
  test that depended on the channel's EPG scope intermittently returned no
  airings. Always pin `'enabled' => true` in tests that assert EPG-driven UI.
- **Docker build validation**: `docker build --build-arg GIT_BRANCH=.. --build-arg GIT_COMMIT=..`
  reproduces the CI `build-check` job (image `m3u-editor:pr-check`).

## Deployment env (this dev box)

- The live container `m3u-editor2` runs image `m3u-editor:poolmerge-dev`, built
  with `docker build -t m3u-editor:poolmerge-dev .` from the working checkout
  and deployed via `C:\m3u-editor\compose.dev.yaml` (`up -d --force-recreate
  m3u-editor`). The `docker-compose.dev.yml` in the source tree is a DIFFERENT
  project (do not use it for the live deployment).
- opcache has `validate_timestamps=Off`: hot-patched files never load in FPM;
  only a full image rebuild + container recreate takes effect. Horizon queue
  workers need `php artisan horizon:terminate`.

## m3u-tv PR #282 (DVR capacity UI) — CI + test learnings

- **Fork-PR CI approval gate**: workflow runs from forks are `action_required`
  until a maintainer approves them (that is why the maintainer saw "checks
  skipped" on the original PR). Nothing can fix this from the fork side —
  approve the run on GitHub (Actions → run page → "Approve and run").
- **`dart format --set-exit-if-changed lib test` is a CI gate**: 11 feature
  files were unformatted after the dev merge; always run `dart format` before
  pushing.
- **`flutter analyze lib test` gate**: the local Flutter (3.47.2) flags lints
  the CI's pinned 3.44.8 may not (unnecessary_unawaited etc. — pre-existing on
  dev in files like notification_toast/row_action_menu). Fix YOUR files; use
  `mounted` (State) rather than `context.mounted` for State-owned contexts.
- **Test regressions found via a base worktree** (`git worktree add <dir>
  <base>` + run the same files): the 30s DVR poll introduced three real bugs —
  1) active+scheduled merge order flipped a started recording back to
  Scheduled (active must win); 2) a debug `uuid.substring(0, 8)` crashed on
  short fixture uuids; 3) a connect-time active refresh leaked recordings
  across account handoffs (the reverb onConnected refresh already covers it).
- **Preflight pass-through**: the live preflight must not swallow 403/404
  (backends type them as expired_token/stream_not_found) — only 5xx JSON
  messages should be surfaced; and replacing an error must not bypass its
  emission.
- **Deferring work to post-frame in build() breaks pump-based widget tests** —
  the repo's LiveTvScreen test contract expects synchronous EPG loading;
  revert such perf tweaks rather than rewriting repo tests.
- **Baseline isolation**: `release_matrix_documentation_test`,
  `tvos_port_drift_test`, `transcoding_contract_test`, and two
  `push_token_lifecycle_test` cases fail on the BASE with the local Flutter
  (version-documentation + contract tests) — environment noise, not PR
  regressions.