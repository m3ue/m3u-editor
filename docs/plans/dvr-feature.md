# DVR Feature Implementation Plan

## Architecture Decisions
- **Recording engine**: Laravel + FFmpeg (spawned from queued jobs)
- **Storage**: Configurable Laravel disk (`dvr` disk), local default, S3-capable
- **User model**: Admin-only DVR management
- **Playback**: New DVR playlist type (auto-generates M3U from completed recordings)
- **EPG**: New `epg_programmes` DB table for DVR-enabled playlists only (existing JSONL cache stays for UI)
- **Recording capture**: Use channel proxy URL when proxy enabled on source playlist
- **File format**: HLS during recording -> concat to single .ts in post-processing -> cleanup segments
- **Metadata**: TMDB (primary, requires key) + TVMaze (free fallback)

## New Enums

### `DvrRecordingStatus` (app/Enums/DvrRecordingStatus.php)
- `Scheduled`, `Recording`, `PostProcessing`, `Completed`, `Failed`, `Cancelled`
- Methods: `getLabel()`, `getColor()`, `getIcon()`

### `DvrRuleType` (app/Enums/DvrRuleType.php)
- `Once`, `Series`, `Manual`
- Methods: `getLabel()`, `getColor()`, `getIcon()`

### `DvrPlaylistGroupBy` (app/Enums/DvrPlaylistGroupBy.php)
- `Show`, `Date`, `Channel`
- Methods: `getLabel()`

## Migrations (5 new tables)

### 1. `dvr_settings` — Per-playlist DVR configuration
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| playlist_id | FK playlists | cascadeOnDelete |
| user_id | FK users | cascadeOnDelete |
| enabled | bool default false | Master toggle |
| storage_disk | string default 'dvr' | Laravel disk name |
| storage_path | string default 'recordings' | Base path within disk |
| max_concurrent_recordings | int default 2 | FFmpeg process limit |
| ffmpeg_path | string nullable | Custom ffmpeg path |
| default_start_early_seconds | int default 30 | Padding before |
| default_end_late_seconds | int default 30 | Padding after |
| enable_metadata_enrichment | bool default true | Auto-enrich |
| tmdb_api_key | text nullable (encrypted) | Optional TMDB key |
| global_disk_quota_gb | int nullable | Storage cap |
| retention_days | int nullable | Auto-delete age |

### 2. `dvr_recording_rules` — What to record
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| user_id | FK users | cascadeOnDelete |
| dvr_setting_id | FK dvr_settings | cascadeOnDelete |
| type | string (DvrRuleType enum) | once/series/manual |
| programme_id | string nullable | EPG programme ID for once |
| series_title | string nullable | Title match for series |
| channel_id | FK channels nullable | Restrict to channel |
| epg_channel_id | FK epg_channels nullable | EPG channel ref |
| new_only | bool default false | New episodes only |
| priority | int default 50 | 1-100, conflict resolution |
| start_early_seconds | int nullable | Override padding |
| end_late_seconds | int nullable | Override padding |
| keep_last | int nullable | Retain N recordings |
| enabled | bool default true | Active toggle |
| manual_start | datetime nullable | Manual type start |
| manual_end | datetime nullable | Manual type end |

### 3. `dvr_recordings` — Actual recordings
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| uuid | uuid unique | File paths, URLs |
| user_id | FK users | cascadeOnDelete |
| dvr_setting_id | FK dvr_settings | cascadeOnDelete |
| dvr_recording_rule_id | FK nullable | nullOnDelete |
| channel_id | FK channels nullable | nullOnDelete |
| status | string (DvrRecordingStatus) | Lifecycle state |
| title | string | Programme title |
| subtitle | string nullable | Episode title |
| description | text nullable | Synopsis |
| season | int nullable | |
| episode | int nullable | |
| scheduled_start | datetime | Recording start |
| scheduled_end | datetime | Recording end |
| actual_start | datetime nullable | |
| actual_end | datetime nullable | |
| duration_seconds | int nullable | |
| file_path | string nullable | Path on disk |
| file_size_bytes | bigint nullable | |
| stream_url | text nullable | Source stream URL |
| metadata | json nullable | TMDB/TVMaze data |
| error_message | text nullable | Failure reason |
| programme_start | datetime nullable | Original EPG start |
| programme_end | datetime nullable | Original EPG end |
| epg_programme_data | json nullable | EPG snapshot |
| pid | int nullable | FFmpeg process ID |

