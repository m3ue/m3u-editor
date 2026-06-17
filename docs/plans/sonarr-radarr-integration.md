# Sonarr/Radarr Content Request Integration Plan

## Overview

New media integration for searching and requesting content via Sonarr (TV) and Radarr (Movies) APIs. Users integrate their existing Sonarr/Radarr configurations, search for content, and request downloads — all within m3u-editor.

### Key Design Decisions

- **Multi-instance model** — per-playlist `ArrIntegration` records (not single global settings)
- **`playlist_id` required** — mirrors DVR's `DvrSetting` per-playlist pattern
- **Guest access** — guests authenticate via playlist UUID, only see integrations with `guest_enabled = true`
- **Follows existing `MediaServerIntegration` pattern** — model, resource, service factory
- **Does NOT use the `MediaServerIntegration` model** — Sonarr/Radarr are content acquisition tools, not media servers

---

## Phase 1: Database & Model

### Migration: `create_arr_integrations_table`

```php
Schema::create('arr_integrations', function (Blueprint $table) {
    $table->id();
    $table->string('name');                              // "Sonarr - 1080p", "Radarr - 4K"
    $table->string('type');                              // 'sonarr' | 'radarr'
    $table->string('url');                               // http://192.168.1.42:8989
    $table->text('api_key');                             // encrypted at app layer
    $table->integer('quality_profile_id')->nullable();   // cached from API
    $table->string('quality_profile_name')->nullable();  // display convenience
    $table->string('root_folder_path')->nullable();      // cached from API
    $table->boolean('enabled')->default(true);
    $table->boolean('guest_enabled')->default(false);
    $table->timestamp('last_test_at')->nullable();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->foreignId('playlist_id')->constrained()->cascadeOnDelete();  // REQUIRED
    $table->timestamps();
});
```

### Model: `app/Models/ArrIntegration.php`

```php
class ArrIntegration extends Model
{
    use HasFactory;

    protected $casts = [
        'enabled' => 'boolean',
        'guest_enabled' => 'boolean',
        'last_test_at' => 'datetime',
    ];

    protected $hidden = ['api_key'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function playlist(): BelongsTo { return $this->belongsTo(Playlist::class); }

    public function isSonarr(): bool { return $this->type === 'sonarr'; }
    public function isRadarr(): bool { return $this->type === 'radarr'; }

    public function getBaseUrlAttribute(): string
    {
        return rtrim($this->url, '/');
    }

    public function scopeEnabled($query) { return $query->where('enabled', true); }
    public function scopeGuestEnabled($query) { return $query->where('guest_enabled', true); }
}
```

---

## Phase 2: Service Layer

### Interface: `app/Interfaces/ArrIntegrationInterface.php`

```php
interface ArrIntegrationInterface
{
    public function testConnection(): array;              // { ok, version }
    public function fetchQualityProfiles(): array;        // [{ id, name }]
    public function fetchRootFolders(): array;            // [{ id, path, freeSpace }]
    public function search(string $term): array;          // lookup results
    public function add(array $payload): array;           // POST to Sonarr/Radarr
    public function checkExists(int $externalId): array;  // { exists, id }
    public function fetchReleases(int $contentId): array; // interactive search
    public function downloadRelease(array $payload): array;
    public function fetchQueue(): array;                  // download queue
}
```

### Factory: `app/Services/ArrService.php`

```php
class ArrService
{
    public static function make(ArrIntegration $integration): ArrIntegrationInterface
    {
        return match ($integration->type) {
            'sonarr' => new SonarrService($integration),
            'radarr' => new RadarrService($integration),
            default => throw new InvalidArgumentException("Unsupported arr type: {$integration->type}"),
        };
    }
}
```

### `app/Services/Arr/SonarrService.php`

- HTTP client using Laravel `Http` facade
- Auth: `X-Api-Key` header
- Base path: `/api/v3`

