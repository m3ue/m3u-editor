<?php

namespace App\Services;

class ProxyService
{
    /**
     * Get the proxy URL for a channel
     *
     * @param string|int $id
     * @return string
     */
    public function getProxyUrlForChannel($id)
    {
        return route('m3u-proxy.channel', ['id' => $id]);
    }

    /**
     * Get the proxy URL for an episode
     *
     * @param string|int $id
     * @return string
     */
    public function getProxyUrlForEpisode($id)
    {
        return route('m3u-proxy.episode', ['id' => $id]);
    }
}
