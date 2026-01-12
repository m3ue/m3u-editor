# Network Broadcast Implementation Plan

## Overview

This document outlines the phased implementation of continuous "live" broadcasting for Networks (pseudo-TV channels). The goal is to create channels that broadcast content according to a schedule, just like traditional TV - when you tune in, the show is already playing at the appropriate time.

## Architecture Summary

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           Network Broadcast System                          │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  ┌─────────────┐     ┌──────────────────┐     ┌─────────────────────────┐  │
│  │   Network   │────▶│  NetworkBroadcast │────▶│    HLS Segments        │  │
│  │  Schedule   │     │     Worker        │     │  /networks/{uuid}/     │  │
│  │ (programmes)│     │                   │     │    live.m3u8           │  │
│  └─────────────┘     │  - FFmpeg process │     │    live000001.ts       │  │
│                      │  - Schedule aware │     │    live000002.ts       │  │
│                      │  - Content switch │     │    ...                 │  │
│  ┌─────────────┐     └──────────────────┘     └─────────────────────────┘  │
│  │   Media     │            │                           │                  │
│  │   Server    │◀───────────┘                           │                  │
│  │ (transcode) │     Request stream at                  ▼                  │
│  └─────────────┘     correct seek position    ┌─────────────────────────┐  │
│                                               │   IPTV Client / Player  │  │
│                                               │   Requests live.m3u8    │  │
│                                               └─────────────────────────┘  │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Key Concepts

1. **Media Server Does Transcoding**: Jellyfin/Emby handle hardware-accelerated transcoding. We request streams with specific bitrate/quality parameters.

2. **FFmpeg Concatenates & Segments**: Our FFmpeg process takes the transcoded stream, seeks to the correct position, and outputs HLS segments.

3. **HLS for Continuous Broadcast**: HLS (HTTP Live Streaming) allows clients to join at any time. The `live.m3u8` playlist is continuously updated with new segments.

4. **Schedule-Aware**: The worker knows what should be playing NOW and calculates the seek position into the current programme.

---

## Phase 1: Network Streaming Configuration ✅ COMPLETE

**Goal**: Add configuration fields to the Network model for streaming settings.

### Completed:

1. **Database Migration** (`2026_01_12_000001_add_broadcast_settings_to_networks_table.php`)
   - `broadcast_enabled` - Toggle for enabling broadcast
   - `output_format` - 'hls' or 'mpegts'
   - `segment_duration` - HLS segment length (default 6s)
   - `hls_list_size` - Segments to keep in playlist (default 10)
   - `transcode_on_server` - Let media server transcode
   - `video_bitrate` - kbps (null = source)
   - `audio_bitrate` - kbps (default 192)
   - `video_resolution` - e.g., '1920x1080' (null = source)
   - `broadcast_started_at` - Timestamp when broadcast started
   - `broadcast_pid` - FFmpeg process ID

2. **Global Settings Migration** (`2026_01_12_000001_add_broadcast_settings.php`)
   - `broadcast_max_concurrent` - Max concurrent broadcasting networks (default 10)

3. **Network Model Updates** (`app/Models/Network.php`)
   - Added casts for new fields
   - `isBroadcasting()` - Check if network is currently broadcasting
   - `getHlsStoragePath()` - Get storage path for HLS segments
   - `getCurrentProgramme()` - Get programme that should be playing now
   - `getNextProgramme()` - Get the next scheduled programme
   - `getCurrentSeekPosition()` - Calculate seek position into current programme
   - `getCurrentRemainingDuration()` - Time remaining in current programme
   - Updated `getStreamUrlAttribute()` to return HLS URL when broadcasting
   - Added `getHlsUrlAttribute()`

4. **GeneralSettings Update** (`app/Settings/GeneralSettings.php`)
   - Added `broadcast_max_concurrent` property

5. **Filament UI Update** (`app/Filament/Resources/Networks/NetworkResource.php`)
   - Added "Broadcast Settings" section with:
     - Enable/disable toggle
     - Output format selection (HLS/MPEG-TS)
     - Segment duration configuration
     - Transcoding settings (bitrate, resolution)
     - Advanced HLS settings
     - Broadcast status display

6. **Routes** (`routes/web.php`)
   - Added `/network/{network}/live.m3u8` for HLS playlist
   - Added `/network/{network}/{segment}.ts` for HLS segments

7. **HLS Controller** (`app/Http/Controllers/NetworkHlsController.php`)
   - `playlist()` - Serve live.m3u8 with proper headers
   - `segment()` - Serve .ts segment files

