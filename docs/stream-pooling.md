# Stream Pooling and Connection Management

## Overview

Stream pooling allows multiple clients to share a single transcoded stream from m3u-proxy without consuming additional provider connections. This is particularly important when:

1. **Provider has connection limits** (e.g., "max 1 connection" per account)
2. **Multiple users want to watch the same channel**
3. **Transcoding is enabled** (pooling works with transcoded streams)

## How It Works

### Without Pooling (Old Behavior)
```
User 1 → m3u-editor → m3u-proxy → Creates Stream A → Provider Connection 1
User 2 → m3u-editor → m3u-proxy → Creates Stream B → Provider Connection 2 ❌ (REJECTED if limit=1)
```

### With Pooling (New Behavior)
```
User 1 → m3u-editor → m3u-proxy → Creates Stream A → Provider Connection 1
User 2 → m3u-editor → m3u-proxy → Reuses Stream A → SAME Provider Connection ✅
User 3 → m3u-editor → m3u-proxy → Reuses Stream A → SAME Provider Connection ✅
```

## Implementation

### m3u-editor Side (M3uProxyService)

The `M3uProxyService` now checks for existing pooled streams before creating new ones:

```php
// In getChannelStream() method
if ($profile) {
    // Check for existing pooled stream
    $existingStreamId = $this->findExistingPooledStream($id, $playlist->uuid);
    
    if ($existingStreamId) {
        // Reuse existing stream
        return $this->buildTranscodeStreamUrl($existingStreamId);
    }
    
    // Create new stream if none exists
    $streamId = $this->createTranscodedStream(...);
}
```

### Finding Pooled Streams

The `findExistingPooledStream()` method queries m3u-proxy's `/streams/by-metadata` endpoint:

```php
protected function findExistingPooledStream(int $channelId, string $playlistUuid): ?string
{
    // Query m3u-proxy for active streams with matching metadata
    $response = Http::get($endpoint, [
        'field' => 'id',
        'value' => (string) $channelId,
        'active_only' => true,
    ]);
    
    // Find stream matching: channel ID + playlist UUID + transcoding enabled
    foreach ($matchingStreams as $stream) {
        if (
            $metadata['id'] == $channelId &&
            $metadata['playlist_uuid'] === $playlistUuid &&
            $metadata['transcoding'] === 'true'
        ) {
            return $stream['stream_id'];
        }
    }
}
```

### m3u-proxy Side

The m3u-proxy handles the actual stream pooling:

1. **Stream Creation**: Each transcoded stream includes metadata:
   ```json
   {
     "id": "12345",
     "type": "channel",
     "playlist_uuid": "abc-def-123",
     "transcoding": "true",
     "profile": "default"
   }
   ```

2. **Client Registration**: Multiple clients can register to the same stream
3. **FFmpeg Process Sharing**: All clients receive data from the same FFmpeg transcoding process
4. **Grace Period Cleanup**: Stream stays alive for 10 seconds after last client disconnects

## Benefits

### Connection Efficiency
- **1 provider connection** serves unlimited clients (within reasonable limits)
- No more "max connections exceeded" errors for popular channels
- Reduced load on provider servers

### Resource Efficiency
- **1 FFmpeg process** transcodes for all clients on the same channel
- Lower CPU usage on the proxy server
- Reduced bandwidth from provider (single source stream)

### Better User Experience
- Users can watch the same channel simultaneously
- Instant playback for subsequent users (stream already running)
- No wait time for FFmpeg to start

## Connection Limit Behavior

### Playlist Connection Limits

m3u-editor still enforces playlist connection limits, but now checks **active m3u-proxy streams** instead of just database records:

```php
if ($playlist->available_streams !== 0) {
    $activeStreams = self::getCachedActiveStreamsCountByMetadata(
        'playlist_uuid', 
        $playlist->uuid, 
        1 // cache for 1 second
    );

    if ($activeStreams >= $playlist->available_streams) {
        abort(503, 'Playlist has reached its maximum stream limit.');
    }
}
```

**Key Point**: With pooling, 5 users watching the same channel only count as **1 active stream** towards the playlist limit!

