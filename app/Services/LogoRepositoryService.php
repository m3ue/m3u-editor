<?php

namespace App\Services;

use App\Models\Channel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class LogoRepositoryService
{
    public const LEGACY_CACHE_KEY = 'logo_repository.index.v1';

    public const CACHE_VERSION = 'v2';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getIndex(): array
    {
        if (app()->runningUnitTests()) {
            return $this->buildIndex();
        }

        $cacheKey = $this->cacheKey();

        /** @var array<int, array<string, mixed>> $result */
        $result = Cache::remember($cacheKey, now()->addMinutes(30), function (): array {
            return $this->buildIndex();
        });

        return $result;
    }

    public function clearCache(): void
    {
        Cache::forget(self::LEGACY_CACHE_KEY);
        Cache::forget($this->cacheKey());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByFilename(string $filename): ?array
    {
        $normalized = $this->normalizeSlug(pathinfo($filename, PATHINFO_FILENAME));

        foreach ($this->getIndex() as $entry) {
            $entryBase = $this->normalizeSlug(pathinfo((string) $entry['filename'], PATHINFO_FILENAME));

            if ($entryBase === $normalized) {
                return $entry;
            }

            $aliases = $entry['aliases'] ?? [];
            if (in_array($normalized, $aliases, true)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function buildIndex(): array
    {
        $channels = Channel::query()
            ->where('enabled', true)
            ->where('is_vod', false)
            ->with(['epgChannel', 'playlist', 'customPlaylist'])
            ->get();

        $usedFilenames = [];
        $entries = [];
        $placeholder = LogoCacheService::getPlaceholderUrl('logo');

        foreach ($channels as $channel) {
            $logoUrl = (string) LogoService::getChannelLogoUrl($channel);

            if (empty($logoUrl) || $logoUrl === $placeholder) {
                continue;
            }

            if (! filter_var($logoUrl, FILTER_VALIDATE_URL)) {
                if (str_starts_with($logoUrl, '/')) {
                    $logoUrl = url($logoUrl);
                } else {
                    continue;
                }
            }

            $slugCandidates = collect([
                $channel->name_custom,
                $channel->name,
                $channel->title,
                (string) ($channel->stream_id ?? ''),
                (string) ($channel->epg_id ?? ''),
            ])->filter()->map(fn (string $value): string => $this->normalizeSlug($value))->filter()->unique()->values()->all();

            if (empty($slugCandidates)) {
                continue;
            }

            $primarySlug = $slugCandidates[0];
            $filename = $primarySlug.'.png';
            $counter = 2;

            while (in_array($filename, $usedFilenames, true)) {
                $filename = $primarySlug.'-'.$counter.'.png';
                $counter++;
            }

            $usedFilenames[] = $filename;

            $entries[] = [
                'filename' => $filename,
                'logo' => $logoUrl,
                'name' => $channel->name_custom ?? $channel->name,
                'channel_id' => $channel->id,
                'aliases' => $slugCandidates,
                'repository_url' => route('logo.repository.file', ['filename' => $filename]),
            ];
        }

        return $entries;
    }

    protected function normalizeSlug(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = str_replace('&', ' and ', $value);
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '';

        return trim($value, '-');
    }

    protected function cacheKey(): string
    {
        return 'logo_repository.index.'.self::CACHE_VERSION.'.'.app()->environment();
    }
}
