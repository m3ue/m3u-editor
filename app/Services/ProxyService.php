<?php

namespace App\Services;

use App\Settings\GeneralSettings;

/**
 * Service to handle proxy URL generation for channels and episodes.
 */
class ProxyService
{
    /**
     * Base URL for the proxy service
     *
     * @var string
     */
    public $baseUrl;

    /**
     * Constructor
     */
    public function __construct()
    {
        // See if proxy override is enabled
        $proxyUrlOverride = config('proxy.url_override');

        // See if override settings apply
        if (! $proxyUrlOverride || empty($proxyUrlOverride)) {
            try {
                $settings = app(GeneralSettings::class);
                $proxyUrlOverride = $settings->url_override ?? null;
            } catch (\Exception $e) {
            }
        }

        // Use the override URL or default to application URL
        $url = $proxyUrlOverride && filter_var($proxyUrlOverride, FILTER_VALIDATE_URL)
            ? $proxyUrlOverride
            : url('');

        // Normalize the base url
        $this->baseUrl = rtrim($url, '/');
    }

    /**
     * Get the base URL for the proxy service
     *
     * @return string
     */
    public function getBaseUrl()
    {
        return $this->baseUrl;
    }

    /**
     * Get the proxy URL for a channel
     *
     * @param  string|int  $id
     * @param  string|null  $playlistUuid  Optional playlist UUID for context (e.g., merged playlists)
     * @return string
     */
    public function getProxyUrlForChannel($id, $playlistUuid = null)
    {
        $url = $this->baseUrl.'/api/m3u-proxy/channel/'.$id;
        if ($playlistUuid) {
            $url .= '/'.$playlistUuid;
        }

        // Note: Username is now passed via X-Username header, not query param
        return $url;
    }

    /**
     * Get the proxy URL for an episode
     *
     * @param  string|int  $id
     * @param  string|null  $playlistUuid  Optional playlist UUID for context (e.g., merged playlists)
     * @return string
     */
    public function getProxyUrlForEpisode($id, $playlistUuid = null)
    {
        $url = $this->baseUrl.'/api/m3u-proxy/episode/'.$id;
        if ($playlistUuid) {
            $url .= '/'.$playlistUuid;
        }

        // Note: Username is now passed via X-Username header, not query param
        return $url;
    }
}