### Provider Connection Limits

Provider limits are respected automatically:
- **Without pooling**: Each user = 1 provider connection (can exceed limit)
- **With pooling**: Multiple users = 1 provider connection (never exceeds limit)

## Requirements

### For Pooling to Work

1. **Transcoding must be enabled** (profile parameter provided)
2. **Same channel** (same channel ID)
3. **Same playlist** (same playlist UUID)
4. **Stream still active** (has at least one connected client)

### Direct Streams (Non-Transcoded)

Direct streams (without transcoding) **do NOT pool** because:
- Each client needs its own connection to the provider
- No shared FFmpeg process
- Provider may serve different bitrates/quality per connection

## API Endpoints Used

### Query for Pooled Streams
```
GET /streams/by-metadata?field=id&value=12345&active_only=true
```

Returns:
```json
{
  "filter": {"field": "id", "value": "12345"},
  "active_only": true,
  "matching_streams": [
    {
      "stream_id": "abc123...",
      "client_count": 3,
      "metadata": {
        "id": "12345",
        "type": "channel",
        "playlist_uuid": "...",
        "transcoding": "true"
      },
      "is_active": true,
      "url": "http://..."
    }
  ],
  "total_matching": 1,
  "total_clients": 3
}
```

### Get Stream Details
```
GET /streams/{stream_id}
```

Returns detailed info about a specific stream, including all connected clients.

## Logging

Look for these log messages:

### m3u-editor Logs
```
[INFO] Found existing pooled transcoded stream
  stream_id: abc123...
  channel_id: 12345
  playlist_uuid: xyz789...
  client_count: 3

[INFO] Reusing existing pooled transcoded stream
  stream_id: abc123...
  channel_id: 12345
  playlist_uuid: xyz789...
```

### m3u-proxy Logs
```
[INFO] Client client_xyz registered to transcoded stream abc123
[INFO] Transcoded stream abc123 now has 3 connected clients
[INFO] Last client disconnected from stream abc123, starting 10s grace period
[INFO] Grace period elapsed for stream abc123, stopping FFmpeg process
```

## Troubleshooting

### Users Can't Connect to Popular Channels

**Symptom**: Error "Playlist has reached its maximum stream limit"

**Cause**: Pooling isn't working - each user creates a new stream

**Check**:
1. Is transcoding enabled? (profile parameter set)
2. Are streams actually being created? (check m3u-proxy logs)
3. Is `findExistingPooledStream()` returning null? (check m3u-editor logs)

### Pooling Not Working

**Symptom**: Multiple FFmpeg processes for same channel

**Debug**:
```bash
# Check active streams via API
curl -H "X-API-Token: your-token" http://your-proxy:8085/m3u-proxy/streams

# Check for pooled streams
curl -H "X-API-Token: your-token" \
  "http://your-proxy:8085/m3u-proxy/streams/by-metadata?field=id&value=12345&active_only=true"
```

**Common Issues**:
- Different playlists (playlist_uuid doesn't match)
- Different profiles (streams have different transcoding profiles)
- Stream expired (no active clients when new user tries to connect)

### Stream Quality Issues

**Symptom**: Video stuttering or buffering with many clients

**Cause**: Single transcoded stream may not have enough bitrate for many clients

**Solutions**:
1. Increase transcoding bitrate in StreamProfile
2. Use hardware acceleration (NVENC/VAAPI)
3. Limit max concurrent clients per stream (not yet implemented)

## Future Enhancements

Potential improvements:

1. **Max clients per pool**: Limit how many clients can share one stream
2. **Quality tiers**: Different pools for different quality levels
3. **Load balancing**: Distribute clients across multiple transcoded streams
4. **Persistent streams**: Keep popular channels always transcoding
5. **Predictive pooling**: Pre-start streams for likely-to-be-watched channels

## Best Practices

1. **Enable transcoding for live channels** to benefit from pooling
2. **Set reasonable playlist limits** based on actual provider connection limits
3. **Monitor stream statistics** to understand usage patterns
4. **Use hardware acceleration** when available for better transcoding performance
5. **Cache stream queries** (already implemented with 1-second cache)
