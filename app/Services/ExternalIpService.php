<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service to fetch the external IP address of the server/container.
 */
class ExternalIpService
{
    protected array $ipServices = [
        'https://api.ipify.org',
        'https://icanhazip.com',
        'https://ipecho.net/plain',
        'https://checkip.amazonaws.com',
    ];

    /**
     * Get the external IP address of the server/container.
     */
    public function getExternalIp(): ?string
    {
        return Cache::remember('external_ip', now()->addMinutes(60), function () {
            foreach ($this->ipServices as $service) {
                try {
                    $response = Http::timeout(5)->get($service);

                    if ($response->successful()) {
                        $ip = trim($response->body());

                        // Validate IP format
                        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6)) {
                            return $ip;
                        }
                    }
                } catch (\Exception $e) {
                    Log::debug("Failed to get IP from {$service}: ".$e->getMessage());

                    continue;
                }
            }

            return null;
        });
    }

    /**
     * Get the external IP with fallback to local detection.
     */
    public function getExternalIpWithFallback(): string
    {
        $externalIp = $this->getExternalIp();

        if ($externalIp) {
            return $externalIp;
        }

        // Fallback to server IP detection methods
        return $this->getServerIp();
    }

    /**
     * Get server IP using various methods.
     */
    protected function getServerIp(): string
    {
        // Try various server variables
        $serverIpMethods = [
            $_SERVER['SERVER_ADDR'] ?? null,
            $_SERVER['LOCAL_ADDR'] ?? null,
            gethostbyname(gethostname()),
        ];

        foreach ($serverIpMethods as $ip) {
            if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return 'Unable to detect IP';
    }

    /**
     * Clear the cached external IP.
     */
    public function clearCache(): void
    {
        Cache::forget('external_ip');
    }
}
