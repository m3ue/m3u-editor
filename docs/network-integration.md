# Network ⇄ Media Server Integration

This document explains the features and technical details of the Network broadcasting and Media Server integrations used in this project. It contains a short, plain-language overview for operators and a technical appendix for engineers who need to debug or extend the system.

---

## Overview (Plain English) ✅

- Networks are "pseudo-TV" channels built from media server content (Jellyfin/Emby/etc.).
- Networks run as continuous HLS broadcasts served from storage (per-network `live.m3u8` + `.ts` segments).
- The system generates programme schedules for networks (EPG) and ensures media plays at the correct position when broadcasting restarts.
- Players can access network content via the built-in Xtream-compatible API or direct HLS URLs.

---

## Quick operational checklist (when to use what) 📋

- Enable and start broadcasting for one network: `php artisan network:broadcast:ensure {network_uuid_or_id}`
- Fix stale/failed broadcasts: `php artisan network:broadcast:heal [--dry-run]`
- Clean up old HLS files: `php artisan hls:gc [--dry-run] [--threshold=<s>]`
- Regenerate schedules (hourly job exists): `php artisan networks:regenerate-schedules`

**Scheduled Broadcasts:**
- Networks can be configured to wait until a specific date/time before starting
- Enable "Schedule Start Time" toggle in Broadcast Settings tab
- Set a future date/time (down to the second) for when broadcast should begin
- The broadcast worker will automatically start the stream when the scheduled time is reached
- Scheduled networks show status "Scheduled" with countdown in the networks list

Tip: Use `--dry-run` on `hls:gc` to preview deletions before actual cleanup. ✅

---

## Features & Behavior (Summarized) 🔧

- **Continuous HLS broadcasting**: Each network writes segments into a storage path (e.g. `storage/app/networks/{uuid}`).
- **Persisted broadcast reference**: When a broadcast starts we persist `broadcast_programme_id` and `broadcast_initial_offset_seconds` on the Network so restarts can resume at the right position.
- **Real seeking**: The broadcast uses FFmpeg input-level seeking (`-ss` before `-i`) to ensure the stream actually begins at the calculated offset. The media server `StartTimeTicks` parameter is still used as a hint when fetching the media.
- **Resilience & healing**: If FFmpeg dies, the heal command clears stale PID entries and attempts to restart using the persisted reference.
- **HLS Garbage Collection**: A scheduled & manual `hls:gc` command deletes old `.ts` and stale playlist files to prevent disk growth.
- **Xtream API support for networks**: `player_api.php` endpoints are supported so IPTV players can list networks and fetch EPG; `/live/` stream requests redirect to the network HLS URL.

---

## Technical appendix (Engineers) 🧩

### Key classes & methods

- NetworkBroadcastService
  - `start(Network $network)`: Start a network broadcast; builds FFmpeg command & executes it in the background.
  - `buildFfmpegCommand(Network $network, NetworkProgramme $programme, int $seekPosition)`: Builds command and adds `-ss` if seeking.
  - `executeCommand(...)`: Runs `nohup ... & echo $!`, stores the PID, and persists `broadcast_programme_id` and `broadcast_initial_offset_seconds`.
  - `isProcessRunning(Network $network)`: Verifies `/proc/<pid>/cmdline` contains `ffmpeg`.
  - `cleanupSegments(Network $network)`: Deletes old `.ts` files older than a short retention (used in periodic cleanup and the `hls:gc` command).
  - `getStatus(Network $network)`: Returns broadcast status including `playlist_exists`, `segment_count`, current programme timings.

- NetworkScheduleService
  - Generates daily programme schedules used by EPG actions and to determine current content.

- XtreamApiController & XtreamStreamController
  - Support for returning networks as live streams (get_live_streams/get_live_categories/get_short_epg/get_simple_data_table).
  - `handleNetworkStream()` redirects `/live/{user}/{pass}/{network_id}.{ext}` to the network's HLS playlist.

### FFmpeg seeking details (important) ⚠️