### Testing Checkpoint

- [ ] Run migration: `php artisan migrate`
- [ ] Can create/edit network with broadcast settings in UI
- [ ] Settings persist correctly
- [ ] HLS endpoint returns 404/503 (expected - no broadcast running yet)
- [ ] Defaults are sensible

---

## Phase 2: Storage & HLS Endpoint

**Goal**: Set up storage location for HLS segments and create endpoint to serve them.

### Storage Structure

```
storage/app/networks/
├── {network-uuid-1}/
│   ├── live.m3u8          # HLS playlist (updated continuously)
│   ├── live000001.ts      # Segment files
│   ├── live000002.ts
│   └── ...
├── {network-uuid-2}/
│   └── ...
```

### HLS Controller

Create `NetworkHlsController` with endpoints:

```php
// Serve the HLS playlist
GET /network/{uuid}/live.m3u8

// Serve individual segments
GET /network/{uuid}/{segment}.ts
```

### Testing Checkpoint

- [ ] Storage directory is created for network
- [ ] HLS playlist endpoint returns 404 (no content yet - expected)
- [ ] Segment endpoint works with test file

---

## Phase 3: FFmpeg Process Management

**Goal**: Create service to spawn and manage FFmpeg processes per network.

### NetworkBroadcastService

Core responsibilities:
1. Start FFmpeg process for a network
2. Monitor process health
3. Stop process gracefully
4. Track running processes

### FFmpeg Command Builder

Build FFmpeg commands that:
1. Request stream from media server at correct seek position
2. Output HLS segments to storage
3. Handle realtime pacing

Example command structure:
```bash
ffmpeg \
  -ss {seek_position} \
  -i "{media_server_stream_url}" \
  -c copy \                              # Copy if server transcoded
  -t {time_until_next_programme} \
  -f hls \
  -hls_time {segment_duration} \
  -hls_list_size {list_size} \
  -hls_flags delete_segments+append_list+program_date_time \
  -hls_segment_filename "{storage_path}/live%06d.ts" \
  "{storage_path}/live.m3u8"
```

### Testing Checkpoint

- [ ] Can start FFmpeg process manually via tinker/command
- [ ] FFmpeg outputs segments to correct location
- [ ] Process can be stopped gracefully
- [ ] live.m3u8 is created and updated

---

## Phase 4: Schedule-Aware Streaming

**Goal**: Make the broadcast worker aware of the schedule and switch content automatically.

### Programme Resolution

```php
// Calculate what should be playing NOW
$currentProgramme = $network->programmes()
    ->where('start_time', '<=', now())
    ->where('end_time', '>', now())
    ->first();

// Calculate seek position
$seekPosition = now()->diffInSeconds($currentProgramme->start_time);

// Calculate remaining duration
$remainingDuration = $currentProgramme->end_time->diffInSeconds(now());
```

### Content Transition Logic

When current programme ends:
1. Current FFmpeg process completes (or is stopped)
2. Next programme is fetched
3. New FFmpeg process starts with new content
4. HLS playlist continues seamlessly (append mode)

### Filler Content

When there's a gap in schedule:
- Use configured filler content (loop a video)
- Or display a "Coming Up" slate
- Or loop network logo

### Testing Checkpoint

- [ ] Current programme is correctly identified
- [ ] Seek position is calculated correctly
- [ ] FFmpeg seeks to correct position in video
- [ ] Transition to next programme works

---

## Phase 5: Background Worker

**Goal**: Create a persistent worker that manages all active network broadcasts.

### Artisan Command

```php
php artisan network:broadcast {network?}
```

Options:
- No argument: Run all enabled networks
- With network UUID: Run specific network

### Worker Loop

```php
while (true) {
    foreach ($activeNetworks as $network) {
        if ($this->needsProcessRestart($network)) {
            $this->stopProcess($network);
            $this->startProcess($network);
        }
        
        $this->monitorHealth($network);
    }
    
    sleep(1); // Check every second
}
```

### Supervisor Configuration

For production, use Supervisor to keep the worker running:

```ini
[program:network-broadcast]
command=php artisan network:broadcast
directory=/var/www/html
autostart=true
autorestart=true
numprocs=1
```

### Testing Checkpoint

- [ ] Command starts and runs continuously
- [ ] Networks start broadcasting automatically
- [ ] Process restarts on content transition
- [ ] Worker handles errors gracefully

---

## Phase 6: Real-Time Monitoring & UI

**Goal**: Add UI to monitor broadcast status and control networks.

### Filament Dashboard Widget

