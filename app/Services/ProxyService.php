<?php

namespace App\Services;

class ProxyService
{
    /**
     * Get the proxy URL for a channel
     *
     * @param string|int $id
     * @param string|null $format
     * @param string|null $proxyUrlOverride
     * @return string
     */
    public function getProxyUrlForChannel($id, $format = 'mpts', $playlist = null)
    {
        $proxyUrlOverride = config('proxy.url_override');
        $id = rtrim(base64_encode($id), '=');
        if ($proxyUrlOverride) {
            $proxyUrlOverride = rtrim($proxyUrlOverride, '/');
            $proxyUrlOverride = "$proxyUrlOverride/stream/$id/$format";
            if ($playlist) {
                $proxyUrlOverride .= "/$playlist";
            }
            return $proxyUrlOverride;
        }
        return route('stream', [
            'encodedId' => $id,
            'format' => $format,
            'playlist' => $playlist
        ]);
    }
}
