# TMDB Dynamic Groups + Reclassify-to-Genre — Test Procedure

Manual verification procedure for two related features:

- **Dynamic Groups (TMDB)** — per-playlist virtual groups computed from TMDB list endpoints
  (Trending, Popular, In Theatres, Coming Soon, Top Genre, By Network, By Provider).
  PR #1468 (**merged**).
- **Reclassify to TMDB genres** — route each enabled VOD channel / Series into a group or
  category matching its own TMDB genre (or `Uncategorized` if no genre resolves).
  PR #1478 (`feat/reclassify-tmdb-genres`, **in flight**).

> **Current state:** PR #1478 splits the reclassify behavior into two independent toggles
> (VOD + Series). The procedure below reflects that split (it does not use the older
> single-toggle "Auto-reclassify groups/categories to TMDB genres on sync").

---

## 0. Prerequisites (one-time)

1. **Feature flag** — set in `.env`:
   ```env
   PLAYLIST_TMDB_DYNAMIC_GROUPS=true
   ```
   Then clear config caches (`php artisan config:clear` or page refresh after `npm run build`
   / `composer run dev`). Without this **the entire "Dynamic Groups (TMDB)" section — including
   the reclassify toggles and the `dynamic_groups_config` repeater — is hidden** (`PlaylistResource.php:2111`).
2. **TMDB API key** — `Settings → TMDB Integration → TMDB API Key`, then **Test Connection**.

---

## 1. Configure TMDB Integration

`Settings → TMDB Integration`:

- **TMDB API Key** (required)
- **Search Language** (e.g. `English (US)`)
- **Auto-lookup on metadata fetch** = **ON** ← *only this first toggle is required — prereq that removes the manual "Fetch TMDB IDs" step*
- **Auto-create groups/categories from TMDB genres** = **OFF** for this flow (enable only if you also want genre groups/categories created automatically)
- **Auto-lookup scope** = `Only enabled` (default)

![TMDB Integration settings — only Auto-lookup on metadata fetch is required](/screenshots/tmdb-integration-settings.png)

---

## 2. Configure the Playlist

`Playlists → Edit {playlist}` → **Processing** tab → **"Dynamic Groups (TMDB)"** section.

- **Auto-reclassify VOD groups to TMDB genres on sync** = **ON** (default `false`)
- **Auto-reclassify Series categories to TMDB genres on sync** = **ON** (default `false`)
  - These are **independent** — enable/disable each separately based on how you organize VOD vs Series.
- **Dynamic groups config** → *Add dynamic group*:
  - **Enabled** toggle
  - **Content Type**: `VOD (Movies)` or `Series`
  - **Source**: `Trending` / `Popular` / `In Theatres` (`vod`) / `Coming Soon` (`vod`) /
    `Top Genre` / `By TV Network` (`series`) / `By Streaming Service`
  - Source-dependent fields:
    - `Top Genre` → **Genre** (from TMDB canonical list)
    - `By TV Network` → **TV Network**
    - `By Streaming Service` → **Streaming Service** (+ **Region**, default `US`)
    - `Trending` → **Time Window** (`Today` or `This Week`, default `This Week`)
  - **Category Name** (required, e.g. `Trending Movies`, `Top Comedy`, `Netflix`)
- Use the **Preview** eye icon on a rule to see its current matches before saving.
- **Save changes**.

![Dynamic Groups (TMDB) processing section — split VOD/Series reclassify toggles and a configured dynamic group](/screenshots/dynamic-groups-processing-section.png)

![Save changes button](/screenshots/save-changes.png)

---

## 3. Run the pipeline

`Playlists → {playlist}` → **Actions** (⋮ menu) → **Sync and Process**.

![Playlist Actions menu — Sync and Process](/screenshots/playlist-actions-sync-process.png)

---

## 4. Verify sync completion

On the **Playlists list**:

- Status = `completed`
- **Live Sync** = 100%, **VOD Syn** = 100%
- Groups / VOD / Series counts populated

![Playlists list — sync complete](/screenshots/playlists-sync-complete.png)
- Dynamic-group rules produce non-zero groups where TMDB list endpoints return content.

---

## 5. Verify reclassify + dynamic groups

- Open **VOD Groups** → the **Dynamic Groups (TMDB)** widget lists the per-playlist dynamic groups
  (e.g. `Trending`) with correct channel counts; the `view` action links to the read-only
  `DynamicGroupResource` detail page.
- Confirm enabled VOD channels were re-routed out of any non-genre group into genre-matched
  groups; items with no usable genre landed in **Uncategorized**.
- Repeat for **Series Categories**.
- Confirm no group/category referenced by an enabled **auto-sync-to-custom-playlist** rule was touched.
- Confirm no **merged** group/category (parent or child) was touched.

---

## 6. Manual fetch path — ONLY if "Auto-lookup on metadata fetch" was OFF

Skip this entirely if step 1 had it ON (the sync pipeline auto-schedules the `VodTmdb`/`SeriesTmdb`
phases, which run `FetchTmdbIds`). If it was OFF, populate IDs manually:

1. **VOD Channels** → select records / check the header checkbox → **Select all** (e.g. all 707).
2. **Bulk VOD actions** → **Fetch TMDB IDs**.
3. Confirm modal → optionally toggle **Overwrite Existing IDs** → **Yes, fetch IDs now**.
4. Re-run **Sync and Process** so reclassify runs against the newly-populated IDs.

![VOD Channels — select all + bulk actions](/screenshots/vod-channels-select-all.png)

![Bulk VOD actions — Fetch TMDB IDs](/screenshots/bulk-vod-actions-fetch-tmdb.png)

![Fetch TMDB IDs confirmation modal](/screenshots/fetch-tmdb-confirm.png)

---

## Reclassify action (manual, in addition to the sync toggle)

The `reclassify_tmdb_genres` action is available directly on:

- **Edit VOD Group** header action (`EditVodGroup.php`), and as a **bulk action** on the
  VOD Groups list table (`VodGroupResource.php`).
- **Edit Series Category** header action (`EditCategory.php`), and as a **bulk action** on the
  Series Categories list table (`CategoryResource.php`).

Useful to reclassify a single group/category immediately without a full sync, or after a
manual fetch when the auto-toggle is off.

---

## Notes / behavior guarantees

- Reclassify only touches `enabled = true` content; deliberately-disabled items are left in place.
- New groups/categories created by reclassify default to `enabled = true`; pre-existing groups
  keep their prior `enabled` state (never silently overwritten).
- If TMDB is not configured, or the canonical genre lookup returns empty (transient TMDB outage),
  the service is a **no-op** — it refuses to make destructive changes.
- The feature flag gates visibility: Widgets (`DynamicGroupsWidget`) also honor
  `config('feature.playlist_tmdb_dynamic_groups')`.
