<?php

namespace App\Enums;

use App\Settings\GeneralSettings;

/**
 * Named artwork sizes the image cache stores. URLs carry the profile name
 * (never pixel values), and the server resolves it to the max width
 * configured under Settings > Assets (env vars override), so a settings
 * change re-sizes artwork without changing any URL a client already holds.
 */
enum ImageProfile: string
{
    case Poster = 'poster';
    case Backdrop = 'backdrop';
    case TitleLogo = 'title_logo';
    case Photo = 'photo';

    public function getLabel(): string
    {
        return match ($this) {
            self::Poster => __('Poster'),
            self::Backdrop => __('Backdrop'),
            self::TitleLogo => __('Title logo'),
            self::Photo => __('Cast photo'),
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Poster => __('VOD and series covers, season covers and episode images.'),
            self::Backdrop => __('Background art on detail screens and landscape programme art.'),
            self::TitleLogo => __('Transparent title logos shown on detail screens.'),
            self::Photo => __('Cast and crew headshots.'),
        };
    }

    /**
     * GeneralSettings property holding this profile's saved max width.
     */
    public function settingKey(): string
    {
        return match ($this) {
            self::Poster => 'image_poster_width',
            self::Backdrop => 'image_backdrop_width',
            self::TitleLogo => 'image_title_logo_width',
            self::Photo => 'image_photo_width',
        };
    }

    public function defaultWidth(): int
    {
        return match ($this) {
            self::Poster => 600,
            self::Backdrop => 1280,
            self::TitleLogo => 800,
            self::Photo => 300,
        };
    }

    /**
     * Config key whose env var overrides the saved setting for this profile.
     */
    public function configKey(): string
    {
        return match ($this) {
            self::Poster => 'proxy.image_resize_poster_width',
            self::Backdrop => 'proxy.image_resize_backdrop_width',
            self::TitleLogo => 'proxy.image_resize_title_logo_width',
            self::Photo => 'proxy.image_resize_photo_width',
        };
    }

    /**
     * Max width artwork is stored at for this profile, or null when image
     * optimization is turned off (originals are cached and served as-is).
     */
    public function maxWidth(): ?int
    {
        if (! self::optimizationEnabled()) {
            return null;
        }

        $settings = self::settings();
        if ($settings) {
            return $settings->imageProfileWidth($this);
        }

        return (int) (config($this->configKey()) ?: $this->defaultWidth());
    }

    public static function optimizationEnabled(): bool
    {
        $settings = self::settings();
        if ($settings) {
            return $settings->imageOptimizationEnabled();
        }

        return (bool) (config('proxy.image_resize_enabled') ?? true);
    }

    /**
     * Encoder quality (1-100) for optimized artwork, or null for the image
     * driver's default.
     */
    public static function quality(): ?int
    {
        $settings = self::settings();
        if ($settings) {
            return $settings->imageQuality();
        }

        $quality = (int) config('proxy.image_resize_quality');

        return $quality > 0 ? min($quality, 100) : null;
    }

    /**
     * Media server (Emby/Jellyfin/Plex) image types carry their role in the
     * proxy route, so the profile is derived from it.
     */
    public static function fromMediaServerImageType(string $imageType): self
    {
        return match (strtolower($imageType)) {
            'backdrop', 'thumb', 'art', 'banner' => self::Backdrop,
            'logo' => self::TitleLogo,
            default => self::Poster,
        };
    }

    /**
     * Snap a `?w=` hint to the profile whose width is closest, so any width
     * maps onto one of the few cached sizes. Only the roles `?w=` URLs were
     * ever generated for (poster, backdrop, cast photo) are candidates.
     */
    public static function nearestToWidth(int $width): self
    {
        $nearest = self::Poster;
        $nearestDistance = PHP_INT_MAX;

        foreach ([self::Poster, self::Backdrop, self::Photo] as $profile) {
            $distance = abs(($profile->maxWidth() ?? $profile->defaultWidth()) - $width);
            if ($distance < $nearestDistance) {
                $nearest = $profile;
                $nearestDistance = $distance;
            }
        }

        return $nearest;
    }

    /**
     * Pick a profile from the image itself when its URL carries no role:
     * landscape art is sized as a backdrop, portrait and square art as a poster.
     */
    public static function forDimensions(int $width, int $height): self
    {
        return $width > $height ? self::Backdrop : self::Poster;
    }

    /**
     * Settings can be unresolvable before the settings migrations have run,
     * in which case callers fall back to config and the profile defaults.
     */
    private static function settings(): ?GeneralSettings
    {
        try {
            return app(GeneralSettings::class);
        } catch (\Throwable) {
            return null;
        }
    }
}
