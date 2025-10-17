# M3U Proxy Integration Guide

## 📁 Files

**Ready-to-use configuration files:**
- [`docker-compose.proxy.yml`](../docker-compose.proxy.yml) - Complete docker-compose setup
- [`.env.proxy.example`](../.env.proxy.example) - Environment variables template

**Quick deployment:**
```bash
# Download files
curl -O https://raw.githubusercontent.com/sparkison/m3u-editor/main/docker-compose.proxy.yml
curl -O https://raw.githubusercontent.com/sparkison/m3u-editor/main/.env.proxy.example

# Setup environment
cp .env.proxy.example .env
# Edit .env and set secure tokens (see instructions in file)

# Deploy
docker-compose -f docker-compose.proxy.yml up -d
```

## 🚀 Quick Start - Recommended Setup (External Instance)

**Why External?** Running m3u-proxy as a separate container provides better performance, scalability, Redis-based pooling, and independent management. This is the **recommended production setup**.

### Option 1: External Proxy with Redis (Recommended)

Complete docker-compose setup with m3u-editor, m3u-proxy, and Redis:

```yaml
services:
  m3u-editor:
    image: sparkison/m3u-editor:latest
    container_name: m3u-editor
    environment:
      - TZ=Etc/UTC
      - APP_URL=http://localhost
      # PostgreSQL settings
      - ENABLE_POSTGRES=true
      - PG_DATABASE=${PG_DATABASE:-m3ue}
      - PG_USER=${PG_USER:-m3ue}
      - PG_PASSWORD=${PG_PASSWORD:-secret}
      - PG_PORT=${PG_PORT:-5432}
      - DB_CONNECTION=pgsql
      - DB_HOST=localhost
      - DB_PORT=${PG_PORT:-5432}
      - DB_DATABASE=${PG_DATABASE:-m3ue}
      - DB_USERNAME=${PG_USER:-m3ue}
      - DB_PASSWORD=${PG_PASSWORD:-secret}
      # M3U Proxy settings (external)
      - M3U_PROXY_ENABLED=true
      - M3U_PROXY_URL=http://m3u-proxy:8085
      - M3U_PROXY_TOKEN=${M3U_PROXY_TOKEN:-your-secure-token-here}
    volumes:
      - ./data:/var/www/config
      - pgdata:/var/lib/postgresql/data
    restart: unless-stopped
    ports:
      - 36400:36400
    networks:
      - m3u-network
    depends_on:
      - m3u-proxy

  m3u-proxy:
    image: sparkison/m3u-proxy:latest
    container_name: m3u-proxy
    environment:
      - API_TOKEN=${M3U_PROXY_TOKEN:-your-secure-token-here}
      - REDIS_ENABLED=true
      - REDIS_URL=redis://redis:6379/0
      - ENABLE_REDIS_POOLING=true
      - LOG_LEVEL=INFO
    restart: unless-stopped
    networks:
      - m3u-network
    devices:
      - /dev/dri:/dev/dri
    depends_on:
      - redis

  redis:
    image: redis:7-alpine
    container_name: m3u-proxy-redis
    restart: unless-stopped
    networks:
      - m3u-network
    volumes:
      - redis-data:/data

networks:
  m3u-network:
    driver: bridge

volumes:
  pgdata:
  redis-data:
```

**Key Benefits:**
- ✅ Redis-based stream pooling (multiple clients share one transcode process)
- ✅ Better performance and resource utilization
- ✅ Independent scaling and management
- ✅ Separate logging and monitoring
- ✅ No nginx reverse proxy overhead

### Option 2: External Proxy without Redis

If you don't need advanced pooling features:

```yaml
services:
  m3u-editor:
    image: sparkison/m3u-editor:latest
    container_name: m3u-editor
    environment:
      - TZ=Etc/UTC
      - APP_URL=http://localhost
      # ... (PostgreSQL settings same as above)
      # M3U Proxy settings (external, no Redis)
      - M3U_PROXY_ENABLED=true
      - M3U_PROXY_URL=http://m3u-proxy:8085
      - M3U_PROXY_TOKEN=${M3U_PROXY_TOKEN:-your-secure-token-here}
    volumes:
      - ./data:/var/www/config
      - pgdata:/var/lib/postgresql/data
    restart: unless-stopped
    ports:
      - 36400:36400
    networks:
      - m3u-network
    depends_on:
      - m3u-proxy

  m3u-proxy:
    image: sparkison/m3u-proxy:latest
    container_name: m3u-proxy
    environment:
      - API_TOKEN=${M3U_PROXY_TOKEN:-your-secure-token-here}
      - LOG_LEVEL=INFO
    restart: unless-stopped
    networks:
      - m3u-network
    devices:
      - /dev/dri:/dev/dri

networks:
  m3u-network:
    driver: bridge

volumes:
  pgdata:
```

