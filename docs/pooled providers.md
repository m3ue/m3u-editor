# Provider Profiles: User Guide

Pool multiple IPTV accounts from the same provider to multiply your available connections and support more simultaneous viewers.

**Important**: Provider Profiles is designed for **pooling multiple accounts from the same IPTV provider**. You can use different servers from that provider, but mixing completely different providers may cause issues.

## Table of Contents

1. [Requirements](#requirements)
2. [Overview](#overview)
3. [Who Should Use Provider Profiles?](#who-should-use-provider-profiles)
4. [How It Works](#how-it-works)
5. [Setting Up Provider Profiles](#setting-up-provider-profiles)
6. [Using Multiple Server URLs](#using-multiple-server-urls)
7. [Managing Your Profiles](#managing-your-profiles)
8. [Understanding Pool Status](#understanding-pool-status)
9. [Troubleshooting](#troubleshooting)
10. [Best Practices](#best-practices)
11. [FAQ](#faq)

---

## Requirements

Before enabling Provider Profiles, ensure:

- ✅ **Proxy mode is enabled** - Required for accurate connection tracking
- ✅ **M3U_PROXY_URL and M3U_PROXY_TOKEN are configured** - Provider Profiles requires the m3u-proxy service
- ✅ **Playlist is Xtream API type** - Profiles only work with Xtream playlists, not plain M3U files
- ✅ **Multiple accounts from the same provider** - You need additional IPTV accounts to pool

**Why Proxy is Required:**
- Tracks active connections in real-time via Redis
- Enables stream pooling (multiple viewers sharing one connection)
- Manages automatic profile selection based on capacity
- Handles credential transformation for different accounts

---

## Overview

**Provider Profiles** allow users to pool multiple Xtream API accounts within a single playlist. This solves the common problem of connection limits by distributing load across multiple accounts.

### Problem Being Solved

Many IPTV providers limit concurrent connections:
- "1 connection per account" or "5 connections per account"
- A household with multiple users/devices quickly hits the limit
- Each user connection consumes a provider connection

**Solution**: Pool multiple accounts to multiply available connections.

### Example Scenario

**Without Profiles:**
```
Account 1: max 2 connections
User watching TV: 1 connection
User on phone: 1 connection
User on tablet: BLOCKED ❌ (limit reached)
```

**With Profiles (2 accounts):**
```
Account 1: max 2 connections → User TV: 1
Account 2: max 2 connections → User phone: 1, User tablet: 1
Total: 4 users can watch simultaneously ✅
```

---

## Who Should Use Provider Profiles?

### You Need Profiles If:

- You're hitting connection limits ("max connections reached" errors)
- Multiple family members/devices watch simultaneously
- You have multiple accounts from the same provider
- You want redundancy with backup accounts/servers

### You DON'T Need Profiles If:

- You rarely have more than 1-2 concurrent viewers
- Your provider's connection limit is sufficient
- You only have one IPTV account
- You're satisfied with current performance

### Quick Example

**Family Scenario:**
```
Without Profiles (1 account, max 2 connections):
✓ Dad watching TV (1 connection)
✓ Mom watching tablet (1 connection)
✗ Kid watching phone - BLOCKED ❌

With Profiles (2 accounts pooled, max 4 connections):
✓ Dad watching TV (uses Account 1)
✓ Mom watching tablet (uses Account 1)
✓ Kid watching phone (uses Account 2) ✅
✓ Room for one more! (uses Account 2)
```

---

## How It Works

### The Basics

Provider Profiles pools multiple IPTV accounts into a single playlist:

1. **Primary Profile** - Automatically created from your playlist's Xtream credentials
2. **Additional Profiles** - Extra accounts you add manually
3. **Automatic Selection** - System picks an account with available capacity
4. **Priority Order** - Profiles tried in order (priority 0 first, then 1, 2, etc.)

### What Each Profile Includes

- **Username & Password** - Xtream account credentials
- **Provider URL** (optional) - Different server from same provider (leave blank to use playlist URL)
- **Max Streams** - Connection limit (auto-detected or manually set)
- **Priority** - Selection order (lower = tried first)
- **Enabled/Disabled** - Toggle to activate/deactivate

### Stream Pooling (Bonus Feature!)

When multiple people watch the **same channel** with transcoding enabled, they can share a single provider connection:

```
5 family members watching the same football game
= Only 1 provider connection used
= All 5 share the same transcoded stream
= Maximum efficiency!
```

This leaves more connections available for watching different channels.

---

## Setting Up Provider Profiles

### Step 1: Enable Provider Profiles

1. Edit your playlist
2. Scroll to "Provider Profiles" section
3. Toggle "Enable Provider Profiles" to **ON**
4. Click **Save**

**Note:** If proxy mode isn't already enabled on your playlist, it will automatically be enabled when you turn on Provider Profiles. This is required for accurate connection tracking.

Your primary account is automatically created as the first profile.

### Step 2: Add Additional Accounts

Click **Add Profile** and fill in:

**Profile Name** (optional)  
Friendly name like "Backup Account" or "US Server"

**Provider URL** (optional)  
- Leave blank = uses same URL as playlist
- Enter URL = uses different server from same provider

**Username** (required)  
Your IPTV account username

**Password** (required)  
Your IPTV account password

**Max Streams** (optional)  
Leave blank to auto-detect, or set a manual limit

**Priority** (default: auto-assigned)  
Lower numbers tried first (0, 1, 2...)

**Enabled** (default: ON)  
Toggle to activate this profile

### Step 3: Test the Profile

**Always test before saving!**

1. Click **Test** button next to the profile
2. System verifies credentials and detects max connections
3. Review the results
4. Click **Save** when ready

---

## Using Multiple Server URLs

### When to Use Different URLs

### Use Cases

**Important**: Provider Profiles is designed for **the same provider with multiple accounts**. The `url` field allows different servers/endpoints from that same provider, not different providers entirely.

1. **Regional Server Failover**: Provider has multiple regional servers
   - Primary: `iptv1.provider.com:80` (Europe)
   - Backup 1: `iptv2.provider.com:80` (Americas)
   - Backup 2: `iptv3.provider.com:80` (Asia)

2. **Different Server Endpoints**: Same provider, different entry points
   - Primary: `provider.com:80` (main)
   - Secondary: `backup.provider.com:80` (failover)
   - Tertiary: `provider-cdn.com:80` (CDN)

3. **Port Variations**: Same provider, different connection methods
   - Primary: `provider.com:80` (HTTP)
   - HTTP/2: `provider.com:8080` (Alt HTTP)
   - HTTPS: `provider.com:443` (Secure)

### Implementation Details

#### Building Xtream Config

Each profile builds its own config from its URL:

```php
public function getXtreamConfigAttribute(): ?array
{
    // Use profile's URL if set, otherwise use playlist's URL
    $url = $this->url ?? $baseConfig['url'] ?? $baseConfig['server'] ?? null;
    
    return [
        'url' => $url,  // ← This can differ per profile
        'username' => $this->username,
        'password' => $this->password,
        'output' => $baseConfig['output'] ?? 'ts',
    ];
}
```

#### URL Transformation

When streaming a channel, the profile transforms the channel URL:

```php
public function transformUrl(string $originalUrl): string
{
    // Original: http://example.com/live/user1/pass1/channel123
    // Profile URL: http://backup.com/live
    
    // Pattern match and replace:
    // Result: http://backup.com/live/user2/pass2/channel123
    
    $pattern = '#^' . preg_quote($sourceBaseUrl, '#') .
               '/(live|series|movie)/' . preg_quote($sourceUsername, '#') .
               '/' . preg_quote($sourcePassword, '#') .
               '/(.+)$#';
    
    if (preg_match($pattern, $originalUrl, $matches)) {
        $streamType = $matches[1];
        $streamIdAndExtension = $matches[2];
        
        // Use profile's URL (which may be different from source URL)
        return "{$profileUrl}/{$streamType}/{$profileUsername}/{$profilePassword}/{$streamIdAndExtension}";
    }
    
    return $originalUrl;
}
```

#### Admin UI Changes

The Filament form for adding profiles now includes:

```php
Repeater::make('additional_profiles')
    ->schema([
        TextInput::make('name'),
        TextInput::make('url')
            ->label('Provider URL')
            ->placeholder(/* playlist default URL */)
            ->helperText('Leave blank to use the same provider as the primary account.'),
        TextInput::make('username'),
        TextInput::make('password'),
        TextInput::make('max_streams'),
        TextInput::make('priority'),
        Toggle::make('enabled'),
    ])
```

**Key UI Features:**
- URL field is optional (defaults to playlist URL)
- Placeholder shows the primary account URL
- Helper text clarifies the behavior
- Test button validates the specific profile's credentials and URL

---

## Connection Management

### Redis-Based Connection Tracking

Connections are tracked in Redis for real-time accuracy (database queries are slow for high-frequency updates).

#### Redis Keys Structure

```
playlist_profile:{profile_id}:count
    → Current active connections for this profile
    
stream:{stream_id}:profile_id
    → Which profile is using this stream
    
playlist_profile:{profile_id}:streams
    → Set of stream IDs using this profile (for cleanup)
```

#### Key Lifecycle

```
Stream Created:
├─ increment playlist_profile:1:count                      [count=1]
├─ set stream:abc123:profile_id = 1
├─ sadd playlist_profile:1:streams abc123
└─ expire all keys with TTL=86400 (24 hours)

Stream Ended:
├─ decrement playlist_profile:1:count                      [count=0]
├─ del stream:abc123:profile_id
└─ srem playlist_profile:1:streams abc123
```

#### Why Redis?

1. **Speed**: O(1) operations, no database query overhead
2. **Real-time**: Immediate reflection of connection changes
3. **Auto-cleanup**: TTL prevents stale keys from accumulating
4. **Distributed**: Shared state across multiple app instances

### Connection Increment/Decrement

```php
// When a new stream starts using a profile
public static function incrementConnections(PlaylistProfile $profile, string $streamId): void
{
    Redis::pipeline(function ($pipe) use ($countKey, $streamKey, $streamsKey) {
        $pipe->incr($countKey);
        $pipe->expire($countKey, 86400);
        $pipe->set($streamKey, $profile->id);
        $pipe->expire($streamKey, 86400);
        $pipe->sadd($streamsKey, $streamId);
        $pipe->expire($streamsKey, 86400);
    });
}

// When a stream ends
public static function decrementConnections(PlaylistProfile $profile, string $streamId): void
{
    $currentCount = (int) Redis::get($countKey);
    
    if ($currentCount > 0) {
        Redis::pipeline(function ($pipe) {
            $pipe->decr($countKey);
            $pipe->del($streamKey);
            $pipe->srem($streamsKey, $streamId);
        });
    }
}
```

### Pool Status Reporting

Getting current pool status across all profiles:

```php
public static function getPoolStatus(Playlist $playlist): array
{
    $profiles = [];
    $totalCapacity = 0;
    $totalActive = 0;
    
    foreach ($playlist->profiles()->get() as $profile) {
        $activeCount = static::getConnectionCount($profile);      // From Redis
        $maxStreams = $profile->effective_max_streams;
        
        $profiles[] = [
            'id' => $profile->id,
            'name' => $profile->name ?? "Profile #{$profile->id}",
            'username' => $profile->username,
            'enabled' => $profile->enabled,
            'priority' => $profile->priority,
            'is_primary' => $profile->is_primary,
            'max_streams' => $maxStreams,
            'active_connections' => $activeCount,
            'available' => max(0, $maxStreams - $activeCount),
        ];
        
        if ($profile->enabled) {
            $totalCapacity += $maxStreams;
            $totalActive += $activeCount;
        }
    }
    
    return [
        'enabled' => true,
        'profiles' => $profiles,
        'total_capacity' => $totalCapacity,
        'total_active' => $totalActive,
        'available' => max(0, $totalCapacity - $totalActive),
    ];
}
```

---

## Admin UI & Management

### Filament Integration

**Use different URLs when you have:**

1. **Regional Servers from Same Provider**
   - US Server: `us.provider.com`
   - EU Server: `eu.provider.com`
   - Asia Server: `asia.provider.com`

2. **Backup/Failover Servers**
   - Primary: `iptv1.provider.com`
   - Backup: `iptv2.provider.com`

3. **Different Ports**
   - Standard: `provider.com:8080`
   - Alternate: `provider.com:80`

### When NOT to Use Different URLs

❌ **Don't mix completely different providers**  
Different providers have different URL structures that won't work together.

✅ **Do use same provider's multiple servers**  
Regional mirrors, backup servers, and CDNs from the same provider work perfectly.

---

## Managing Your Profiles

### Testing Profiles

**Always test after adding!**

1. Click **Test** button
2. System checks credentials and connectivity
3. Auto-detects max connections
4. Updates Max Streams field
5. Shows success/failure notification

### Adjusting Priorities

Control which profiles are tried first:

- **0** = Highest priority (usually primary)
- **1** = Second choice (first backup)
- **2** = Third choice (second backup)

Lower numbers = tried first

### Setting Connection Limits

Override auto-detected limits:

- Leave blank = use provider's limit
- Set number = enforce your own limit

**Why manually limit?**
- Reserve connections for other apps
- Prevent overloading a profile
- Test with reduced capacity

### Enabling/Disabling Profiles

Toggle profiles on/off without deleting:

- Disabled = skipped during selection
- Useful for troubleshooting
- Rotate accounts easily

---

## Understanding Pool Status

The Pool Status widget shows real-time connection usage:

```
Total: 5/15 active | 10 available

✓ ⭐ Primary: 3/5 streams
✓ Backup: 2/5 streams  
✗ Account3: 0/5 streams (Disabled)
```

**Reading the Display:**

- ✓ = Profile enabled
- ✗ = Profile disabled
- ⭐ = Primary profile
- **3/5** = 3 active of 5 maximum
- **Total: 5/15** = 5 in use, 15 capacity, 10 availableTrack in Redis
ProfileService::incrementConnections($selectedProfile, $streamId);

// Return URL to client
return buildTranscodeStreamUrl($streamId);
```

### 5. Stream Ends

```
User stops watching
    ↓
m3u-proxy detects no clients
    ↓
m3u-proxy notifies app (or app polls)
    ↓
ProfileService::decrementConnectionsByStreamId($streamId)
    ├─ Look up profile via Redis
    ├─ Decrement connection count
    └─ Clean up Redis keys
    ↓
Next stream request can use that capacity
```

---

## Configuration & Settings

### Environment Variables

```env
# M3U Proxy Configuration (required for profiles)
M3U_PROXY_URL=http://localhost:8085
M3U-secret-token

# Pl

### Database Migrations

```
# Create playlist_profiles table
2025_12_17_000001_create_playlist_profiles_table.php

# Add URL field (NEW in January 2026)
2026_01_03_181027_add_url_to_playlist_profiles_table.php
```

---

## Best Practices

### For End Users

1. able profiles only if needed**: Adds complexity and requires proxy
2. **Test all profiles after adding**: Ensure credentials are correct
3. **Set reasonable priorities**: Primary = 0, backups = 1, 2, etc.
4. **Disable unused profiles**: Don't leave disabled profiles lying around
5. **Monitor pool status**: Check the pool status widget regularly

### For Developers

1. **Cache provider info**: Don't call API on every request
2. **Use Redis for connections**: Avoid database for high-frequency updates
3. **Handle profile URL transformation carefully**: Regex patterns must be robust
4. **Test failover scenarios**: Ensure graceful degradation when profiles fail
5. **Log profile selection**: Help debug "no capacity" issues

### For Documentation

1. **Document the URL field**: Many users won't understand it's optional
2. **Explain the pool status**: What do the numbers mean?
3. **Provide troubleshooting**: Common issues and solutions
4. **Show configuration examples**: Different provider setups

---

## Future Enhancements

Potential improvements for future versions:

1. **Max Clients Per Pool**: Limit how many users can share one stream
2. **Quality Tiers**: Different pools for SD/HD/4K quality
3. **Load Balancing**: Distribute clients across multiple transcoded streams
4. **Persistent Streams**: Keep popular channels always transcoding
5. **Predictive Pooling**: Pre-start streams for likely-to-be-watched channels
6. **Profile Health Check**: Monitor provider connectivity automatically
7. **Automatic Failover**: Switch to backup profile if primary fails
8. **Usage Analytics**: Track profile usage and efficiency metrics

---

## Frequently Asked Questions

### Q: Can I use accounts from completely different IPTV providers?

**AWhile technically possible by setting different URLs, it's **not recommended**. Provider Profiles is designed for the same provider with multiple accounts. Different providers may have:
- Incompatible URL structures
- Different API implementations  
- Varying authentication methods
- Different channel naming/IDs

For different providers, create separate playlists instead.

### Q: Why can't I just set any URL I want?

**A:** The URL transformation system uses pattern matching to replace credentials and server URLs. It expects a consistent Xtream API URL format:
```
http://provider.com/live/username/password/stream123.ts
```

If Provider B uses a different structure, the transformation will fail:
```
http://different.com/stream/username/password/123.ts  ← Won't match pattern
```Proxy Not Enabled Error

**Symptoms:**  
"Provider Profiles require proxy to be enabled"

**Fix:**
1. Edit your playlist
2. Enable "Enable Proxy" toggle
3. Ensure M3U_PROXY_URL and M3U_PROXY_TOKEN are configured
4. Save playlist
5. Try enabling profiles againReal-World Examples

**Family with 3 IPTV Accounts:**
```
Provider: MyIPTV.com
Account 1: user1 @ MyIPTV.com (5 connections)
Account 2: user2 @ MyIPTV.com (5 connections)  
Account 3: user3 @ MyIPTV.com (5 connections)

Total Capacity: 15 simultaneous streams!
```

**Provider with Regional Servers:**
```
Provider: GlobalIPTV
Account 1: user1 @ us.global-iptv.com (primary)
Same Account: user1 @ eu.global-iptv.com (backup URL)
Same Account: user1 @ asia.global-iptv.com (backup URL)

Redundancy without extra cost!
```AQQuick Reference

### Common Configurations

**Basic Setup (Same Provider, Same Server):**
- Primary: username=user1, URL=(blank)
- Backup: username=user2, URL=(blank)

**Advanced Setup (Same Provider, Multiple Servers):**
- Primary: username=user1, URL=us.provider.com
- Backup 1: username=user2, URL=eu.provider.com
- Backup 2: username=user3, URL=asia.provider.com

### Capacity Planning

- **Light use** (1-3 people): 2 accounts = 4-6 connections
- **Medium use** (3-5 people): 3 accounts = 9-15 connections
- **Heavy use** (5+ people): 4-5 accounts = 20-25 connections

---

## Related Documentation

- [Stream Pooling Technical Details](stream-pooling.md)
- [M3U Proxy Integration Guide](m3u-proxy-integration.md
