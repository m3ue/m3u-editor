---
description: Review a diff/PR against m3u-editor's project-specific PR standards (queries, complexity, Filament-first UI, no regressions, migration safety)
argument-hint: "[PR number | base-branch] (defaults to diffing against main)"
---

Review the current changes in `m3u-editor` against this project's PR standards.

## Scope

This command is a focused, standards-only pass — it checks the five project-specific rules below, not general correctness. For a broader correctness/simplification review, use `/code-review` (or `/code-review ultra` for a deep multi-agent pass); the two are complementary and can both be run on the same diff.

## Steps

1. **Load context**: invoke the `pr-review-standards` skill (`Skill` tool, skill name `pr-review-standards`). Also keep `filament-first` and `laravel-best-practices` in mind — `pr-review-standards` builds on both.
2. **Get the diff**:
   - If `$ARGUMENTS` looks like a PR number (e.g. `123` or `#123`), fetch it with `gh pr diff $ARGUMENTS` (run from `m3u-editor/`).
   - If `$ARGUMENTS` is a branch/ref name, run `git diff $ARGUMENTS...HEAD` from `m3u-editor/`.
   - If `$ARGUMENTS` is empty, run `git diff main...HEAD` from `m3u-editor/` (fall back to `master` if `main` doesn't exist).
3. **Walk the diff against the five rules** from `pr-review-standards`:
   1. Memory-efficient queries — flag unbounded `->all()`/`->get()` that should be `->cursor()` or a generator.
   2. Unnecessary complexity — raw `DB::statement()` over the query builder, new functions where extending an existing one would do, logic duplicated across files instead of using a shared `app/Services/*` or `app/Filament/Actions/*`. Also check for **stragglers**: if the PR introduces a new shared helper specifically to stop duplication, `grep` the codebase for other pre-existing call sites with the same inline pattern the helper replaces — this is the one check that requires looking outside the diff (see step 5).
   3. Framework-first UI — hand-rolled Blade/Alpine where a Filament component exists (see `filament-first`), excluding the EPG Viewer and in-app player, which are approved custom surfaces.
   4. No regressions — any existing default/behavior the diff touches (not just sync) that changes without being gated behind explicit user opt-in and isn't a stated bug fix. Give the `Process*` import job chains the most scrutiny (`app/Jobs/ProcessM3uImport*.php`, `app/Jobs/ProcessEpgImport*.php`, `app/Jobs/ProcessEpgSDImport.php`, `app/Jobs/ProcessVodChannels*.php`, `app/Jobs/ProcessChannelScrubber*.php`), plus `app/Sync/`, `app/Jobs/Sync*.php`, `app/Services/SyncPipelineService.php`, and `app/Services/NetworkChannelSyncService.php` — these are the app's flagship sync/import features — but check whatever feature area the diff actually touches.
   5. Migration/schema safety — if the diff includes a `database/migrations/*.php` file, check it even if nothing else stands out. Flag Postgres DDL (`CREATE INDEX`, etc.) that runs without `CONCURRENTLY` inside Laravel's default migration transaction on tables the `Process*`/`Sync*` job chains read or write (`channels`, `epg_channels`, `playlists`, `epgs`, etc.) — that locks out in-flight import/sync jobs for the duration of the build.
4. Only flag things actually in the diff, **except** the §2 straggler check and any control-flow context needed to evaluate §4/§5 (e.g. reading the non-diff code a changed function calls) — those legitimately require looking beyond the changed lines. Don't relitigate unrelated pre-existing issues the PR didn't touch or enable.
5. **Report findings** with the `ReportFindings` tool: most-severe first, each tagged with a `category` matching the rule it violates (`memory-efficient-queries`, `unnecessary-complexity`, `framework-first-ui`, `regression-risk`, `migration-safety`), with `file`/`line` pointing at the diff (or the straggler's actual location, for §2 stragglers). If nothing violates these standards, report an empty findings list and say so briefly — don't pad it with unrelated observations.
