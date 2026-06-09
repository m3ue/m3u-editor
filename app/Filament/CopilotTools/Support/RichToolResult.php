<?php

declare(strict_types=1);

namespace App\Filament\CopilotTools\Support;

/**
 * A tool return value that carries both the plain-text the LLM sees
 * and a sidecar media array that is embedded as an HTML comment marker
 * inside the result string so the Blade view can render image cards.
 *
 * Markers use the format:  <!--img:URL:LABEL-->
 * They are invisible in the collapsible raw-result <pre> block but
 * are intercepted by chat-message.blade.php before rendering.
 */
class RichToolResult
{
    /** @param array<int, array{type: string, url: string, label: string}> $media */
    public function __construct(
        public readonly string $text,
        public readonly array $media = [],
    ) {}

    /**
     * Serialise to the string the copilot agent receives.
     * Image markers are appended as HTML-comment tokens after the text
     * so they are inert in the LLM context but parseable by Blade.
     */
    public function __toString(): string
    {
        $markers = '';

        foreach ($this->media as $item) {
            if (($item['type'] ?? '') === 'image' && ! empty($item['url'])) {
                $url = $item['url'];
                // TMDB returns absolute https; TVMaze returns protocol-relative (//...)
                if (str_starts_with($url, '//')) {
                    $url = 'https:'.$url;
                }
                $label = addslashes($item['label'] ?? '');
                $markers .= "\n<!--img:{$url}:{$label}-->";
            }
        }

        return $this->text.$markers;
    }
}
