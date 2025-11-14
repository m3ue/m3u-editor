# Dockerfile Build Arguments

This document explains the build arguments available when building the m3u-editor Docker image.

---

## Available Build Arguments

### **M3U_PROXY_REPO**
**Description:** The Git repository URL for m3u-proxy  
**Default:** `https://github.com/sparkison/m3u-proxy.git` (upstream)  
**Purpose:** Allows using a fork of m3u-proxy instead of the upstream repository

### **M3U_PROXY_BRANCH**
**Description:** The Git branch to clone from the m3u-proxy repository  
**Default:** `main`  
**Purpose:** Allows using a specific branch (e.g., `dev`, `feature-branch`)

### **GIT_BRANCH**
**Description:** The current Git branch of m3u-editor  
**Default:** (set by CI/CD)  
**Purpose:** Embedded in the image for version tracking

### **GIT_COMMIT**
**Description:** The current Git commit SHA of m3u-editor  
**Default:** (set by CI/CD)  
**Purpose:** Embedded in the image for version tracking

### **GIT_TAG**
**Description:** The current Git tag of m3u-editor (if any)  
**Default:** (set by CI/CD)  
**Purpose:** Embedded in the image for version tracking

---

## Usage Examples

### **1. Build with Default (Upstream) m3u-proxy**

```bash
docker build -t m3u-editor:custom .
```

This uses:
- `M3U_PROXY_REPO=https://github.com/sparkison/m3u-proxy.git`
- `M3U_PROXY_BRANCH=main`

---

### **2. Build with Your Fork of m3u-proxy**

```bash
docker build \
  --build-arg M3U_PROXY_REPO=https://github.com/hektyc/m3u-proxy.git \
  --build-arg M3U_PROXY_BRANCH=dev \
  -t m3u-editor:custom .
```

This uses:
- Your fork: `hektyc/m3u-proxy`
- Your branch: `dev`

---

### **3. Build with a Specific Feature Branch**

```bash
docker build \
  --build-arg M3U_PROXY_REPO=https://github.com/yourusername/m3u-proxy.git \
  --build-arg M3U_PROXY_BRANCH=feature/new-hls-improvements \
  -t m3u-editor:feature .
```

---

### **4. Build with Docker Compose**

Add to your `docker-compose.yml`:

```yaml
services:
  m3u-editor:
    build:
      context: .
      args:
        M3U_PROXY_REPO: https://github.com/hektyc/m3u-proxy.git
        M3U_PROXY_BRANCH: dev
    image: m3u-editor:custom
    # ... rest of config
```

Then build:
```bash
docker-compose build
```

---

## GitHub Actions Integration

The GitHub Actions workflows automatically use the repository owner's fork:

**File:** `.github/workflows/publish_dev.yml`

```yaml
build-args: |
  GIT_BRANCH=${{ github.ref_name }}
  GIT_COMMIT=${{ github.sha }}
  GIT_TAG=${{ github.ref_type == 'tag' && github.ref_name || '' }}
  M3U_PROXY_REPO=https://github.com/${{ github.repository_owner }}/m3u-proxy.git
  M3U_PROXY_BRANCH=dev
```

**How it works:**
- `${{ github.repository_owner }}` automatically resolves to the GitHub username
- For `hektyc/m3u-editor`, it uses `https://github.com/hektyc/m3u-proxy.git`
- For `sparkison/m3u-editor`, it uses `https://github.com/sparkison/m3u-proxy.git`
- **Fully dynamic** - works for any fork!

---

## Benefits

### **For Fork Maintainers**
✅ Test your m3u-proxy changes in AIO without manual intervention  
✅ CI/CD automatically uses your fork  
✅ No need to modify Dockerfile for each change

### **For Contributors**
✅ Can test feature branches easily  
✅ Build locally with custom m3u-proxy versions  
✅ Flexible development workflow

### **For Upstream**
✅ Default behavior unchanged (uses sparkison/m3u-proxy)  
✅ No breaking changes  
✅ Backward compatible

---

## Verification

To verify which m3u-proxy version is in your image:

```bash
# Check the build log
docker build . 2>&1 | grep "Cloning m3u-proxy"

# Or inspect the running container
docker exec m3u-editor cat /opt/m3u-proxy/.git/config
```

You should see output like:
```
Cloning m3u-proxy from: https://github.com/hektyc/m3u-proxy.git (branch: dev)
```

---

## Troubleshooting

### **Issue: Build fails with "fatal: Remote branch not found"**

**Cause:** The specified branch doesn't exist in the repository

**Solution:** Check that the branch exists:
```bash
git ls-remote https://github.com/yourusername/m3u-proxy.git
```

### **Issue: Build uses wrong repository**

**Cause:** Build args not passed correctly

**Solution:** Verify build args:
```bash
docker build --build-arg M3U_PROXY_REPO=... --progress=plain . 2>&1 | grep M3U_PROXY
```

### **Issue: GitHub Actions still uses upstream**

**Cause:** Workflow file not updated or m3u-proxy fork doesn't exist

**Solution:** 
1. Ensure you have a fork at `https://github.com/yourusername/m3u-proxy`
2. Check workflow file has the build args
3. Verify repository owner matches your username

---

## Related Files

- `Dockerfile` - Contains the ARG declarations and git clone command
- `.github/workflows/publish_dev.yml` - Dev branch CI/CD
- `.github/workflows/publish_master.yml` - Master branch CI/CD
- `.github/workflows/publish_experimental.yml` - Experimental branch CI/CD

---

**Last Updated:** 2025-11-13  
**Related Issue:** HLS Segment Storage Fixes

