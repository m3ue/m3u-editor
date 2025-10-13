# M3U Proxy Quick Reference

## 🚀 Quick Start

### Use Embedded Proxy (Default) <sup>`v0.8.1+`</sup>
```bash
# .env or docker-compose.yml
M3U_PROXY_ENABLED=false  # or don't set it at all
# M3U_PROXY_URL is auto-set to ${APP_URL}/m3u-proxy
```

**Access:** `${APP_URL}/m3u-proxy/` (e.g., `http://m3ueditor.test/m3u-proxy/`)

### Use External Proxy Container
```bash
# .env or docker-compose.yml
M3U_PROXY_ENABLED=true         # Use external service
M3U_PROXY_URL=http://m3u-proxy:8085
```

## 📋 Commands

| Command | Description | When to Use |
|---------|-------------|-------------|
| `php artisan m3u-proxy:status` | Check status, health, and stats | Anytime - verifying setup |
| `php artisan m3u-proxy:update` | Update to latest version | After upstream changes |
| `php artisan m3u-proxy:restart` | Restart the service | After config changes |

### Command Examples

```bash
# Check if proxy is running and healthy
docker exec -it m3u-editor php artisan m3u-proxy:status

# Update proxy to latest version and restart
docker exec -it m3u-editor php artisan m3u-proxy:update --restart

# Just restart the service
docker exec -it m3u-editor php artisan m3u-proxy:restart
```

## 🔧 Configuration Reference

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `M3U_PROXY_ENABLED` | `false` | false/unset = embedded, true = external |
| `M3U_PROXY_HOST` | `127.0.0.1` | Proxy bind address (embedded, localhost) |
| `M3U_PROXY_PORT` | `8085` | Proxy internal port (embedded) |
| `M3U_PROXY_URL` | auto-set | `${APP_URL}/m3u-proxy` (embedded) or custom (external) |
| `M3U_PROXY_TOKEN` | auto-set (embedded) | auto generated or custom (if using external instance, should match `API_TOKEN` in m3u-proxy instance) |
| `M3U_PROXY_LOG_LEVEL` | `ERROR` | (embedded) set embedded instance log level |

NOTE: When using API token authentication, please reference the m3u-proxy docs for more detail: [M3U Proxy Authentication](https://github.com/sparkison/m3u-proxy/blob/master/docs/AUTHENTICATION.md)

## 🐛 Troubleshooting

### Proxy Not Starting

```bash
# Check supervisor
docker exec -it m3u-editor supervisorctl status m3u-proxy

# View logs
docker exec -it m3u-editor tail -50 /var/www/html/storage/logs/m3u-proxy.log

# Restart
docker exec -it m3u-editor supervisorctl restart m3u-proxy
```

### Port Already in Use

Change the port:
```bash
M3U_PROXY_PORT=8086
M3U_PROXY_URL=http://localhost:8086
```

Then restart the container.

### Update Failed

```bash
# Manual update
docker exec -it m3u-editor sh -c "cd /opt/m3u-proxy && git pull"
docker exec -it m3u-editor sh -c "cd /opt/m3u-proxy && .venv/bin/pip install -r requirements.txt"
docker exec -it m3u-editor php artisan m3u-proxy:restart
```

### Health Check Failed

```bash
# Test API directly
curl http://localhost:8085/health

# Check if service is running
docker exec -it m3u-editor supervisorctl status m3u-proxy

# View recent logs
docker exec -it m3u-editor tail -100 /var/www/html/storage/logs/m3u-proxy.log
```

## 📍 File Locations

| Path | Description |
|------|-------------|
| `/opt/m3u-proxy` | Proxy installation directory |
| `/opt/m3u-proxy/.venv` | Python virtual environment |
| `/opt/m3u-proxy/main.py` | Proxy entry point |
| `/var/www/html/storage/logs/m3u-proxy.log` | Proxy logs |

## 🔄 Common Workflows

### Switch from External to Embedded

1. Update configuration:
```bash
M3U_PROXY_ENABLED=false  # or remove it
# M3U_PROXY_URL will be auto-set
```

2. Rebuild and start:
```bash
docker-compose down
docker-compose build --no-cache
docker-compose up -d
```

3. Verify:
```bash
docker exec -it m3u-editor php artisan m3u-proxy:status
```

### Switch from Embedded to External

1. Update configuration:
```bash
M3U_PROXY_ENABLED=true
M3U_PROXY_URL=http://m3u-proxy:8085
```

2. Add external proxy to docker-compose.yml

3. Restart:
```bash
docker-compose restart
```

### Update Embedded Proxy

```bash
# Quick update and restart
docker exec -it m3u-editor php artisan m3u-proxy:update --restart

# Verify new version
docker exec -it m3u-editor php artisan m3u-proxy:status
```

## 🧪 Testing

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

## 📚 Additional Resources

- **Full Documentation**: `docs/m3u-proxy-integration.md`
- **Implementation Details**: `docs/IMPLEMENTATION_SUMMARY.md`
- **M3U Proxy Repo**: https://github.com/sparkison/m3u-proxy

## 💡 Tips

- **Default is embedded** - just don't set `M3U_PROXY_ENABLED` or set it to `false`
- **Embedded proxy uses nginx reverse proxy** - accessible at `${APP_URL}/m3u-proxy/`
- **No extra ports needed** - embedded proxy binds to localhost only
- Set `M3U_PROXY_ENABLED=true` only when using an external proxy service
- Proxy version is frozen at build time - use `m3u-proxy:update` to update without rebuild
- Logs are helpful for debugging - check them first when issues occur
- `m3u-proxy:status` gives you a quick overview of everything
- External mode is useful for scaling and independent management
- Embedded mode is simpler and more secure (localhost only)
