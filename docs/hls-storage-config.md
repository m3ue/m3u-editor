# HLS Storage Configuration Guide

## Problem

You're seeing this warning in startup logs:
```
🎬 Configuring HLS segment storage...
   HLS_TEMP_DIR: /dev/shm
   Available disk space: 0GB (64MB)
   ⚠️  WARNING: Low disk space for HLS segments!
   🔴 CRITICAL: Very low disk space!
```

**Root Cause**: You set `HLS_TEMP_DIR=/dev/shm`, which points to the **container's** `/dev/shm` (64MB tmpfs), NOT your host's `/dev/shm`.

---

## Solution: Proper Volume Mapping

### Option 1: Use Host /dev/shm (Recommended for Performance)

**Docker Run Command**:
```bash
docker run -d \
  --name m3u-editor \
  -p 36400:36400 \
  -v ./data:/var/www/config \
  -v /dev/shm:/hls-segments \  # ← Map host /dev/shm to container path
  -e HLS_TEMP_DIR=/hls-segments \  # ← Point to the mapped path
  sparkison/m3u-editor:dev
```

**Docker Compose**:
```yaml
services:
  m3u-editor:
    image: sparkison/m3u-editor:dev
    container_name: m3u-editor
    ports:
      - "36400:36400"
    volumes:
      - ./data:/var/www/config
      - /dev/shm:/hls-segments  # ← Map host /dev/shm
    environment:
      - HLS_TEMP_DIR=/hls-segments  # ← Point to mapped path
```

**Result**: Container uses your host's `/dev/shm` (8TB available)

---

### Option 2: Use Host Directory (Persistent Storage)

**Docker Run Command**:
```bash
docker run -d \
  --name m3u-editor \
  -p 36400:36400 \
  -v ./data:/var/www/config \
  -v /path/to/your/hls-storage:/hls-segments \  # ← Map host directory
  -e HLS_TEMP_DIR=/hls-segments \
  sparkison/m3u-editor:dev
```

**Docker Compose**:
```yaml
services:
  m3u-editor:
    image: sparkison/m3u-editor:dev
    container_name: m3u-editor
    ports:
      - "36400:36400"
    volumes:
      - ./data:/var/www/config
      - /mnt/storage/hls-segments:/hls-segments  # ← Map host directory
    environment:
      - HLS_TEMP_DIR=/hls-segments
```

**Result**: HLS segments stored on your host filesystem (persistent, 8TB available)

---

### Option 3: Use Docker tmpfs Mount (In-Memory, Size-Limited)

**Docker Run Command**:
```bash
docker run -d \
  --name m3u-editor \
  -p 36400:36400 \
  -v ./data:/var/www/config \
  --tmpfs /hls-segments:rw,size=10g \  # ← Create 10GB tmpfs
  -e HLS_TEMP_DIR=/hls-segments \
  sparkison/m3u-editor:dev
```

**Docker Compose**:
```yaml
services:
  m3u-editor:
    image: sparkison/m3u-editor:dev
    container_name: m3u-editor
    ports:
      - "36400:36400"
    volumes:
      - ./data:/var/www/config
    tmpfs:
      - /hls-segments:rw,size=10g  # ← Create 10GB tmpfs
    environment:
      - HLS_TEMP_DIR=/hls-segments
```

**Result**: Container has dedicated 10GB in-memory storage (fast, but limited)

---

## Recommended Configuration

**For your setup (8TB available on host)**:

```yaml
services:
  m3u-editor:
    image: sparkison/m3u-editor:dev
    container_name: m3u-editor
    ports:
      - "36400:36400"
    volumes:
      - ./data:/var/www/config
      - /dev/shm:/hls-segments  # Map host /dev/shm
    environment:
      # Application
      - APP_URL=http://10.76.23.92:36400
      - APP_PORT=36400
      
      # HLS Storage
      - HLS_TEMP_DIR=/hls-segments  # Use mapped /dev/shm
      - HLS_GC_ENABLED=true
      - HLS_GC_INTERVAL=600  # 10 minutes
      - HLS_GC_AGE_THRESHOLD=3600  # 1 hour
      
      # M3U Proxy
      - M3U_PROXY_ENABLED=true
      - M3U_PROXY_TOKEN=your-secure-token
```

---

## Environment variables

The following environment variables control HLS storage behavior and garbage collection. Add them to your environment or `.env` file to override defaults.

