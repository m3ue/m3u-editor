<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use Throwable;

final class XtreamManagedCacheCapacitySnapshotSource implements ManagedCacheCapacitySnapshotSource
{
    private const MAX_CONNECTION_LIMIT = 10000;

    public function __construct(private readonly ManagedCacheAccountGroupIdentity $groups) {}

    public function snapshotFor(Channel|Episode $item): ?ManagedCacheCapacitySnapshot
    {
        $playlist = $item->playlist;
        if (! $playlist instanceof Playlist || ! $playlist->xtream) {
            return null;
        }

        $config = $playlist->xtream_config;
        $server = $this->nonEmptyString($config['url'] ?? null);
        $username = $this->nonEmptyString($config['username'] ?? null);
        $password = $this->nonEmptyString($config['password'] ?? null);
        if ($server === null || $username === null || $password === null) {
            return null;
        }

        if (! $this->isTrustedSource($item->cacheSourceUrl(), $playlist, $username, $password)) {
            return null;
        }

        try {
            $response = XtreamService::make($playlist, retryLimit: 1)->userInfo(timeout: 5);
        } catch (Throwable) {
            return null;
        }

        $userInfo = $response['user_info'] ?? null;
        if (! is_array($userInfo) || ! $this->isAuthenticated($userInfo['auth'] ?? null)) {
            return null;
        }

        $limit = $this->boundedPositiveInteger($userInfo['max_connections'] ?? null);
        $activeConnections = $this->nonNegativeInteger($userInfo['active_cons'] ?? null);
        if ($limit === null || $activeConnections === null || $activeConnections > $limit) {
            return null;
        }

        return new ManagedCacheCapacitySnapshot(
            $this->groups->for($this->accountOrigin(), $username."\n".$password),
            limit: $limit,
            live: $activeConnections,
            background: 0,
        );
    }

    private function accountOrigin(): string
    {
        return 'xtream';
    }

    private function isTrustedSource(string $source, Playlist $playlist, string $username, string $password): bool
    {
        $sourceParts = parse_url($source);
        if (! is_array($sourceParts) || ! isset($sourceParts['scheme'], $sourceParts['host'], $sourceParts['path'])) {
            return false;
        }

        if (! in_array(strtolower($sourceParts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $sourceOrigin = strtolower($sourceParts['scheme'].'://'.$sourceParts['host'].
            (isset($sourceParts['port']) ? ':'.$sourceParts['port'] : ''));
        $configuredOrigins = array_map(function (string $url): string {
            $parts = parse_url($url);

            return is_array($parts) && isset($parts['scheme'], $parts['host'])
                ? strtolower($parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : ''))
                : '';
        }, $playlist->getOrderedXtreamUrls());
        if (! in_array($sourceOrigin, $configuredOrigins, true)) {
            return false;
        }

        $segments = array_values(array_filter(explode('/', trim($sourceParts['path'], '/')), 'strlen'));
        if (count($segments) !== 4 || ! in_array($segments[0], ['live', 'movie', 'series'], true)) {
            return false;
        }

        return hash_equals($username, urldecode($segments[1]))
            && hash_equals($password, urldecode($segments[2]));
    }

    private function isAuthenticated(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    private function boundedPositiveInteger(mixed $value): ?int
    {
        $integer = $this->nonNegativeInteger($value);

        return $integer !== null && $integer > 0 && $integer <= self::MAX_CONNECTION_LIMIT ? $integer : null;
    }

    private function nonNegativeInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (! is_string($value) || ! ctype_digit($value)) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return $integer === false ? null : $integer;
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
