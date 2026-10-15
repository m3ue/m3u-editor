<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\Pages\Concerns\BaseSettingsPage;
use App\Filament\Resources\CachedContentFiles\CachedContentFileResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class ManageCacheSettings extends BaseSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?string $slug = 'cache';

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return __('Cache');
    }

    public function getTitle(): string
    {
        return __('Cache');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('manage_cached_items')
                ->label(__('Manage Cached Items'))
                ->color('gray')
                ->icon('heroicon-o-arrow-down-tray')
                ->url(CachedContentFileResource::getUrl())
                ->visible(fn (): bool => CachedContentFileResource::canAccess()),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Callout::make(__('Mount a volume for cached files'))
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('warning')
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => (bool) $get('enable_cache'))
                    ->description(fn (): HtmlString => new HtmlString(
                        e(__('When running in Docker, cached files are written inside the container unless you mount a volume at the cache folder. Without it, every cached file is lost when the container is rebuilt or updated, and each entry has to be downloaded again. Add a volume like this to your docker-compose.yml:'))
                        .'<pre class="mt-2 overflow-x-auto rounded-lg bg-gray-950/5 p-3 font-mono text-xs dark:bg-white/5"><code>volumes:'."\n"
                        .'  - ./cache:'.e((string) config('filesystems.disks.cache.root')).'</code></pre>'
                    )),
                Callout::make(__('Cache through Radarr or Sonarr'))
                    ->icon('heroicon-o-information-circle')
                    ->color('info')
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => (bool) $get('enable_cache'))
                    ->description(__('Turn on "Use for caching" on a Radarr or Sonarr integration to send new titles there instead of downloading them from the provider. This applies to playlists that prefer media server sources. Those titles are managed in Radarr or Sonarr, and cache retention never removes them unless a Radarr integration has "Remove after leaving dynamic groups" on.')),
                Section::make(__('Cached Content'))
                    ->description(__('Download VOD movies and series episodes to local storage with the "Cache Now" actions. Once a download completes, playback uses the local copy instead of the provider.'))
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        Toggle::make('enable_cache')
                            ->label(__('Enable cache'))
                            ->inline(false)
                            ->live()
                            ->helperText(__('Shows the "Cache Now" actions and serves completed downloads during playback. When off, nothing new is downloaded and playback always uses the provider. Existing cached files are kept.'))
                            ->default(false),
                        Select::make('cache_retention_mode')
                            ->label(__('Cache retention mode'))
                            ->options(self::cacheRetentionOptions())
                            ->default('automatic')
                            ->helperText(__('"Automatic" deletes a cached file once its movie or episode is removed from the playlist. "Never expire" and "Manual" keep files until you delete them from the Cached Downloads page. Playlists can override this.')),
                        Toggle::make('default_share_cache_across_playlists')
                            ->label(__('Share cache across playlists by default'))
                            ->inline(false)
                            ->helperText(__('Default for the "Share cache across playlists" option on new playlists.'))
                            ->default(false),
                    ]),
            ]);
    }

    /**
     * Retention mode options, shared with the per-playlist override.
     *
     * @return array<string, string>
     */
    public static function cacheRetentionOptions(): array
    {
        return [
            'automatic' => __('Automatic (remove when content leaves the playlist)'),
            'never-expire' => __('Never expire'),
            'manual' => __('Manual'),
        ];
    }
}
