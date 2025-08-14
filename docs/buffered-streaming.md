# Buffered Streaming Documentation

This document describes the comprehensive analytics and dashboard system for the xTeVe-like shared streaming functionality.

## Overview

The enhanced stats system provides real-time monitoring, analytics, and management capabilities for shared streaming operations. It includes:

- **Real-time Dashboard**: Live monitoring of streams, clients, and system health
- **Performance Analytics**: Historical data analysis and trend visualization
- **Alert System**: Proactive monitoring with automated alerts
- **API Endpoints**: RESTful API for external integrations
- **Admin Interface**: Filament-based management interface

## Dashboard Components

### Shared Stream Monitor (`/admin/shared-stream-monitor`)

Real-time monitoring interface with:

- **Live Stream List**: Active streams with client details
- **System Statistics**: Resource usage and health metrics
- **Stream Management**: Start, stop, restart capabilities
- **Auto-refresh**: Configurable real-time updates
- **Client Tracking**: Individual client connections and metrics

## Widgets

### Core Statistics Widgets

1. **SharedStreamStatsWidget**: Key performance indicators
2. **SystemHealthWidget**: System resource monitoring

### Analytics Widgets

3. **SharedStreamPerformanceChart**: 24-hour performance trends

## API Endpoints

### Monitoring API (`/api/monitor/`)

- `GET /stats` - Core streaming statistics
- `GET /realtime` - Real-time metrics for widgets
- `GET /dashboard` - Comprehensive dashboard data
- `GET /performance` - Historical performance data
- `GET /alerts` - System alerts and warnings
- `GET /health` - System health check
- `GET /streams` - Active streams list
- `POST /streams/test` - Test stream creation
- `DELETE /streams/{id}` - Stop specific stream
- `POST /cleanup` - Cleanup inactive streams

### Example API Usage

```bash
# Get real-time metrics
curl http://localhost:36400/api/monitor/realtime

# Get system health
curl http://localhost:36400/api/monitor/health

# Get performance history for last 24 hours
curl http://localhost:36400/api/monitor/performance?period=24h&metric=bandwidth
```

## Database Schema

### Shared Streaming Tables

1. **shared_streams**: Stream configuration and status
2. **shared_stream_clients**: Client connections and metrics
3. **shared_stream_stats**: Historical performance data

### Key Relationships

- Stream → Many Clients (One-to-Many)
- Stream → Many Stats Records (One-to-Many)
- Automatic cleanup of old statistics (configurable retention)

## Configuration

### Proxy Configuration (`config/proxy.php`)

```php
'shared_streaming' => [
    'enabled' => true,
    'buffer_path' => '/tmp/m3u-proxy-buffers',
    'max_concurrent_streams' => 100,
    'client_timeout' => 300,
    'monitoring' => [
        'enabled' => true,
        'stream_timeout' => 300,
        'health_check_interval' => 60,
        'bandwidth_threshold' => 50000, // kbps
        'cleanup_interval' => 300,
        'stats_retention_days' => 7,
    ],
],
```

## Background Jobs

### Automated Maintenance

1. **SharedStreamCleanup**: Removes inactive streams and clients
2. **StreamMonitorUpdate**: Updates stream health and statistics
3. **StreamBufferManager**: Manages stream buffer

### Scheduling

Jobs and commands are scheduled in `routes/console.php`:

```php
Schedule::job(new StreamMonitorUpdate)->everyMinute();
Schedule::job(new SharedStreamCleanup)->everyFiveMinutes();
Schedule::job(new StreamBufferManager())->everyFiveMinutes();
Schedule::command('app:shared-streams cleanup')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('app:shared-streams sync')->everyTenMinutes()->withoutOverlapping();
```

## Alert System

### Alert Types

- **Error**: Critical issues requiring immediate attention
- **Warning**: Issues that may impact performance
- **Info**: Informational notifications

### Monitored Conditions

- Unhealthy streams
- High bandwidth usage
- Redis connectivity
- System resource usage
- Idle streams
- Frequent stream restarts

## Performance Optimization

### Real-time Updates

- Widgets use polling intervals (5-60 seconds)
- Redis caching for frequently accessed data
- Database indexing for performance queries
- Efficient query optimization

### Resource Management

- Automatic cleanup of old statistics
- Buffer file management
- Memory usage monitoring
- Disk space monitoring

## Security Considerations

- API endpoints protected by authentication
- Rate limiting on monitoring endpoints
- Secure stream key generation
- Client IP tracking and validation

## Testing

### Test Suite Coverage

- Stream creation and management
- Client connection handling
- Statistics recording
- Alert generation
- API endpoint functionality

### Running Tests

```bash
php artisan test --filter SharedStreamingTest
```

## Integration Examples

### External Monitoring

```bash
# Prometheus metrics endpoint
curl http://localhost:36400/api/monitor/realtime | jq '.data'

# Grafana dashboard integration
curl http://localhost:36400/api/monitor/performance?period=1h
```

## Troubleshooting

### Common Issues

1. **High Memory Usage**: Check buffer sizes and cleanup jobs
2. **Redis Connection Issues**: Verify Redis server status
3. **Slow Dashboard**: Check database query performance
4. **Missing Statistics**: Verify background jobs are running

### Debug Commands

```bash
# Check active streams
php artisan app:shared-streams  --list

# View system health
php artisan app:shared-streams  --stats

# Cleanup manually
php artisan app:shared-streams  --cleanup
```

## Future Enhancements

### Planned Features

- Advanced analytics and reporting
- Stream quality monitoring
- Custom alert rules
- Export/import functionality
- Advanced caching strategies

### API Versioning

Future API versions will maintain backward compatibility while adding enhanced features.
