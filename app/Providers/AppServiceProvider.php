<?php

namespace App\Providers;

use Exception;
use App\Events\EpgCreated;
use App\Events\PlaylistCreated;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Epg;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use App\Services\PlaylistUrlService;
use App\Services\ProxyService;
use App\Settings\GeneralSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
use Illuminate\Support\Facades\DB;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Don't kill the app if the database hasn't been created.
        try {
            DB::connection('sqlite')->statement(
                'PRAGMA synchronous = OFF;'
            );
        } catch (\Throwable $throwable) {
            return;
        }

        // Disable mass assignment protection (security handled by Filament)
        Model::unguard();

        // Setup the middleware
        $this->setupMiddleware();

        // Setup the gates
        $this->setupGates();

        // Register the model event listeners
        $this->registerModelEventListeners();

        // Register the Filament hooks
        $this->registerFilamentHooks();

        // Setup the API
        $this->setupApi();

        // Setup the services
        $this->setupServices();
    }

    /**
     * Setup the middleware.
     */
    private function setupMiddleware(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(optional($request->user())->id ?: $request->ip());
        });
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

        // Add log viewer auth
        $userPreferences = app(GeneralSettings::class);
        try {
            $showLogs = $userPreferences->show_logs;
        } catch (Exception $e) {
            $showLogs = false;
        }
        if (!$showLogs) {
            Gate::define('viewLogViewer', fn() => false);
        }
        LogViewer::auth(fn($request) => $showLogs);
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
            Playlist::creating(function (Playlist $playlist) {
                $playlist->user_id = auth()->id();
                if (!$playlist->sync_interval) {
                    $playlist->sync_interval = '24 hours';
                }
                $playlist->uuid = \Illuminate\Support\Str::orderedUuid()->toString();
                return $playlist;
            });
            Playlist::updating(function (Playlist $playlist) {
                if (!$playlist->sync_interval) {
                    $playlist->sync_interval = '24 hours';
                }
                return $playlist;
            });
            Playlist::deleting(function (Playlist $playlist) {
                Storage::disk('local')->deleteDirectory($playlist->folder_path);
                if ($playlist->uploads && Storage::disk('local')->exists($playlist->uploads)) {
                    Storage::disk('local')->delete($playlist->uploads);
                }
                return $playlist;
            });

            // Process epg on creation
            Epg::created(fn(Epg $epg) => event(new EpgCreated($epg)));
            Epg::creating(function (Epg $epg) {
                $epg->user_id = auth()->id();
                if (!$epg->sync_interval) {
                    $epg->sync_interval = '24 hours';
                }
                $epg->uuid = \Illuminate\Support\Str::orderedUuid()->toString();
                return $epg;
            });
            Epg::updating(function (Epg $epg) {
                if (!$epg->sync_interval) {
                    $epg->sync_interval = '24 hours';
                }
                return $epg;
            });
            Epg::deleting(function (Epg $epg) {
                Storage::disk('local')->deleteDirectory($epg->folder_path);
                if ($epg->uploads && Storage::disk('local')->exists($epg->uploads)) {
                    Storage::disk('local')->delete($epg->uploads);
                }
                return $epg;
            });

            // Merged playlist
            // MergedPlaylist::created(fn(MergedPlaylist $mergedPlaylist) => /* ... */);
            MergedPlaylist::creating(function (MergedPlaylist $mergedPlaylist) {
                $mergedPlaylist->user_id = auth()->id();
                $mergedPlaylist->uuid = \Illuminate\Support\Str::orderedUuid()->toString();
                return $mergedPlaylist;
            });

            // Custom playlist
            // CustomPlaylist::created(fn(CustomPlaylist $customPlaylist) => /* ... */);
            CustomPlaylist::creating(function (CustomPlaylist $customPlaylist) {
                $customPlaylist->user_id = auth()->id();
                $customPlaylist->uuid = \Illuminate\Support\Str::orderedUuid()->toString();
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
        } catch (\Throwable $e) {
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
                return Str::startsWith($route->uri, [
                    'playlist/', 
                    'epg/', 
                    'user/'
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
        $this->app->singleton('playlistUrl', function () {
            return new PlaylistUrlService();
        });
    }
}