| Method | Endpoint | Notes |
|---|---|---|
| `testConnection()` | `GET /system/status` | Returns `{ ok, version }` |
| `fetchQualityProfiles()` | `GET /qualityprofile` | |
| `fetchRootFolders()` | `GET /rootfolder` | |
| `search($term)` | `GET /series/lookup?term={term}` | Returns array with tvdbId, title, year, overview, poster, seasons |
| `add($payload)` | `POST /series` | `{ tvdbId, title, titleSlug, qualityProfileId, rootFolderPath, monitored: true, addOptions: { searchForMissingEpisodes: true } }` |
| `checkExists($tvdbId)` | `GET /series/lookup?term=tvdb:{tvdbId}` | |
| `fetchReleases($seriesId)` | `GET /release?seriesId={seriesId}` | |
| `downloadRelease($payload)` | `POST /release` | `{ guid, indexerId, seriesId }` |
| `fetchQueue()` | `GET /queue?includeSeries=true` | Maps progress from `sizeleft/size` |

### `app/Services/Arr/RadarrService.php`

Same pattern, different endpoints and key names (`tmdbId` instead of `tvdbId`):

| Method | Endpoint | Notes |
|---|---|---|
| `testConnection()` | `GET /system/status` | |
| `fetchQualityProfiles()` | `GET /qualityprofile` | |
| `fetchRootFolders()` | `GET /rootfolder` | |
| `search($term)` | `GET /movie/lookup?term={term}` | Returns array with tmdbId, title, year, overview, poster, genres |
| `add($payload)` | `POST /movie` | `{ tmdbId, qualityProfileId, rootFolderPath, monitored: true, addOptions: { searchForMovie: true } }` |
| `checkExists($tmdbId)` | `GET /movie?tmdbId={tmdbId}` | |
| `fetchReleases($movieId)` | `GET /release?movieId={movieId}` | |
| `downloadRelease($payload)` | `POST /release` | `{ guid, indexerId, movieId }` |
| `fetchQueue()` | `GET /queue?includeMovie=true` | |

---

## Phase 3: Admin Filament Resource

### `ArrIntegrationResource` — `app/Filament/Resources/ArrIntegrationResource.php`

**Navigation:** Integrations group, after MediaServerIntegration
**Icon:** `heroicon-o-magnifying-glass-circle`
**Access:** `auth()->user()->canUseIntegrations()`

#### Form Schema

```
Section "Connection"
├── TextInput "name"
│   └── required, max:255
├── Select "type"
│   └── options: ['sonarr' => 'Sonarr', 'radarr' => 'Radarr'], required
├── Select "playlist_id"
│   └── relationship('playlist', 'name'), searchable, required
├── TextInput "url"
│   └── required, url, placeholder "http://192.168.1.42:8989"
├── TextInput "api_key"
│   └── required, password (masked)
├── Action "Test Connection & Discover"
│   └── Uses form state to create temp ArrIntegration
│       Calls ArrService::make($temp)->testConnection()
│       On success: calls fetchQualityProfiles() + fetchRootFolders()
│       Populates quality_profile_id and root_folder_path selects
│       Notifies success/failure
├── Select "quality_profile_id"
│   └── nullable, label 'Quality Profile'
│       Options populated by "Test Connection & Discover"
│       AfterStateHydrated and hidden until profiles loaded
├── Select "root_folder_path"
│   └── nullable, label 'Root Folder'
│       Options populated by "Test Connection & Discover"
│       AfterStateHydrated and hidden until folders loaded
├── Toggle "enabled"
│   └── default true
└── Toggle "guest_enabled"
    └── default false, helperText 'Allow guests on this playlist to request content'
```

#### Table Columns