### 4. `dvr_playlists` — DVR output playlists
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| uuid | uuid unique | For URLs |
| user_id | FK users | cascadeOnDelete |
| name | string | Playlist name |
| dvr_setting_id | FK dvr_settings | cascadeOnDelete |
| enabled | bool default true | Toggle |
| group_by | string default 'show' | DvrPlaylistGroupBy enum |
| short_urls_enabled | bool default false | |

### 5. `epg_programmes` — EPG data for DVR scheduling
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| epg_id | FK epgs | cascadeOnDelete |
| epg_channel_id | string | XMLTV channel ID |
| title | string | |
| subtitle | string nullable | |
| description | text nullable | |
| category | string nullable | |
| start_time | datetime indexed | |
| end_time | datetime nullable | |
| episode_num | string nullable | Raw episode number |
| season | int nullable | Parsed |
| episode | int nullable | Parsed |
| is_new | bool default false | |
| icon | string nullable | Programme icon |
| rating | string nullable | |
| Composite index: (epg_channel_id, start_time) | |
| Composite index: (epg_id, start_time) | |

## New Models (5)

### `DvrSetting` — HasOne per Playlist
- Relationships: `playlist()`, `user()`, `recordingRules()`, `recordings()`, `dvrPlaylists()`
- Casts: encrypted `tmdb_api_key`

### `DvrRecordingRule`
- Relationships: `user()`, `dvrSetting()`, `channel()`, `epgChannel()`, `recordings()`
- Scopes: `enabled()`, `series()`, `once()`, `manual()`

### `DvrRecording`
- Relationships: `user()`, `dvrSetting()`, `recordingRule()`, `channel()`
- Scopes: `scheduled()`, `recording()`, `completed()`, `failed()`, `upcoming()`

### `DvrPlaylist`
- Relationships: `user()`, `dvrSetting()`
- Traits: `ShortUrlTrait`

### `EpgProgramme`
- Relationships: `epg()`
- Scopes: `startingBetween()`, `forChannels()`, `upcoming()`

## New Services (6)

### `DvrRecorderService`
- `startRecording(DvrRecording $recording): void`
- `stopRecording(DvrRecording $recording): void`
- `isAtCapacity(DvrSetting $setting): bool`
- Uses FFmpeg: `-i {url} -c copy -f hls -hls_time 6 -hls_list_size 0`
- Records to temp: `dvr/{uuid}/live/`
- Gets stream URL via `Channel::getProxyUrl()` when proxy enabled

### `DvrSchedulerService`
- `tick(): void` — Main scheduler loop
- `matchSeriesRules(DvrSetting $setting, Collection $rules): void`
- `matchOnceRules(DvrSetting $setting, Collection $rules): void`
- `matchManualRules(DvrSetting $setting, Collection $rules): void`
- `resolveConflicts(DvrSetting $setting, Collection $pending): void`
- Runs every 60s via scheduled command
- Queries `epg_programmes` for 30-min lookahead

### `DvrPostProcessorService`
- `process(DvrRecording $recording): void`
- Step 1: Concat HLS -> single .ts (ffmpeg -c copy)
- Step 2: Move to library path: `{show}/Season {XX}/{show} - S{XX}E{XX}.ts`
- Step 3: Calculate file size + duration
- Step 4: Trigger metadata enrichment job
- Step 5: Cleanup temp HLS directory

### `DvrMetadataEnricherService`
- `enrich(DvrRecording $recording): void`
- `enrichBatch(Collection $recordings): void`
- `normalizeTitle(string $title): string`
- `searchTmdb(string $title, ?int $season, ?int $episode): ?array`
- `searchTvMaze(string $title): ?array`
- TMDB primary (requires key), TVMaze fallback (free)