```env
# HLS storage path (where segments are written)
HLS_TEMP_DIR=/var/www/html/storage/app/hls-segments

# Garbage collection: enable/disable the background GC loop
HLS_GC_ENABLED=true

# GC loop interval in seconds (default: 600 seconds = 10 minutes)
HLS_GC_INTERVAL=600

# Delete files older than this threshold in seconds (default: 7200 seconds = 2 hours)
HLS_GC_AGE_THRESHOLD=7200
```

### Defaults & behavior (when env vars are not set)
- **Defaults used by the system:**
  - `HLS_TEMP_DIR=/var/www/html/storage/app/hls-segments`
  - `HLS_GC_ENABLED=true`
  - `HLS_GC_INTERVAL=600` (seconds)
  - `HLS_GC_AGE_THRESHOLD=7200` (seconds)
- **Startup behavior:** If `HLS_TEMP_DIR` is not set the startup script uses the default path, **creates the directory if missing**, sets permissions, and **checks available disk space** (warns if <2GB, critical if <512MB).
- **Garbage collector behavior:** `php artisan hls:gc` honors `HLS_GC_ENABLED`; when enabled Supervisor runs the command in loop mode using the configured `--interval` and `--threshold` values. Use `--dry-run` to preview deletions safely.
- **Recommendation:** For production explicitly set these env vars and **volume map** `HLS_TEMP_DIR` to a host path (or tmpfs) so you control capacity and retention.

**Tips:**
- Use `HLS_GC_ENABLED=false` to disable automatic GC (useful for local development or debugging). 
- Use `php artisan hls:gc --dry-run` to preview deletions before enabling automatic GC.

---

## HLS Garbage Collector (hls:gc)

A built-in Artisan command `php artisan hls:gc` will remove old HLS segment files and stale playlists. It supports a looping mode which is enabled in the container via Supervisor when `HLS_GC_ENABLED=true`.

Supervisor runs the command as:

```
php /var/www/html/artisan hls:gc --loop --interval=$HLS_GC_INTERVAL --threshold=$HLS_GC_AGE_THRESHOLD --no-interaction
```

The command has the following options:

- `--loop` : Run continuously (used by Supervisor)
- `--interval` : Seconds to sleep between iterations (default: 600)
- `--threshold` : File age threshold in seconds (default: 7200)
- `--dry-run` : Show which files would be deleted without removing them

By default the GC looks in both `storage/app/networks/*` and the `HLS_TEMP_DIR` path.

### Metrics emitter

A lightweight metrics emitter `php artisan hls:metrics` will record per-network HLS metrics (segment counts and storage bytes) to `/var/log/hls-metrics.log` and to the Laravel log. Supervisor can run this in a loop when `HLS_METRICS_ENABLED=true`.

Environment variables:

- `HLS_METRICS_ENABLED` (default: `true`) — run periodic metrics emitter via Supervisor
- `HLS_METRICS_INTERVAL` (default: `300`) — run interval in seconds


---

## Verification

After restarting with correct volume mapping, you should see:

```
🎬 Configuring HLS segment storage...
   HLS_TEMP_DIR: /hls-segments
   HLS_GC_ENABLED: true
   HLS_GC_INTERVAL: 600s
   HLS_GC_AGE_THRESHOLD: 3600s
   ✅ Directory exists: /hls-segments
   Available disk space: 7450GB (7629824MB)  ← Your 8TB!
   ✅ HLS storage configured
```

---

## Common Mistakes

### ❌ WRONG: Setting HLS_TEMP_DIR without volume mapping
```yaml
environment:
  - HLS_TEMP_DIR=/dev/shm  # Points to container's 64MB /dev/shm!
```

### ✅ CORRECT: Map host path AND set HLS_TEMP_DIR
```yaml
volumes:
  - /dev/shm:/hls-segments  # Map host /dev/shm
environment:
  - HLS_TEMP_DIR=/hls-segments  # Use mapped path
```

---

## Why This Matters

1. **Container Isolation**: Containers have their own `/dev/shm` (default 64MB)
2. **Volume Mapping**: You must explicitly map host paths to container paths
3. **Environment Variable**: `HLS_TEMP_DIR` tells m3u-proxy WHERE to write segments
4. **Disk Space Check**: The startup script checks available space at `HLS_TEMP_DIR`

**Without proper mapping**: Container sees only 64MB (container's /dev/shm)
**With proper mapping**: Container sees your full 8TB (host's /dev/shm or directory)

---

## Next Steps

1. **Stop your container**
2. **Update your docker-compose.yml or run command** with proper volume mapping
3. **Start the container**
4. **Verify** the startup logs show correct disk space
5. **Test** HLS streaming - segments should now be written successfully

