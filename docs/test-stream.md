# Test Stream Documentation

The `StreamTestController` provides endpoints for testing continuous TS streams with configurable timeouts. This is useful for testing stream stability, proxy functionality, and behavior when streams fail mid-stream.

## Endpoints

### Direct TS Stream (Primary Endpoint)
```
GET /api/stream/test/{timeout}.ts
```

**Note:** HLS streaming endpoints have been removed to focus on the more reliable direct TS streaming for proxy testing.

## Parameters

- `{timeout}` - Integer representing seconds:
  - `0` = No timeout (infinite stream, shows runtime counter, perfect for proxy testing)
  - `10` = Stream runs for 10 seconds then terminates (shows countdown)
  - `30` = Stream runs for 30 seconds then terminates (shows countdown)
  - etc.

## Stream Features

### Technical Specifications
- **Video**: 720p H.264 (Constrained Baseline, Level 3.0) at 25fps
- **Audio**: AAC-LC mono at 128 kbps, 48kHz
- **Container**: MPEG-TS (Transport Stream)
- **Real-time output**: Uses `-re` flag for proper timing

### Stream Types

#### Infinite Streams (`timeout=0`)
- **Purpose**: Designed for proxy testing and long-running scenarios
- **Behavior**: Continuous stream that runs indefinitely until connection is closed
- **Display**: Shows runtime counter (Runtime: 00:00, 00:04, 00:08, etc.)
- **Proxy Compatible**: Tested with FFmpeg stream copying for extended periods

#### Finite Streams (`timeout>0`)
- **Purpose**: Testing timeout scenarios and stream termination
- **Behavior**: Stream runs for specified duration then terminates cleanly
- **Display**: Shows countdown timer (Countdown: 00:30, 00:26, 00:22, etc.)

## Examples

### Infinite test stream for proxy testing (shows runtime counter)  
```
http://localhost:36400/api/stream/test/0.ts
```

### 30-second test stream (shows countdown)
```
http://localhost:36400/api/stream/test/30.ts
```
- **Resolution**: 720p (1280x720) with H.264 video and AAC audio
- **Format**: MPEG-TS segments suitable for HLS streaming
- **Segment Duration**: 4 seconds per segment
- **Video**: H.264 Constrained Baseline profile, 25fps, test pattern with timer overlay
- **Audio**: AAC 128kbps, 1kHz test tone

## Features

- **FFmpeg Integration**: Uses FFmpeg for proper video generation with timer overlay
- **HLS Segmented Streaming**: Proper HLS playlist with individual TS segments
- **Connection Monitoring**: Automatically stops when client disconnects
- **Logging**: Comprehensive logging for debugging stream behavior
- **CORS Support**: Includes CORS headers for web player compatibility
- **Real-time Generation**: Segments generated on-demand

## Testing with Video Players

### VLC
1. Open VLC
2. Media → Open Network Stream
3. Enter: `http://localhost:36400/api/stream/test/30.m3u8`
4. Click Play

### FFplay
```bash
# Test 30-second countdown stream
ffplay http://localhost:36400/api/stream/test/30.m3u8

# Test infinite runtime stream  
ffplay http://localhost:36400/api/stream/test/0.m3u8
```

### Web Players (HLS.js, Video.js, etc.)
The `.m3u8` playlist format is compatible with most web-based HLS players.

### cURL Testing
```bash
# Download first segment
curl "http://localhost:36400/api/stream/test/30/segment_0.ts" -o segment.ts

# Verify segment with ffprobe
ffprobe segment.ts
```

## Use Cases

1. **Stream Stability Testing**: Test how your application handles streams that suddenly terminate
2. **Timeout Testing**: Verify stream timeout handling with different durations  
3. **Player Compatibility**: Test different video players with a known-good stream
4. **Network Testing**: Use as a test stream for network performance analysis
5. **Development**: Quick test stream without needing external sources
6. **HLS Testing**: Test HLS playlist parsing and segment fetching

## Technical Details

### Playlist Structure
- **VOD Playlist**: For streams with timeout (finite duration)
- **Live-like Playlist**: For infinite streams (shows first 10 segments)
- **Segment Duration**: 4 seconds each
- **Target Duration**: 4 seconds

### Video Specifications
- **Container**: MPEG-TS
- **Video Codec**: H.264 (Constrained Baseline, Level 3.0)
- **Audio Codec**: AAC-LC
- **Resolution**: 1280x720 (720p)
- **Frame Rate**: 25 fps
- **Pixel Format**: YUV420P

## Logs

All stream activity is logged to Laravel's standard logging system with the following information:
- Segment generation start/completion
- FFmpeg command execution
- Connection status and errors
- Process timeouts and failures

## Troubleshooting

### No Video Content
- Ensure FFmpeg is installed and available in PATH
- Check Laravel logs for FFmpeg errors
- Verify the endpoint returns HTTP 200 with `video/mp2t` content-type

### Player Won't Load Stream
- Test playlist URL directly in browser (should show M3U8 content)
- Verify individual segment URLs are accessible
- Check CORS headers if testing from web browsers

### Stream Stops Unexpectedly
- Check Laravel logs for connection abort messages
- Verify timeout parameter is correct
- Test with a longer timeout value

## Proxy Testing

The infinite test streams (`timeout=0`) are specifically designed for testing proxy functionality. They provide continuous, stable streams that are ideal for validating proxy configurations and stream forwarding.

### Proxy Compatibility
- **Stream Copying**: Compatible with FFmpeg's `-c:v copy -c:a copy` stream copying
- **Extended Duration**: Tested for extended periods without timeout issues  
- **Real-time Output**: Uses proper timing for consistent proxy behavior
- **Connection Handling**: Handles proxy disconnections gracefully

### Example Proxy Commands

#### FFmpeg Stream Copy (Most Common)
```bash
# Proxy the infinite test stream with stream copying
ffmpeg -i "http://localhost:36400/api/stream/test/0.ts" \
       -c:v copy -c:a copy \
       -f mpegts \
       pipe:1

# Proxy with timeout (useful for testing)
ffmpeg -i "http://localhost:36400/api/stream/test/0.ts" \
       -c:v copy -c:a copy \
       -t 60 \
       -f mpegts \
       output.ts
```

#### Testing Internal Proxy Systems
```bash
# Test your proxy by using the test stream as source
# Replace with your actual proxy implementation
your-proxy-command --source="http://localhost:36400/api/stream/test/0.ts" --output=mp4
```

### Troubleshooting Proxy Issues

1. **30-second timeouts**: The infinite streams resolve common proxy timeout issues
2. **Stream ending unexpectedly**: Use `timeout=0` for continuous streams  
3. **Compatibility issues**: The streams use standard H.264/AAC encoding compatible with most players
4. **Buffering problems**: Real-time output ensures consistent data flow