### `DvrRetentionService`
- `enforce(DvrSetting $setting): void`
- `enforceKeepLast(DvrRecordingRule $rule): void`
- `enforceDiskQuota(DvrSetting $setting): void`
- `enforceAge(DvrSetting $setting): void`
- Deletes files + DB records, cleans empty directories

### `DvrPlaylistGeneratorService`
- `generate(DvrPlaylist $playlist): string` — Returns M3U content
- `generateGroupedByShow(DvrSetting $setting): string`
- `generateGroupedByDate(DvrSetting $setting): string`
- `generateGroupedByChannel(DvrSetting $setting): string`
- Cache with invalidation on recording state changes

## New Jobs (6)

| Job | Queue | Timeout | Purpose |
|---|---|---|---|
| `DvrSchedulerTick` | dvr | 60s | Match rules to upcoming programmes |
| `StartDvrRecording` | dvr | 0 (long-running) | Spawn FFmpeg for recording |
| `StopDvrRecording` | dvr | 120s | Gracefully stop FFmpeg |
| `PostProcessDvrRecording` | dvr-post | 30min | Concat + rename + cleanup |
| `EnrichDvrMetadata` | dvr-meta | 120s | TMDB/TVMaze lookup |
| `DvrRetentionCleanup` | dvr | 300s | Periodic retention enforcement |

## Config Changes

### `config/filesystems.php` — Add dvr disk
```php
'dvr' => [
    'driver' => 'local',
    'root' => env('DVR_STORAGE_PATH', storage_path('app/private/dvr')),
    'serve' => true,
],
```

### `config/horizon.php` — Add DVR queues
```php
'dvr-queue' => [
    'connection' => 'redis',
    'queue' => ['dvr', 'dvr-post', 'dvr-meta'],
    'balance' => 'auto',
    'maxProcesses' => env('DB_CONNECTION', 'sqlite') === 'sqlite' ? 1 : 4,
    'maxTime' => 0,
    'maxJobs' => 0,
    'memory' => 512,
    'tries' => 3,
    'timeout' => 60 * 60, // 1 hour (long recordings)
    'nice' => 0,
],
```

## Filament Changes

### PlaylistResource — DVR tab on edit/view
- New "DVR" section with enable toggle, storage config, recording settings
- Creates/updates `DvrSetting` on save

### EPG Viewer — Record button
- Click programme -> modal with Record Once / Record Series options
- Series form: new-only toggle, channel restriction, padding dropdowns, keep-last, priority

### New Resources
- `DvrRecordingsResource` — List/view/manage recordings
- `DvrRecordingRulesResource` — List/create/edit recording rules
- `DvrPlaylistsResource` — List/create/manage DVR playlists

## Routes

### DVR file serving
```
GET /dvr/recordings/{uuid}/stream — Serve .ts file with byte-range support
```

## EpgCacheService Update
- After JSONL cache generation, check if any DVR-enabled playlist uses this EPG
- If yes, also insert/upsert programmes into `epg_programmes` table
- Only store programmes from -1 day to +7 days
- Clean up expired programme rows

## Implementation Order
1. Enums (3 files)
2. Migrations (5 files)
3. Models (5 files) + Factories (5 files)
4. Config changes (filesystems.php, horizon.php)
5. EpgCacheService update (epg_programmes population)
6. DvrRecorderService
7. DvrSchedulerService
8. DvrPostProcessorService
9. DvrMetadataEnricherService
10. DvrRetentionService
11. DvrPlaylistGeneratorService
12. Jobs (6 files)
13. Scheduled command registration
14. PlaylistResource DVR tab
15. EPG viewer recording action
16. DvrRecordingsResource
17. DvrRecordingRulesResource
18. DvrPlaylistsResource
19. DVR file serving route
20. Pest tests
21. Run Pint + verify tests pass
