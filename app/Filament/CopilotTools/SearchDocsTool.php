<?php

declare(strict_types=1);

namespace App\Filament\CopilotTools;

use App\Services\CopilotDocsSearch;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Copilot tool that searches the m3u editor documentation.
 *
 * It queries the index the docs site publishes at /data/copilot-docs.json
 * (built from the docs themselves on every deploy), ranks sections by
 * relevance, and returns the best matches with a link to each.
 */
class SearchDocsTool extends BaseTool
{
    private const MAX_RESULTS = 4;

    private const MAX_SECTION_CHARACTERS = 1800;

    public function description(): Stringable|string
    {
        return 'Search the m3u editor documentation (editor, proxy, integrations, m3u-tv, plugins, AI Copilot) for how features work, how to configure them, and troubleshooting. Always use this before answering "how do I" or "what does" questions, and cite the returned URL in your answer.';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description(__('Search term or question to look up in the docs'))->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $query = trim((string) $request['query']);

        if ($query === '') {
            return 'Please provide a search query.';
        }

        $search = app(CopilotDocsSearch::class);
        $index = $search->loadIndex();

        if ($index === null) {
            return 'The documentation index could not be loaded. Tell the user to browse the docs at '.config('dev.docs_url').'/docs instead.';
        }

        $results = $search->search($index, $query, self::MAX_RESULTS);

        if ($results === []) {
            return "No documentation found matching '{$query}'. Browse the docs at ".config('dev.docs_url').'/docs';
        }

        $lines = ["Documentation results for '{$query}':", ''];

        foreach ($results as $result) {
            $lines[] = '## '.$result['page'].($result['heading'] !== $result['page'] ? ' > '.$result['heading'] : '');
            $lines[] = 'URL: '.$result['url'];
            $lines[] = '';
            $lines[] = mb_strimwidth($result['text'], 0, self::MAX_SECTION_CHARACTERS, '...');
            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
