# M3U Proxy Deployment Examples

This directory contains ready-to-use configuration files for deploying m3u-editor with external m3u-proxy.

## 📁 Files

- **`docker-compose.proxy.yml`** - Complete Docker Compose configuration with m3u-editor, m3u-proxy, and Redis
- **`.env.proxy.example`** - Environment variables template with secure defaults
- **`m3u-proxy-integration.md`** - Complete integration guide and troubleshooting

## 🚀 Quick Start

### 1. Download Configuration Files

```bash
# Create project directory
mkdir m3u-editor && cd m3u-editor

# Download docker-compose and env template
curl -O https://raw.githubusercontent.com/sparkison/m3u-editor/main/docker-compose.proxy.yml
curl -o .env https://raw.githubusercontent.com/sparkison/m3u-editor/main/.env.proxy.example
```

### 2. Generate Secure Tokens

```bash
# Generate M3U Proxy token
echo "M3U_PROXY_TOKEN=$(openssl rand -hex 32)" >> .env

# Generate database password
echo "PG_PASSWORD=$(openssl rand -base64 32)" >> .env

# Set your application URL
echo "APP_URL=http://localhost" >> .env
```

Or manually edit `.env` and set:
- `M3U_PROXY_TOKEN` - Secure authentication token
- `PG_PASSWORD` - PostgreSQL password
- `APP_URL` - Your domain or IP

### 3. Deploy

```bash
# Start all services
docker-compose -f docker-compose.external-proxy.yml up -d

# Wait for services to start (about 30 seconds)
docker-compose -f docker-compose.external-proxy.yml ps

# Verify m3u-proxy is healthy
docker exec -it m3u-editor php artisan m3u-proxy:status
```

### 4. Access Application

Open your browser to the URL you set in `APP_URL` (default: http://localhost:36400)

## 📊 What Gets Deployed

| Service | Container | Port | Purpose |
|---------|-----------|------|---------|
| **m3u-editor** | m3u-editor | 36400 | Main Laravel application |
| **m3u-proxy** | m3u-proxy | 8085* | Streaming proxy with transcoding |
| **Redis** | m3u-proxy-redis | 6379* | Stream pooling and caching |
| **PostgreSQL** | (embedded) | 5432* | Database (inside m3u-editor) |

\* Internal ports only (not exposed externally by default)

## 🔧 Management

### View Logs
```bash
# All services
docker-compose -f docker-compose.external-proxy.yml logs -f

# Specific service
docker logs m3u-proxy -f
docker logs m3u-editor -f
docker logs m3u-proxy-redis -f
```

### Restart Services
```bash
# Restart all
docker-compose -f docker-compose.external-proxy.yml restart

# Restart specific service
docker restart m3u-proxy
```

### Stop Services
```bash
# Stop all (preserves data)
docker-compose -f docker-compose.external-proxy.yml down

# Stop and remove volumes (WARNING: deletes all data)
docker-compose -f docker-compose.external-proxy.yml down -v
```

### Check Status
```bash
# Container status
docker-compose -f docker-compose.external-proxy.yml ps

# M3U Proxy health
docker exec -it m3u-editor php artisan m3u-proxy:status

# View stats
curl -H "X-API-Token: your-token" http://localhost:8085/stats
```

## 🛠️ Customization

### Change Application URL
Edit `.env`:
```bash
APP_URL=https://m3u.yourdomain.com
```
Then restart: `docker-compose restart`

### Expose PostgreSQL
Uncomment in `docker-compose.external-proxy.yml`:
```yaml
ports:
  - "5432:5432"
```

### Adjust Resource Limits
Uncomment and modify `deploy.resources` sections in `docker-compose.external-proxy.yml`

### Change Ports
Edit the `ports` section in `docker-compose.external-proxy.yml`:
```yaml
ports:
  - "8080:36400"  # Access via port 8080 instead
```

## 📖 Full Documentation

See [`m3u-proxy-integration.md`](./m3u-proxy-integration.md) for:
- Detailed configuration options
- Troubleshooting guide
- Performance tuning
- Security recommendations
- Migration guides (embedded ↔ external)

## 🆘 Common Issues

### Services Won't Start
```bash
# Check logs
docker-compose -f docker-compose.external-proxy.yml logs

# Rebuild and restart
docker-compose -f docker-compose.external-proxy.yml down
docker-compose -f docker-compose.external-proxy.yml up -d --build
```

### Token Authentication Fails
Ensure tokens match in `.env`:
```bash
# Should be the same value
grep M3U_PROXY_TOKEN .env
```

### Redis Connection Issues
```bash
# Test Redis
docker exec -it m3u-proxy redis-cli -h redis ping

# Check Redis logs
docker logs m3u-proxy-redis
```

### Can't Access Application
```bash
# Check if port is in use
lsof -i :36400

# Try different port
# Edit docker-compose.external-proxy.yml ports section
```

## 🔗 Links

- **M3U Editor**: https://github.com/sparkison/m3u-editor
- **M3U Proxy**: https://github.com/sparkison/m3u-proxy
- **Docker Compose Docs**: https://docs.docker.com/compose/

## 📝 Notes

- **Data Persistence**: Volumes `pgdata` and `redis-data` preserve your data across container restarts
- **Security**: Always use strong tokens in production (see `.env` file for generation commands)
- **Scaling**: Can run multiple m3u-proxy instances behind a load balancer
- **Monitoring**: Use `docker stats` to monitor resource usage
