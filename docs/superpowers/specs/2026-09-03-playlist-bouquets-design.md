# Playlist Bouquets — Design Spec

- **Issue:** [#1391 "Playlist bouquets"](https://github.com/m3ue/m3u-editor/issues/1391)
- **Date:** 2026-09-03
- **Status:** Approved design, pre-implementation
- **Target branch:** `dev`

## 1. Summary

A **Bouquet** is a named, reusable, user-owned selection of a standard playlist's live groups, VOD groups, and series categories. Playlist aliases attach any number of bouquets; an alias's effective channel filter becomes the **union** of its existing manual `group_filter` and all attached bouquets' selections. Bouquets are **live references**: editing a bouquet immediately changes the output of every alias that uses it (the Xtream Codes "bouquets assigned per line" model).

This removes the need to re-select the same groups manually on every alias, which is the entire ask of issue #1391.

## 2. Scope

### In scope (v1)

1. `Bouquet` model + two new tables (`bouquets`, `bouquet_playlist_alias`).
2. Union resolution inside the existing `PlaylistAlias` accessors so every consumer (M3U, EPG, Xtream API, guest panel, stream-time validation, counts) inherits it with no call-site changes.
3. Filament `BouquetResource` (index page + slideOver modal CRUD) and a bouquet multi-select on the alias form.
4. "Add to Bouquet" row/bulk actions on Groups, VOD Groups, and Categories list pages, centralized in `PlaylistService`.
5. Per-bouquet `auto_include_new_live` / `auto_include_new_vod` toggles (default off): newly-appeared provider groups are appended during sync.
6. Provider-rename propagation into bouquet selections, piggybacking the existing `import_prefs` rename hook.
7. **Companion bug-fix (flagged separately in the PR):** the same rename hook also rewrites `playlist_aliases.group_filter` manual selections, which today silently stop matching after a provider rename.
8. EPG cache invalidation for attached aliases when a bouquet changes.

### Out of scope (documented follow-ups)

- **Custom-playlist aliases** (group identity is Spatie-tag-based; name storage makes this additive later — nullable `custom_playlist_id` on `bouquets` + UI, no data migration).
- **Merged-playlist aliases** (they have no group filtering at all today — the pre-existing "Pass 2" TODO at `app/Models/PlaylistAlias.php:403-404`, `:506`).
- **Series-category auto-include and rename propagation** (`SourceCategory` has no rename detection today; bouquet series selections inherit the same parity gap as manual `selected_categories`).
- **Merging bouquet-contributed groups into the custom live-group-sort Repeater.** In v1, bouquet-contributed groups not present in `live_group_order` fall into the existing deterministic CASE `ELSE` bucket (after explicitly ordered groups, in playlist order) — disclosed via helper text.
- Rule/regex-based bouquet membership (the tuliprox model). Static curated lists first; patterns are a possible v2.

## 3. Ground truth the design rests on

- Alias group selection lives in `playlist_aliases.group_filter` (jsonb, cast array): `selected_groups` / `selected_vod_groups` / `selected_categories` (**name** arrays) + `sort_live_groups_custom` / `live_group_order`. Names match `channels.group_internal` (provider-stable); series names resolve to `source_categories.source_category_id` at query time.
- Enforcement is baked into `PlaylistAlias::channels()` / `::series()` via the accessors `getAllowedLiveGroupNames()` / `getAllowedVodGroupNames()` / `getAllowedCategoryNames()` / `hasGroupFilter()` (`app/Models/PlaylistAlias.php:84-117`). A full consumer trace (24 consumers) confirmed all read through these four methods — including Xtream category-list endpoints (accessor output hoisted at `XtreamApiController.php:588-596`), guest panel resources, and stream-time membership validation (`XtreamStreamController.php:166, :203`).
- Sync churn: `groups` are upserted (matched by `source_group_id`, then `name_internal`), soft-deleted on vanish, force-deleted after 30 days. `source_groups` rows are hard-deleted and their IDs are unstable by design — the alias picker already dehydrates IDs→names for this reason. Renames detected via `source_group_id` are propagated **only** into `playlists.import_prefs` today (`ProcessM3uImport.php:1695-1702`).
- Identifier-convention verdict: name lists (the `import_prefs` convention: names + rename propagation) beat Group-ID pivots (membership silently dies on the 30-day force-delete cycle) and copy-on-assign snapshots (issue + prior art demand live references; un-assigning a snapshot is ill-defined). A `source_groups` pivot is disqualified outright (unstable IDs).

## 4. Data model

### Table `bouquets`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK → users, `cascadeOnDelete` | ownership |
| `playlist_id` | FK → playlists, `cascadeOnDelete` | **NOT NULL in v1** — a bouquet's names are only meaningful within one playlist |
| `name` | string | display name |
| `description` | text, nullable | |
| `group_selections` | jsonb, nullable, cast `array` | `{selected_groups: [names], selected_vod_groups: [names], selected_categories: [names]}` — no sort keys |
| `auto_include_new_live` | boolean, default false | append newly-appeared live groups during sync |
| `auto_include_new_vod` | boolean, default false | same for VOD |
| timestamps | | |

Indexes: `unique(['playlist_id', 'name'])`, `index('user_id')`.

### Table `bouquet_playlist_alias`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `bouquet_id` | FK → bouquets, `cascadeOnDelete` | |
| `playlist_alias_id` | FK → playlist_aliases, `cascadeOnDelete` | |

Indexes: `unique(['bouquet_id', 'playlist_alias_id'])`, `index('playlist_alias_id')` (the hot alias→bouquets direction). No pivot `sort_order`: union semantics make assignment order meaningless.

**Migration safety:** two plain `Schema::create` calls; no ALTER/index DDL on any table the Process*/Sync job chains write to. Safe on Postgres and SQLite. `down()` drops pivot first.

### Models

- `App\Models\Bouquet`: casts; `belongsTo(Playlist)`, `belongsTo(User)`, `belongsToMany(PlaylistAlias)`; accessors mirroring the alias vocabulary (`getSelectedLiveGroupNames()` etc.). `user_id` auto-set on create (the `AppServiceProvider` convention).
- `App\Models\PlaylistAlias`: new `bouquets(): BelongsToMany`; per-instance memoized effective-filter property (the `$resolvedCategoryIds` idiom).
- `App\Models\Playlist`: `hasMany(Bouquet)`.
- **Invariant:** `bouquet.playlist_id === alias.playlist_id`, enforced in the Filament form's option scoping **and** server-side (model-level validation/observer on attach), since API/console writes bypass the form. Not DB-expressible without triggers; do not add triggers.

## 5. Composition & resolution semantics (normative)

For each content type **independently** (live, VOD, series categories):

1. `effective(type) = manual group_filter[type] ∪ ⋃ attached bouquets' group_selections[type]`, deduplicated. Order-insignificant.
2. `effective(type)` empty → **unrestricted** for that type (fail-open — identical to today's `[]`-means-all sentinel used across all consumers). An alias with no bouquets and no manual selection behaves bit-for-bit as before.
3. `effective(type)` non-empty → only matching content is delivered, via the existing matching rules (live/VOD against `channels.group_internal` with the custom-channel fallbacks; categories resolved to `source_category_id` at query time).
4. An empty bouquet (or a type-empty bouquet) contributes nothing; attaching a bouquet expresses **inclusion only**, never exclusion.
5. A non-empty effective list matching nothing (all groups vanished) yields zero channels for that type — deliberate, matches current stale-filter behavior, self-heals when names return.
6. `hasGroupFilter()` is redefined over the **effective** lists, so a bouquet-only alias reports `true` (verified: no app-code consumers outside the model today, only tests — zero regression for bouquet-less aliases).
7. `sort_live_groups_custom` / `live_group_order` remain alias-level and manual-only in v1 (see out-of-scope).

### Resolution point & hard constraints

Resolution happens **only** inside the four accessors on `PlaylistAlias`:

- **R1 (critical):** resolution must **never** be implemented as an Eloquent attribute accessor/cast on `group_filter`. The Filament alias form binds `group_filter.*` state paths directly; an attribute-level union would be hydrated into the form and persisted on save, permanently materializing bouquet contents into the manual filter. `group_filter` stays raw; a regression test enforces this.
- **Identity fast path:** when the alias has no attached bouquets, accessors return the manual arrays **unchanged** (no `array_unique`/re-index) — existing SQL text and test assertions stay bit-for-bit identical.
- **Memoization:** one query (pivot join → `bouquets.group_selections`) per model instance, populated `??=`, serving all accessor calls; prefer `relationLoaded('bouquets')` when eager-loaded. Zero queries when `playlist_id` is null (custom/merged aliases short-circuit to manual-only). No cross-request cache — bouquet edits must take effect immediately.

### Knock-on behaviors (accepted, mirror manual-filter behavior)

- A bouquet-attached alias suppresses TMDB dynamic categories and dynamic-category ID stamping, exactly as a manual filter does today (deliberate curation; advertising empty categories is worse).
- Xtream `category_id` values are Group/Category PKs and are only ever *subtracted* from listings, never re-keyed. The empty-list `'all'` fallback behaves as it does for over-narrow manual filters.
- Stream-time membership validation (`XtreamStreamController`) inherits bouquet gating automatically when auth resolves the alias.

## 6. Sync & lifecycle behaviors

| Event | Behavior |
|---|---|
| Provider renames a group (Xtream, `source_group_id`-tracked) | `ProcessM3uImport::syncSourceGroupType()` already builds the old→new `$renames` map and rewrites `import_prefs` (`:1695-1702`). Extend that block: iterate the playlist's bouquets with `->cursor()`, map the type-appropriate selection key through `$renames`, save only changed rows. **Companion fix:** rewrite `playlist_aliases.group_filter` for the playlist's aliases in the same pass (today they silently break). Runs before channels re-import under new `group_internal`, so filters keep matching within the same sync. Idempotent; tolerates aborted/reverted syncs with the same semantics `import_prefs` has (self-heals next successful sync). |
| Provider renames a group (M3U source) | Untrackable everywhere in the system (no stable ID). Documented parity with `import_prefs`, not a regression. |
| Group vanishes | **Keep the name — never auto-prune.** Unmatched names are harmless in `whereIn`; vanish is often transient (30-day soft-delete window); auto-pruning is the Threadfin silent-loss failure mode. UI surfaces staleness instead. |
| Group returns under the same name | Membership resumes automatically, zero bookkeeping. |
| New provider group appears | During `syncSourceGroupType()`'s upsert pass, names new to that (playlist, type) are appended to bouquets with the matching `auto_include_new_*` flag on. Default off — no accidental widening. |
| Bouquet edited/deleted/detached | Clear attached aliases' cached EPG files (`clearPlaylistEpgCacheFile()`); never touch the existing target-change clearing in `AppServiceProvider`. Delete cascades pivot rows; UX warns with the list of affected aliases (deleting the only bouquet on a manual-filter-less alias widens it to all groups — confirmation required). No soft-deletes on bouquets. |
| Playlist deleted | `bouquets` cascade via FK; pivots cascade transitively. |
| Alias retargeted (source type or playlist changed) | The two form hooks that call `resetGroupFilter()` also clear the bouquet selection; relationship save syncs the pivot empty. Server-side invariant rejects mismatched attaches. |
| User renames `groups.name` (display name) | No effect — storage and enforcement use provider-stable identifiers, same as today. |

## 7. UI design (Filament v5, per `filament-first`)

### BouquetResource (new, `app/Filament/Resources/Bouquets/`)

- Nav group **Playlist**, label "Playlist Bouquets", modeled on `PlaylistAuthResource`: `getPages()` registers only `index`; `CreateAction`/`EditAction` slideOvers. Uses `HasUserFiltering` (both `getEloquentQuery()` and `getGlobalSearchEloquentQuery()`), plus a `BouquetPolicy`.
- Table: `name` (+ description), linked `playlist.name`, computed Live/VOD/Series counts from the name arrays, `aliases_count` (`withCount`) as the at-a-glance delete-safety signal, `updated_at`.
- Editor form: `name` (required, `Rule::unique` scoped to playlist+user, ignore self), `description`, `playlist_id` Select (standard playlists only, `->live()`, **`disabledOn('edit')`** — retargeting would invalidate every stored name; delete-and-recreate is the honest flow; changing it on create wipes the pickers), three `ModalTableSelect` pickers reusing `SourceGroupsTable` (live/vod) and `SourceCategoriesTable` with the existing ID↔name round-trip, and the two auto-include `Toggle`s with explanatory helper text.
- **Shared component extraction:** the ID↔name `ModalTableSelect` closures would become their third copy — extract a shared builder (e.g. `App\Filament\Forms\Components\SourceGroupModalSelect`) used by BouquetResource; migrating the two existing copies is an optional follow-up.
- **Staleness:** a `Callout` on edit diffs stored names against current `source_groups` ("2 saved groups no longer exist in the source: …") with a "Remove missing groups" hint action. The dehydrator **merges unresolvable-but-stored names back into the saved array** so provider churn never silently shrinks a bouquet on save (deliberate deviation from the alias picker's silent-drop).
- `DeleteAction`/`DeleteBulkAction` `requiresConfirmation()` listing affected aliases; an "in use by N aliases" `Callout` on edit.

### Alias form (PlaylistAliasResource)

- New `Fieldset "Bouquets"` at the top of the "Channel Filter (optional)" fieldset: `Select::make('bouquets')->multiple()->relationship('bouquets','name', modifyQueryUsing: scoped to `$get('playlist_id')` + auth user)->searchable()->preload()->live()`, visible only when `playlist_id` is set (v1 standard-only, consistent with the fieldset's existing per-FK visibility). Helper text states union semantics plainly.
- Name-only inline quick-create via `createOptionForm` + `createOptionUsing` (playlist/user injected from form state); a notification directs the user to the BouquetResource to pick groups. (Full inline editor is infeasible: `createOptionForm` can't read the parent form's `playlist_id` for `tableArguments`, and it would nest modal-in-modal-in-slideOver.)
- Live `Callout` when ≥1 bouquet selected: "Assigned bouquets contribute N live groups, N VOD groups, N series categories in addition to your manual selections."
- Manual pickers gain an optional "In bouquet" indicator column (`tableArguments(['bouquet_group_names' => ...])`; the tables already branch on arguments). Selecting an already-covered row is harmless — union dedupes. (A "checked-but-locked" row state is not feasible with `ModalTableSelect` and would mis-signal exclusion.)
- Sort Repeater gains helper text: "Groups contributed by bouquets that are not listed here are appended in source-playlist order."
- `resetGroupFilter()` additionally `$set('bouquets', [])`.
- Alias list table: `TextColumn::make('bouquets.name')->badge()->limitList(2)->toggleable()` after `alias_of`; add `bouquets` to the existing eager-load.
- Custom-playlist aliases keep their manual pickers and get no bouquet Select; the existing custom-playlist `Callout` gains one sentence noting bouquets currently apply to standard-playlist aliases.

### "Add to Bouquet" actions

- `PlaylistService::getAddGroupsToBouquetBulkAction()` / `getAddGroupsToBouquetAction()`, mirroring the Custom Playlist pair; wired into the same `ActionGroup`s on GroupResource, VodGroupResource, CategoryResource (row + bulk) and the three Edit pages.
- Modal: single bouquet Select scoped to the records' playlist + auth user, with the same name-only quick-create. Cross-playlist selections abort with a danger notification. Maps `name_internal` (groups) / `name` (categories) into the stored arrays with dedupe. Synchronous (jsonb merge — no queued job needed), success notification reports added/already-present counts.

### Language files

All new user-facing strings go through `lang/en/*.php` / `lang/en.json`; run `php artisan lang:merge-conflicts` before committing (pre-PR checklist).

## 8. Regression guarantees (must-not-change checklist)

For aliases with **no** bouquets, all of the following stay bit-for-bit identical: accessor return values (identity fast path), `hasGroupFilter()` truth table for manual-only inputs, `channels()`/`series()` SQL, custom-playlist alias filtering, live-group custom sort, Xtream `category_id` values and the `'all'` fallback, dynamic-category presence for unfiltered aliases, EPG cache path scheme and the target-change clearing hook, and alias `deleting` cleanup (bouquet pivots must cascade without disturbing it).

Additional guards:

- `SyncPipelineService.php:480/:484` reads a `$rule['group_filter']` key on auto-sync rules — an unrelated field that merely shares the name. Do not touch; note in the PR.
- Guest panel resources hand-roll their filters but call the accessors — they inherit bouquets automatically. Do **not** "clean them up" onto `channels()` in this PR (query-shape change, out of scope).

## 9. Test plan

**Conventions:** real pipeline, no `Bus::fake()` to paper over the import chain; `Http::preventStrayRequests()` in Playlist-touching tests; `createQuietly()` / `group_id => null` factory patterns to avoid cascaded imports. Add a `BouquetFactory`; keep the existing `makeAlias()` / direct-create patterns for models without factories.

**New feature tests:**

- `BouquetResourceTest` — Filament CRUD (names persisted, IDs hydrated), user/playlist scoping, per-playlist unique names, vanished-name preservation on save, delete confirmation naming affected aliases, staleness badge.
- `PlaylistAliasBouquetTest` — alias form attach/detach, option scoping, retarget clears bouquets, quick-create.
- `PlaylistAliasBouquetResolutionTest` — union semantics per §5 (manual-only / bouquet-only / both / emptied-bouquet fail-open per type / `hasGroupFilter()`), memoization (query-count assertion), zero-query short-circuit for custom/merged aliases.
- `BouquetRenamePropagationTest` — two-pass `ProcessM3uImport` with faked HTTP (renamed `category_name`, stable `category_id`): `source_groups`, `import_prefs`, bouquet selections, **and manual alias `group_filter`** (companion fix) all move; other playlists untouched; rename-collision skip path leaves bouquets alone. (First-ever coverage of the `:1695-1702` path — protects existing `import_prefs` behavior as a bonus.)
- `BouquetAutoIncludeTest` — new provider group appended only to flagged bouquets, per type.
- `GroupBouquetBulkActionTest` — merge/dedupe, cross-playlist abort, all three resource variants.
- `GuestPanelBouquetFilterTest` — VOD/Series query narrowing and badge counts for a bouquet-attached alias.

**Existing files gaining cases:** `PlaylistAliasTest` (union end-to-end via `channels()`/`series()`), `PlaylistAliasLiveGroupSortTest` (bouquet groups rank in the ELSE bucket), `PlaylistAliasCustomPlaylistFilterTest` (**R1 guard:** form save with attached bouquet persists only manual names into `group_filter`; custom alias cannot attach), `XtreamCategoryServiceTest` / `XtreamDynamicCategoriesTest` (narrowing + suppression from bouquet-only attachment; no change for bouquet-less), `XtreamApiControllerTest` (category/stream endpoints; `'all'` fallback), `EpgPlaylistAliasCacheInvalidationTest` (bouquet edit clears attached aliases' cache; target-change clearing unchanged).

## 10. Design rejections (for the record)

- **Pivot to `groups` by DB ID:** dies on the 30-day force-delete cycle; needs ID→name translation at read time; fourth identifier convention; can't extend to custom playlists (tags, no `groups` rows).
- **Copy-on-assign snapshot into `group_filter`:** diverges silently on bouquet edit; un-assigning can't attribute names to bouquet vs manual. (A "copy bouquet into manual filter" convenience action remains possible UI sugar later.)
- **Pivot to `source_groups`:** IDs are unstable by design (hard-deleted on absence); the alias picker already litigated this by dehydrating IDs→names.
- **RelationManager on PlaylistResource:** relation-manager tabs were deliberately removed there; the Playlist edit page is a wizard.
- **Bouquet-aware sort Repeater (v1):** its hydration reads `group_filter` raw and needs real rework; the runtime fallback is already deterministic and safe. Follow-up.
