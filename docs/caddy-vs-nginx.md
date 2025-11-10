# Caddy vs Nginx - External Setup Comparison

This document explains the differences between the Nginx and Caddy configurations for the fully external m3u-editor setup.

## Quick Comparison

| Feature | Nginx (`docker-compose.external-all.yml`) | Caddy (`docker-compose.external-all-caddy.yml`) |
|---------|------------------------------------------|------------------------------------------------|
| **Configuration File** | `nginx.conf` | `Caddyfile` |
| **Container Port** | `NGINX_PORT` (default: 8080) | `CADDY_PORT` (default: 8080) |
| **HTTPS Setup** | Manual configuration required | Automatic with Let's Encrypt |
| **Config Syntax** | Complex, verbose | Simple, concise |
| **Volumes** | Config file only | Config file + data volumes for certs |

## Environment Variables

### Nginx Setup
```bash
APP_PORT=36400           # HTTP port
NGINX_SSL_PORT=443       # HTTPS port (if enabled)
```

### Caddy Setup
```bash
APP_PORT=36400           # HTTP port
CADDY_SSL_PORT=443       # HTTPS port (if enabled)
```

## Starting the Services

### Nginx Version
```bash
docker-compose -f docker-compose.external-all.yml up -d
```

### Caddy Version
```bash
docker-compose -f docker-compose.external-all-caddy.yml up -d
```

## HTTPS/SSL Configuration

### Nginx - Manual Configuration Required

1. Obtain SSL certificates (Let's Encrypt, commercial CA, etc.)

2. Uncomment HTTPS server block in `nginx.conf`

3. Mount certificates in docker-compose.yml:
```yaml
nginx:
  volumes:
    - ./nginx.conf:/etc/nginx/nginx.conf:ro
    - ./ssl:/etc/nginx/ssl:ro
  ports:
    - "${APP_PORT:-36400}:80"
    - "443:443"
```

### Caddy - Automatic HTTPS

Caddy can automatically obtain and renew SSL certificates from Let's Encrypt!

1. Update your Caddyfile domain:
```caddyfile
https://your-domain.com {
    # Caddy handles SSL automatically!
    # Just specify your email for Let's Encrypt
    tls your-email@example.com
    
    # Rest of your config...
}
```

2. Update environment variables:
```bash
APP_URL=https://your-domain.com
CADDY_SSL_PORT=443
```

3. Uncomment HTTPS port in docker-compose:
```yaml
caddy:
  ports:
    - "${APP_PORT:-36400}:80"
    - "443:443"  # Uncomment this
```

That's it! Caddy will automatically:
- Obtain SSL certificates from Let's Encrypt
- Renew certificates before expiration
- Redirect HTTP to HTTPS
- Handle OCSP stapling

## Configuration Files

### Nginx - nginx.conf
- Traditional nginx syntax
- Separate `server`, `location`, and `upstream` blocks
- Requires manual buffer and timeout configuration
- More verbose but very powerful

Example:
```nginx
location /health {
    access_log off;
    return 200 "healthy\n";
    add_header Content-Type text/plain;
}
```

### Caddy - Caddyfile
- Simple, declarative syntax
- Automatic HTTPS by default
- Sensible defaults for most use cases
- Less verbose, easier to read

Example:
```caddyfile
@health {
    path /health
}
handle @health {
    respond "healthy" 200
}
```

## When to Choose Each

### Choose Nginx if:
- ✅ You need maximum control and customization
- ✅ You're already familiar with nginx configuration
- ✅ You have complex routing requirements
- ✅ You want to manage SSL certificates manually
- ✅ You need specific nginx modules

### Choose Caddy if:
- ✅ You want automatic HTTPS with zero configuration
- ✅ You prefer simpler, more readable configurations
- ✅ You want Let's Encrypt certificates automatically managed
- ✅ You're setting up a new deployment
- ✅ You value ease of use over maximum control

## Health Checks

Both configurations include health checks:

```bash
# Test health endpoint
curl http://localhost:8080/health
# Should return: healthy
```

## Troubleshooting

### Nginx Issues
```bash
# Test nginx config
docker exec m3u-nginx nginx -t

# View nginx logs
docker-compose -f docker-compose.external-all.yml logs nginx

# Reload nginx config
docker exec m3u-nginx nginx -s reload
```

### Caddy Issues
```bash
# View Caddy config
docker exec m3u-caddy caddy fmt /etc/caddy/Caddyfile

# View Caddy logs
docker-compose -f docker-compose.external-all-caddy.yml logs caddy

# Reload Caddy config
docker exec m3u-caddy caddy reload --config /etc/caddy/Caddyfile
```

## Performance

Both Nginx and Caddy are excellent reverse proxies with minimal performance differences for most use cases:

- **Nginx**: Slightly lower memory footprint, battle-tested in production
- **Caddy**: Modern, efficient, with automatic optimizations

For m3u-editor's use case (PHP-FPM proxy + static file serving + streaming proxy), both perform excellently.

## Migration Between Versions

To switch from Nginx to Caddy (or vice versa):

```bash
# Stop current setup
docker-compose -f docker-compose.external-all.yml down

# Start with Caddy
docker-compose -f docker-compose.external-all-caddy.yml up -d

# Or back to Nginx
docker-compose -f docker-compose.external-all.yml up -d
```

The shared volumes (postgres-data, redis-data, app-public) are compatible between both setups.
