<?php

/**
 * Guards the generated OpenAPI document (Scramble) against regressing into vague or
 * wrong response docs, e.g. from Scribe-style `@response 200 {...}` tags (which Scramble
 * reads as the literal type `200`) or from an early return Scramble can't infer.
 */

use App\Models\Category;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\Series;
use App\Models\User;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Bus;
use Symfony\Component\HttpFoundation\StreamedResponse;

function generatedApiDocument(): array
{
    return app(Generator::class)(Scramble::getGeneratorConfig('default'));
}

it('documents a real schema for every success response', function () {
    $vagueResponses = [];

    foreach (generatedApiDocument()['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            foreach ($operation['responses'] ?? [] as $status => $response) {
                $schema = $response['content']['application/json']['schema'] ?? null;

                if ($status < 200 || $status >= 300 || $schema === null) {
                    continue;
                }

                $isBareObject = $schema === ['type' => 'object'];
                $isLiteralInteger = ($schema['type'] ?? null) === 'integer' && array_key_exists('const', $schema);

                if ($isBareObject || $isLiteralInteger) {
                    $vagueResponses[] = strtoupper($method)." {$path} {$status}";
                }
            }
        }
    }

    expect($vagueResponses)->toBe([]);
});

it('registers the api resources as named schemas', function () {
    $schemas = generatedApiDocument()['components']['schemas'];

    expect($schemas)->toHaveKeys([
        'Channel', 'ChannelDetail', 'ChannelFailover', 'Group', 'GroupSummary',
        'CustomPlaylistChannel', 'CustomPlaylistGroup', 'PlaylistSummary', 'EpgSummary',
        'Recording', 'SeriesRule', 'SeriesRuleMatch',
    ])
        ->and($schemas)->not->toHaveKey('MessageBag');
});

it('only keeps schemas that something references', function () {
    $document = generatedApiDocument();
    $serialized = json_encode($document, JSON_UNESCAPED_SLASHES);

    $unreferenced = collect(array_keys($document['components']['schemas']))
        ->reject(fn (string $name) => str_contains($serialized, "\"#/components/schemas/{$name}\""))
        ->values()
        ->all();

    expect($unreferenced)->toBe([]);
});

it('documents the dvr token errors on dvr routes', function () {
    $responses = generatedApiDocument()['paths']['/recordings']['get']['responses'];

    expect($responses)->toHaveKeys(['401', '403'])
        ->and($responses['401']['content']['application/json']['schema']['required'])->toBe(['detail']);
});

it('keeps hand-written responses that share a status with a shared response', function () {
    $schema = generatedApiDocument()['paths']['/custom-playlist/{uuid}/groups/{id}']['patch']['responses']['422']['content']['application/json']['schema'];
    $bodyKeys = collect($schema['anyOf'])->map(fn (array $body) => array_keys($body['properties']))->all();

    expect($bodyKeys)->toContain(['success', 'message'], ['message', 'errors']);
});

it('keeps the inferred player_api error responses, including the request-error shape', function () {
    $responses = generatedApiDocument()['paths']['/player_api.php']['get']['responses'];

    expect(json_encode($responses['401']))->toContain('authentication_failed')
        ->and(json_encode($responses['404']))->toContain('integration_not_found');
});

it('defaults the api version to the app version', function () {
    config(['scramble.info.version' => '']);

    expect(generatedApiDocument()['info']['version'])->toBe(config('dev.version'));
});

it('documents each player_api action with a schema matching the real response', function () {
    Bus::fake();

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $playlistAuth = PlaylistAuth::create([
        'name' => 'Docs', 'username' => 'docs', 'password' => 'secret', 'enabled' => true, 'user_id' => $user->id,
    ]);
    $playlist->playlistAuths()->attach($playlistAuth);

    $liveGroup = Group::factory()->for($user)->create(['playlist_id' => $playlist->id, 'type' => 'live']);
    $vodGroup = Group::factory()->for($user)->create(['playlist_id' => $playlist->id, 'type' => 'vod']);
    Channel::factory()->for($user)->for($playlist)->for($liveGroup)->create(['enabled' => true, 'is_vod' => false]);
    Channel::factory()->for($user)->for($playlist)->for($vodGroup)->create(['enabled' => true, 'is_vod' => true]);
    $category = Category::factory()->for($user)->for($playlist)->create();
    Series::factory()->for($user)->for($playlist)->create(['category_id' => $category->id, 'enabled' => true]);

    $document = generatedApiDocument();

    expect(collect($document['paths']['/player_api.php']['get']['responses']['200']['content']['application/json']['schema']['anyOf'])->pluck('$ref')->all())
        ->toContain('#/components/schemas/XtreamPanel', '#/components/schemas/XtreamLiveStreams');

    $schemaForAction = [
        'panel' => 'XtreamPanel',
        'get_live_streams' => 'XtreamLiveStreams',
        'get_vod_streams' => 'XtreamVodStreams',
        'get_series' => 'XtreamSeries',
        'get_live_categories' => 'XtreamLiveCategories',
        'get_vod_categories' => 'XtreamVodCategories',
        'get_series_categories' => 'XtreamSeriesCategories',
    ];

    foreach ($schemaForAction as $action => $schemaName) {
        $response = $this->get(route('xtream.api.player', ['username' => 'docs', 'password' => 'secret', 'action' => $action]))->assertOk();
        $body = $response->baseResponse instanceof StreamedResponse
            ? json_decode($response->streamedContent(), true)
            : $response->json();

        $schema = $document['components']['schemas'][$schemaName];
        if ($schema['type'] === 'array') {
            // List items are their own titled schema so the docs UI can name the array.
            $itemSchemaName = str($schema['items']['$ref'])->afterLast('/')->toString();
            $schema = $document['components']['schemas'][$itemSchemaName];

            expect($schema['title'])->toBe($itemSchemaName);
        }
        $documentedKeys = $schema['required'];
        $actualKeys = array_keys(array_is_list($body) ? $body[0] : $body);

        expect($documentedKeys)->toEqualCanonicalizing($actualKeys, "{$schemaName} is out of date with the {$action} response");
    }
});
