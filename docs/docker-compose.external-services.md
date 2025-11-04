# Full External Services Setup

This is a comprehensive production setup with **ALL services externalized** into separate containers. This configuration demonstrates how to run M3U Editor with all its internal services disabled, using dedicated containers for each component.

## Architecture Overview

```
┌─────────────────────────────────────────────────────────┐
│                        Nginx                            │
│                  (Reverse Proxy)                        │
│                    Port: 80/443                         │
└──────────────┬────────────────────┬─────────────────────┘
               │                    │
      ┌────────▼──────────┐   ┌─────▼──────────┐
      │   M3U Editor      │   │   M3U Proxy    │
      │   (PHP-FPM)       │   │   (Streaming)  │
      │   Port: 9000      │   │   Port: 38085  │
      └────────┬──────────┘   └─────┬──────────┘
               │                    │
      ┌────────▼────────────────────▼──────────┐
      │                                        │
┌─────▼──────┐         ┌──────────────┐        │
│ PostgreSQL │         │    Redis     │        │
│ Port: 5432 │         │  Port: 6379  │        │
└────────────┘         └──────────────┘        │
                                               │
                                               └─ Shared Network
```

## Services Included

1. **PostgreSQL** (`postgres`) - External database container
   - PostgreSQL 17 Alpine
   - Persistent data volume
   - Health checks configured

2. **Redis** (`redis`) - External cache and stream pooling
   - Redis Alpine 3.22
   - Configured for optimal caching
   - Used by both m3u-editor and m3u-proxy

3. **M3U Proxy** (`m3u-proxy`) - External streaming proxy
   - Handles all streaming requests
   - Hardware acceleration support
   - Redis-based connection pooling

4. **M3U Editor** (`m3u-editor`) - Main application
   - **ALL internal services DISABLED**
   - PHP-FPM only (no embedded nginx)
   - Connects to external postgres, redis, and m3u-proxy

5. **Nginx** (`nginx`) - External reverse proxy
   - Routes traffic to m3u-editor and m3u-proxy
   - Handles SSL termination (when configured)
   - Optimized for streaming

## Disabled Internal Services

This configuration explicitly disables all internal services in m3u-editor:

- ✅ `NGINX_ENABLED=false` - Using external Nginx container
- ✅ `ENABLE_POSTGRES=false` - Using external PostgreSQL container
- ✅ `REDIS_ENABLED=false` - Using external Redis container
- ✅ `M3U_PROXY_ENABLED=false` - Using external m3u-proxy container

## Quick Start

### 1. Prerequisites

- Docker and Docker Compose installed
- At least 2GB RAM available
- Ports 8080 (or your chosen port) available
- The `public` directory must exist in your project root (contains static assets)

### 2. Setup Environment

```bash
# Copy the example environment file
cp .env.external-all.example .env

# Generate secure credentials
echo "PG_PASSWORD=$(openssl rand -base64 32)" >> .env
echo "M3U_PROXY_TOKEN=$(openssl rand -hex 32)" >> .env

# Edit .env and update APP_URL with your port
# Example: APP_URL=http://localhost:8080
nano .env
```

**Important**: Make sure `APP_URL` and `M3U_PROXY_PUBLIC_URL` include the correct port!
- `APP_URL=http://localhost`
- `APP_PORT=8080`
- `M3U_PROXY_PUBLIC_URL=http://localhost:8080/m3u-proxy`

### 3. Start Services

```bash
# Start all services
docker-compose -f docker-compose.external-all.yml up -d

# View logs (helpful to see startup progress)
docker-compose -f docker-compose.external-all.yml logs -f

# Check service health (wait for all services to show "healthy")
docker-compose -f docker-compose.external-all.yml ps
```

**Note**: Initial startup may take 90-120 seconds for:
- Database migrations to complete
- Services to become healthy
- PHP-FPM to initialize

### 4. Access Application

- **Web Interface**: http://localhost:8080 (or your configured `NGINX_PORT`)
- **Default Access**: Configure on first access

### 5. Troubleshooting

If services fail to start or you can't access the application:

```bash
# Run the automated troubleshooting script
./troubleshoot-external-services.sh
```

This script will check:
- Service health status
- Network connectivity between services
- Application endpoint accessibility
- Common configuration issues

### 6. Stop Services

```bash
# Stop all services
docker-compose -f docker-compose.external-all.yml down

# Stop and remove volumes (CAUTION: deletes all data)
docker-compose -f docker-compose.external-all.yml down -v
```

## Configuration

### Environment Variables

Key environment variables in `.env`:

```bash
# Application
APP_URL=http://localhost     # Your domain or IP
APP_PORT=8080                # External HTTP port (used for routing and URL creation)
NGINX_PORT=8080              # External HTTP port

# Database
PG_DATABASE=m3ue
PG_USER=m3ue
PG_PASSWORD=<generate-secure-password>

# Redis
REDIS_PORT=6379

# M3U Proxy
M3U_PROXY_PORT=38085
M3U_PROXY_PUBLIC_URL=http://localhost/m3u-proxy
M3U_PROXY_TOKEN=<generate-secure-token>
```

