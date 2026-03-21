<?php

namespace App\Services;

use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\URL;

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
        if ($proxyUrlOverride && filter_var($proxyUrlOverride, FILTER_VALIDATE_URL)) {
            $url = rtrim($proxyUrlOverride, '/');
        } else {
            // Use `url('')` to get request aware URL, which respects the current request's scheme and host, and is more reliable in various environments (e.g., behind proxies, load balancers)
            $url = url('');
        }

        // Set the base URL for the proxy service
        $this->baseUrl = $url;
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
}
