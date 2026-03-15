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

The following environment variables control HLS storage behavior and garbage collection. All GC runs entirely inside the proxy process — no Laravel commands are involved.

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

### Broadcast segment storage

Network broadcasts (pseudo-TV channels) write short-lived `.ts` segments that are replaced every few seconds. Because these segments are ephemeral — they never need to survive a container restart — writing them to a RAM disk avoids unnecessary disk I/O and reduces latency.

```env
# Where broadcast HLS segments are written (default: /dev/shm — RAM disk)
# /dev/shm is a tmpfs mount available on all Linux containers.
# Override with a host-mapped path or larger tmpfs if your /dev/shm is too small.
HLS_BROADCAST_DIR=/dev/shm
```

> **Tip:** If you run many concurrent networks or have a small `/dev/shm`, map a larger host tmpfs or bind-mount a fast local directory instead:
> ```yaml
> volumes:
>   - /run/m3u-broadcast:/broadcast-segments  # host tmpfs at /run
> environment:
>   - HLS_BROADCAST_DIR=/broadcast-segments
> ```

### Broadcast HLS garbage collection

During programme transitions in network broadcasts, the old FFmpeg process is stopped and a new one starts. The segments left by the old process are orphaned — they fall outside the new playlist's window and are never cleaned up by FFmpeg's `delete_segments` flag. Over time these orphans accumulate and fill storage.

The broadcast GC runs as a background task inside the proxy and periodically scans broadcast directories to remove orphaned `.ts` files.

```env
# Enable/disable broadcast segment cleanup (default: true)
BROADCAST_GC_ENABLED=true

# How often to scan for orphaned broadcast segments in seconds (default: 300 = 5 minutes)
BROADCAST_GC_INTERVAL=300

# Remove stale inactive broadcast directories older than this in seconds (default: 600 = 10 minutes)
BROADCAST_GC_AGE_THRESHOLD=600
```

The broadcast GC performs two passes on each interval:
1. **Active broadcasts** — removes `.ts` files not referenced by the current playlist that are older than 60 seconds (guards against race conditions during transitions).
2. **Inactive directories** — removes entire stale broadcast directories (no active process, age > `BROADCAST_GC_AGE_THRESHOLD`) using a safe recursive delete.

### Defaults & behavior (when env vars are not set)

- **Defaults used by the system:**
  - `HLS_TEMP_DIR=/var/www/html/storage/app/hls-segments`
  - `HLS_GC_ENABLED=true`
  - `HLS_GC_INTERVAL=600` (seconds)
  - `HLS_GC_AGE_THRESHOLD=7200` (seconds)
  - `HLS_BROADCAST_DIR=/dev/shm` (RAM disk — all Linux containers have this)
  - `BROADCAST_GC_ENABLED=true`
  - `BROADCAST_GC_INTERVAL=300` (seconds)
  - `BROADCAST_GC_AGE_THRESHOLD=600` (seconds)
- **Startup behavior:** If `HLS_TEMP_DIR` is not set the startup script uses the default path, **creates the directory if missing**, sets permissions, and **checks available disk space** (warns if <2GB, critical if <512MB). The same directory-creation logic applies to `HLS_BROADCAST_DIR` (warns if <256MB).
- **Garbage collector behavior:** All GC runs inside the proxy as async background tasks. The proxy also performs pre-start cleanup — when a fresh broadcast begins (`segment_start_number=0`), any leftover `.ts`/`.m3u8` files are removed before FFmpeg starts.
- **Recommendation:** For production explicitly set these env vars and **volume map** `HLS_TEMP_DIR` to a host path (or tmpfs) so you control capacity and retention. `HLS_BROADCAST_DIR` defaults to `/dev/shm` which is ideal for ephemeral broadcast segments.

**Tips:**
- Use `HLS_GC_ENABLED=false` to disable automatic GC (useful for local development or debugging).
- Use `BROADCAST_GC_ENABLED=false` to disable broadcast segment cleanup independently.
- Use `php artisan network:cleanup-segments` to manually trigger a one-off cleanup of old segments across all networks.

---

## External Proxy: HLS Environment Variables

When using an **external** m3u-proxy container (i.e. `M3U_PROXY_ENABLED=false`), you **must** set the HLS environment variables on the `m3u-proxy` service itself. Environment variables on the `m3u-editor` service are not visible to the separate proxy container.

If these variables are missing from the proxy service, it falls back to its internal defaults and ignores any path you configured on the editor.

```yaml
services:
  m3u-proxy:
    image: sparkison/m3u-proxy:experimental
    environment:
      - API_TOKEN=${M3U_PROXY_TOKEN}
      # ... other proxy env vars ...

      # HLS Segment Storage & Garbage Collection
      - HLS_TEMP_DIR=${HLS_TEMP_DIR:-/var/www/html/storage/app/hls-segments}
      - HLS_GC_ENABLED=${HLS_GC_ENABLED:-true}
      - HLS_GC_INTERVAL=${HLS_GC_INTERVAL:-600}
      - HLS_GC_AGE_THRESHOLD=${HLS_GC_AGE_THRESHOLD:-7200}

      # Broadcast Segment Storage (network broadcasts write to RAM disk by default)
      - HLS_BROADCAST_DIR=${HLS_BROADCAST_DIR:-/dev/shm}

      # Broadcast HLS Garbage Collection (orphaned segment cleanup)
      - BROADCAST_GC_ENABLED=${BROADCAST_GC_ENABLED:-true}
      - BROADCAST_GC_INTERVAL=${BROADCAST_GC_INTERVAL:-300}
      - BROADCAST_GC_AGE_THRESHOLD=${BROADCAST_GC_AGE_THRESHOLD:-600}
```

> **Note:** When using embedded proxy (`M3U_PROXY_ENABLED=true`), the `start-container` script and Supervisor automatically pass all these values to the proxy process — no extra configuration is needed.

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
