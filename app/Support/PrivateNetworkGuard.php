<?php

namespace App\Support;

class PrivateNetworkGuard
{
    /**
     * Determine whether an IP address is private, loopback, link-local, or otherwise reserved.
     */
    public static function ipIsPrivate(string $ip): bool
    {
        return ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
