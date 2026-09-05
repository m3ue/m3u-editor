# Grim's Practical VOD & Series Guide

This guide covers a practical configuration that improves the way your VOD
groups and Series categories are organized. The goal is to let TMDB keep your
content current instead of relying entirely on the groups supplied by your
provider.

Many providers package large amounts of stale or non-genre-specific content
into broad groups. We will use m3u-editor's **Dynamic Groups** feature to create
virtual groups for content such as trending movies, popular titles, and top
genres.

For clarity, m3u-editor uses these terms:

- VOD movies are organized into **groups**.
- Series are organized into **categories**.

## What Is a Dynamic Group?

Put simply, a Dynamic Group is a virtual overlay built from VOD or Series
entries whose TMDB IDs match criteria you define. Rules can include daily or
weekly trending and popular titles, movies currently in theatres, top genres,
TV networks, streaming services, and more.

A Dynamic Group does not move content out of its original group or category.
The content keeps its normal membership and can also appear in one or more
Dynamic Groups. This gives you a dynamically curated playlist without
destroying the provider's original organization.

Dynamic Groups require content to have TMDB IDs. Only enabled content is
served in the resulting Dynamic Group, so disabled content remains excluded
from the output.

## 1. Prerequisites

Complete these steps before configuring a playlist.

### Enable the feature

Set the experimental feature flag in `.env`:

```env
PLAYLIST_TMDB_DYNAMIC_GROUPS=true
```

Clear the configuration cache after changing the environment file. Without
this flag, the **Dynamic Groups (TMDB)** section is hidden from the playlist
Processing tab.

### Configure TMDB

Open `Settings → TMDB Integration` and configure the following:

1. Enter a valid **TMDB API Key**.
2. Select the desired **Search Language**.
3. Enable **Auto-lookup on metadata fetch**. This automatically populates TMDB
   IDs during the sync process, eliminating the need to fetch IDs manually for
   normal imports. **Only this first toggle is required for this guide.**
4. Leave **Auto-create groups/categories from TMDB genres** disabled for this
   practical configuration. Enable it only if you also want genre groups and
   categories created automatically during metadata fetching.
5. Set **Auto-lookup scope** to **Only enabled** unless you specifically want
   to process all new content or both enabled and new content.
6. Select **Test Connection** and confirm the connection succeeds.

![TMDB Integration settings — only Auto-lookup on metadata fetch is required](/screenshots/tmdb-integration-settings.png)

### Content requirements

Your playlist must contain synced VOD or Series content with TMDB IDs. The
automatic lookup setting above handles this during normal synchronization.
Only enabled content is reclassified and served. Disabled content is left in
its existing group or category.

## 2. Configure the Playlist

Open `Playlists → Edit {playlist} → Processing` and expand **Dynamic Groups
(TMDB)**.

### Choose reclassification behavior

The feature has two independent settings:

- **Auto-reclassify VOD groups to TMDB genres on sync** — enable this if you
  want enabled VOD movies moved into groups named after their TMDB genre.
- **Auto-reclassify Series categories to TMDB genres on sync** — enable this if
  you want enabled Series moved into categories named after their TMDB genre.

Series reclassification is optional. You may prefer to leave it disabled if
your provider's existing Series category organization is more useful to you.

![Dynamic Groups (TMDB) processing section](/screenshots/dynamic-groups-processing-section.png)

### Add a Dynamic Group

Under **Dynamic groups config**, select **Add dynamic group** and configure a
rule. A practical first rule is:

- **Enabled**: On
- **Content Type**: `VOD (Movies)`
- **Source**: `Trending`
- **Time Window**: `This Week`
- **Category Name**: `Trending Movies`

You can use other sources such as `Popular`, `In Theatres`, `Coming Soon`,
`Top Genre`, `By TV Network`, or `By Streaming Service`. Use the eye icon to
preview a rule's current matches before saving.

![Save changes](/screenshots/save-changes.png)

## 3. Run the Synchronization

From the playlist's **Actions** menu, select **Sync and Process**.

The synchronization performs the normal import and metadata work. With
automatic TMDB lookup enabled, TMDB IDs are fetched as part of the process.
When the playlist reclassification toggles are enabled, the matching VOD
groups and Series categories are updated during this workflow.

![Playlist Actions — Sync and Process](/screenshots/playlist-actions-sync-process.png)

## 4. Verify the Result

Return to the Playlists list and wait for the playlist to finish:

- **Status** should be `completed`.
- **Live Sync** and **VOD Sync** should reach 100% where applicable.
- Playlist counts should be populated.

![Playlist sync complete](/screenshots/playlists-sync-complete.png)

Then verify the content:

- VOD movies with TMDB genres appear in matching genre groups.
- Series appear in matching genre categories only if Series reclassification
  is enabled.
- Items without a usable TMDB genre appear in `Uncategorized`.
- Dynamic Groups contain the titles matching their configured TMDB criteria.
- A title can remain in its normal group or category while also appearing in a
  Dynamic Group.

## 5. What Reclassification Does Not Change

Reclassification is deliberately limited:

- Disabled channels and Series are not moved.
- Merged groups and categories, including their parent and child rows, are not
  changed.
- Groups and categories referenced by enabled Auto-Add to Custom Playlist rules
  are not changed.
- Existing groups and categories keep their current enabled state.
- Newly created genre groups and categories are enabled by default.
- If TMDB is not configured, or TMDB's canonical genre lookup fails, the
  operation does nothing rather than moving everything to `Uncategorized`.

## 6. Manual TMDB ID Lookup (Alternative)

This section is only needed when **Auto-lookup on metadata fetch** is disabled,
or when you need to backfill existing content manually.

1. Open **VOD Channels**.
2. Select the desired records, or use **Select all**.
3. Open **Bulk VOD actions**.
4. Select **Fetch TMDB IDs**.
5. Leave **Overwrite Existing IDs** disabled unless existing IDs should be
   replaced.
6. Select **Yes, fetch IDs now**.
7. Run **Sync and Process** again so the reclassification uses the newly
   populated IDs.

![VOD Channels — select records](/screenshots/vod-channels-select-all.png)

![Bulk VOD actions — Fetch TMDB IDs](/screenshots/bulk-vod-actions-fetch-tmdb.png)

![Fetch TMDB IDs confirmation](/screenshots/fetch-tmdb-confirm.png)

## 7. Manual Reclassification

If you want to reclassify immediately without running a complete playlist
sync, use the `reclassify_tmdb_genres` action from the VOD Groups or Series
Categories list, or from the relevant edit page. The same protection rules in
this guide still apply.

For the complete verification checklist, see
[`docs/tmdb-dynamic-groups-reclassify.md`](tmdb-dynamic-groups-reclassify.md).

## Screenshot Reference

The following captures are included from the original walkthrough:

![Groups list — Enable group channels action](/screenshots/groups-enable-group-channels.png)

> **Historical reference:** This screenshot shows the earlier single-toggle
> version of the playlist setting. The current interface uses separate VOD and
> Series reclassification toggles; use the screenshot in section 2 as the
> current reference.

![Earlier Dynamic Groups processing section](/screenshots/dynamic-groups-processing-old-single-toggle.png)
