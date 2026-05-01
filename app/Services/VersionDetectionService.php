<?php

namespace App\Services;

use App\Models\Channel;
use Illuminate\Support\Str;

class VersionDetectionService
{
    protected array $editionTags = [
        "Director's Cut",
        'Directors Cut',
        'Extended Edition',
        'Extended Cut',
        'Theatrical Cut',
        'Special Edition',
        'Anniversary Edition',
        'Final Cut',
        'Ultimate Cut',
        'Extended',
        'Theatrical',
        'Unrated',
        'Uncut',
        'Remastered',
        'Criterion',
        'Ultimate',
        'IMAX',
    ];

    public function detectEditionFromTitle(string $title): ?string
    {
        $lowerTitle = Str::lower($title);

        foreach ($this->editionTags as $tag) {
            if (str_contains($lowerTitle, Str::lower($tag))) {
                return $tag;
            }
        }

        return null;
    }

    public function detectEditionWithPattern(string $title, string $pattern): ?string
    {
        if (empty($pattern)) {
            return null;
        }

        try {
            if (preg_match($pattern, $title, $matches) === 1) {
                return trim($matches[1] ?? $matches[0]);
            }
        } catch (\Exception $e) {
            return null;
        }

        return null;
    }

    public function detectEdition(Channel $channel, ?string $customPattern = null): ?string
    {
        $title = $channel->name ?? $channel->title ?? '';

        if (! empty($customPattern)) {
            $edition = $this->detectEditionWithPattern($title, $customPattern);
            if ($edition) {
                return $edition;
            }
        }

        return $this->detectEditionFromTitle($title);
    }

    public function extractBaseTitle(string $title): string
    {
        $baseTitle = $title;

        foreach ($this->editionTags as $tag) {
            $baseTitle = preg_replace(
                '/\s*[-\[(\s]*'.preg_quote($tag, '/').'[-\])\s]*/i',
                '',
                $baseTitle
            ) ?? $baseTitle;
        }

        return trim($baseTitle);
    }

    public function areVersionsOfSameMovie(Channel $a, Channel $b): bool
    {
        $titleA = $this->extractBaseTitle($a->name ?? $a->title ?? '');
        $titleB = $this->extractBaseTitle($b->name ?? $b->title ?? '');

        if (empty($titleA) || empty($titleB)) {
            return false;
        }

        similar_text(Str::lower($titleA), Str::lower($titleB), $percent);

        return $percent >= 85;
    }

    public function groupByMovie(iterable $channels): array
    {
        $groups = [];

        foreach ($channels as $channel) {
            $baseTitle = $this->extractBaseTitle($channel->name ?? $channel->title ?? '');
            if (empty($baseTitle)) {
                continue;
            }

            $key = Str::slug($baseTitle);
            $groups[$key][] = $channel;
        }

        return $groups;
    }
}