### Option 3: Embedded Proxy (Legacy)

For simple setups or development only:

```bash
# .env or docker-compose.yml
M3U_PROXY_ENABLED=false  # or don't set it at all
# M3U_PROXY_URL is auto-set to ${APP_URL}/m3u-proxy
```

**Access:** `${APP_URL}/m3u-proxy/` (e.g., `http://m3ueditor.test/m3u-proxy/`)

**Note:** Embedded mode is simpler but lacks Redis pooling and independent management.


## 📋 Management Commands

| Command | Description | When to Use |
|---------|-------------|-------------|
| `php artisan m3u-proxy:status` | Check status, health, and stats | Verifying external or embedded setup |
| `php artisan m3u-proxy:update` | Update embedded proxy to latest | Only for embedded mode |
| `php artisan m3u-proxy:restart` | Restart embedded proxy service | Only for embedded mode |

### Command Examples

**For External Proxy:**
```bash
# Check if external proxy is reachable and healthy
docker exec -it m3u-editor php artisan m3u-proxy:status

# Restart external proxy container
docker restart m3u-proxy

# View external proxy logs
docker logs m3u-proxy -f --tail 100
```

**For Embedded Proxy:**
```bash
# Check embedded proxy status
docker exec -it m3u-editor php artisan m3u-proxy:status

# Update embedded proxy to latest version
docker exec -it m3u-editor php artisan m3u-proxy:update --restart

# Restart embedded proxy service
docker exec -it m3u-editor php artisan m3u-proxy:restart

# View embedded proxy logs
docker exec -it m3u-editor tail -100 /var/www/html/storage/logs/m3u-proxy.log
```


## 🔧 Configuration Reference

### Environment Variables

#### M3U Editor Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `M3U_PROXY_ENABLED` | `false` | `false` = embedded proxy, `true` = external proxy |
| `M3U_PROXY_URL` | auto-set | External: `http://m3u-proxy:8085`, Embedded: `${APP_URL}/m3u-proxy` |
| `M3U_PROXY_TOKEN` | auto-generated | API token - must match `API_TOKEN` in m3u-proxy |

#### M3U Proxy Container Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `API_TOKEN` | none | API authentication token (must match `M3U_PROXY_TOKEN`) |
| `REDIS_URL` | none | Redis connection URL (e.g., `redis://redis:6379/0`) |
| `REDIS_ENABLED` | `false` | Enable Redis connection |
| `ENABLE_TRANSCODING_POOLING` | `true` | Enable transcoding stream pooling (if Redis enabled) |
| `LOG_LEVEL` | `INFO` | Logging level: `DEBUG`, `INFO`, `WARNING`, `ERROR` |
| `ROOT_PATH` | `/m3u-proxy` | API root path - default optimized for m3u-editor integration |
| `DOCS_URL` | `/docs` | Swagger UI path (relative to ROOT_PATH) |

#### Embedded Mode Only Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `M3U_PROXY_HOST` | `127.0.0.1` | Bind address (embedded only) |
| `M3U_PROXY_PORT` | `8085` | Internal port (embedded only) |
| `M3U_PROXY_LOG_LEVEL` | `ERROR` | Log level (embedded only) |

