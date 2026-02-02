<?php

namespace App\Providers;

use App\Events\EpgCreated;
use App\Events\EpgDeleted;
use App\Events\EpgUpdated;
use App\Events\PlaylistCreated;
use App\Events\PlaylistDeleted;
use App\Events\PlaylistUpdated;
use App\Jobs\SyncMediaServer;
use App\Livewire\BackupDestinationListRecords;
use App\Livewire\StreamPlayer;
use App\Livewire\TmdbSearch;
use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\CustomPlaylist;
use App\Models\Epg;
use App\Models\Group;
use App\Models\MediaServerIntegration;
use App\Models\MergedPlaylist;
use App\Models\Network;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\StreamFileSetting;
use App\Models\StreamProfile;
use App\Models\User;
use App\Services\EpgCacheService;
use App\Services\GitInfoService;
use App\Services\NetworkBroadcastService;
use App\Services\NetworkChannelSyncService;
use App\Services\PlaylistService;
use App\Services\ProxyService;
use App\Services\SortService;
use App\Settings\GeneralSettings;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Exception;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Tags\Tag;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GitInfoService::class);

        // Register Artisan commands for HLS maintenance
        if ($this->app->runningInConsole()) {
            // Ensure command class file is loaded in environments without composer dump-autoload
            $ensurePath = __DIR__.'/../Console/Commands/NetworkBroadcastEnsure.php';
            if (file_exists($ensurePath)) {
                require_once $ensurePath;
            }

            $this->commands([
                \App\Console\Commands\NetworkBroadcastHeal::class,
                \App\Console\Commands\NetworkBroadcastEnsure::class,
            ]);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Disable mass assignment protection (security handled by Filament)
        Model::unguard();

        // App URL generation based on context
        if (app()->runningInConsole()) {
            // When running in console (e.g. queued jobs, Artisan commands), there is
            // no HTTP request context for URL generation. Force the root URL,
            // including the configured port, so route()/url() use the correct base.
            $this->configureConsoleBaseUrl();
        } elseif (request()->hasHeader('X-Forwarded-Proto')) {
            // Detect actual protocol from request headers
            // This allows the app to work correctly with both HTTP and HTTPS access
            // when behind a reverse proxy with SSL termination
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
     * SchedulesDirect artwork proxies written into EPG files).
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
            $baseUrl .= ':'.$configuredPort;
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
                if (File::exists(database_path($connection.'.sqlite')) === false) {
                    continue;
                }

                // For the jobs database, ensure the schema exists
                // This handles cases where the jobs.sqlite file gets deleted/corrupted
                // since migrations are tracked in the main database.sqlite
                if ($connection === 'jobs') {
                    $this->ensureJobsTableExists();
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
            Log::error('Error setting SQLite pragmas: '.$throwable->getMessage());
        }
    }

    /**
     * Ensure the jobs table exists in the jobs database.
     *
     * This is necessary because the jobs.sqlite database is separate from the main
     * database, but migrations are tracked in the main database. If the jobs.sqlite
     * file gets deleted or corrupted, the migration won't run again automatically.
     *
     * This method creates the table schema directly if it doesn't exist, ensuring
     * the application can always write to the jobs table.
     */
    private function ensureJobsTableExists(): void
    {
        try {
            if (! Schema::connection('jobs')->hasTable('jobs')) {
                Schema::connection('jobs')->create('jobs', function (Blueprint $table) {
                    $table->id();
                    $table->string('title');
                    $table->string('batch_no');
                    $table->longText('payload');
                    $table->json('variables')->nullable();
                    $table->timestamps();
                });

                Log::info('Created jobs table in jobs.sqlite database');
            }
        } catch (Throwable $e) {
            Log::error('Failed to create jobs table: '.$e->getMessage());
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
            Playlist::created(fn (Playlist $playlist) => event(new PlaylistCreated($playlist)));
            Playlist::updated(function (Playlist $playlist) {
                // Check if any of the EPG related fields were changed and perform EPG cache busting
                $fields = ['auto_channel_increment', 'channel_start', 'dummy_epg', 'dummy_epg_category', 'dummy_epg_length', 'id_channel_by'];
                if ($playlist->isDirty($fields)) {
                    EpgCacheService::clearPlaylistEpgCacheFile($playlist);
                }

                // Fire the updated event
                event(new PlaylistUpdated($playlist));
            });
            Playlist::creating(function (Playlist $playlist) {
                if (! $playlist->user_id) {
                    $playlist->user_id = auth()->id();
                }
                if (! $playlist->sync_interval) {
                    $playlist->sync_interval = '0 0 * * *';
                }
                if (($playlist->xtream_config['url'] ?? false) && Str::endsWith($playlist->xtream_config['url'], '/')) {
                    // Remove trailing slash from Xtream URL
                    $playlist->xtream_config = [
                        ...$playlist->xtream_config,
                        'url' => rtrim($playlist->xtream_config['url'], '/'),
                    ];
                }
                $playlist->uuid = Str::orderedUuid()->toString();

                return $playlist;
            });
            Playlist::updating(function (Playlist $playlist) {
                if (! $playlist->sync_interval) {
                    $playlist->sync_interval = '0 0 * * *';
                }
                if (($playlist->xtream_config['url'] ?? false) && Str::endsWith($playlist->xtream_config['url'], '/')) {
                    // Remove trailing slash from Xtream URL
                    $playlist->xtream_config = [
                        ...$playlist->xtream_config,
                        'url' => rtrim($playlist->xtream_config['url'], '/'),
                    ];
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
            Epg::created(fn (Epg $epg) => event(new EpgCreated($epg)));
            Epg::updated(fn (Epg $epg) => event(new EpgUpdated($epg)));
            Epg::creating(function (Epg $epg) {
                if (! $epg->user_id) {
                    $epg->user_id = auth()->id();
                }
                if (! $epg->sync_interval) {
                    $epg->sync_interval = '0 */6 * * *';
                }
                $epg->uuid = Str::orderedUuid()->toString();

                return $epg;
            });
            Epg::updating(function (Epg $epg) {
                if (! $epg->sync_interval) {
                    $epg->sync_interval = '0 */6 * * *';
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
                if (! $mergedPlaylist->user_id) {
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
                if (! $customPlaylist->user_id) {
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
                        ->where('type', $originalUuid.'-category')
                        ->update(['type' => $customPlaylist->uuid.'-category']);
                }

                return $customPlaylist;
            });
            CustomPlaylist::deleting(function (CustomPlaylist $customPlaylist) {
                // Remove short URLs
                $customPlaylist->removeShortUrls();
                // Cleanup tags
                Tag::query()
                    ->where('type', $customPlaylist->uuid)
                    ->orWhere('type', $customPlaylist->uuid.'-category')
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
                if (! $channelFailover->user_id) {
                    $channelFailover->user_id = auth()->id();
                }

                return $channelFailover;
            });

            // PlayslistAlias
            PlaylistAlias::creating(function (PlaylistAlias $playlistAlias) {
                if (! $playlistAlias->user_id) {
                    $playlistAlias->user_id = auth()->id();
                }
                if (($playlistAlias->xtream_config['url'] ?? false) && Str::endsWith($playlistAlias->xtream_config['url'], '/')) {
                    // Remove trailing slash from Xtream URL
                    $playlistAlias->xtream_config = [
                        ...$playlistAlias->xtream_config,
                        'url' => rtrim($playlistAlias->xtream_config['url'], '/'),
                    ];
                }
                $playlistAlias->uuid = Str::orderedUuid()->toString();

                return $playlistAlias;
            });
            PlaylistAlias::updating(function (PlaylistAlias $playlistAlias) {
                if (($playlistAlias->xtream_config['url'] ?? false) && Str::endsWith($playlistAlias->xtream_config['url'], '/')) {
                    // Remove trailing slash from Xtream URL
                    $playlistAlias->xtream_config = [
                        ...$playlistAlias->xtream_config,
                        'url' => rtrim($playlistAlias->xtream_config['url'], '/'),
                    ];
                }
                if ($playlistAlias->isDirty('short_urls_enabled')) {
                    $playlistAlias->generateShortUrl();
                }
                if ($playlistAlias->isDirty('uuid')) {
                    // If changing the UUID, remove the old short URLs and generate new ones
                    if ($playlistAlias->short_urls_enabled) {
                        $playlistAlias->removeShortUrls();
                        $playlistAlias->generateShortUrl();
                    }
                }

                return $playlistAlias;
            });
            PlaylistAlias::deleting(function (PlaylistAlias $playlistAlias) {
                // Remove short URLs
                $playlistAlias->removeShortUrls();

                return $playlistAlias;
            });

            // StreamProfile
            StreamProfile::creating(function (StreamProfile $streamProfile) {
                if (! $streamProfile->user_id) {
                    $streamProfile->user_id = auth()->id();
                }

                return $streamProfile;
            });

            // MediaServerIntegration
            MediaServerIntegration::created(function (MediaServerIntegration $integration) {
                // Dispatch initial sync job
                dispatch(new SyncMediaServer($integration->id));

                return $integration;
            });
            MediaServerIntegration::deleting(function (MediaServerIntegration $integration) {
                // Remove any associated Playlists
                $integration->playlist()->delete();

                return $integration;
            });

            // Network
            Network::creating(function (Network $network) {
                if (empty($network->uuid)) {
                    $network->uuid = Str::uuid()->toString();
                }
            });
            Network::updated(function (Network $network) {
                app(NetworkChannelSyncService::class)->refreshNetworkChannel($network);
            });
            Network::deleting(function (Network $network) {
                // Ensure any running broadcast is stopped and HLS files are removed
                try {
                    app(NetworkBroadcastService::class)->stop($network);
                } catch (Throwable $e) {
                    Log::warning('Failed to stop network broadcast during deletion', [
                        'network_id' => $network->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                Channel::where('network_id', $network->id)->delete();
            });

            // StreamFileSetting
            StreamFileSetting::creating(function (StreamFileSetting $setting) {
                if (! $setting->user_id) {
                    $setting->user_id = auth()->id();
                }

                return $setting;
            });

            // ...

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
            fn (): string => new HtmlString('<script>document.addEventListener("scroll-to-top", () => window.scrollTo({top: 0, left: 0, behavior: "smooth"}))</script>'),
        );

        // Add footer view
        FilamentView::registerRenderHook(
            PanelsRenderHook::FOOTER,
            fn () => view('footer')
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
                return ! Str::startsWith($route->uri, 'playlist/v/') && Str::startsWith($route->uri, [
                    'playlist/',
                    'epg/',
                    'user/',
                    'channel/',
                    'proxy/',
                    'player_api.php',
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
            return new ProxyService;
        });

        // Register the playlist url service
        $this->app->singleton('playlist', function () {
            return new PlaylistService;
        });

        // Register the sort service
        $this->app->singleton('sort', function () {
            return new SortService;
        });
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

        // Register the TMDB search component
        Livewire::component('tmdb-search', TmdbSearch::class);
    }

    /**
     * Configure Filament v4 to preserve v3 behavior.
     */
    private function configureFilamentV3Compatibility(): void
    {
        // Preserve v3 file upload behavior (public visibility)
        \Filament\Forms\Components\FileUpload::configureUsing(fn (\Filament\Forms\Components\FileUpload $fileUpload) => $fileUpload
            ->visibility('public'));

        \Filament\Tables\Columns\ImageColumn::configureUsing(fn (\Filament\Tables\Columns\ImageColumn $imageColumn) => $imageColumn
            ->visibility('public'));

        \Filament\Infolists\Components\ImageEntry::configureUsing(fn (\Filament\Infolists\Components\ImageEntry $imageEntry) => $imageEntry
            ->visibility('public'));

        // // Preserve v3 table filter behavior (not deferred)
        // \Filament\Tables\Table::configureUsing(fn(\Filament\Tables\Table $table) => $table
        //     ->deferFilters(false)
        //     ->paginationPageOptions([5, 10, 25, 50, 'all']));

        // Preserve v3 layout component behavior (column span full)
        \Filament\Schemas\Components\Fieldset::configureUsing(fn (\Filament\Schemas\Components\Fieldset $fieldset) => $fieldset
            ->columnSpanFull());

        \Filament\Schemas\Components\Grid::configureUsing(fn (\Filament\Schemas\Components\Grid $grid) => $grid
            ->columnSpanFull());

        \Filament\Schemas\Components\Section::configureUsing(fn (\Filament\Schemas\Components\Section $section) => $section
            ->columnSpanFull());

        // Preserve v3 unique validation behavior (not ignoring record by default)
        \Filament\Forms\Components\Field::configureUsing(fn (\Filament\Forms\Components\Field $field) => $field
            ->uniqueValidationIgnoresRecordByDefault(false));
    }
}
