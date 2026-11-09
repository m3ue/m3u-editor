<?php

use App\Filament\CopilotTools\SearchDocsTool;
use App\Services\CopilotDocsSearch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

function fakeDocsIndex(): array
{
    return [
        'schema' => 1,
        'pages' => [
            [
                'path' => 'integrations/plex_integration',
                'url' => 'https://docs.test/docs/integrations/plex_integration',
                'title' => 'Plex Integration',
                'description' => 'Connect a Plex server to import movies and shows.',
                'tags' => ['Plex', 'Integrations'],
                'sections' => [
                    ['heading' => 'Plex Integration', 'anchor' => '', 'text' => 'Plex servers can be added as a media server.'],
                    ['heading' => 'Get your Plex token', 'anchor' => 'get-your-plex-token', 'text' => 'Open Plex Web, view XML for any item, and copy the X-Plex-Token value.'],
                ],
            ],
            [
                'path' => 'advanced/alerts',
                'url' => 'https://docs.test/docs/advanced/alerts',
                'title' => 'Alerts',
                'description' => 'Send errors to Discord, Slack, or Telegram.',
                'tags' => ['Notifications'],
                'sections' => [
                    ['heading' => 'Alerts', 'anchor' => '', 'text' => 'Create a webhook in Discord and paste the URL into settings.'],
                ],
            ],
        ],
    ];
}

beforeEach(function (): void {
    Cache::forget(CopilotDocsSearch::FRESH_CACHE_KEY);
    Cache::forget(CopilotDocsSearch::STALE_CACHE_KEY);
    config(['dev.docs_url' => 'https://docs.test']);
});

it('returns the best matching section with a deep link', function (): void {
    Http::fake(['docs.test/data/copilot-docs.json' => Http::response(fakeDocsIndex())]);

    $result = (string) (new SearchDocsTool)->handle(new Request(['query' => 'where do I find my Plex token?']));

    expect($result)
        ->toContain('Plex Integration > Get your Plex token')
        ->toContain('URL: https://docs.test/docs/integrations/plex_integration#get-your-plex-token')
        ->toContain('X-Plex-Token')
        ->not->toContain('Discord');
});

it('finds pages whose slug and body share no words with the query title', function (): void {
    Http::fake(['docs.test/data/copilot-docs.json' => Http::response(fakeDocsIndex())]);

    $result = (string) (new SearchDocsTool)->handle(new Request(['query' => 'send errors to slack']));

    expect($result)->toContain('URL: https://docs.test/docs/advanced/alerts');
});

it('reports when nothing matches', function (): void {
    Http::fake(['docs.test/data/copilot-docs.json' => Http::response(fakeDocsIndex())]);

    expect((string) (new SearchDocsTool)->handle(new Request(['query' => 'zzzqqq'])))
        ->toContain("No documentation found matching 'zzzqqq'");
});

it('caches the index between searches', function (): void {
    Http::fake(['docs.test/data/copilot-docs.json' => Http::response(fakeDocsIndex())]);

    (new SearchDocsTool)->handle(new Request(['query' => 'plex']));
    (new SearchDocsTool)->handle(new Request(['query' => 'alerts']));

    Http::assertSentCount(1);
});

it('falls back to the last good index when the docs site is unreachable', function (): void {
    Http::fake(['docs.test/data/copilot-docs.json' => Http::response(fakeDocsIndex())]);
    (new SearchDocsTool)->handle(new Request(['query' => 'plex']));

    Cache::forget(CopilotDocsSearch::FRESH_CACHE_KEY);
    Http::fake(['docs.test/data/copilot-docs.json' => Http::response('down', 503)]);

    expect((string) (new SearchDocsTool)->handle(new Request(['query' => 'plex token'])))
        ->toContain('docs.test/docs/integrations/plex_integration');
});

it('points the user at the docs when no index is available', function (): void {
    Http::fake(['docs.test/data/copilot-docs.json' => Http::response('down', 503)]);

    expect((string) (new SearchDocsTool)->handle(new Request(['query' => 'plex'])))
        ->toContain('could not be loaded')
        ->toContain('https://docs.test/docs');
});
