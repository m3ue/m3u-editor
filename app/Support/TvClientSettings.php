<?php

namespace App\Support;

/**
 * The M3U TV app settings a TV Client Profile can manage. This list is the
 * contract with the app: keys are never renamed or removed (only marked
 * deprecated), and values are the exact strings the app stores, so an app
 * that doesn't know a key or value just ignores it.
 *
 * Each managed setting has a mode:
 *  - default: replaces the app's built-in default; a user's own choice wins.
 *  - enforced: always applies and the app locks the setting.
 * A setting without a mode is not managed and the app behaves as before.
 */
class TvClientSettings
{
    public const API_VERSION = 1;

    /** First M3U TV release that reads `client_config`. */
    public const MIN_APP_VERSION = '1.3.0';

    public const MODE_DEFAULT = 'default';

    public const MODE_ENFORCED = 'enforced';

    public const MODES = [self::MODE_DEFAULT, self::MODE_ENFORCED];

    public const TYPE_BOOL = 'bool';

    public const TYPE_ENUM = 'enum';

    public const TYPE_INT = 'int';

    /**
     * @return array<string, array{group: string, type: string, label: string, helper?: string, options?: array<string|int, string>, app_default: string|int|bool, platforms?: string}>
     */
    public static function definitions(): array
    {
        $mediaSortOptions = [
            'defaultOrder' => __('Default'),
            'ratingDesc' => __('Rating'),
            'releaseDateDesc' => __('Newest First'),
            'releaseDateAsc' => __('Oldest First'),
            'recentlyAdded' => __('Recently Added'),
            'recentlyUpdated' => __('Recently Updated'),
            'titleAsc' => __('Title (A-Z)'),
            'titleDesc' => __('Title (Z-A)'),
        ];

        return [
            'default_start_page' => [
                'group' => 'home',
                'type' => self::TYPE_ENUM,
                'label' => __('Default start page'),
                'helper' => __('The screen the app opens on.'),
                'options' => [
                    'home' => __('Home'),
                    'search' => __('Search'),
                    'liveTv' => __('Live TV'),
                    'sports' => __('Sports'),
                    'movies' => __('Movies'),
                    'series' => __('Series'),
                ],
                'app_default' => 'home',
            ],
            'home_screen_style' => [
                'group' => 'home',
                'type' => self::TYPE_ENUM,
                'label' => __('Home screen'),
                'options' => [
                    'simplified' => __('Simplified'),
                    'rich' => __('Spotlight'),
                ],
                'app_default' => 'rich',
            ],
            'live_tv_layout' => [
                'group' => 'live',
                'type' => self::TYPE_ENUM,
                'label' => __('Live TV layout'),
                'options' => [
                    'list' => __('List'),
                    'grid' => __('Grid'),
                    'timeline' => __('Timeline'),
                ],
                'app_default' => 'list',
            ],
            'channel_column_layout' => [
                'group' => 'live',
                'type' => self::TYPE_ENUM,
                'label' => __('Live TV channels column'),
                'options' => [
                    'logoAndTitle' => __('Logo + Title'),
                    'logoOnly' => __('Logo Only'),
                    'titleOnly' => __('Title Only'),
                ],
                'app_default' => 'logoOnly',
            ],
            'epg_start_view' => [
                'group' => 'live',
                'type' => self::TYPE_ENUM,
                'label' => __('EPG starts at'),
                'options' => [
                    'currentTime' => __('Current time'),
                    'primeTime' => __('Prime time'),
                ],
                'app_default' => 'currentTime',
            ],
            'epg_preview_playback' => [
                'group' => 'live',
                'type' => self::TYPE_ENUM,
                'label' => __('Guide preview playback'),
                'options' => [
                    'off' => __('Off'),
                    'pauseToPlay' => __('Play after pausing'),
                    'clickToPreview' => __('Press OK to preview'),
                ],
                'app_default' => 'off',
            ],
            'epg_preview_delay_seconds' => [
                'group' => 'live',
                'type' => self::TYPE_INT,
                'label' => __('Preview delay'),
                'options' => [
                    2 => __(':count seconds', ['count' => 2]),
                    3 => __(':count seconds', ['count' => 3]),
                    5 => __(':count seconds', ['count' => 5]),
                    8 => __(':count seconds', ['count' => 8]),
                    10 => __(':count seconds', ['count' => 10]),
                ],
                'app_default' => 5,
            ],
            'remember_media_sort' => [
                'group' => 'sorting',
                'type' => self::TYPE_BOOL,
                'label' => __('Filter Persistence'),
                'helper' => __('Remembers each screen\'s sort and filters between visits. When off, they reset each time.'),
                'app_default' => true,
            ],
            'vod_sort_option' => [
                'group' => 'sorting',
                'type' => self::TYPE_ENUM,
                'label' => __('Movies sort'),
                'options' => $mediaSortOptions,
                'app_default' => 'defaultOrder',
            ],
            'series_sort_option' => [
                'group' => 'sorting',
                'type' => self::TYPE_ENUM,
                'label' => __('Series sort'),
                'options' => $mediaSortOptions,
                'app_default' => 'defaultOrder',
            ],
            'live_tv_sort_option' => [
                'group' => 'sorting',
                'type' => self::TYPE_ENUM,
                'label' => __('Live TV sort'),
                'options' => [
                    'playlistOrder' => __('Playlist Order'),
                    'channelNumber' => __('Channel Number'),
                    'alphabeticalAsc' => __('Alphabetical (A-Z)'),
                    'alphabeticalDesc' => __('Alphabetical (Z-A)'),
                ],
                'app_default' => 'playlistOrder',
            ],
            'vod_favorites_first' => [
                'group' => 'sorting',
                'type' => self::TYPE_BOOL,
                'label' => __('Movies: Favorites First'),
                'app_default' => true,
            ],
            'series_favorites_first' => [
                'group' => 'sorting',
                'type' => self::TYPE_BOOL,
                'label' => __('Series: Favorites First'),
                'app_default' => true,
            ],
            'live_tv_favorites_first' => [
                'group' => 'sorting',
                'type' => self::TYPE_BOOL,
                'label' => __('Live TV: Favorites First'),
                'app_default' => true,
            ],
            'vod_player' => [
                'group' => 'playback',
                'type' => self::TYPE_ENUM,
                'label' => __('Movies and series player'),
                'options' => [
                    'exoPlayerFirst' => __('Try ExoPlayer first'),
                    'mpv' => __('Use mpv'),
                ],
                'app_default' => 'exoPlayerFirst',
                'platforms' => __('Android only'),
            ],
            'match_refresh_rate' => [
                'group' => 'playback',
                'type' => self::TYPE_BOOL,
                'label' => __('Match display refresh rate'),
                'app_default' => false,
                'helper' => __('App default is on for Apple TV and off elsewhere.'),
            ],
            'hdr_enabled' => [
                'group' => 'playback',
                'type' => self::TYPE_BOOL,
                'label' => __('HDR mode'),
                'app_default' => true,
                'platforms' => __('Windows and Linux only'),
            ],
            'deinterlace' => [
                'group' => 'playback',
                'type' => self::TYPE_BOOL,
                'label' => __('Deinterlace video'),
                'app_default' => false,
            ],
            'prebuffer_live' => [
                'group' => 'playback',
                'type' => self::TYPE_BOOL,
                'label' => __('Buffer live streams before playing'),
                'app_default' => false,
            ],
            'show_playback_diagnostics' => [
                'group' => 'playback',
                'type' => self::TYPE_BOOL,
                'label' => __('Show playback diagnostics'),
                'app_default' => false,
            ],
            'navigation_sound_enabled' => [
                'group' => 'interface',
                'type' => self::TYPE_BOOL,
                'label' => __('Navigation sound'),
                'app_default' => true,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function groups(): array
    {
        return [
            'home' => __('Start and Home'),
            'live' => __('Live TV and Guide'),
            'sorting' => __('Sorting'),
            'playback' => __('Playback'),
            'interface' => __('Interface'),
        ];
    }

    /**
     * Drops unknown keys, unknown modes and values outside a setting's
     * options, and casts values to the type the app expects.
     *
     * @param  array<string, mixed>|null  $settings
     * @return array<string, array{mode: string, value: string|int|bool}>
     */
    public static function normalize(?array $settings): array
    {
        $normalized = [];

        foreach (self::definitions() as $key => $definition) {
            $entry = $settings[$key] ?? null;

            if (! is_array($entry) || ! in_array($entry['mode'] ?? null, self::MODES, true)) {
                continue;
            }

            $value = self::castValue($definition, $entry['value'] ?? null);

            if ($value === null) {
                continue;
            }

            $normalized[$key] = ['mode' => $entry['mode'], 'value' => $value];
        }

        return $normalized;
    }

    /**
     * @param  array{type: string, options?: array<string|int, string>}  $definition
     */
    private static function castValue(array $definition, mixed $value): string|int|bool|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($definition['type']) {
            self::TYPE_BOOL => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            self::TYPE_INT => is_numeric($value) && array_key_exists((int) $value, $definition['options'] ?? [])
                ? (int) $value
                : null,
            default => is_string($value) && array_key_exists($value, $definition['options'] ?? [])
                ? $value
                : null,
        };
    }
}