### Nginx Configuration

The `nginx.conf` file includes:

- **PHP-FPM routing** - Proxies PHP requests to m3u-editor:9000
- **M3U Proxy routing** - Routes `/m3u-proxy/*` to m3u-proxy:38085
- **Static file serving** - Optimized caching for assets
- **Streaming optimization** - Disabled buffering for live streams
- **Security headers** - Basic security hardening

To customize:

```bash
nano nginx.conf
# Restart nginx after changes
docker-compose -f docker-compose.external-all.yml restart nginx
```

## SSL/HTTPS Configuration

To enable HTTPS:

1. Obtain SSL certificates (Let's Encrypt, commercial CA, etc.)

2. Uncomment HTTPS server block in `nginx.conf`

3. Mount certificates in `docker-compose.external-all.yml`:

```yaml
nginx:
  volumes:
    - ./nginx.conf:/etc/nginx/nginx.conf:ro
    - ./ssl:/etc/nginx/ssl:ro  # Add this line
  ports:
    - "80:80"
    - "443:443"  # Uncomment this
```

4. Update environment variables:

```bash
APP_URL=https://your-domain.com
M3U_PROXY_PUBLIC_URL=https://your-domain.com/m3u-proxy
```

## Monitoring and Logs

### View Logs

```bash
# All services
docker-compose -f docker-compose.external-all.yml logs -f

# Specific service
docker-compose -f docker-compose.external-all.yml logs -f m3u-editor
docker-compose -f docker-compose.external-all.yml logs -f nginx
docker-compose -f docker-compose.external-all.yml logs -f postgres
```

### Health Checks

```bash
# Check all services
docker-compose -f docker-compose.external-all.yml ps

# All services should show (healthy) status
```

### Database Access

```bash
# Connect to PostgreSQL
docker exec -it m3u-postgres psql -U m3ue -d m3ue

# Backup database
docker exec m3u-postgres pg_dump -U m3ue m3ue > backup.sql

# Restore database
docker exec -i m3u-postgres psql -U m3ue -d m3ue < backup.sql
```

### Redis Access

```bash
# Connect to Redis CLI
docker exec -it m3u-redis redis-cli

# Monitor Redis commands
docker exec -it m3u-redis redis-cli MONITOR

# Check Redis memory usage
docker exec -it m3u-redis redis-cli INFO memory
```

## Resource Limits

The compose file includes optional resource limits for each service:

- **PostgreSQL**: 2 CPU cores, 1GB RAM (limits)
- **Redis**: 1 CPU core, 512MB RAM (limits)
- **M3U Proxy**: 2 CPU cores, 2GB RAM (limits)
- **M3U Editor**: 2 CPU cores, 2GB RAM (limits)
- **Nginx**: 1 CPU core, 256MB RAM (limits)

These are configured but can be adjusted based on your needs.

## Networking

All services communicate on the `m3u-network` bridge network:

- Services use container names as hostnames (e.g., `postgres`, `redis`, `m3u-proxy`)
- Only Nginx exposes ports externally
- Internal service ports are not exposed for security

## Volumes

Persistent data is stored in Docker volumes:

- `postgres-data` - PostgreSQL database
- `redis-data` - Redis cache
- `./data` - M3U Editor configuration (bind mount)

### Backup Volumes

```bash
# Backup PostgreSQL data
docker run --rm -v m3u-editor_postgres-data:/data -v $(pwd):/backup alpine tar czf /backup/postgres-backup.tar.gz -C /data .

# Backup Redis data
docker run --rm -v m3u-editor_redis-data:/data -v $(pwd):/backup alpine tar czf /backup/redis-backup.tar.gz -C /data .
```

## Troubleshooting

### M3U Editor Container is Unhealthy

**Symptoms**: 
- `dependency failed to start: container m3u-editor is unhealthy`
- Other services can't start due to m3u-editor dependency

**Common Causes**:

1. **Database migrations still running**: Wait 90-120 seconds for initial startup
   ```bash
   # Watch the logs
   docker-compose -f docker-compose.external-all.yml logs -f m3u-editor
   ```

2. **PHP-FPM not responding**: Check if PHP-FPM is listening on port 9000
   ```bash
   # Test from nginx container
   docker exec m3u-nginx nc -zv m3u-editor 9000
   ```

3. **Database connection failed**: Verify postgres is healthy and credentials are correct
   ```bash
   docker-compose -f docker-compose.external-all.yml ps postgres
   docker exec m3u-editor env | grep DB_
   ```

4. **Missing required files**: Ensure `public` directory exists
   ```bash
   ls -la public/
   ```

**Solution**: Increase the health check `start_period` if startup is slow, or check logs for specific errors.

### Services Won't Start

1. Check if ports are available:
```bash
lsof -i :8080  # Check if your configured port is in use
```

2. Check service logs:
```bash
docker-compose -f docker-compose.external-all.yml logs
```

3. Verify environment variables:
```bash
docker-compose -f docker-compose.external-all.yml config
```

4. Ensure required files exist:
```bash
# Check for required files
ls -la nginx.conf public/ .env
```

### Database Connection Issues

1. Verify PostgreSQL is healthy:
```bash
docker-compose -f docker-compose.external-all.yml ps postgres
```

2. Test connection from m3u-editor:
```bash
docker exec m3u-editor nc -zv postgres 5432
```

3. Check database credentials in `.env`:
```bash
grep "^PG_\|^DB_" .env
```

4. View PostgreSQL logs:
```bash
docker-compose -f docker-compose.external-all.yml logs postgres
```

### Nginx 502 Bad Gateway

1. Verify m3u-editor is healthy:
```bash
docker-compose -f docker-compose.external-all.yml ps m3u-editor
```

2. Test PHP-FPM connectivity from nginx:
```bash
docker exec m3u-nginx nc -zv m3u-editor 9000
```

3. Check m3u-editor logs:
```bash
docker-compose -f docker-compose.external-all.yml logs m3u-editor
```

4. Verify public directory is mounted:
```bash
docker exec m3u-nginx ls -la /var/www/html/public
```

### Static Files Not Loading (CSS/JS/Images)

**Symptoms**: Application loads but looks broken, no styling

**Cause**: The `public` directory is not properly mounted to nginx

**Solution**:
1. Verify the public directory exists:
```bash
ls -la public/
```

2. Check nginx volume mounts:
```bash
docker inspect m3u-nginx | grep -A 10 Mounts
```

3. Restart nginx:
```bash
docker-compose -f docker-compose.external-all.yml restart nginx
```

### Port Configuration Issues

**Symptoms**: Can't access application, wrong URLs generated

**Cause**: Mismatch between `APP_URL`, `APP_PORT`, `NGINX_PORT`, and `M3U_PROXY_PUBLIC_URL`

**Solution**: Ensure consistency in `.env`:
```bash
# If using port 8080
APP_URL=http://localhost:8080
APP_PORT=8080
NGINX_PORT=8080
M3U_PROXY_PUBLIC_URL=http://localhost:8080/m3u-proxy
```

After changing, restart services:
```bash
docker-compose -f docker-compose.external-all.yml restart m3u-editor nginx
```

### Streaming Issues

1. Verify m3u-proxy is healthy:
```bash
docker-compose -f docker-compose.external-all.yml ps m3u-proxy
```

2. Check proxy logs:
```bash
docker-compose -f docker-compose.external-all.yml logs m3u-proxy
```

3. Test m3u-proxy health endpoint:
```bash
# Get token from .env
M3U_TOKEN=$(grep M3U_PROXY_TOKEN .env | cut -d= -f2)
curl "http://localhost:8080/m3u-proxy/health?api_token=$M3U_TOKEN"
```

4. Verify `M3U_PROXY_PUBLIC_URL` matches your setup and is accessible from client devices

## Performance Tuning

### Database

Increase PostgreSQL memory in `docker-compose.external-all.yml`:

```yaml
postgres:
  command: postgres -c shared_buffers=256MB -c effective_cache_size=1GB
  deploy:
    resources:
      limits:
        memory: 2G  # Increase from 1G
```

### Redis

Increase Redis memory limit:

```yaml
redis:
  command: redis-server --maxmemory 512mb  # Increase from 256mb
```

### Nginx

Increase worker connections in `nginx.conf`:

```nginx
events {
    worker_connections 2048;  # Increase from 1024
}
```

## Security Considerations

1. **Change default credentials** - Generate secure passwords for `PG_PASSWORD` and `M3U_PROXY_TOKEN`
2. **Use HTTPS** - Configure SSL certificates for production
3. **Firewall rules** - Only expose necessary ports (80/443)
4. **Keep updated** - Regularly update Docker images
5. **Monitor access** - Review nginx and application logs
6. **Backup regularly** - Implement automated backup strategy

## Comparison with Other Setups

| Feature | docker-compose.aio.yml | docker-compose.proxy.yml | docker-compose.proxy-vpn.yml | docker-compose.external-all.yml |
|---------|------------------------|--------------------------|------------------------------|--------------------------------|
| Embedded Postgres | ✅ Yes | ✅ Yes | ✅ Yes | ❌ No (external) |
| Embedded Nginx | ✅ Yes | ✅ Yes | ✅ Yes | ❌ No (external) |
| Embedded Redis | ✅ Yes | ❌ No | ❌ No | ❌ No (external) |
| Embedded M3U Proxy | ✅ Yes | ❌ No | ❌ No | ❌ No (external) |
| External M3U Proxy | ❌ No | ✅ Yes | ✅ Yes | ✅ Yes |
| External Redis | ❌ No | ✅ Yes | ✅ Yes | ✅ Yes |
| External Postgres | ❌ No | ❌ No | ❌ No | ✅ Yes |
| External Nginx | ❌ No | ❌ No | ❌ No | ✅ Yes |
| VPN Support | ❌ No | ❌ No | ✅ Yes | ❌ No |
| **Containers** | 1 | 3 | 4 | 5 |
| **Complexity** | Very Low | Low | Medium | High |
| **Flexibility** | Very Low | Low | Medium | Very High |
| **Best For** | Development/Personal Use | Production | Production w/ VPN | Enterprise/Custom |