Show:
- Active broadcasts with status
- Current programme info
- Viewer count (if available)
- Stream health indicators

### Control Actions

- Start/Stop broadcast
- Force content refresh
- View logs

### Testing Checkpoint

- [ ] Dashboard shows broadcast status
- [ ] Can start/stop from UI
- [ ] Status updates in real-time

---

## Phase 7: Integration & Polish

**Goal**: Integrate with existing playlist system and polish the feature.

### Playlist Integration

- Network channels in playlist point to HLS endpoint
- EPG reflects actual broadcast schedule
- Failover handling

### Error Handling

- Media server unavailable
- FFmpeg crashes
- Storage full
- Network content exhausted

### Performance Optimization

- Segment cleanup (delete old segments)
- Memory management
- Process pooling limits

### Testing Checkpoint

- [ ] End-to-end test: Create network → Add content → Generate schedule → Start broadcast → Watch in player
- [ ] Stress test multiple networks
- [ ] Recovery from failures

---

## Technical Notes

### Why HLS?

1. **Join Anytime**: Clients can start watching at any segment boundary
2. **Standard Format**: Supported by all IPTV players
3. **Adaptive**: Can support multiple quality levels (future)
4. **HTTP-based**: Works through firewalls, cacheable

### Media Server Transcoding Parameters

Jellyfin/Emby stream URL with transcoding:

```
/Videos/{itemId}/stream.ts?
  VideoBitrate=4000000&
  AudioBitrate=192000&
  MaxWidth=1920&
  MaxHeight=1080&
  VideoCodec=h264&
  AudioCodec=aac&
  api_key={key}
```

### HLS Playlist Format

```m3u8
#EXTM3U
#EXT-X-VERSION:3
#EXT-X-TARGETDURATION:6
#EXT-X-MEDIA-SEQUENCE:142
#EXT-X-PROGRAM-DATE-TIME:2026-01-12T15:30:00.000Z
#EXTINF:6.000,
live000142.ts
#EXTINF:6.000,
live000143.ts
#EXTINF:6.000,
live000144.ts
```

### Process Management Considerations

1. **One FFmpeg per Network**: Each network has its own FFmpeg process
2. **Runs in Main Container**: FFmpeg worker runs inside the m3u-editor container (internal deployment)
3. **Local Storage**: HLS segments stored on local disk (no shared storage needed for single-server)
4. **Simple Count Limit**: Max concurrent broadcasting networks (e.g., 10)
5. **Graceful Shutdown**: SIGTERM to FFmpeg for clean exit
6. **Crash Recovery**: Detect and restart failed processes

---

## Implementation Order

| Phase | Description | Estimated Effort | Dependencies |
|-------|-------------|------------------|--------------|
| 1 | Network Configuration | 1-2 hours | None |
| 2 | Storage & HLS Endpoint | 1-2 hours | Phase 1 |
| 3 | FFmpeg Process Management | 2-3 hours | Phase 2 |
| 4 | Schedule-Aware Streaming | 2-3 hours | Phase 3 |
| 5 | Background Worker | 2-3 hours | Phase 4 |
| 6 | Monitoring & UI | 2-3 hours | Phase 5 |
| 7 | Integration & Polish | 2-3 hours | Phase 6 |

**Total Estimated Effort**: 12-19 hours

---

## Questions to Resolve

1. ~~**Docker Considerations**~~: FFmpeg worker runs in main m3u-editor container ✓

2. ~~**Storage Location**~~: Local disk storage (single-server deployment) ✓

3. ~~**Max Concurrent Networks**~~: Simple count limit (e.g., max 10 broadcasting) ✓

4. **Viewer Tracking**: Do we need to track who's watching?

5. **DVR/Timeshift**: Future feature to allow rewinding live content?

---

## Future Roadmap

Features to consider for future phases:

### Bandwidth-Based Throttling
Instead of simple count limits, track actual bandwidth usage:
- Configure server network capacity (e.g., 1Gbps)
- Set percentage limit for broadcasts (e.g., 50%)
- System calculates available bandwidth dynamically
- Higher quality networks "cost more" capacity

### Multi-Server / Scaling
- Shared storage (NFS/S3) for HLS segments
- Load balancing across broadcast workers
- Centralized process coordination

### DVR / Timeshift
- Keep more HLS segments (hours instead of seconds)
- Allow clients to seek backwards in live stream
- Storage considerations for longer retention

---

## Getting Started

To begin Phase 1, run:

```bash
php artisan make:migration add_broadcast_settings_to_networks_table
```

Then update the Network model and Filament resource with the new fields.