**Authentication:** When using API token authentication, see [M3U Proxy Authentication](https://github.com/sparkison/m3u-proxy/blob/master/docs/AUTHENTICATION.md)


## 🐛 Troubleshooting

### External Proxy Issues

**Proxy Not Reachable:**
```bash
# Check if m3u-proxy container is running
docker ps | grep m3u-proxy

# Test connectivity from m3u-editor
docker exec -it m3u-editor curl http://m3u-proxy:8085/health

# Check m3u-proxy logs
docker logs m3u-proxy -f --tail 100

# Verify network configuration
docker network inspect m3u-network
```

**Authentication Errors (401/403):**
```bash
# Verify tokens match
docker exec -it m3u-editor env | grep M3U_PROXY_TOKEN
docker exec -it m3u-proxy env | grep API_TOKEN

# Test with token
curl -H "X-API-Token: your-token-here" http://localhost:8085/health
```

**Redis Connection Issues:**
```bash
# Check Redis container
docker ps | grep redis

# Test Redis from m3u-proxy
docker exec -it m3u-proxy ping redis

# Check Redis logs
docker logs m3u-proxy-redis

# Verify Redis URL
docker exec -it m3u-proxy env | grep REDIS_URL
```

### Embedded Proxy Issues

**Proxy Not Starting:**
```bash
# Check supervisor status
docker exec -it m3u-editor supervisorctl status m3u-proxy

# View logs
docker exec -it m3u-editor tail -50 /var/www/html/storage/logs/m3u-proxy.log

# Restart
docker exec -it m3u-editor supervisorctl restart m3u-proxy
```

**Port Already in Use:**
Change the port:
```bash
M3U_PROXY_PORT=8086
M3U_PROXY_URL=http://localhost:8086
```

Then restart the container.

**Update Failed:**
```bash
# Manual update
docker exec -it m3u-editor sh -c "cd /opt/m3u-proxy && git pull"
docker exec -it m3u-editor sh -c "cd /opt/m3u-proxy && .venv/bin/pip install -r requirements.txt"
docker exec -it m3u-editor php artisan m3u-proxy:restart
```
docker exec -it m3u-editor tail -100 /var/www/html/storage/logs/m3u-proxy.log
```


## 📍 File Locations

### External Proxy (Container)
| Path | Description |
|------|-------------|
| Container logs | `docker logs m3u-proxy` |
| Redis data | Named volume `redis-data` |

### Embedded Proxy
| Path | Description |
|------|-------------|
| `/opt/m3u-proxy` | Proxy installation directory |
| `/opt/m3u-proxy/.venv` | Python virtual environment |
| `/opt/m3u-proxy/main.py` | Proxy entry point |
| `/var/www/html/storage/logs/m3u-proxy.log` | Proxy logs |

## 🔄 Common Workflows

### Deploy New External Setup (Recommended)

1. Create `docker-compose.yml` with the full example above

2. Create `.env` file:
```bash
# Generate secure token
M3U_PROXY_TOKEN=$(openssl rand -hex 32)

# Database credentials
PG_DATABASE=m3ue
PG_USER=m3ue
PG_PASSWORD=$(openssl rand -base64 32)
```

3. Start services:
```bash
docker-compose up -d
```

4. Verify everything is running:
```bash
# Check containers
docker-compose ps

# Test m3u-proxy health
docker exec -it m3u-editor php artisan m3u-proxy:status

# Check Redis connection
docker exec -it m3u-proxy redis-cli -h redis ping
```

### Migrate from Embedded to External

1. Update docker-compose.yml to add m3u-proxy and redis services

2. Update m3u-editor environment:
```yaml
environment:
  - M3U_PROXY_ENABLED=true
  - M3U_PROXY_URL=http://m3u-proxy:8085
  - M3U_PROXY_TOKEN=your-secure-token-here
```

3. Stop and recreate containers:
```bash
docker-compose down
docker-compose up -d
```

4. Verify:
```bash
docker exec -it m3u-editor php artisan m3u-proxy:status
```

### Switch from External to Embedded

1. Update m3u-editor environment:
```yaml
environment:
  - M3U_PROXY_ENABLED=false  # or remove it
  # M3U_PROXY_URL will be auto-set
```

2. Remove m3u-proxy and redis services from docker-compose.yml

3. Rebuild and start:
```bash
docker-compose down
docker-compose build --no-cache
docker-compose up -d
```

4. Verify:
```bash
docker exec -it m3u-editor php artisan m3u-proxy:status
```


## 🧪 Testing

### Test External Proxy

```bash
# 1. Check status from m3u-editor
docker exec -it m3u-editor php artisan m3u-proxy:status

# 2. Test API health directly
curl http://localhost:8085/health

# 3. Test with authentication
curl -H "X-API-Token: your-token-here" http://localhost:8085/health

# 4. List active streams
curl -H "X-API-Token: your-token-here" http://localhost:8085/streams

# 5. Create a test stream
curl -X POST http://localhost:8085/streams \
  -H "X-API-Token: your-token-here" \
  -H "Content-Type: application/json" \
  -d '{"url": "https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8"}'

# 6. Test Redis connection
docker exec -it m3u-proxy redis-cli -h redis ping

# 7. Check proxy stats
curl -H "X-API-Token: your-token-here" http://localhost:8085/stats
```

### Test Embedded Proxy

```bash
# 1. Check status
docker exec -it m3u-editor php artisan m3u-proxy:status

# 2. Test API health (via nginx reverse proxy)
curl http://m3ueditor.test/m3u-proxy/health

# 3. List active streams
curl http://m3ueditor.test/m3u-proxy/streams

# 4. Create a test stream
curl -X POST http://m3ueditor.test/m3u-proxy/streams \
  -H "Content-Type: application/json" \
  -d '{"url": "https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8"}'

# 5. Test from inside container (direct)
docker exec -it m3u-editor curl http://127.0.0.1:8085/health
```

## 🎯 Performance Tuning

### Redis Pooling Configuration

When using external m3u-proxy with Redis, you can tune pooling behavior:

```yaml
m3u-proxy:
  environment:
    - REDIS_URL=redis://redis:6379/0
    - ENABLE_REDIS_POOLING=true
    - REDIS_POOL_MAX_CONNECTIONS=50  # Max Redis connections
    - STREAM_TIMEOUT=300              # Stream timeout in seconds
    - CLEANUP_INTERVAL=60             # Cleanup interval in seconds
```

### Resource Limits

Set resource limits for better control:

```yaml
m3u-proxy:
  deploy:
    resources:
      limits:
        cpus: '2.0'
        memory: 2G
      reservations:
        cpus: '0.5'
        memory: 512M

redis:
  deploy:
    resources:
      limits:
        cpus: '1.0'
        memory: 512M
      reservations:
        cpus: '0.25'
        memory: 128M
```


## 📚 Additional Resources

- **M3U Proxy Repository**: https://github.com/sparkison/m3u-proxy
- **M3U Proxy Documentation**: https://github.com/sparkison/m3u-proxy/tree/master/docs
- **Authentication Guide**: https://github.com/sparkison/m3u-proxy/blob/master/docs/AUTHENTICATION.md
- **Event System**: https://github.com/sparkison/m3u-proxy/blob/master/docs/EVENT_SYSTEM.md
- **Implementation Details**: `docs/IMPLEMENTATION_SUMMARY.md`

## 💡 Tips & Best Practices

### General
- **Use external proxy for production** - better performance, Redis pooling, independent scaling
- **Use embedded proxy for development** - simpler setup, no extra containers
- **Always use API token authentication** in production environments
- **Monitor Redis memory usage** when using pooling (set maxmemory policy)
- **Use docker-compose health checks** for automatic container restart

### External Proxy
- ✅ Redis pooling allows multiple clients to share transcoding processes
- ✅ Independent container restart without affecting m3u-editor
- ✅ Better resource isolation and monitoring
- ✅ Can scale horizontally by running multiple proxy instances
- ✅ Direct access to m3u-proxy API and logs

### Embedded Proxy
- ✅ Simpler setup - one less container to manage
- ✅ Nginx reverse proxy provides path-based routing
- ✅ Localhost-only binding (more secure by default)
- ✅ Automatic updates via artisan commands
- ⚠️ No Redis pooling support
- ⚠️ Shares resources with Laravel application
- ⚠️ Must rebuild container to update Python dependencies

### Security
- Always set strong `API_TOKEN` / `M3U_PROXY_TOKEN` in production
- Use Docker networks to isolate services
- Don't expose m3u-proxy ports directly (use nginx reverse proxy)
- Rotate tokens periodically
- Monitor logs for unusual activity

### Monitoring
```bash
# Watch proxy logs in real-time (external)
docker logs m3u-proxy -f

# Watch proxy logs (embedded)
docker exec -it m3u-editor tail -f /var/www/html/storage/logs/m3u-proxy.log

# Monitor Redis memory
docker exec -it m3u-proxy-redis redis-cli INFO memory

# Check active streams periodically
watch -n 5 'curl -s -H "X-API-Token: token" http://localhost:8085/stats | jq'
```

## 🔒 Security Recommendations

1. **Use strong API tokens**:
```bash
# Generate secure token
openssl rand -hex 32
```

2. **Limit network exposure**:
```yaml
m3u-proxy:
  ports: []  # Don't expose ports externally
  networks:
    - m3u-network  # Only internal network
```

3. **Use environment files**:
```bash
# .env file (add to .gitignore)
M3U_PROXY_TOKEN=your-secret-token-here
```

4. **Enable Redis authentication** (optional):
```yaml
redis:
  command: redis-server --requirepass your-redis-password
```

5. **Use read-only filesystem** where possible:
```yaml
m3u-proxy:
  read_only: true
  tmpfs:
    - /tmp
```