| Column | Type | Notes |
|---|---|---|
| Name | TextColumn | searchable, sortable |
| Type | TextColumn | badge: Sonarr (blue) / Radarr (purple) |
| Playlist | TextColumn | `playlist.name`, sortable |
| URL | TextColumn | copyable, searchable |
| Quality Profile | TextColumn | `quality_profile_name` or fallback |
| Enabled | IconColumn | boolean check/x |
| Guest | IconColumn | boolean check/x |
| Last Tested | TextColumn | `last_test_at` datetime |

#### Actions

- **Edit** — standard
- **Delete** — with confirmation
- **Test Connection** — table action, tests saved credentials and updates `last_test_at`
- **Sync Profiles** — table action, re-fetches quality profiles and root folders, updates cache

#### Pages to create:

```
app/Filament/Resources/ArrIntegrationResource/Pages/
├── ListArrIntegrations.php
├── CreateArrIntegration.php
└── EditArrIntegration.php
```

---

## Phase 4: Admin Request Content Page

### `app/Filament/Pages/RequestContent.php`

- **Navigation:** Integrations group (discoverable via `getNavigationItems()`)
- **Icon:** `heroicon-o-film`
- **Access:** `auth()->user()->canUseIntegrations()`

#### Layout

```
┌─────────────────────────────────────────────────────────┐
│  Integration: [Sonarr - 1080p ▼]    [Queue: 3 active]  │
├─────────────────────────────────────────────────────────┤
│  [Search for content...                         🔍]    │
├─────────────────────────────────────────────────────────┤
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐  │
│  │ Poster   │ │ Poster   │ │ Poster   │ │ Poster   │  │
│  │ Title    │ │ Title    │ │ Title    │ │ Title    │  │
│  │ Year     │ │ Year     │ │ Year     │ │ Year     │  │
│  │ Overview │ │ Overview │ │ Overview │ │ Overview │  │
│  │[Request] │ │[Request] │ │[Request] │ │[Request] │  │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘  │
└─────────────────────────────────────────────────────────┘
```

#### Request Modal

When "Request" is clicked:

```
┌────────────────────────────────────┐
│  Request: "Breaking Bad"           │
│                                    │
│  Quality Profile: [HD - 1080p ▼]  │
│  Root Folder: [/media/tv ▼]       │
│  ☑ Search immediately             │
│  ☐ Monitor only specific seasons  │
│                                    │
│  [Cancel]              [Request]  │
└────────────────────────────────────┘
```

- Quality profile and root folder pre-selected from integration defaults
- For Sonarr series: optionally show season picker (monitor specific seasons)
- On success: notification + optionally switch to queue tab

#### Queue Panel

Sidebar or collapsible section showing:
- Download progress bars (0-100%)
- Title, status, time remaining
- Auto-refreshes every 5 seconds via Livewire polling

---

## Phase 5: Guest Request Content Page

### `app/Filament/GuestPanel/Pages/GuestRequestContent.php`

- **Uses trait:** `HasGuestAuth` (session-based playlist auth)
- **Discovered via:** Filament auto-discovery in `GuestPanelPanelProvider`

#### canAccess()

```php
public static function canAccess(): bool
{
    // Resolve playlist UUID from route or session
    $uuid = request()->route('uuid') ?? session()->get('playlist_uuid');
    if (! $uuid) return false;

    $playlist = \App\Models\Playlist::where('uuid', $uuid)->first();
    if (! $playlist) return false;

    return \App\Models\ArrIntegration::where('playlist_id', $playlist->id)
        ->where('enabled', true)
        ->where('guest_enabled', true)
        ->exists();
}
```

#### UI Differences from Admin

- Integration auto-resolved (no selector dropdown)
- If multiple integrations exist for the playlist, show tabs or selector filtered to guest-enabled ones
- Request uses integration defaults (quality profile + root folder) — no overrides
- No delete/edit capability
- Queue panel shows only their playlist's integrations

---

## Phase 6: Shared Livewire Component

### `app/Livewire/ArrSearch.php`