- Use `-ss <seconds>` before `-i` for demuxer-level seek. This performs an actual seek into the input and is necessary for accurate resume behaviour.
- The media server `StartTimeTicks` parameter (Jellyfin/Emby) is still set in the fetch URL as a secondary measure, but `-ss` is what ensures the process begins at the right position.
- Example built command fragment:

```text
ffmpeg -y -ss 3600 -re -reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 10 -i 'https://media.example/Videos/12345/stream.ts?api_key=...' -t 1500 ... -f hls -hls_time 6 -hls_list_size 20 -hls_flags delete_segments+append_list+program_date_time -hls_segment_filename /path/live%06d.ts /path/live.m3u8
```

### Persisted broadcast reference

- Columns added to `networks` table (via migrations):
  - `broadcast_programme_id` (nullable)
  - `broadcast_initial_offset_seconds` (nullable)
- Purpose: allow restart after crash to calculate seek position relative to when broadcast originally started.

### Automated cleanup & scheduling

- **`hls:gc`** - cleans `storage/app/networks/*` and a temp HLS path. Use `--dry-run` to validate.

#### tmpfs (/dev/shm) — performance vs persistence
- Using a tmpfs (for example mounting the host's `/dev/shm` into the container) gives the best I/O for HLS segment writes (lower latency and higher throughput).
- **Important:** tmpfs is RAM-backed and **non‑persistent** — contents are lost on reboot/container restart. Do **not** rely on tmpfs if you need segments to survive restarts.
- **Sizing & GC guidance:** pick tmpfs size >= (segments_per_stream × avg_segment_size × concurrent_streams × safety_factor). Example: 20 segments × 1.5MB × 100 streams ≈ 3GB; add ×2 headroom. If using tmpfs, make GC more aggressive (e.g. `HLS_GC_INTERVAL=60`, `HLS_GC_AGE_THRESHOLD=180`) to avoid memory exhaustion.
- **Recommendation:** for ephemeral/high‑performance staging use tmpfs; for production use a host directory (or host tmpfs) mapped into the container and tune `HLS_GC_*`. See `docs/hls-storage-config.md` for full examples and verification steps.
- **Per-network cleanup** - `NetworkBroadcastService::cleanupSegments()` deletes segments older than the retention window.
- **Hourly schedule** - `networks:regenerate-schedules` is scheduled to ensure daily EPG schedules are kept fresh.

---

## Testing & troubleshooting (commands & tips) 🧪

- Check broadcast PID and process:
  - Inspect PID on Network model: `SELECT broadcast_pid, broadcast_started_at FROM networks WHERE id = <id>`
  - Check `/proc/<pid>/cmdline` contains `ffmpeg` (or `ps -ef | grep ffmpeg`)
- Check logs and FFmpeg output:
  - Per-network `ffmpeg.log` under network HLS storage (e.g., `storage/app/networks/{uuid}/ffmpeg.log`)
  - Laravel logs show `HLS_METRIC` entries: `broadcast_started`, `broadcast_crashed`, `broadcast_healed`, `broadcast_stopped`.
- Test Xtream API (replace `uuid` & `admin`/password accordingly):
  - List streams: curl "http://localhost:36400/player_api.php?username=admin&password=<playlist_uuid>&action=get_live_streams"
  - List categories: curl "...&action=get_live_categories"
  - Short EPG: curl "...&action=get_short_epg&stream_id=<network_id>&limit=4"
  - Check stream redirect: curl -I "http://localhost:36400/live/admin/<playlist_uuid>/<network_id>.ts" (should return `302 Location: http://.../network/{uuid}/live.m3u8`)
- Verify HLS playlist & segments are being generated:
  - `curl http://127.0.0.1:36400/network/<network-uuid>/live.m3u8 | head -20`
  - `ls storage/app/networks/<uuid> | tail` to inspect segments
- Run garbage collection (dry-run):
  - `php artisan hls:gc --dry-run --threshold=3600`
- Heal stale broadcast state (dry-run then run):
  - `php artisan network:broadcast:heal --dry-run`
  - If dry-run shows stale pids, then `php artisan network:broadcast:heal` will clear them and attempt restarts
- Manually enable, generate schedule, and start a network in one step:
  - `php artisan network:broadcast:ensure <network_uuid_or_id>`

---

## Automated tests (added) ✅

- `tests/Feature/NetworkCleanupTest.php` — verifies:
  - `stop()` removes lingering `live.m3u8`, `*.m3u8.tmp`, and `*.ts` files when PID is null or when a PID exists but the process is not running
  - destroying a network triggers `stop()` and removes the network HLS storage directory
  - `cleanupSegments()` deletes old `.ts` segments
  - deleted network endpoints return `404`

- `tests/Feature/NetworkReconnectAfterStopTest.php` — verifies:
  - a client that reconnects after `stop()` cannot resume playback; playlist/segment endpoints return `503` or `404`
  - segments are served with non-cacheable headers to prevent intermediaries/browsers from replaying stopped streams

- `tests/Feature/NetworkHlsControllerTest.php` — updated assertions to ensure:
  - the playlist endpoint only returns content when `isBroadcasting()` is true
  - the segment endpoint returns `503` when a network is not actively broadcasting (even if segment files exist)
  - `Content-Type: application/vnd.apple.mpegurl` is accepted with or without an explicit `charset`

How to run these tests locally (inside the project container):

- Run a single test file:
  - `./vendor/bin/pest tests/Feature/NetworkReconnectAfterStopTest.php`
- Run a named test via the Laravel runner:
  - `php artisan test --filter="stop removes lingering HLS files"`
- Run all network-related feature tests:
  - `./vendor/bin/pest tests/Feature/Network* -v`

**Why these tests were added:**
- Prevent serving stale HLS content after a broadcast is stopped or a network is deleted
- Verify atomic promotion of temporary playlists to avoid serving partially-written playlists
- Ensure controller guards and cache headers prevent clients, proxies, or browsers from continuing to play stopped streams

---

## Improvements & Checklist ✅

This section summarizes what has already been implemented for the Network → HLS integration, how to verify it, and recommended follow-ups prioritized by impact.

### Completed (✅)
- **Controller guards**: playlist/segment endpoints and legacy stream endpoint now require the network to be actively broadcasting (`isBroadcasting()`) before serving content.
- **Stop cleanup**: `NetworkBroadcastService::stop()` clears persisted broadcast refs and deletes `live.m3u8`, `*.m3u8.tmp`, and `*.ts` files to prevent stale playback.
- **Sequence tracking**: `broadcast_segment_sequence` and `broadcast_discontinuity_sequence` columns track HLS continuity across programme transitions. FFmpeg uses `-start_number` to continue segment numbering.
- **Discontinuity markers**: `insertDiscontinuityMarker()` adds `#EXT-X-DISCONTINUITY` to playlists when transitioning between programmes.
- **Non-cache headers**: segment and stream responses include `Cache-Control: no-cache, no-store, must-revalidate` (and related headers) to avoid proxies/browsers replaying stopped content.
- **EPG programme images**: `NetworkScheduleService::getContentImage()` now tries multiple fallback sources (episode cover → info fields → series cover; channel logo → movie_data → info) to maximize image availability in generated EPG.
- **EPG XMLTV icon fallback**: `NetworkEpgService::formatProgrammeXml()` falls back to contentable's image fields when `programme->image` is empty, ensuring programmes display icons even if they weren't stored during schedule generation.
- **Tests added / updated**:
  - `NetworkCleanupTest` (cleanup behavior)
  - `NetworkBroadcastPromotionTest` (tmp → live promotion)
  - `NetworkReconnectAfterStopTest` (reconnect cannot resume playback)
  - `NetworkHlsControllerTest` (assertions tightened / relaxed where necessary)
  - `NetworkEpgServiceTest` (EPG icon output and fallback logic)
- **Container test runs**: Composer & dev deps installed in container; tests run and relevant new tests pass locally in the container.
- **Logging / metrics**: `HLS_METRIC` events emitted on broadcast lifecycle events (`broadcast_started`, `broadcast_stopped`, `broadcast_crashed`).

### In-progress / Validated (⚡)
- Test harness adjustments: made CI-friendly fixes and made header assertions tolerant to platform variations.

### Planned / Recommended (📝)
- **High priority**
  - Add monitoring & alerts for FFmpeg failures (metrics, uptime checks, Prometheus/Grafana dashboard panels, alert rules).
- **Medium priority**
  - Add optional proxy cache invalidation or light restart on stop to guarantee no intermediary caches stale segments/playlists (use only when embedded proxy is used).
- **Low priority**
  - Add more stress tests for rapid start/stop/reconnect and for race-condition coverage.
  - Harden file permissions/ownership and test idempotency of cleanup under concurrent stop/start calls.

### Checklist (status | item)
- [x] Controller guards implemented
- [x] Stop cleanup (delete playlists/segments & clear persisted refs) implemented
- [x] Sequence tracking for HLS continuity implemented
- [x] Segment/stream no-cache headers set
- [x] EPG programme image fallbacks in schedule generation
- [x] EPG XMLTV icon fallback to contentable images
- [x] Tests added and passing (local container)
- [ ] Optional: proxy cache invalidation on stop
- [ ] Monitoring & alerting for broadcast health
- [ ] Add race-condition tests

### How to verify manually 🔍
1. Start a broadcast: `php artisan network:broadcast:ensure <network_id>` or use Filament UI.
2. Confirm playlist & segment are reachable (200 status) and segment header contains `Cache-Control` that includes `no-cache`.
3. Stop the broadcast via Filament action (**Stop Broadcast**) or `app(\App\Services\NetworkBroadcastService::class)->stop($network)`.
4. Confirm playlist returns `503` (or `404` if files removed) and segment returns `503`/`404`.
5. Confirm `storage/app/networks/<uuid>` no longer contains `live.m3u8`/`.ts` files for that network.
6. Check Laravel logs for `HLS_METRIC: broadcast_stopped`.

### Ownership & ETA suggestions
- Proxy invalidation is optional and should be implemented only for embedded proxy setups (1 sprint).

---

## Notes, caveats & best practices 💡

- Configuration should be read via `config()` rather than `env()` in application code (config caching). `network_broadcast_enabled` lives in `config/app.php` and is controlled via `NETWORK_BROADCAST_ENABLED` env var.
- Keep `HLS_GC_ENABLED=true` in environments where automatic cleanup is acceptable.
- Always `--dry-run` the `hls:gc` command the first time you run it in a new environment.
- When restarting broadcasts, persistent references ensure correct seek; however, if schedules are changed, the persisted programme may no longer match the current timeline — `network:broadcast:ensure` regenerates schedules if missing.

---

## Where to look in the codebase 🔎

- Service & logic:
  - `app/Services/NetworkBroadcastService.php`
  - `app/Services/NetworkScheduleService.php`
  - `app/Models/Network.php` (helpers like `getCurrentProgramme()`, persisted reference logic)
- Commands:
  - `app/Console/Commands/NetworkBroadcastEnsure.php`
  - `app/Console/Commands/NetworkBroadcastHeal.php`
  - `app/Console/Commands/RegenerateNetworkSchedulesCommand.php`
- Xtream API:
  - `app/Http/Controllers/XtreamApiController.php`
  - `app/Http/Controllers/XtreamStreamController.php`
- Migrations:
  - `database/migrations/*broadcast_reference*.php`

---

## Road Map

- [x] Add configurable schedule generation window (per-network setting, 1-30 days)
- [ ] Implement network refresh and regeneration. (perhaps on adjustments, network is regenerated cleanly -- May require duplicating the network and removing the source)
- [ ] Implement Schedules view with gui adjustments
- [ ] Add optional filler content when no media is available
- [ ] Add optional automatic playlist generation on network creation
- [ ] Add optional intelligent content line ups based on media collections (e.g. "Trilogys", "Top Rated", "Recently Added")
- [ ] Add "Export lineup" feature to generate shareable playlists from networks
- [x] Content reshuffling to avoid repeats in short timeframes (seeded shuffle with weekly variation)
- [x] Add configurable schedule generations (e.g. beyond the 7 day default) (per-network `schedule_window_days` setting)
- [x] Add auto-regeneration toggle (per-network `auto_regenerate_schedule` flag)
