<?php

namespace App\Services;

use InvalidArgumentException;

final class ManagedCacheAccountGroupIdentity
{
    /**
     * Returns an opaque stable group key. Source and account values are never
     * persisted or emitted as part of the group identity.
     */
    public function for(string $origin, string $account): string
    {
        $origin = strtolower(trim($origin));
        $account = trim($account);

        if ($origin === '' || $account === '') {
            throw new InvalidArgumentException('Managed cache account identity is incomplete.');
        }

        return hash_hmac('sha256', $origin."\n".$account, (string) config('app.key'));
    }
}
