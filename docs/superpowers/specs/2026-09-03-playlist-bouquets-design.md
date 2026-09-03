# Playlist Bouquets — Design Spec

- **Issue:** [#1391 "Playlist bouquets"](https://github.com/m3ue/m3u-editor/issues/1391)
- **Date:** 2026-09-03 (revised same day: custom-playlist aliases moved into v1 scope)
- **Status:** Approved design, pre-implementation
- **Target branch:** `dev`

## 1. Summary

A **Bouquet** is a named, reusable, user-owned selection of a playlist's groups — either a **standard playlist's** provider groups (live/VOD) and series categories, or a **custom playlist's** groups (its tag names unioned with fallback provider group names). Playlist aliases attach any number of bouquets targeting the same playlist; an alias's effective channel filter becomes the **union** of its existing manual `group_filter` and all attached bouquets' selections. Bouquets are **live references**: editing a bouquet immediately changes the output of every alias that uses it (the Xtream Codes "bouquets assigned per line" model).

This removes the need to re-select the same groups manually on every alias, which is the entire ask of issue #1391.

## 2. Scope

### In scope (v1)

1. `Bouquet` model + two new tables (`bouquets`, `bouquet_playlist_alias`), supporting **standard-playlist and custom-playlist targets** (exactly one per bouquet).
2. Union resolution inside the existing `PlaylistAlias` accessors so every consumer (M3U, EPG, Xtream API, guest panel, stream-time validation, counts) inherits it with no call-site changes — for both target types.
3. Filament `BouquetResource` (index page + slideOver modal CRUD) with a target selector and per-target picker variants, and a bouquet multi-select on the alias form for standard **and** custom aliases.
4. "Add to Bouquet" row/bulk actions on the provider-group resources (Groups, VOD Groups, Categories), centralized in `PlaylistService`. (These surfaces are standard-playlist-only by nature; see out-of-scope for the custom equivalents.)
5. Per-bouquet `auto_include_new_live` / `auto_include_new_vod` toggles (default off) for **standard targets**: newly-appeared provider groups are appended during sync. Hidden and normalized to `false` for custom targets (no provider sync exists there).
6. Provider-rename propagation into standard-target bouquet selections, piggybacking the existing `import_prefs` rename hook.
7. **Companion bug-fix (flagged separately in the PR):** the same provider-rename hook also rewrites standard aliases' manual `playlist_aliases.group_filter`, which today silently stops matching after a provider rename.
8. **Tag-rename propagation (the custom-world twin of #6/#7):** renaming a custom playlist's group/category tag rewrites that playlist's bouquet selections **and** its aliases' manual `group_filter` — today those also break silently.
9. EPG cache invalidation for attached aliases (either target type) when a bouquet changes.
10. `DuplicateCustomPlaylist` and `DuplicatePlaylist` copy the playlist's bouquets to the duplicate (selections verbatim, **no pivot rows** — the duplicate has no aliases).

### Out of scope (documented follow-ups)

- **Merged-playlist aliases** (they have no group filtering at all today — the pre-existing "Pass 2" TODO at `app/Models/PlaylistAlias.php:403-404`, `:506`). Attach is blocked; accessors short-circuit.
- **Series-category auto-include and rename propagation for standard targets** (`SourceCategory` has no rename detection today; bouquet series selections inherit the same parity gap as manual `selected_categories`).
- **"Add to Bouquet" actions on the CustomPlaylist RelationManagers.** The Groups tab shows tags only — half the selectable namespace (fallback provider names have no rows there); an action reaching half the namespace mis-teaches the model, and custom playlists are hand-curated with few groups. The bouquet editor's picker over the complete union is the v1 surface. Additive later if demand materializes.
- **Auto-include for custom targets** ("new" defined as newly-created/auto-synced tags): definable via a `Tag::created` hook, but scope creep with surprise-widening risk.
- **Merging bouquet-contributed groups into the custom live-group-sort Repeater.** In v1, bouquet-contributed groups not present in `live_group_order` fall into the existing deterministic CASE `ELSE` bucket (after explicitly ordered groups, in playlist order) — disclosed via helper text. Applies identically to both target types.
- Rule/regex-based bouquet membership (the tuliprox model). Static curated lists first; patterns are a possible v2.

## 3. Ground truth the design rests on

### Standard playlists

- Alias group selection lives in `playlist_aliases.group_filter` (jsonb, cast array): `selected_groups` / `selected_vod_groups` / `selected_categories` (**name** arrays) + `sort_live_groups_custom` / `live_group_order`. Names match `channels.group_internal` (provider-stable); series names resolve to `source_categories.source_category_id` at query time.
- Sync churn: `groups` are upserted (matched by `source_group_id`, then `name_internal`), soft-deleted on vanish, force-deleted after 30 days. `source_groups` rows are hard-deleted and their IDs are unstable by design — the alias picker already dehydrates IDs→names for this reason. Renames detected via `source_group_id` are propagated **only** into `playlists.import_prefs` today (`ProcessM3uImport.php:1695-1702`).

### Custom playlists

- A custom playlist's "groups" are Spatie tags (`tags.type` = the playlist's `uuid`; categories use `uuid.'-category'`). Tag names are translatable JSON; name→tag-ID resolution happens in PHP (`PlaylistAlias::resolveCustomTagIds()`, `:645-658`) — memoized per tag type with the **full** name→ID map, so a larger unioned input changes neither correctness nor query count. Tags carry no `user_id`; every resolution path is type-scoped to the alias's own playlist UUID (verified fail-closed — a bouquet's names can never resolve against another user's tags).
- Untagged channels fall back to matching `channels.group` (provider name **frozen at channel INSERT**, never rewritten on re-sync). The selectable list is the name-keyed union of tag names + fallback names (`CustomPlaylist::filterableGroupsQuery()` / `filterableCategoriesQuery()`, presented via the table-less `CustomPlaylistGroup`).
- Custom-alias enforcement already flows through the same accessors into `constrainChannelsToCustomGroups()` (`:562`) / `constrainSeriesToCustomCategories()` (`:591`). Xtream category lists for custom aliases are built from tag PKs + fallback Group PKs, then filtered by **name** via `filterCategoriesByName()` (pure subtraction, no re-keying; `XtreamApiController.php:2381`).
- Nothing rename-propagates tag names today; a tag rename already strands custom aliases' manual `group_filter` silently. `AutoSyncGroupsToCustomPlaylist` detaches "ghost" tags that lose all channels (tag row survives; matching becomes a harmless no-op).

### Enforcement architecture

Enforcement is baked into `PlaylistAlias::channels()` / `::series()` via the accessors `getAllowedLiveGroupNames()` / `getAllowedVodGroupNames()` / `getAllowedCategoryNames()` / `hasGroupFilter()` (`app/Models/PlaylistAlias.php:84-117`). Full consumer traces for **both** target branches (24 standard consumers, 20 custom consumers) confirmed all read through these four methods — including Xtream category endpoints (accessor output hoisted at `XtreamApiController.php:588-596`), guest panel resources, and stream-time membership validation (`XtreamStreamController.php:166, :203`).

### Identifier-convention verdict

Name lists (the `import_prefs` convention: names + rename propagation) beat Group-ID pivots (membership silently dies on the 30-day force-delete cycle), tag-ID pivots (same class of problem via tag deletion/recreation), and copy-on-assign snapshots (issue + prior art demand live references; un-assigning a snapshot is ill-defined). A `source_groups` pivot is disqualified outright (unstable IDs). Names are also the **only** representation valid across both target types.

## 4. Data model

### Table `bouquets`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK → users, `cascadeOnDelete` | ownership |
| `playlist_id` | FK → playlists, **nullable**, `cascadeOnDelete` | standard target |
| `custom_playlist_id` | FK → custom_playlists, **nullable**, `cascadeOnDelete` | custom target |
| `name` | string | display name |
| `description` | text, nullable | |
| `group_selections` | jsonb, nullable, cast `array` | `{selected_groups: [names], selected_vod_groups: [names], selected_categories: [names]}` — no sort keys. Same shape for both target types; for custom targets the names live in the tag∪fallback namespace |
| `auto_include_new_live` | boolean, default false | standard targets only |
| `auto_include_new_vod` | boolean, default false | standard targets only |
| timestamps | | |

Indexes: `unique(['playlist_id', 'name'])` **and** `unique(['custom_playlist_id', 'name'])` — both Postgres and SQLite treat NULLs as distinct in unique indexes, so each row is constrained by exactly the index for its target type (no partial indexes or raw DDL needed); each unique doubles as the leftmost-column index for per-target scans. Plus `index('user_id')`.

**Exactly-one-target enforcement** (not DB-expressible portably): structurally guaranteed by the form (target selector dehydrates into exactly one hidden FK) and server-side by a `Bouquet::saving` guard registered in `AppServiceProvider::boot()` (the codebase's model-event convention) rejecting zero-or-both-set and normalizing `auto_include_new_*` to `false` for custom targets. Friendly uniqueness errors via `Rule::unique` scoped to the active FK; the DB uniques remain the backstop.

**Deliberate difference from the alias FK precedent:** alias target FKs are `nullOnDelete` (orphaned aliases survive and short-circuit); bouquet FKs are `cascadeOnDelete` — a bouquet is meaningless without its target. A morph (`bouquetable`) was rejected: no real FK cascade, string-type handling in every scope, and the codebase's morphs model attachment, not exclusive ownership — the exclusive-target precedent is the alias FK trio.

### Table `bouquet_playlist_alias`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `bouquet_id` | FK → bouquets, `cascadeOnDelete` | |
| `playlist_alias_id` | FK → playlist_aliases, `cascadeOnDelete` | |

Indexes: `unique(['bouquet_id', 'playlist_alias_id'])`, `index('playlist_alias_id')` (the hot alias→bouquets direction). No pivot `sort_order`: union semantics make assignment order meaningless.

**Migration safety:** two plain `Schema::create` calls; no ALTER/index DDL on any table the Process*/Sync job chains write to. Safe on Postgres and SQLite. `down()` drops pivot first.

### Models

- `App\Models\Bouquet`: casts; `belongsTo(Playlist)`, `belongsTo(CustomPlaylist)`, `belongsTo(User)`, `belongsToMany(PlaylistAlias)`; accessors mirroring the alias vocabulary (`getSelectedLiveGroupNames()` etc.). `user_id` auto-set on create (the `AppServiceProvider` convention).
- `App\Models\PlaylistAlias`: new `bouquets(): BelongsToMany`; per-instance memoized effective-filter property (the `$resolvedCategoryIds` idiom).
- `App\Models\Playlist` and `App\Models\CustomPlaylist`: `hasMany(Bouquet)`.
- **Attach invariant:** a bouquet attaches to an alias iff they share the same concrete target — `(bouquet.playlist_id IS NOT NULL AND bouquet.playlist_id = alias.playlist_id) OR (bouquet.custom_playlist_id IS NOT NULL AND bouquet.custom_playlist_id = alias.custom_playlist_id)`. Merged aliases attach nothing. Enforced in the Filament form's option scoping **and** server-side via a pivot model (`->using()` on both `belongsToMany` sides, precedent `App\Pivots\MergedPlaylistPivot`) whose `creating` event validates the match — Filament's relationship `sync()` and API/console attaches both go through the relationship, so pivot events fire. No DB triggers.

## 5. Composition & resolution semantics (normative)

For each content type **independently** (live, VOD, series categories):

1. `effective(type) = manual group_filter[type] ∪ ⋃ attached bouquets' group_selections[type]`, deduplicated. Order-insignificant.
2. `effective(type)` empty → **unrestricted** for that type (fail-open — identical to today's `[]`-means-all sentinel used across all consumers). An alias with no bouquets and no manual selection behaves bit-for-bit as before.
3. `effective(type)` non-empty → only matching content is delivered, via the existing per-target matching rules: standard targets against `channels.group_internal` (with the custom-channel fallbacks) and `source_category_id` resolution for series; custom targets through `constrainChannelsToCustomGroups()` / `constrainSeriesToCustomCategories()` (tag-ID resolution + `channels.group` fallback). Bouquet-contributed names are indistinguishable from manual ones by the time they reach the constraint machinery.
4. An empty bouquet (or a type-empty bouquet) contributes nothing; attaching a bouquet expresses **inclusion only**, never exclusion.
5. A non-empty effective list matching nothing (all groups/tags vanished) yields zero channels for that type — deliberate, matches current stale-filter behavior, self-heals when names return.
6. `hasGroupFilter()` is redefined over the **effective** lists, so a bouquet-only alias reports `true` (verified: no app-code consumers outside the model today, only tests — zero regression for bouquet-less aliases).
7. `sort_live_groups_custom` / `live_group_order` remain alias-level and manual-only in v1.

### Resolution point & hard constraints

Resolution happens **only** inside the four accessors on `PlaylistAlias`:

- **R1 (critical):** resolution must **never** be implemented as an Eloquent attribute accessor/cast on `group_filter`. The Filament alias form binds `group_filter.*` state paths directly; an attribute-level union would be hydrated into the form and persisted on save, permanently materializing bouquet contents into the manual filter. This is *more* load-bearing for custom aliases: the custom pickers are name-keyed with **no dehydrate transform at all**, so the materialization would persist verbatim on any save. `group_filter` stays raw; regression tests enforce this on both form paths.
- **Identity fast path:** when the alias has no attached bouquets, accessors return the manual arrays **unchanged** (no `array_unique`/re-index) — existing SQL text and test assertions stay bit-for-bit identical for both target types.
- **Memoization:** one query (pivot join → `bouquets.group_selections`) per model instance, populated `??=`, serving all accessor calls; prefer `relationLoaded('bouquets')` when eager-loaded. **Short-circuit (zero queries, manual-only):** only when `merged_playlist_id` is set, or all three target FKs are null (orphaned alias — possible because alias FKs are `nullOnDelete`). Standard and custom aliases pay the same single memoized query. Accepted cost: a bouquet-less **custom** alias gains +1 pivot-existence query vs today (no existing test asserts against it; recoverable later via eager-loading in hot controllers). `resolveCustomTagIds()`'s own query count is unchanged in every existing scenario. No cross-request cache — bouquet edits must take effect immediately.

### Knock-on behaviors (accepted, mirror manual-filter behavior)

- A bouquet-attached standard alias suppresses TMDB dynamic categories and dynamic-category ID stamping, exactly as a manual filter does. (Dynamic categories never apply to custom targets — both gates require non-custom context.)
- Xtream `category_id` values (Group PKs for standard; tag PKs + fallback Group PKs for custom) are only ever *subtracted* from listings — `filterCategoriesByName()` is pure subtraction, never re-keying. The empty-list `'all'` fallback behaves as it does for over-narrow manual filters.
- Stream-time membership validation (`XtreamStreamController`) inherits bouquet gating automatically when auth resolves the alias, for both target types.
- **Locale/case:** all name comparisons stay strict and case-sensitive end to end, and custom pickers/resolvers agree on the `en`-locale representation because the bouquet editor reuses `filterableGroupsQuery()` verbatim. Do **not** normalize case in bouquet storage — it would diverge from manual-filter matching.

## 6. Sync & lifecycle behaviors

| Event | Behavior |
|---|---|
| Provider renames a group (Xtream, `source_group_id`-tracked, standard target) | `ProcessM3uImport::syncSourceGroupType()` already builds the old→new `$renames` map and rewrites `import_prefs` (`:1695-1702`). Extend that block: iterate the playlist's bouquets (`where('playlist_id', …)->cursor()`), map the type-appropriate selection key through `$renames`, save only changed rows. **Companion fix:** rewrite standard aliases' `group_filter` in the same pass. Runs before channels re-import under new `group_internal`, so filters keep matching within the same sync. Idempotent; tolerates aborted/reverted syncs with the same semantics `import_prefs` has. **Structurally never touches custom-target bouquets** (the scan is by `playlist_id`; custom targets have it NULL) — asserted by test. |
| Provider renames a group (M3U source) | Untrackable everywhere in the system (no stable ID). Documented parity with `import_prefs`, not a regression. |
| **User renames a custom group/category tag** | Propagate (the tag-world companion fix). A `Tag::updating`/`updated` closure in `AppServiceProvider::boot()` (vendor model; static event registration is the established convention — the only rename write path today is the inline `TextInputColumn` in the Groups/Categories RelationManagers): guard `isDirty('name')`, compare resolved `en` translations, resolve `tags.type` to a `CustomPlaylist` by uuid (strip `-category`; skip silently when no playlist matches). Rewrite old→new with dedupe in that playlist's bouquets (group tags map through **both** `selected_groups` and `selected_vod_groups` — the tag namespace is shared across live/VOD; category tags through `selected_categories`) **and** its aliases' manual `group_filter`. Save bouquets via Eloquent so the EPG-cache hook fires. Replace-semantics: if the old name also matched untagged channels' fallback provider group, that fallback selection follows the rename; the old provider name stays selectable and can be re-added. |
| Fallback provider name "renames" (custom targets) | No rename event exists to hook: `channels.group` is frozen at INSERT and never rewritten. Existing fallback selections keep matching existing channels forever; after a provider rename, newly-inserted channels carry the new name, so the fallback namespace can split (both names selectable — user unions both if desired). Documented, not a regression. |
| Group vanishes (standard) / tag deleted or ghost-detached (custom) | **Keep the name — never auto-prune.** Unmatched names are harmless in `whereIn`; vanish is often transient; auto-pruning is the Threadfin silent-loss failure mode. Ghost-detached tags (tag row survives, no channels carry it) match nothing while the fallback branch may still legitimately match untagged channels with the same name; membership self-heals if the tag/channels return (`Tag::findOrCreate` reuses names). UI surfaces staleness instead. |
| Group returns under the same name | Membership resumes automatically, zero bookkeeping. Both target types. |
| New provider group appears (standard target) | During `syncSourceGroupType()`'s upsert pass, names new to that (playlist, type) are appended to bouquets with the matching `auto_include_new_*` flag on. Default off — no accidental widening. Custom targets: flags are hidden and normalized false; no equivalent event exists. |
| Bouquet edited/deleted/detached | Clear attached aliases' cached EPG files (`clearPlaylistEpgCacheFile()`), regardless of target type; never touch the existing target-change clearing in `AppServiceProvider`. Delete cascades pivot rows; UX warns with the list of affected aliases (deleting the only bouquet on a manual-filter-less alias widens it to all groups — confirmation required). No soft-deletes on bouquets. |
| Playlist / custom playlist deleted | `bouquets` cascade via the target FK; pivots cascade transitively. The alias itself survives with its FK nulled (existing `nullOnDelete`) and short-circuits to manual-only. |
| Playlist / custom playlist duplicated | `DuplicateCustomPlaylist` (inside its existing transaction, after tag recreation — tag names are recreated byte-identical under the new uuid, so name selections copy cleanly) and `DuplicatePlaylist` (groups replicate with identical names) copy the playlist's bouquets: replicate, retarget to the duplicate, `saveQuietly()`. **Pivot rows are never copied** — the duplicate has no aliases, and copying would attach new-playlist bouquets to old-playlist aliases, violating the invariant. |
| Alias retargeted (source type or playlist changed) | The two form hooks that call `resetGroupFilter()` also clear the bouquet selection (covers standard→custom, custom→standard, and custom→custom-of-a-different-playlist); relationship save syncs the pivot empty; the `->live()` options closure re-scopes. The pivot-model invariant rejects mismatched attaches server-side. |
| User renames `groups.name` (standard display name) | No effect — storage and enforcement use provider-stable identifiers, same as today. |

## 7. UI design (Filament v5, per `filament-first`)

### BouquetResource (new, `app/Filament/Resources/Bouquets/`)

- Nav group **Playlist**, label "Playlist Bouquets", modeled on `PlaylistAuthResource`: `getPages()` registers only `index`; `CreateAction`/`EditAction` slideOvers. Uses `HasUserFiltering` (both `getEloquentQuery()` and `getGlobalSearchEloquentQuery()`), plus a `BouquetPolicy`.
- Table: `name` (+ description), linked target column (playlist or custom playlist name — the alias table's `alias_of` pattern), computed Live/VOD/Series counts from the name arrays, `aliases_count` (`withCount`) as the at-a-glance delete-safety signal, `updated_at`.
- **Target selector:** the alias form's own UI-only `source_type`/`source_id` + hidden-FK idiom (`PlaylistAliasResource.php:338-412`), minus the merged option: `target_type` (Standard/Custom, `dehydrated(false)`, `live()`) + `target_id` (user's playlists of that type) dehydrating into `Hidden::make('playlist_id')` / `Hidden::make('custom_playlist_id')` — structurally exactly one FK set. **Both `disabledOn('edit')`**: retargeting would invalidate every stored name; delete-and-recreate is the honest flow. On create, changing either wipes the pickers and toggles.
- **Pickers: twin components per content type bound to the same state path**, visibility split on the concrete FK (the alias form's proven `:704`/`:788` pattern; hidden twins hydrate but don't dehydrate, and the standard variant keeps the hydration bail guard): standard targets get `SourceGroupsTable`/`SourceCategoriesTable` with the ID↔name round-trip; custom targets get `CustomPlaylistGroupsTable`/`CustomPlaylistCategoriesTable`, name-keyed with identity label closures and no round-trip.
- **Shared component extraction:** `App\Filament\Forms\Components\SourceGroupModalSelect` (the round-trip closures — avoids a third copy) plus a sibling `CustomPlaylistGroupModalSelect` (name-keyed builder). Two small builders, not one mode-switched class — the variants share nothing but the base component. Migrating the alias form's existing inline copies onto the builders is an optional follow-up.
- Auto-include `Toggle`s: `visible` only for standard targets (hide, don't disable — absence is honest; hidden fields don't dehydrate, and the `saving` guard normalizes false anyway).
- **Staleness:** a `Callout` on edit diffs stored names against the current selectable set — `source_groups` for standard targets ("2 saved groups no longer exist in the source: …"); `filterableGroupsQuery()` / `filterableCategoriesQuery()` for custom targets, reworded ("no longer selectable in this custom playlist" — a name can vanish because channels were disabled or re-tagged, not just provider deletion), computed once in the callout closure (≤3 union queries per edit render, same cost class as one picker page). Helper text notes that renames are propagated automatically (per §6). "Remove missing groups" hint action for deliberate cleanup.
- **Never-silently-shrink on save:** the standard dehydrator merges unresolvable-but-stored names back into the saved array (deliberate deviation from the alias picker's silent-drop). Custom variants get this for free — identity dehydration cannot drop anything.
- `DeleteAction`/`DeleteBulkAction` `requiresConfirmation()` listing affected aliases; an "in use by N aliases: … Changes take effect immediately" `Callout` on edit.

### Alias form (PlaylistAliasResource)

- New `Fieldset "Bouquets"` at the top of the "Channel Filter (optional)" fieldset: `Select::make('bouquets')->multiple()->relationship(...)->searchable()->preload()->live()`, **visible when `playlist_id` OR `custom_playlist_id` is set** (i.e. the parent fieldset's own visibility; merged aliases see nothing). Options scoped to the auth user + whichever FK is set (both are `Hidden` fields, so `$get` works uniformly). Helper text states union semantics plainly.
- Name-only inline quick-create via `createOptionForm` + `createOptionUsing`, injecting **whichever target FK is set** plus the auth user; a notification directs the user to Playlist Bouquets to pick groups. (Full inline editor is infeasible: `createOptionForm` can't read the parent form's FK for `tableArguments`, and it would nest modal-in-modal-in-slideOver.)
- Live `Callout` when ≥1 bouquet selected: "Assigned bouquets contribute N live groups, N VOD groups, N series categories in addition to your manual selections." (Counts come from stored name arrays — target-agnostic.)
- All six manual pickers (standard **and** custom variants) gain an "In bouquet" indicator column via a `bouquet_group_names` table argument (all four table classes already read `$table->getArguments()` lazily; the custom tables' rows are keyed by the very names bouquets store, so matching is exact). Selecting an already-covered row is harmless — union dedupes. (A "checked-but-locked" row state is not feasible with `ModalTableSelect` and would mis-signal exclusion.)
- Sort Repeater gains helper text: "Groups contributed by bouquets that are not listed here are appended in source-playlist order."
- `resetGroupFilter()` additionally `$set('bouquets', [])`.
- Alias list table: `TextColumn::make('bouquets.name')->badge()->limitList(2)->toggleable()` after `alias_of`; add `bouquets` to the existing eager-load.

### "Add to Bouquet" actions

- `PlaylistService::getAddGroupsToBouquetBulkAction()` / `getAddGroupsToBouquetAction()`, mirroring the Custom Playlist pair; wired into the same `ActionGroup`s on GroupResource, VodGroupResource, CategoryResource (row + bulk) and the three Edit pages. These surfaces list provider groups, so the target bouquet Select is scoped to standard-target bouquets of the records' playlist.
- Modal: single bouquet Select + the same name-only quick-create so the flow works with zero pre-existing bouquets. Cross-playlist selections abort with a danger notification. Maps `name_internal` (groups) / `name` (categories) into the stored arrays with dedupe. Synchronous (jsonb merge — no queued job needed), success notification reports added/already-present counts.

### Language files

All new user-facing strings go through `lang/en/*.php` / `lang/en.json`; run `php artisan lang:merge-conflicts` before committing (pre-PR checklist).

## 8. Regression guarantees (must-not-change checklist)

For aliases with **no** bouquets — standard *and* custom — all of the following stay bit-for-bit identical: accessor return values (identity fast path), `hasGroupFilter()` truth table for manual-only inputs, `channels()`/`series()` SQL, custom-playlist alias filtering (the entire `PlaylistAliasCustomPlaylistFilterTest` suite passes unmodified; the only permitted delta is the +1 pivot query, which no existing test asserts against), live-group custom sort, Xtream `category_id` values (Group PKs / tag PKs — no re-keying) and the `'all'` fallback, dynamic-category presence for unfiltered standard aliases, EPG cache path scheme and the target-change clearing hook, and alias `deleting` cleanup (bouquet pivots must cascade without disturbing it).

Additional guards:

- `SyncPipelineService.php:480/:484` reads a `$rule['group_filter']` key on auto-sync rules — an unrelated field that merely shares the name. Do not touch; note in the PR.
- Guest panel resources hand-roll their filters but call the accessors — they inherit bouquets automatically (both branches). Do **not** "clean them up" onto `channels()` in this PR.
- The provider-rename pass must be shown (by test) not to touch custom-target bouquets; the tag-rename hook must be shown not to touch other playlists' bouquets or other tag types.
- No case/locale normalization of stored names anywhere (see §5 knock-ons).

## 9. Test plan

**Conventions:** real pipeline, no `Bus::fake()` to paper over the import chain; `Http::preventStrayRequests()` in Playlist-touching tests; `createQuietly()` / `group_id => null` factory patterns to avoid cascaded imports; existing `makeAlias()`/`makeCustomAlias()`/`tagChannels()`/`tagSeries()` helpers. Add a `BouquetFactory` (states for standard/custom targets); keep direct-create patterns for models without factories.

**New feature tests:**

- `BouquetResourceTest` — Filament CRUD for both target types (standard: names persisted, IDs hydrated; custom: names persisted **verbatim**, no round-trip artifacts), target-type switch wipes pickers on create, both target fields disabled on edit, per-target unique names (same name allowed across target types and across playlists), auto-include toggles hidden + persisted false for custom targets, vanished-name preservation on save (both variants), staleness callout (standard: missing source group; custom: deleted tag, ghost-detached tag, vanished fallback name — reworded copy), delete confirmation naming affected aliases.
- `PlaylistAliasBouquetTest` — attach/detach for standard and custom aliases; option scoping (own user, matching target only — a custom alias never sees standard-target bouquets or another playlist's); retarget in all directions clears bouquets; quick-create injects the correct FK; server-side invariant rejects mismatched attaches both directions and anything on merged aliases.
- `PlaylistAliasBouquetResolutionTest` — union semantics per §5 for both target branches (manual-only / bouquet-only / both / emptied-bouquet fail-open per type / `hasGroupFilter()`; custom: tag-matched **and** untagged-fallback channels both pass); memoization (exactly one pivot query per instance across repeated accessor calls; one tag query per tag type regardless of union size); **zero-query short-circuit for merged and orphaned aliases; exactly-one for custom**; ghost/vanished names harmless and self-healing.
- `BouquetRenamePropagationTest` — two-pass `ProcessM3uImport` with faked HTTP (renamed `category_name`, stable `category_id`): `source_groups`, `import_prefs`, standard-target bouquet selections, **and standard aliases' manual `group_filter`** (companion fix) all move; other playlists **and custom-target bouquets** untouched; rename-collision skip path leaves bouquets alone. (First-ever coverage of the `:1695-1702` path.)
- `TagRenamePropagationTest` — renaming a group tag rewrites that playlist's bouquets (both live and VOD keys) and its aliases' `group_filter`; renaming a `-category` tag rewrites `selected_categories`; other playlists, other tag types, and standard-target bouquets untouched; non-playlist tag types skipped silently; EPG cache cleared for attached aliases.
- `BouquetAutoIncludeTest` — new provider group appended only to flagged standard-target bouquets, per type; custom-target bouquets never touched.
- `BouquetDuplicationTest` — `DuplicateCustomPlaylist` and `DuplicatePlaylist` copy bouquets (retargeted, selections verbatim, **zero pivot rows**); source playlist's aliases keep their attachments and output.
- `GroupBouquetBulkActionTest` — merge/dedupe, cross-playlist abort, all three resource variants; options show standard-target bouquets only.
- `GuestPanelBouquetFilterTest` — VOD/Series query narrowing and badge counts for bouquet-attached standard **and** custom aliases.

**Existing files gaining cases:** `PlaylistAliasTest` (union end-to-end via `channels()`/`series()`), `PlaylistAliasLiveGroupSortTest` (bouquet groups rank in the ELSE bucket), `PlaylistAliasCustomPlaylistFilterTest` (**R1 guards, both form paths:** form save with attached bouquet persists only manual names into `group_filter` — the custom path matters most, having no dehydrate transform; plus cross-target attach rejection), `XtreamCategoryServiceTest` / `XtreamDynamicCategoriesTest` (narrowing + suppression from bouquet-only attachment; no change for bouquet-less), `XtreamApiControllerTest` (category/stream endpoints for both target types: `category_id`s stable, narrowed lists, `'all'` fallback, per-category stream filtering on the union base, stream-time rejection of out-of-union content), `EpgPlaylistAliasCacheInvalidationTest` (bouquet edit clears attached aliases' cache for both target types; target-change clearing unchanged).

## 10. Design rejections (for the record)

- **Pivot to `groups` by DB ID:** dies on the 30-day force-delete cycle; needs ID→name translation at read time; fourth identifier convention; can't extend to custom playlists (tags, no `groups` rows).
- **Copy-on-assign snapshot into `group_filter`:** diverges silently on bouquet edit; un-assigning can't attribute names to bouquet vs manual. (A "copy bouquet into manual filter" convenience action remains possible UI sugar later.)
- **Pivot to `source_groups`:** IDs are unstable by design (hard-deleted on absence); the alias picker already litigated this by dehydrating IDs→names.
- **Polymorphic `bouquetable` target:** no real FK cascades (custom-playlist deletion must clean up dependents), morph-type strings in every scope, and the codebase's morphs model attachment rather than exclusive ownership. The alias FK-trio precedent wins.
- **One mode-switched picker component:** the standard and custom variants share nothing but the base component; two small builders beat conditional soup.
- **RelationManager on PlaylistResource:** relation-manager tabs were deliberately removed there; the Playlist edit page is a wizard.
- **"Add to Bouquet" on CustomPlaylist RelationManagers (v1):** the Groups tab shows tags only — half the selectable namespace; an incomplete surface mis-teaches the model. Follow-up.
- **Bouquet-aware sort Repeater (v1):** its hydration reads `group_filter` raw and needs real rework; the runtime fallback is already deterministic and safe. Follow-up.
