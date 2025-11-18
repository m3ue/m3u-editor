<?php

namespace App\Providers;

use App\Services\GitInfoService;
use Throwable;
use Exception;
use App\Events\EpgCreated;
use App\Events\EpgDeleted;
use App\Events\EpgUpdated;
use App\Events\PlaylistCreated;
use App\Events\PlaylistDeleted;
use App\Events\PlaylistUpdated;
use App\Livewire\BackupDestinationListRecords;
use App\Livewire\StreamPlayer;
use App\Models\ChannelFailover;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Epg;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\StreamProfile;
use App\Models\User;
use App\Services\EpgCacheService;
use App\Services\FfmpegCodecService;
use App\Services\PlaylistService;
use App\Services\ProxyService;
use App\Settings\GeneralSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Storage;
use Opcodes\LogViewer\Facades\LogViewer;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Tags\Tag;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GitInfoService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Disable mass assignment protection (security handled by Filament)
        Model::unguard();

        // When running in console (e.g. queued jobs, Artisan commands), there is
        // no HTTP request context for URL generation. Force the root URL,
        // including the configured port, so route()/url() use the correct base.
        if (app()->runningInConsole()) {
            $this->configureConsoleBaseUrl();
        }

        // ✅ DYNAMIC HTTPS DETECTION: Detect actual protocol from request headers
        // This allows the app to work correctly with both HTTP and HTTPS access
        // when behind a reverse proxy with SSL termination
        if (app()->runningInConsole() === false && request()->headers->has('X-Forwarded-Proto')) {
            $this->configureDynamicHttpsDetection();
        }

        // Setup the middleware
        $this->setupMiddleware();

        // Set WAL mode on SQLite connections
        $this->setWalModeOnSqlite();

        // Setup the gates
        $this->setupGates();

        // Register the model event listeners
        $this->registerModelEventListeners();

        // Register the Filament hooks
        $this->registerFilamentHooks();

        // Configure Filament v4 to preserve v3 behavior
        $this->configureFilamentV3Compatibility();

        // Setup the API
        $this->setupApi();

        // Setup the services
        $this->setupServices();

        // Livewire components
        $this->registerLivewireComponents();
    }

    /**
     * Configure dynamic HTTPS detection based on actual request headers.
     *
     * This allows the application to work correctly when accessed via both
     * HTTP and HTTPS, especially when behind a reverse proxy with SSL termination.
     *
     * The detection logic:
     * 1. Check reverse proxy headers (X-Forwarded-Proto, X-Forwarded-Scheme, etc.)
     * 2. If HTTPS detected via headers → force HTTPS for asset URLs
     * 3. If HTTP detected or no reverse proxy → use HTTP for asset URLs
     *
     * This prevents mixed content blocking when:
     * - APP_URL=https://domain.com but accessed via http://domain.com
     * - APP_URL=http://domain.com but accessed via https://domain.com
     */
    private function configureDynamicHttpsDetection(): void
    {
        // Detect HTTPS from reverse proxy headers
        $isHttps = $this->detectHttpsFromHeaders();

        if ($isHttps) {
            // Force HTTPS scheme for all generated URLs (assets, routes, etc.)
            URL::forceScheme('https');

            // Set HTTPS server variable for Laravel to recognize HTTPS context
            request()->server->set('HTTPS', 'on');
        } else {
            // Force HTTP scheme for all generated URLs
            URL::forceScheme('http');

            // Ensure HTTPS server variable is off
            request()->server->set('HTTPS', 'off');
        }
    }

    /**
     * Configure a sensible base URL for console/CLI contexts where there is
     * no incoming HTTP request. This ensures that route() and url() include
     * the correct host and port when generating absolute URLs (e.g. for
     * Schedules Direct artwork proxies written into EPG files).
     */
    private function configureConsoleBaseUrl(): void
    {
        $baseUrl = rtrim((string) config('app.url'), '/');
        if ($baseUrl === '') {
            return;
        }

        $configuredPort = config('app.port');
        $hasPortInUrl = parse_url($baseUrl, PHP_URL_PORT) !== null;

        if ($configuredPort && ! $hasPortInUrl) {
            $baseUrl .= ':' . $configuredPort;
        }

        URL::forceRootUrl($baseUrl);
    }

    /**
     * Detect if the current request is HTTPS based on reverse proxy headers.
     *
     * Supports all major reverse proxies:
     * - NGINX, Caddy, Traefik, Apache (X-Forwarded-Proto)
     * - NGINX Proxy Manager (X-Forwarded-Scheme)
     * - Cloudflare, AWS ELB (X-Forwarded-Ssl)
     * - Microsoft IIS, Azure (Front-End-Https)
     * - RFC 7239 compliant proxies (Forwarded header)
     *
     * @return bool True if HTTPS detected, false otherwise
     */
    private function detectHttpsFromHeaders(): bool
    {
        $request = request();

        // Check X-Forwarded-Proto header (most common)
        $forwardedProto = $request->header('X-Forwarded-Proto');
        if ($forwardedProto && strtolower($forwardedProto) === 'https') {
            return true;
        }

        // Check X-Forwarded-Scheme header (NGINX Proxy Manager)
        $forwardedScheme = $request->header('X-Forwarded-Scheme');
        if ($forwardedScheme && strtolower($forwardedScheme) === 'https') {
            return true;
        }

        // Check X-Forwarded-Ssl header (Cloudflare, AWS ELB)
        $forwardedSsl = $request->header('X-Forwarded-Ssl');
        if ($forwardedSsl && strtolower($forwardedSsl) === 'on') {
            return true;
        }

        // Check Front-End-Https header (Microsoft IIS, Azure)
        $frontEndHttps = $request->header('Front-End-Https');
        if ($frontEndHttps && strtolower($frontEndHttps) === 'on') {
            return true;
        }

        // Check Forwarded header (RFC 7239 standard)
        $forwarded = $request->header('Forwarded');
        if ($forwarded && str_contains(strtolower($forwarded), 'proto=https')) {
            return true;
        }

        // Check X-Forwarded-Port header (port 443 = HTTPS)
        $forwardedPort = $request->header('X-Forwarded-Port');
        if ($forwardedPort && $forwardedPort === '443') {
            return true;
        }

        // Fallback: Check if APP_URL contains https
        // This ensures backward compatibility when no reverse proxy is used
        if (Str::contains(config('app.url'), 'https')) {
            return true;
        }

        // No HTTPS detected
        return false;
    }

    /**
     * Setup the middleware.
     */
    private function setupMiddleware(): void
    {
        // API rate limiter (for general API routes)
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(optional($request->user())->id ?: $request->ip());
        });
        
        // Note: Proxy rate limiting is handled by ProxyRateLimitMiddleware for better performance
    }

    /**
     * Set WAL mode on SQLite connections.
     */
    private function setWalModeOnSqlite(): void
    {
        // Don't kill the app if the database hasn't been created.
        try {
            foreach (['sqlite', 'jobs'] as $connection) {
                // Check if the file exists
                if (File::exists(database_path($connection . '.sqlite')) === false) {
                    continue;
                }

                // Set SQLite pragmas
                DB::connection($connection)
                    ->statement('
                        PRAGMA synchronous = NORMAL;
                        PRAGMA mmap_size = 134217728; -- 128 megabytes
                        PRAGMA cache_size = 1000000000;
                        PRAGMA foreign_keys = true;
                        PRAGMA busy_timeout = 5000;
                        PRAGMA temp_store = memory;
                        PRAGMA auto_vacuum = incremental;
                        PRAGMA incremental_vacuum;
                    ');
            }
        } catch (Throwable $throwable) {
            // Log the error
            Log::error('Error setting SQLite pragmas: ' . $throwable->getMessage());
        }
    }

    /**
     * Setup the gates.
     */
    private function setupGates(): void
    {
        // Allow only the admin to download and delete backups
        Gate::define('download-backup', function (User $user) {
            return in_array($user->email, config('dev.admin_emails'), true);
        });
        Gate::define('delete-backup', function (User $user) {
            return in_array($user->email, config('dev.admin_emails'), true);
        });
    }

    /**
     * Register the model event listeners.
     */
    private function registerModelEventListeners(): void
    {
        // Register the event listener
        try {
            // Process playlist on creation
            Playlist::created(fn(Playlist $playlist) => event(new PlaylistCreated($playlist)));
            Playlist::updated(fn(Playlist $playlist) => event(new PlaylistUpdated($playlist)));
            Playlist::creating(function (Playlist $playlist) {
                if (!$playlist->user_id) {
                    $playlist->user_id = auth()->id();
                }
                if (!$playlist->sync_interval) {
                    $playlist->sync_interval = '0 0 * * *';
                }
                $playlist->uuid = Str::orderedUuid()->toString();
                return $playlist;
            });
            Playlist::updating(function (Playlist $playlist) {
                if (!$playlist->sync_interval) {
                    $playlist->sync_interval = '0 0 * * *';
                }
                if ($playlist->isDirty('short_urls_enabled')) {
                    $playlist->generateShortUrl();
                }
                if ($playlist->isDirty('uuid')) {
                    // If changing the UUID, remove the old short URLs and generate new ones
                    if ($playlist->short_urls_enabled) {
                        $playlist->removeShortUrls();
                        $playlist->generateShortUrl();
                    }
                }
                return $playlist;
            });
            Playlist::deleting(function (Playlist $playlist) {
                Storage::disk('local')->deleteDirectory($playlist->folder_path);
                if ($playlist->uploads && Storage::disk('local')->exists($playlist->uploads)) {
                    Storage::disk('local')->delete($playlist->uploads);
                }

                // Delete cached EPG files
                EpgCacheService::clearPlaylistEpgCacheFile($playlist);

                // Remove short URLs and detach playlist auths
                $playlist->removeShortUrls();
                $playlist->playlistAuths()->detach();
                event(new PlaylistDeleted($playlist));
                $playlist->postProcesses()->detach();
                return $playlist;
            });

            // Process epg on creation
            Epg::created(fn(Epg $epg) => event(new EpgCreated($epg)));
            Epg::updated(fn(Epg $epg) => event(new EpgUpdated($epg)));
            Epg::creating(function (Epg $epg) {
                if (!$epg->user_id) {
                    $epg->user_id = auth()->id();
                }
                if (!$epg->sync_interval) {
                    $epg->sync_interval = '0 0 * * *';
                }
                $epg->uuid = Str::orderedUuid()->toString();
                return $epg;
            });
            Epg::updating(function (Epg $epg) {
                if (!$epg->sync_interval) {
                    $epg->sync_interval = '0 0 * * *';
                }
                return $epg;
            });
            Epg::deleting(function (Epg $epg) {
                Storage::disk('local')->deleteDirectory($epg->folder_path);
                if ($epg->uploads && Storage::disk('local')->exists($epg->uploads)) {
                    Storage::disk('local')->delete($epg->uploads);
                }
                event(new EpgDeleted($epg));
                $epg->postProcesses()->detach();
                return $epg;
            });

            // Merged playlist
            // MergedPlaylist::created(fn(MergedPlaylist $mergedPlaylist) => /* ... */);
            MergedPlaylist::creating(function (MergedPlaylist $mergedPlaylist) {
                if (!$mergedPlaylist->user_id) {
                    $mergedPlaylist->user_id = auth()->id();
                }
                $mergedPlaylist->uuid = Str::orderedUuid()->toString();
                return $mergedPlaylist;
            });
            MergedPlaylist::updating(function (MergedPlaylist $mergedPlaylist) {
                if ($mergedPlaylist->isDirty('short_urls_enabled')) {
                    $mergedPlaylist->generateShortUrl();
                }
                if ($mergedPlaylist->isDirty('uuid')) {
                    // If changing the UUID, remove the old short URLs and generate new ones
                    if ($mergedPlaylist->short_urls_enabled) {
                        $mergedPlaylist->removeShortUrls();
                        $mergedPlaylist->generateShortUrl();
                    }
                }
                return $mergedPlaylist;
            });
            MergedPlaylist::deleting(function (MergedPlaylist $mergedPlaylist) {
                // Remove short URLs
                $mergedPlaylist->removeShortUrls();
                return $mergedPlaylist;
            });

            // Custom playlist
            // CustomPlaylist::created(fn(CustomPlaylist $customPlaylist) => /* ... */);
            CustomPlaylist::creating(function (CustomPlaylist $customPlaylist) {
                if (!$customPlaylist->user_id) {
                    $customPlaylist->user_id = auth()->id();
                }
                $customPlaylist->uuid = Str::orderedUuid()->toString();
                return $customPlaylist;
            });
            CustomPlaylist::updating(function (CustomPlaylist $customPlaylist) {
                if ($customPlaylist->isDirty('short_urls_enabled')) {
                    $customPlaylist->generateShortUrl();
                }
                if ($customPlaylist->isDirty('uuid')) {
                    // If changing the UUID, remove the old short URLs and generate new ones
                    if ($customPlaylist->short_urls_enabled) {
                        $customPlaylist->removeShortUrls();
                        $customPlaylist->generateShortUrl();
                    }

                    // Need to also update any tags with the new type
                    $originalUuid = $customPlaylist->getOriginal('uuid');
                    Tag::query()
                        ->where('type', $originalUuid)
                        ->update(['type' => $customPlaylist->uuid]);
                    Tag::query()
                        ->where('type', $originalUuid . '-category')
                        ->update(['type' => $customPlaylist->uuid . '-category']);
                }
                return $customPlaylist;
            });
            CustomPlaylist::deleting(function (CustomPlaylist $customPlaylist) {
                // Remove short URLs
                $customPlaylist->removeShortUrls();
                // Cleanup tags
                Tag::query()
                    ->where('type', $customPlaylist->uuid)
                    ->orWhere('type', $customPlaylist->uuid . '-category')
                    ->delete();
                return $customPlaylist;
            });

            // Groups
            Group::updated(function (Group $group) {
                $changes = $group->getChanges();
                if (isset($changes['name'])) {
                    // Update the name of the group in the channels
                    $group->channels()
                        ->update(['group' => $group->name]);
                }
            });

            // Failover channels
            ChannelFailover::creating(function (ChannelFailover $channelFailover) {
                if (!$channelFailover->user_id) {
                    $channelFailover->user_id = auth()->id();
                }
                return $channelFailover;
            });

            // PlayslistAlias
            PlaylistAlias::creating(function (PlaylistAlias $playlistAlias) {
                if (!$playlistAlias->user_id) {
                    $playlistAlias->user_id = auth()->id();
                }
                $playlistAlias->uuid = Str::orderedUuid()->toString();
                return $playlistAlias;
            });
            PlaylistAlias::updating(function (PlaylistAlias $playlist) {
                if ($playlist->isDirty('short_urls_enabled')) {
                    $playlist->generateShortUrl();
                }
                if ($playlist->isDirty('uuid')) {
                    // If changing the UUID, remove the old short URLs and generate new ones
                    if ($playlist->short_urls_enabled) {
                        $playlist->removeShortUrls();
                        $playlist->generateShortUrl();
                    }
                }
                return $playlist;
            });
            PlaylistAlias::deleting(function (PlaylistAlias $playlistAlias) {
                // Remove short URLs
                $playlistAlias->removeShortUrls();
                return $playlistAlias;
            });

            // StreamProfile
            StreamProfile::creating(function (StreamProfile $streamProfile) {
                if (!$streamProfile->user_id) {
                    $streamProfile->user_id = auth()->id();
                }
                return $streamProfile;
            });
        } catch (Throwable $e) {
            // Log the error
            report($e);
        }
    }

    /**
     * Register the Filament hooks.
     */
    private function registerFilamentHooks(): void
    {
        // Add scroll to top event listener
        FilamentView::registerRenderHook(
            PanelsRenderHook::SCRIPTS_AFTER,
            fn(): string => new HtmlString('<script>document.addEventListener("scroll-to-top", () => window.scrollTo({top: 0, left: 0, behavior: "smooth"}))</script>'),
        );

        // Add footer view
        FilamentView::registerRenderHook(
            PanelsRenderHook::FOOTER,
            fn() => view('footer')
        );
    }

    /**
     * Setup the API.
     */
    private function setupApi(): void
    {
        // Add log viewer auth
        $userPreferences = app(GeneralSettings::class);
        try {
            $showApiDocs = $userPreferences->show_api_docs;
        } catch (Exception $e) {
            $showApiDocs = false;
        }

        // Allow access to api docs
        Gate::define('viewApiDocs', function (User $user) use ($showApiDocs) {
            return $showApiDocs && in_array($user->email, config('dev.admin_emails'), true);
        });

        // Configure the API
        Scramble::configure()
            ->routes(function (Route $route) {
                return !Str::startsWith($route->uri, 'playlist/v/') && Str::startsWith($route->uri, [
                    'playlist/',
                    'epg/',
                    'user/',
                    'channel/',
                    'player_api.php'
                ]);
            })
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->secure(
                    SecurityScheme::http('bearer')
                );
            });
    }

    /**
     * Setup the services.
     */
    public function setupServices(): void
    {
        // Register the proxy service
        $this->app->singleton('proxy', function () {
            return new ProxyService();
        });

        // Register the playlist url service
        $this->app->singleton('playlist', function () {
            return new PlaylistService();
        });

        // Register the FFmpeg codec service
        $this->app->singleton(FfmpegCodecService::class);
    }

    /**
     * Register Livewire components.
     */
    private function registerLivewireComponents(): void
    {
        // Register the backup destination list records component
        Livewire::component('backup-destination-list-records', BackupDestinationListRecords::class);

        // Register the stream player component
        Livewire::component('stream-player', StreamPlayer::class);
    }

    /**
     * Configure Filament v4 to preserve v3 behavior.
     */
    private function configureFilamentV3Compatibility(): void
    {
        // Preserve v3 file upload behavior (public visibility)
        \Filament\Forms\Components\FileUpload::configureUsing(fn(\Filament\Forms\Components\FileUpload $fileUpload) => $fileUpload
            ->visibility('public'));

        \Filament\Tables\Columns\ImageColumn::configureUsing(fn(\Filament\Tables\Columns\ImageColumn $imageColumn) => $imageColumn
            ->visibility('public'));

        \Filament\Infolists\Components\ImageEntry::configureUsing(fn(\Filament\Infolists\Components\ImageEntry $imageEntry) => $imageEntry
            ->visibility('public'));

        // Preserve v3 table filter behavior (not deferred)
        \Filament\Tables\Table::configureUsing(fn(\Filament\Tables\Table $table) => $table
            ->deferFilters(false)
            ->paginationPageOptions([5, 10, 25, 50, 'all']));

        // Preserve v3 layout component behavior (column span full)
        \Filament\Schemas\Components\Fieldset::configureUsing(fn(\Filament\Schemas\Components\Fieldset $fieldset) => $fieldset
            ->columnSpanFull());

        \Filament\Schemas\Components\Grid::configureUsing(fn(\Filament\Schemas\Components\Grid $grid) => $grid
            ->columnSpanFull());

        \Filament\Schemas\Components\Section::configureUsing(fn(\Filament\Schemas\Components\Section $section) => $section
            ->columnSpanFull());

        // Preserve v3 unique validation behavior (not ignoring record by default)
        \Filament\Forms\Components\Field::configureUsing(fn(\Filament\Forms\Components\Field $field) => $field
            ->uniqueValidationIgnoresRecordByDefault(false));
    }
}
