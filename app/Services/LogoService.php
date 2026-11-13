<?php

namespace App\Services;

use App\Enums\ChannelLogoType;
use App\Http\Controllers\LogoProxyController;

/**
 * Service to handle logo URL retrieval and proxying.
 */
class LogoService
{
    /**
     * Get the logo URL for a channel, using proxy if it's a remote URL
     */
    public static function getChannelLogoUrl($channel): string
    {
        if (! $channel) {
            return LogoCacheService::getPlaceholderUrl('logo');
        }

        $logoUrl = self::preferredChannelLogo($channel);

        // If still empty, return placeholder
        if (empty($logoUrl)) {
            if ($channel->is_vod) {
                return LogoCacheService::getPlaceholderUrl('poster');
            } else {
                return LogoCacheService::getPlaceholderUrl('logo');
            }
        }

        // If it's already a local URL, return as-is
        if (! filter_var($logoUrl, FILTER_VALIDATE_URL) || str_starts_with($logoUrl, url('/'))) {
            return $logoUrl;
        }

        // Check if proxy is enabled
        $playlist = $channel->playlist ?? $channel->customPlaylist ?? null;

        // Return proxied URL for remote images
        return $playlist?->enable_logo_proxy
            ? LogoProxyController::generateProxyUrl($logoUrl, internal: true) // Don't use `PROXY_URL_OVERRIDE`, use base app url for Resource requests
            : $logoUrl;
    }

    /**
     * The channel's logo source (URL or local path) before proxying: the logo
     * override, then the logo its logo type prefers (channel or EPG), then
     * any other it has. Empty when it has none.
     */
    public static function preferredChannelLogo($channel): string
    {
        // Determine logo based on logo_type preference
        if ($channel->logo) {
            // Logo override takes precedence
            $logoUrl = $channel->logo;
        } elseif ($channel->logo_type === ChannelLogoType::Channel) {
            $logoUrl = $channel->logo_internal;
        } else {
            $logoUrl = $channel->epgChannel?->icon_custom
                ?? $channel->epgChannel?->icon
                ?? $channel->logo
                ?? $channel->logo_internal;
        }

        // Fallback to any available logo if preferred type is empty
        if (empty($logoUrl)) {
            $logoUrl = $channel->logo
                ?? $channel->logo_internal
                ?? $channel->epgChannel?->icon_custom
                ?? $channel->epgChannel?->icon
                ?? '';
        }

        return (string) $logoUrl;
    }

    /**
     * Every logo source the channel has, in the order {@see preferredChannelLogo()}
     * picks from, for callers that can try the next one when a logo turns out
     * to be unreachable.
     *
     * @return list<string>
     */
    public static function channelLogoCandidates($channel): array
    {
        return array_values(array_unique(array_filter([
            self::preferredChannelLogo($channel),
            $channel->logo,
            $channel->logo_internal,
            $channel->epgChannel?->icon_custom,
            $channel->epgChannel?->icon,
        ], fn (?string $logo): bool => filled($logo))));
    }

    /**
     * Get the logo URL for a series, using proxy if it's a remote URL
     */
    public static function getSeriesLogoUrl($series): string
    {
        if (! $series || empty($series->cover)) {
            return LogoCacheService::getPlaceholderUrl('poster');
        }

        $logoUrl = $series->cover;

        // If it's already a local URL, return as-is
        if (! filter_var($logoUrl, FILTER_VALIDATE_URL) || str_starts_with($logoUrl, url('/'))) {
            return $logoUrl;
        }

        // Return proxied URL for remote images
        return $series->playlist?->enable_logo_proxy
            ? LogoProxyController::generateProxyUrl($logoUrl, internal: true) // Don't use `PROXY_URL_OVERRIDE`, use base app url for Resource requests
            : $logoUrl;
    }

    /**
     * Get the logo URL for a Series episode, using proxy if it's a remote URL
     */
    public static function getEpisodeLogoUrl($episode): string
    {
        if (! $episode || empty($episode->info)) {
            return LogoCacheService::getPlaceholderUrl('episode');
        }

        $logoUrl = $episode->info['movie_image'] ?? $episode->info['cover_big'] ?? '';

        // If it's already a local URL, return as-is
        if (! filter_var($logoUrl, FILTER_VALIDATE_URL) || str_starts_with($logoUrl, url('/'))) {
            return $logoUrl;
        }

        // Return proxied URL for remote images
        return $episode->playlist?->enable_logo_proxy
            ? LogoProxyController::generateProxyUrl($logoUrl, internal: true) // Don't use `PROXY_URL_OVERRIDE`, use base app url for Resource requests
            : $logoUrl;
    }

    /**
     * Get the logo URL for an EPG channel, using proxy if it's a remote URL
     */
    public static function getEpgChannelLogoUrl($epgChannel): string
    {
        if (! $epgChannel || empty($epgChannel->icon)) {
            return LogoCacheService::getPlaceholderUrl('logo');
        }

        $logoUrl = $epgChannel->icon;

        // If it's already a local URL, return as-is
        if (! filter_var($logoUrl, FILTER_VALIDATE_URL) || str_starts_with($logoUrl, url('/'))) {
            return $logoUrl;
        }

        // Return proxied URL for remote images
        return LogoProxyController::generateProxyUrl($logoUrl);
    }

    /**
     * Preload logos for multiple channels (useful for batch operations)
     */
    public static function preloadChannelLogos(array $channels): array
    {
        $logoUrls = [];

        foreach ($channels as $channel) {
            $logoUrls[$channel->id] = self::getChannelLogoUrl($channel);
        }

        return $logoUrls;
    }
}
