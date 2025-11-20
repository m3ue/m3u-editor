<?php

namespace App\Services;

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
        $proxyOverrideUrl = config('proxy.url_override');
        if (!empty($proxyOverrideUrl)) {
            $url = $proxyOverrideUrl;
        } else {
            // Default base URL
            $url = url("");
        }

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
     * @param string|int $id
     * @param string|null $playlistUuid Optional playlist UUID for context (e.g., merged playlists)
     * @return string
     */
    public function getProxyUrlForChannel($id, $playlistUuid = null)
    {
        $url = $this->baseUrl . '/api/m3u-proxy/channel/' . $id;
        if ($playlistUuid) {
            $url .= '/' . $playlistUuid;
        }
        return $url;
    }

    /**
     * Get the proxy URL for an episode
     *
     * @param string|int $id
     * @param string|null $playlistUuid Optional playlist UUID for context (e.g., merged playlists)
     * @return string
     */
    public function getProxyUrlForEpisode($id, $playlistUuid = null)
    {
        $url = $this->baseUrl . '/api/m3u-proxy/episode/' . $id;
        if ($playlistUuid) {
            $url .= '/' . $playlistUuid;
        }
        return $url;
    }
}