```php
use Livewire\Component;
use App\Models\ArrIntegration;
use App\Services\ArrService;

class ArrSearch extends Component
{
    public ?int $integrationId = null;
    public string $searchTerm = '';
    public array $results = [];
    public bool $isSearching = false;
    public bool $guestMode = false;

    // keyed by integration
    public function getIntegrationProperty(): ?ArrIntegration
    public function getArrServiceProperty(): ?ArrIntegrationInterface

    // Wire:model.live.debounce.300ms on search input
    public function updatedSearchTerm(): void

    // Search action
    public function search(): void { ...ArrService::make($this->integration)->search($this->searchTerm) }

    // Request action (opens modal or confirms)
    public function request(int $externalId, ?int $qualityProfileId, ?string $rootFolderPath): void
    {
        $payload = [
            // Sonarr: 'tvdbId' => $externalId
            // Radarr: 'tmdbId' => $externalId
            'qualityProfileId' => $qualityProfileId ?? $this->integration->quality_profile_id,
            'rootFolderPath' => $rootFolderPath ?? $this->integration->root_folder_path,
        ];
        ArrService::make($this->integration)->add($payload);
    }

    // Queue management
    public array $queue = [];
    public function loadQueue(): void { ... }
    public function getQueuePollInterval(): int { return 5; } // seconds

    public function render()
    {
        return view('livewire.arr-search');
    }
}
```

#### View: `resources/views/livewire/arr-search.blade.php`

- Search input with loading indicator
- Grid of result cards (Tailwind CSS)
- Request button per result
- Queue panel with progress bars

---

## Phase 7: Navigation

### Admin Panel (`AdminPanelProvider`)

Add to `->navigation()` builder:

```php
NavigationGroup::make(fn () => __('Integrations'))
    ->icon('heroicon-m-server-stack')
    ->items([
        ...MediaServerIntegrationResource::getNavigationItems(),
        ...ArrIntegrationResource::getNavigationItems(),
        ...RequestContent::getNavigationItems(),
        ...NetworkResource::getNavigationItems(),
    ]),
```

### Guest Panel

Auto-discovered. `GuestRequestContent::canAccess()` controls visibility in top nav.

---

## File Manifest

| # | File | Action |
|---|---|---|
| 1 | `database/migrations/YYYY_MM_DD_HHMMSS_create_arr_integrations_table.php` | Create |
| 2 | `app/Models/ArrIntegration.php` | Create |
| 3 | `app/Interfaces/ArrIntegrationInterface.php` | Create |
| 4 | `app/Services/ArrService.php` | Create |
| 5 | `app/Services/Arr/SonarrService.php` | Create |
| 6 | `app/Services/Arr/RadarrService.php` | Create |
| 7 | `app/Filament/Resources/ArrIntegrationResource.php` | Create |
| 8 | `app/Filament/Resources/ArrIntegrationResource/Pages/ListArrIntegrations.php` | Create |
| 9 | `app/Filament/Resources/ArrIntegrationResource/Pages/CreateArrIntegration.php` | Create |
| 10 | `app/Filament/Resources/ArrIntegrationResource/Pages/EditArrIntegration.php` | Create |
| 11 | `app/Filament/Pages/RequestContent.php` | Create |
| 12 | `app/Filament/GuestPanel/Pages/GuestRequestContent.php` | Create |
| 13 | `app/Livewire/ArrSearch.php` | Create |
| 14 | `resources/views/livewire/arr-search.blade.php` | Create |
| 15 | `app/Providers/Filament/AdminPanelProvider.php` | Modify |

---

## Implementation Order

1. Migration + Model
2. Interface + Services (`SonarrService`, `RadarrService`, `ArrService`)
3. `ArrIntegrationResource` + CRUD pages
4. Register in `AdminPanelProvider` navigation
5. Admin `RequestContent` page + `ArrSearch` Livewire component
6. Guest `GuestRequestContent` page
7. `vendor/bin/pint --format agent`
8. Tests
